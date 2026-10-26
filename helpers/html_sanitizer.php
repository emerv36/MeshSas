<?php
/**
 * Filtro de HTML por lista blanca para el contenido de lecciones (editor Quill).
 *
 * Conserva el formato que produce Quill y elimina scripts, manejadores de eventos
 * (on*), URLs javascript:/vbscript:, estilos peligrosos y etiquetas no permitidas.
 */

const HTML_ETIQUETAS_PERMITIDAS = [
    'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'sub', 'sup', 'span', 'div',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'hr',
    'a', 'img', 'iframe', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
];

const HTML_ATRIBUTOS_PERMITIDOS = [
    '*'      => ['class', 'style'],
    'a'      => ['href', 'target', 'rel', 'title'],
    'img'    => ['src', 'alt', 'title', 'width', 'height'],
    'iframe' => ['src', 'width', 'height', 'allowfullscreen', 'frameborder'],
    'td'     => ['colspan', 'rowspan'],
    'th'     => ['colspan', 'rowspan'],
    'ol'     => ['start'],
];

/**
 * Devuelve la URL si es segura (http, https, mailto, tel o relativa); si no, '#'.
 * Con $permitir_data_imagen acepta imágenes embebidas en base64 (las que inserta Quill).
 */
function url_segura(?string $url, bool $permitir_data_imagen = false): string
{
    $url = trim((string)$url);
    if ($url === '') {
        return '#';
    }
    // Quitar caracteres de control y espacios que los navegadores ignoran dentro del esquema
    $normalizada = strtolower(preg_replace('/[\x00-\x20]+/', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

    if ($permitir_data_imagen && preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#', $normalizada)) {
        return $url;
    }
    if (preg_match('#^([a-z][a-z0-9+.-]*):#', $normalizada, $m)) {
        return in_array($m[1], ['http', 'https', 'mailto', 'tel'], true) ? $url : '#';
    }
    return $url; // relativa
}

/**
 * Texto plano de un HTML (para tarjetas y resúmenes): sin etiquetas ni entidades,
 * espacios normalizados y recortado a $max caracteres sin partir letras multibyte.
 * Un editor vacío (`<p><br></p>`) devuelve ''. El resultado debe escaparse al imprimirlo.
 */
function extracto_texto(?string $html, int $max = 0): string
{
    $texto = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], ' ', (string)$html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $texto = trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $texto)));
    if ($max > 0 && mb_strlen($texto) > $max) {
        $texto = rtrim(mb_substr($texto, 0, $max)) . '…';
    }
    return $texto;
}

function limpiar_html(?string $html): string
{
    $html = (string)$html;
    if (trim($html) === '') {
        return '';
    }

    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="__raiz__">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $raiz = $doc->getElementById('__raiz__');
    if (!$raiz) {
        return htmlspecialchars($html, ENT_QUOTES, 'UTF-8');
    }
    limpiar_nodo_html($raiz);

    $salida = '';
    foreach ($raiz->childNodes as $hijo) {
        $salida .= $doc->saveHTML($hijo);
    }
    return $salida;
}

function limpiar_nodo_html(DOMNode $nodo): void
{
    // Copiar la lista: se modifica el árbol mientras se recorre
    foreach (iterator_to_array($nodo->childNodes) as $hijo) {
        if ($hijo instanceof DOMComment || $hijo instanceof DOMProcessingInstruction) {
            $nodo->removeChild($hijo);
            continue;
        }
        if (!$hijo instanceof DOMElement) {
            continue;
        }

        $tag = strtolower($hijo->tagName);
        if (in_array($tag, ['script', 'style', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta', 'base', 'svg', 'math', 'frame', 'frameset', 'applet'], true)) {
            $nodo->removeChild($hijo);
            continue;
        }
        if (!in_array($tag, HTML_ETIQUETAS_PERMITIDAS, true)) {
            // Etiqueta desconocida: se conserva su contenido, no la etiqueta
            limpiar_nodo_html($hijo);
            while ($hijo->firstChild) {
                $nodo->insertBefore($hijo->firstChild, $hijo);
            }
            $nodo->removeChild($hijo);
            continue;
        }

        $permitidos = array_merge(HTML_ATRIBUTOS_PERMITIDOS['*'], HTML_ATRIBUTOS_PERMITIDOS[$tag] ?? []);
        foreach (iterator_to_array($hijo->attributes) as $attr) {
            $nombre = strtolower($attr->name);
            $valor = $attr->value;
            if (!in_array($nombre, $permitidos, true)) {
                $hijo->removeAttribute($attr->name);
            } elseif ($nombre === 'style' && preg_match('/expression|javascript|vbscript|url\s*\(|@import|behavior|-moz-binding/i', $valor)) {
                $hijo->removeAttribute($attr->name);
            } elseif ($nombre === 'href') {
                $hijo->setAttribute('href', url_segura($valor));
            } elseif ($nombre === 'src') {
                $seguro = url_segura($valor, $tag === 'img');
                if ($tag === 'iframe' && !preg_match('#^https://(www\.youtube(-nocookie)?\.com/embed/|player\.vimeo\.com/video/)#i', $seguro)) {
                    $seguro = '';
                }
                if ($seguro === '' || $seguro === '#') {
                    $nodo->removeChild($hijo);
                    continue 2;
                }
                $hijo->setAttribute('src', $seguro);
            }
        }

        if ($tag === 'a' && $hijo->getAttribute('target') === '_blank') {
            $hijo->setAttribute('rel', 'noopener noreferrer');
        }

        limpiar_nodo_html($hijo);
    }
}

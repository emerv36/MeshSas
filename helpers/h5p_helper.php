<?php
/**
 * Lecciones H5P: validación e instalación de paquetes .h5p y resolución segura de sus archivos.
 *
 * Cada paquete se descomprime completo (contenido + librerías) en contenido_h5p/{leccion}-{hash8}/
 * y el original se guarda al lado como {leccion}-{hash8}.h5p para que el docente lo descargue.
 * La carpeta no se sirve por URL: los archivos los entrega dashboard/h5p_archivo.php tras
 * comprobar el acceso al curso. Los datos del paquete van en lecciones.contenido (JSON).
 */

const H5P_DIR = __DIR__ . '/../contenido_h5p';
const H5P_MAX_BYTES = 100 * 1024 * 1024;           // .h5p subido
const H5P_MAX_DESCOMPRIMIDO = 500 * 1024 * 1024;   // contenido total descomprimido (zip bomb)
const H5P_MAX_ARCHIVOS = 5000;

/** Extensiones que se instalan y se sirven, con su tipo MIME fijo. */
const H5P_EXTENSIONES = [
    'json' => 'application/json', 'js' => 'text/javascript', 'css' => 'text/css',
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
    'webp' => 'image/webp', 'bmp' => 'image/bmp', 'svg' => 'image/svg+xml',
    'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
    'eot' => 'application/vnd.ms-fontobject',
    'mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg',
    'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'wav' => 'audio/wav',
    'vtt' => 'text/vtt', 'webvtt' => 'text/vtt', 'txt' => 'text/plain', 'csv' => 'text/csv',
    'pdf' => 'application/pdf', 'gltf' => 'model/gltf+json', 'glb' => 'model/gltf-binary',
];

/** Extensiones ejecutables o de riesgo: si aparecen, el paquete entero se rechaza. */
const H5P_EXTENSIONES_PROHIBIDAS = [
    'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'inc',
    'cgi', 'pl', 'py', 'sh', 'bash', 'exe', 'bat', 'cmd', 'com', 'dll', 'so', 'jar',
    'asp', 'aspx', 'jsp', 'htaccess', 'htpasswd', 'html', 'htm', 'xhtml', 'shtml', 'xml', 'swf',
];

/** Datos del paquete guardados en lecciones.contenido ([] si la lección no tiene paquete). */
function h5p_datos_leccion(?string $contenido): array
{
    $datos = json_decode((string)$contenido, true);
    return is_array($datos) && isset($datos['carpeta']) && h5p_carpeta_valida($datos['carpeta']) ? $datos : [];
}

/** "Solo completar" o con nota mínima (por defecto). */
function h5p_modo(array $datos): string
{
    return ($datos['modo'] ?? '') === 'completar' ? 'completar' : 'nota';
}

function h5p_carpeta_valida($carpeta): bool
{
    return is_string($carpeta) && preg_match('/^\d+-[0-9a-f]{8}$/', $carpeta) === 1;
}

/**
 * Recibe el .h5p subido y lo prepara (h5p_extraer_paquete) en una carpeta temporal dentro de
 * contenido_h5p/. Lanza RuntimeException con un mensaje para el docente.
 */
function h5p_preparar_paquete(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        throw new RuntimeException(in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'El paquete supera el tamaño máximo que admite el servidor.'
            : 'No se recibió el paquete H5P.');
    }
    if (strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'h5p') {
        throw new RuntimeException('El archivo debe tener extensión .h5p.');
    }
    h5p_asegurar_directorio();
    $original = H5P_DIR . '/_tmp_' . bin2hex(random_bytes(8)) . '.h5p';
    if (!move_uploaded_file($file['tmp_name'], $original)) {
        throw new RuntimeException('No se pudo guardar el paquete subido.');
    }
    try {
        return h5p_extraer_paquete($original);
    } catch (Throwable $e) {
        if (is_file($original)) {
            unlink($original);
        }
        throw $e;
    }
}

/**
 * Valida un .h5p ya guardado en contenido_h5p/_tmp_xxx.h5p y lo descomprime al lado, en
 * contenido_h5p/_tmp_xxx/. Devuelve los datos para instalarlo; lanza RuntimeException con un
 * mensaje para el docente (y no deja la carpeta a medias).
 *
 * @return array{temporal:string, original:string, titulo:string, libreria:string, bytes:int, sha256:string, omitidos:int}
 */
function h5p_extraer_paquete(string $original): array
{
    $bytes = (int)filesize($original);
    if ($bytes <= 0 || $bytes > H5P_MAX_BYTES) {
        throw new RuntimeException('El paquete supera los ' . (H5P_MAX_BYTES / 1024 / 1024) . ' MB permitidos.');
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('El servidor no tiene la extensión zip de PHP.');
    }

    $zip = new ZipArchive();
    if ($zip->open($original) !== true) {
        throw new RuntimeException('El archivo no es un paquete H5P válido (no se pudo abrir el ZIP).');
    }

    try {
        // 1. Revisar todas las entradas antes de escribir nada en disco
        $entradas = [];
        $total = 0;
        $omitidos = 0;
        if ($zip->numFiles > H5P_MAX_ARCHIVOS) {
            throw new RuntimeException('El paquete tiene demasiados archivos.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $nombre = $stat['name'] ?? '';
            if ($nombre === '' || str_ends_with($nombre, '/')) {
                continue; // directorio
            }
            if (!h5p_ruta_interna_valida($nombre)) {
                throw new RuntimeException('El paquete contiene una ruta no permitida: ' . $nombre);
            }
            // Enlaces simbólicos (atributo Unix S_IFLNK)
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX
                && (($attr >> 16) & 0170000) === 0120000) {
                throw new RuntimeException('El paquete contiene enlaces simbólicos.');
            }
            $partes = explode('/', $nombre);
            if ($partes[0] === '__MACOSX' || array_filter($partes, fn($p) => str_starts_with($p, '.'))) {
                $omitidos++;
                continue; // metadatos y archivos ocultos
            }
            $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
            if (in_array($ext, H5P_EXTENSIONES_PROHIBIDAS, true)) {
                throw new RuntimeException('El paquete contiene un archivo no permitido: ' . $nombre);
            }
            if (!isset(H5P_EXTENSIONES[$ext])) {
                $omitidos++;
                continue; // documentación u otros archivos que el reproductor no usa
            }
            $total += (int)($stat['size'] ?? 0);
            if ($total > H5P_MAX_DESCOMPRIMIDO) {
                throw new RuntimeException('El contenido descomprimido del paquete es demasiado grande.');
            }
            $entradas[$i] = $nombre;
        }

        // 2. h5p.json y content/content.json
        $h5p_json = h5p_leer_json($zip, 'h5p.json');
        if (!$h5p_json || empty($h5p_json['mainLibrary']) || !is_array($h5p_json['preloadedDependencies'] ?? null)) {
            throw new RuntimeException('Falta h5p.json o no es válido. Exporta el contenido desde H5P o Lumi.');
        }
        if (h5p_leer_json($zip, 'content/content.json') === null) {
            throw new RuntimeException('Falta content/content.json o no es válido.');
        }

        // 3. Las librerías declaradas deben venir dentro del paquete
        $libreria_principal = '';
        foreach ($h5p_json['preloadedDependencies'] as $dep) {
            $carpeta_lib = ($dep['machineName'] ?? '') . '-' . (int)($dep['majorVersion'] ?? 0) . '.' . (int)($dep['minorVersion'] ?? 0);
            if (h5p_leer_json($zip, $carpeta_lib . '/library.json') === null) {
                throw new RuntimeException("El paquete no incluye la librería {$carpeta_lib}. Expórtalo con las librerías incluidas (por ejemplo desde Lumi).");
            }
            if (($dep['machineName'] ?? '') === $h5p_json['mainLibrary']) {
                $libreria_principal = $carpeta_lib;
            }
        }
        if ($libreria_principal === '') {
            throw new RuntimeException('La librería principal del paquete no está entre sus dependencias.');
        }

        // 4. Descomprimir en temporal, copiando cada entrada (sin extractTo) y contando los bytes reales
        $temporal = substr($original, 0, -strlen('.h5p'));
        if (!mkdir($temporal, 0755)) {
            throw new RuntimeException('No se pudo preparar la carpeta del paquete.');
        }
        try {
            $escritos = 0;
            foreach ($entradas as $i => $nombre) {
                $destino = $temporal . '/' . $nombre;
                $dir = dirname($destino);
                if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                    throw new RuntimeException('No se pudo descomprimir el paquete.');
                }
                $in = $zip->getStream($nombre);
                $out = fopen($destino, 'wb');
                if (!$in || !$out) {
                    throw new RuntimeException('No se pudo descomprimir el paquete.');
                }
                while (!feof($in)) {
                    $bloque = fread($in, 65536);
                    if ($bloque === false) {
                        break;
                    }
                    $escritos += strlen($bloque);
                    if ($escritos > H5P_MAX_DESCOMPRIMIDO) {
                        fclose($in);
                        fclose($out);
                        throw new RuntimeException('El contenido descomprimido del paquete es demasiado grande.');
                    }
                    fwrite($out, $bloque);
                }
                fclose($in);
                fclose($out);
            }
        } catch (Throwable $e) {
            h5p_borrar_directorio($temporal);
            throw $e;
        }
    } finally {
        $zip->close();
    }

    return [
        'temporal' => $temporal,
        'original' => $original,
        'titulo' => mb_substr(trim((string)($h5p_json['title'] ?? '')), 0, 200),
        'libreria' => $libreria_principal,
        'bytes' => $bytes,
        'sha256' => hash_file('sha256', $original),
        'omitidos' => $omitidos,
    ];
}

/** Mueve un paquete preparado a su carpeta definitiva y devuelve los datos para lecciones.contenido. */
function h5p_instalar_paquete(array $preparado, int $leccion_id, string $modo, ?array $anterior = null): array
{
    $carpeta = $leccion_id . '-' . substr($preparado['sha256'], 0, 8);
    $destino = H5P_DIR . '/' . $carpeta;
    // El mismo paquete subido de nuevo: se reemplaza
    if (is_dir($destino)) {
        h5p_borrar_directorio($destino);
    }
    if (!rename($preparado['temporal'], $destino) || !rename($preparado['original'], $destino . '.h5p')) {
        h5p_descartar_preparado($preparado);
        throw new RuntimeException('No se pudo instalar el paquete.');
    }
    // El anterior se borra solo después de instalar el nuevo
    if ($anterior && ($anterior['carpeta'] ?? '') !== $carpeta) {
        h5p_eliminar_paquete($anterior['carpeta']);
    }
    return [
        'carpeta' => $carpeta,
        'titulo' => $preparado['titulo'],
        'libreria' => $preparado['libreria'],
        'bytes' => $preparado['bytes'],
        'sha256' => $preparado['sha256'],
        'modo' => $modo === 'completar' ? 'completar' : 'nota',
        'subido_at' => date('Y-m-d H:i:s'),
    ];
}

/**
 * Formulario de lección (gestionar_curso.php y modulos.php), antes del INSERT/UPDATE.
 * Devuelve el contenido y el puntaje a guardar y el paquete subido ya validado, si lo hay.
 * Lanza RuntimeException con el mensaje para el docente (no se guarda nada).
 *
 * @param array|null $previa ['tipo' => ..., 'contenido' => ...] de la lección antes de editarla
 */
function h5p_antes_de_guardar(?array $previa, array $post): array
{
    $anterior = ($previa['tipo'] ?? '') === 'h5p' ? h5p_datos_leccion($previa['contenido'] ?? '') : [];
    $modo = ($post['h5p_modo'] ?? '') === 'completar' ? 'completar' : 'nota';
    $puntaje = (int)($post['h5p_puntaje_minimo'] ?? PASSING_GRADE);
    $puntaje = ($puntaje >= 1 && $puntaje <= 100) ? $puntaje : PASSING_GRADE;

    $preparado = null;
    if (isset($_FILES['h5p_file']) && ($_FILES['h5p_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $preparado = h5p_preparar_paquete($_FILES['h5p_file']);
    } elseif (!$anterior) {
        throw new RuntimeException('Sube el paquete .h5p de la lección.');
    }

    // Sin paquete nuevo se conserva el instalado; solo cambia el modo
    $contenido = $anterior ? json_encode(['modo' => $modo] + $anterior) : '{}';
    return ['contenido' => $contenido, 'puntaje' => $puntaje, 'modo' => $modo, 'preparado' => $preparado, 'anterior' => $anterior];
}

/**
 * Después del INSERT/UPDATE: instala el paquete subido y, si la lección dejó de ser H5P,
 * borra el paquete que tenía. $plan es lo que devolvió h5p_antes_de_guardar() (null si el
 * tipo nuevo no es h5p). Lanza RuntimeException si no se pudo instalar.
 */
function h5p_despues_de_guardar(PDO $pdo, int $leccion_id, ?array $plan, ?array $previa): void
{
    if ($plan === null) {
        if (($previa['tipo'] ?? '') === 'h5p') {
            h5p_eliminar_paquete(h5p_datos_leccion($previa['contenido'] ?? '')['carpeta'] ?? null);
        }
        return;
    }
    if ($plan['preparado']) {
        $datos = h5p_instalar_paquete($plan['preparado'], $leccion_id, $plan['modo'], $plan['anterior'] ?: null);
        $pdo->prepare("UPDATE lecciones SET contenido = ? WHERE id = ?")->execute([json_encode($datos), $leccion_id]);
    }
}

/** Tamaño máximo real de subida: el menor entre H5P_MAX_BYTES y los límites de PHP. */
function h5p_limite_subida(): int
{
    $limites = [H5P_MAX_BYTES];
    foreach (['upload_max_filesize', 'post_max_size'] as $clave) {
        $valor = trim((string)ini_get($clave));
        if ($valor === '' || $valor === '0') {
            continue;
        }
        $num = (float)$valor;
        $unidad = strtolower(substr($valor, -1));
        $num *= ['g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024][$unidad] ?? 1;
        $limites[] = (int)$num;
    }
    return min($limites);
}

/** Descarta un paquete preparado que no se llegó a instalar. */
function h5p_descartar_preparado(array $preparado): void
{
    h5p_borrar_directorio($preparado['temporal']);
    if (is_file($preparado['original'])) {
        unlink($preparado['original']);
    }
}

/** Borra la carpeta de un paquete instalado y su .h5p original. */
function h5p_eliminar_paquete(?string $carpeta): void
{
    if (!h5p_carpeta_valida($carpeta)) {
        return;
    }
    h5p_borrar_directorio(H5P_DIR . '/' . $carpeta);
    if (is_file(H5P_DIR . '/' . $carpeta . '.h5p')) {
        unlink(H5P_DIR . '/' . $carpeta . '.h5p');
    }
}

/**
 * Ruta absoluta de un archivo dentro de un paquete instalado, o null si no existe, sale de la
 * carpeta o su extensión no se sirve.
 */
function h5p_resolver_archivo(string $carpeta, string $relativa): ?string
{
    if (!h5p_carpeta_valida($carpeta) || !h5p_ruta_interna_valida($relativa)) {
        return null;
    }
    if (!isset(H5P_EXTENSIONES[strtolower(pathinfo($relativa, PATHINFO_EXTENSION))])) {
        return null;
    }
    $base = realpath(H5P_DIR . '/' . $carpeta);
    $ruta = realpath(H5P_DIR . '/' . $carpeta . '/' . $relativa);
    if ($base === false || $ruta === false || !is_file($ruta)) {
        return null;
    }
    return str_starts_with($ruta, $base . DIRECTORY_SEPARATOR) ? $ruta : null;
}

/** Ruta relativa sin "..", absolutas, barras invertidas, bytes nulos ni caracteres de control. */
function h5p_ruta_interna_valida(string $ruta): bool
{
    if ($ruta === '' || strlen($ruta) > 400 || str_starts_with($ruta, '/') || preg_match('/[\x00-\x1f\\\\:]/', $ruta)) {
        return false;
    }
    foreach (explode('/', $ruta) as $parte) {
        if ($parte === '' || $parte === '.' || $parte === '..') {
            return false;
        }
    }
    return true;
}

function h5p_leer_json(ZipArchive $zip, string $nombre): ?array
{
    $txt = $zip->getFromName($nombre);
    if ($txt === false || strlen($txt) > 20 * 1024 * 1024) {
        return null;
    }
    $datos = json_decode($txt, true);
    return is_array($datos) ? $datos : null;
}

function h5p_asegurar_directorio(): void
{
    if (!is_dir(H5P_DIR) && !mkdir(H5P_DIR, 0755, true)) {
        throw new RuntimeException('No existe la carpeta contenido_h5p y no se pudo crear.');
    }
}

function h5p_borrar_directorio(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

<?php
/**
 * Imágenes subidas (cursos, rutas, fotos de perfil) con respaldo cuando faltan.
 *
 * Las rutas se guardan relativas a la raíz del proyecto (p. ej. `uploads/curso_5.jpg`).
 * Un campo vacío o un archivo que ya no está en disco usan el respaldo en lugar de
 * mostrar una imagen rota.
 */

const IMAGEN_CURSO_RESPALDO = 'img/course-placeholder.svg';

/** true si la ruta apunta a un archivo existente del proyecto o es una URL externa. */
function imagen_disponible(?string $ruta): bool
{
    $ruta = trim((string)$ruta);
    if ($ruta === '') {
        return false;
    }
    if (preg_match('#^https?://#i', $ruta)) {
        return true; // externa: no se puede comprobar en disco
    }
    return is_file(dirname(__DIR__) . '/' . ltrim($ruta, '/'));
}

/** La ruta de la imagen o la de respaldo de cursos y rutas. El resultado debe escaparse. */
function imagen_curso(?string $ruta): string
{
    return imagen_disponible($ruta) ? trim((string)$ruta) : IMAGEN_CURSO_RESPALDO;
}

/** Hasta dos iniciales en mayúscula ("Carlos Sánchez León" → "CS"); '' si no hay nombre. */
function iniciales_nombre(?string $nombre): string
{
    $iniciales = '';
    foreach (array_slice(preg_split('/\s+/u', trim((string)$nombre), -1, PREG_SPLIT_NO_EMPTY), 0, 2) as $palabra) {
        $iniciales .= mb_strtoupper(mb_substr($palabra, 0, 1));
    }
    return $iniciales;
}

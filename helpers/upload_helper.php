<?php
/**
 * Validación y guardado seguro de archivos subidos.
 *
 * La extensión final se deriva del tipo MIME real del archivo (finfo), nunca
 * del nombre enviado por el cliente, y el nombre en disco es aleatorio.
 */

const UPLOAD_IMAGENES_MIME = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

/**
 * Valida que el archivo subido sea una imagen real de los tipos permitidos.
 * Devuelve la extensión segura correspondiente a su MIME.
 *
 * @throws RuntimeException si el archivo no es válido.
 */
function validar_imagen_subida(array $file, array $mimes_permitidos = ['image/jpeg', 'image/png', 'image/webp'], int $max_bytes = 5 * 1024 * 1024): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('No se recibió el archivo correctamente.');
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > $max_bytes) {
        throw new RuntimeException('La imagen no debe superar ' . round($max_bytes / 1048576) . ' MB.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, $mimes_permitidos, true) || !isset(UPLOAD_IMAGENES_MIME[$mime]) || @getimagesize($file['tmp_name']) === false) {
        $tipos = implode(', ', array_map(fn($m) => strtoupper(UPLOAD_IMAGENES_MIME[$m] ?? $m), $mimes_permitidos));
        throw new RuntimeException('Formato de imagen no permitido. Usa ' . $tipos . '.');
    }

    return UPLOAD_IMAGENES_MIME[$mime];
}

/**
 * Valida y guarda una imagen subida en $dir_absoluto con un nombre aleatorio.
 * Devuelve el nombre de archivo generado (sin directorio).
 *
 * @throws RuntimeException si el archivo no es válido o no se pudo guardar.
 */
function guardar_imagen_subida(array $file, string $dir_absoluto, string $prefijo, array $mimes_permitidos = ['image/jpeg', 'image/png', 'image/webp']): string
{
    $ext = validar_imagen_subida($file, $mimes_permitidos);

    if (!is_dir($dir_absoluto) && !mkdir($dir_absoluto, 0755, true)) {
        throw new RuntimeException('No fue posible crear el directorio de destino.');
    }

    $prefijo = preg_replace('/[^A-Za-z0-9_-]/', '', $prefijo);
    $nombre = $prefijo . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], rtrim($dir_absoluto, '/\\') . DIRECTORY_SEPARATOR . $nombre)) {
        throw new RuntimeException('Error al guardar la imagen en el servidor.');
    }

    return $nombre;
}

/**
 * ¿Hay un archivo adjunto en este campo? (ignora el caso "no se seleccionó archivo").
 */
function hay_archivo_subido(string $campo): bool
{
    return isset($_FILES[$campo]) && ($_FILES[$campo]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

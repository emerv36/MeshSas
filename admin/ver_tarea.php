<?php
require_once __DIR__ . '/admin_auth.php';
require_rol(['admin', 'docente']);

$file = (string)($_GET['f'] ?? '');

// Validar que el archivo no esté vacío y no intente hacer path traversal
if ($file === '' || strpos($file, '..') !== false || strpos($file, 'uploads/tareas/') !== 0) {
    http_response_code(400);
    die("Archivo inválido.");
}

// El archivo debe ser una entrega registrada de un curso que el usuario gestiona
$stmt_curso = $pdo->prepare("
    SELECT m.curso_id
    FROM tareas_entregas te
    JOIN lecciones l ON te.leccion_id = l.id
    JOIN modulos m ON l.modulo_id = m.id
    WHERE te.archivo_ruta = ?
    LIMIT 1
");
$stmt_curso->execute([$file]);
if (!puede_gestionar_curso($pdo, (int)$stmt_curso->fetchColumn())) {
    http_response_code(403);
    die("Acceso denegado.");
}

// Construir ruta absoluta al archivo
$base_dir = realpath(__DIR__ . '/../'); // c:/xampp/htdocs/Mesh Lms
$file_path = realpath($base_dir . '/' . $file);

// Verificar que el archivo realmente exista y esté dentro de la carpeta permitida
if (!$file_path || strpos($file_path, realpath($base_dir . '/uploads/tareas')) !== 0 || !file_exists($file_path)) {
    http_response_code(404);
    die("Archivo no encontrado o acceso no permitido.");
}

// Obtener mime type. Solo PDF e imágenes se muestran en el navegador; el resto se
// descarga, para que un archivo con HTML no se ejecute dentro del panel.
$mime_type = (new finfo(FILEINFO_MIME_TYPE))->file($file_path) ?: 'application/octet-stream';
$inline_permitidos = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
$disposicion = in_array($mime_type, $inline_permitidos, true) ? 'inline' : 'attachment';
if ($disposicion === 'attachment') {
    $mime_type = 'application/octet-stream';
}

// Enviar headers
header('Content-Type: ' . $mime_type);
header('Content-Disposition: ' . $disposicion . '; filename="' . basename($file_path) . '"');
header('Content-Length: ' . filesize($file_path));
header('X-Content-Type-Options: nosniff');

// Limpiar buffer
if (ob_get_level()) {
    ob_end_clean();
}

// Servir archivo
readfile($file_path);
exit;

<?php
// Descarga del paquete .h5p original de una lección (para editarlo en H5P o Lumi).
// Solo admin y el docente asignado al curso; el estudiante nunca recibe el paquete.
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../helpers/h5p_helper.php';
require_rol(['admin', 'docente']);

$leccion_id = (int)($_GET['leccion'] ?? 0);
$stmt = $pdo->prepare("SELECT l.titulo, l.contenido, m.curso_id FROM lecciones l JOIN modulos m ON l.modulo_id = m.id WHERE l.id = ? AND l.tipo = 'h5p'");
$stmt->execute([$leccion_id]);
$leccion = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$leccion || !puede_gestionar_curso($pdo, (int)$leccion['curso_id'])) {
    http_response_code($leccion ? 403 : 404);
    exit;
}
$datos = h5p_datos_leccion($leccion['contenido']);
$archivo = $datos ? H5P_DIR . '/' . $datos['carpeta'] . '.h5p' : '';
if ($archivo === '' || !is_file($archivo)) {
    http_response_code(404);
    exit;
}

$nombre = trim(preg_replace('/[^\p{L}\p{N} _.-]+/u', '', $leccion['titulo'])) ?: 'leccion-' . $leccion_id;
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $nombre . '.h5p"; filename*=UTF-8\'\'' . rawurlencode($nombre . '.h5p'));
header('Content-Length: ' . filesize($archivo));
header('X-Content-Type-Options: nosniff');
readfile($archivo);

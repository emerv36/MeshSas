<?php
require_once __DIR__ . '/admin_auth.php';
require_rol(['admin', 'tesoreria']);

// Los comprobantes viven en uploads/pagos/, que está bloqueado por .htaccess.
// Se localizan por ID de pago (no por ruta) para evitar path traversal.
$pago_id = intval($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT comprobante_ruta FROM pagos WHERE id = ?");
$stmt->execute([$pago_id]);
$ruta = (string)($stmt->fetchColumn() ?: '');

$pagos_dir = realpath(__DIR__ . '/../uploads/pagos');
$file_path = $ruta !== '' && $ruta !== 'N/A' ? realpath(__DIR__ . '/../' . $ruta) : false;

if (!$pagos_dir || !$file_path || strpos($file_path, $pagos_dir . DIRECTORY_SEPARATOR) !== 0 || !is_file($file_path)) {
    http_response_code(404);
    die("Comprobante no encontrado.");
}

$permitidos = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
$mime_type = (new finfo(FILEINFO_MIME_TYPE))->file($file_path) ?: 'application/octet-stream';
$disposicion = in_array($mime_type, $permitidos, true) ? 'inline' : 'attachment';
if ($disposicion === 'attachment') {
    $mime_type = 'application/octet-stream';
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime_type);
header('Content-Disposition: ' . $disposicion . '; filename="' . basename($file_path) . '"');
header('Content-Length: ' . filesize($file_path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

readfile($file_path);
exit;

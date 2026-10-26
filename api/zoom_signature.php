<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/csrf_helper.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar autenticación
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

if (!csrf_token_valido(csrf_token_de_peticion())) {
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF inválido']);
    exit;
}

$meeting_number = preg_replace('/[^0-9]/', '', (string)($_POST['meetingNumber'] ?? ''));
// Rol de Zoom: 1 = anfitrión. Solo el personal docente puede pedir una firma de anfitrión.
$role = (intval($_POST['role'] ?? 0) === 1 && in_array($_SESSION['user_rol'] ?? '', ['admin', 'docente'], true)) ? 1 : 0;

if (empty($meeting_number)) {
    http_response_code(400);
    echo json_encode(['error' => 'Meeting Number es requerido']);
    exit;
}

// Leer desde entorno o .env (asumiendo que config.php carga .env o getenv funciona)
// Ya que definimos variables en config o entorno:
$envFile = __DIR__ . '/../.env';
$env = [];
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $env[trim($name)] = trim($value);
    }
}
$sdk_key = $env['ZOOM_SDK_KEY'] ?? getenv('ZOOM_SDK_KEY') ?? '';
$sdk_secret = $env['ZOOM_SDK_SECRET'] ?? getenv('ZOOM_SDK_SECRET') ?? '';

if (empty($sdk_key) || empty($sdk_secret) || $sdk_key === 'tu_sdk_key_aqui') {
    http_response_code(500);
    echo json_encode(['error' => 'Credenciales de Zoom no configuradas en el servidor']);
    exit;
}

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

$header = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
$time = time() - 30; // 30 segundos atrás para margen de reloj
$exp = $time + (60 * 60 * 2); // 2 horas

$payload = json_encode([
    'sdkKey' => $sdk_key,
    'appKey' => $sdk_key,
    'mn' => (int)$meeting_number, // Casteado a integer para la validación estricta de Zoom
    'role' => $role,
    'iat' => $time,
    'exp' => $exp,
    'tokenExp' => $exp
]);

$base64UrlHeader = base64url_encode($header);
$base64UrlPayload = base64url_encode($payload);
$signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $sdk_secret, true);
$base64UrlSignature = base64url_encode($signature);

$jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;

echo json_encode([
    'signature' => $jwt,
    'sdkKey' => $sdk_key
]);

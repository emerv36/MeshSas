<?php
require_once '../config.php';
require_once __DIR__ . '/../helpers/csrf_helper.php';

// Avoid BOMs. Output strict JSON.
header('Content-Type: application/json; charset=UTF-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo no permitido']);
    exit;
}

if (!csrf_token_valido(csrf_token_de_peticion())) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido']);
    exit;
}

// Handle both standard POST and sendBeacon FormData/JSON
$leccion_id = filter_var($_POST['leccion_id'] ?? 0, FILTER_VALIDATE_INT);
$segundos = filter_var($_POST['segundos'] ?? 0, FILTER_VALIDATE_INT);

// sendBeacon might send raw JSON depending on how we format it in frontend. 
// We will send standard FormData in frontend, so $_POST will populate correctly.

if (!$leccion_id || $segundos <= 0) {
    echo json_encode(['success' => false, 'error' => 'Datos invalidos']);
    exit;
}

// Security: Prevent absurd amounts of time in a single ping (e.g. hack attempts)
// Maximum expected ping is 5 mins (300s). Allow up to 10 mins (600s) just in case.
if ($segundos > 600) {
    $segundos = 600; 
}

$usuario_id = $_SESSION['user_id'];

try {
    // Solo lecciones publicadas existentes (evita filas de progreso huérfanas)
    $stmt_lec = $pdo->prepare("SELECT 1 FROM lecciones l JOIN modulos m ON l.modulo_id = m.id WHERE l.id = ? AND l.estado = 'publicado' AND m.estado = 'publicado'");
    $stmt_lec->execute([$leccion_id]);
    if (!$stmt_lec->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'Lección no encontrada']);
        exit;
    }

    // Check if row exists because we don't know if there is a unique key on (usuario_id, leccion_id)
    $stmt_check = $pdo->prepare("SELECT id FROM progreso WHERE usuario_id = ? AND leccion_id = ?");
    $stmt_check->execute([$usuario_id, $leccion_id]);
    $row = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $stmt_upd = $pdo->prepare("UPDATE progreso SET segundos_vistos = segundos_vistos + ?, fecha = NOW() WHERE id = ?");
        $stmt_upd->execute([$segundos, $row['id']]);
    } else {
        $stmt_ins = $pdo->prepare("INSERT INTO progreso (usuario_id, leccion_id, segundos_vistos, fecha) VALUES (?, ?, ?, NOW())");
        $stmt_ins->execute([$usuario_id, $leccion_id, $segundos]);
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error de servidor']);
}

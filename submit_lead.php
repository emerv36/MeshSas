<?php
require 'config.php';
require_once __DIR__ . '/helpers/csrf_helper.php';
require_once __DIR__ . '/helpers/limite_intentos.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_token_valido(csrf_token_de_peticion())) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'La sesión expiró. Recarga la página e intenta de nuevo.']);
        exit;
    }

    $nombre = mb_substr(trim($_POST['nombre'] ?? ''), 0, 255);
    $email = trim($_POST['email'] ?? '');
    $telefono = mb_substr(trim($_POST['telefono'] ?? ''), 0, 50);

    if ($nombre === '' || $telefono === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'message' => 'Completa todos los campos con un correo válido.']);
        exit;
    }

    if (intentos_bloqueado($pdo, 'lead', $email) > 0) {
        http_response_code(429);
        echo json_encode(['status' => 'error', 'message' => 'Ya recibimos tus datos. Un asesor te contactará pronto.']);
        exit;
    }
    intentos_registrar($pdo, 'lead', $email);

    try {
        $stmt = $pdo->prepare("INSERT INTO prospectos (nombre, email, telefono) VALUES (?, ?, ?)");
        $stmt->execute([$nombre, $email, $telefono]);
        echo json_encode(['status' => 'success', 'message' => '¡Gracias por tu interés! Un asesor te contactará muy pronto.']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Hubo un error al guardar tu solicitud. Intenta nuevamente.']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
}

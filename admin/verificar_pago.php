<?php
require_once __DIR__ . '/admin_auth.php';
require_rol(['admin', 'tesoreria']);
require_once __DIR__ . '/../app/Commerce/Payments/PaymentService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}

$paymentId = (int)($_POST['pago_id'] ?? 0);
try {
    if ($paymentId <= 0) {
        throw new InvalidArgumentException('Pago inválido.');
    }
    $service = new PaymentService($pdo);
    $payment = $service->verifyPayment($paymentId, (int)$user_id);

    require_once __DIR__ . '/../helpers/mailer.php';
    $mailSent = enviarEstadoPago($payment, $payment['producto_titulo']);
    $message = "Pago #{$paymentId} verificado e inscripciones confirmadas.";
    if (!$mailSent) {
        $message .= ' La notificación por correo falló; el acceso permanece activo.';
    }
    header('Location: pagos?ok=' . rawurlencode($message), true, 303);
} catch (Throwable $e) {
    error_log("Error verificando pago {$paymentId}: " . $e->getMessage());
    header('Location: pagos?err=' . rawurlencode($e->getMessage()), true, 303);
}
exit;

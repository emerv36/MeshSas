<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/Commerce/Payments/PaymentService.php';
require_once __DIR__ . '/mail_helper.php';
require_once __DIR__ . '/helpers/limite_intentos.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index');
    exit;
}

$type = (string)($_POST['tipo'] ?? 'curso');
$id = (int)($_POST['id'] ?? 0);
$returnUrl = 'pago?tipo=' . rawurlencode($type) . '&id=' . $id;
$uploadedAbsolutePath = null;

try {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (empty($_SESSION['checkout_csrf']) || !hash_equals($_SESSION['checkout_csrf'], $token)) {
        throw new RuntimeException('La sesión del formulario expiró. Recarga la página.');
    }
    // Cada envío cuenta para el límite (crea cuentas y envía correos): por correo y por IP
    $limiteId = isset($_SESSION['user_id']) ? 'usuario:' . (int)$_SESSION['user_id'] : (string)($_POST['email'] ?? '');
    if ($espera = intentos_bloqueado($pdo, 'checkout', $limiteId)) {
        throw new InvalidArgumentException("Demasiados intentos. Espera {$espera} minutos e intenta de nuevo.");
    }
    intentos_registrar($pdo, 'checkout', $limiteId);

    $service = new PaymentService($pdo);
    $product = $service->getProduct($type, $id);
    if (!in_array((string)$product['estado'], ['activo', 'privado'], true)) {
        throw new RuntimeException('Este producto no está disponible para inscripción.');
    }
    $sessionRole = (string)($_SESSION['user_rol'] ?? '');
    $isStaff = in_array($sessionRole, ['admin', 'administrativo'], true);
    $isPrivate = (string)$product['estado'] === 'privado';
    if ($isPrivate && !$isStaff) {
        throw new RuntimeException('Este producto es exclusivo para personal autorizado.');
    }
    if ($sessionRole === 'administrativo' && !$isPrivate) {
        throw new RuntimeException('Tu perfil solo puede inscribirse en productos internos.');
    }
    $amount = $service->finalPrice($product);
    if ($isPrivate && $isStaff) {
        $amount = 0.0;
    }
    $phone = trim((string)($_POST['telefono'] ?? ''));
    $method = $amount <= 0 ? 'gratis' : (string)($_POST['metodo_pago'] ?? '');
    $reference = $amount <= 0 ? 'GRATIS' : trim((string)($_POST['referencia'] ?? ''));
    if ($amount > 0 && (!in_array($method, ['transferencia', 'pse', 'efecty', 'baloto'], true) || $reference === '')) {
        throw new InvalidArgumentException('Selecciona un método e ingresa la referencia del pago.');
    }

    $proofRelative = 'N/A';
    if ($amount > 0) {
        if (!isset($_FILES['comprobante']) || $_FILES['comprobante']['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Debes adjuntar el comprobante de pago.');
        }
        if ($_FILES['comprobante']['size'] > 5 * 1024 * 1024) {
            throw new InvalidArgumentException('El comprobante no debe superar 5 MB.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['comprobante']['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
        if (!isset($extensions[$mime])) {
            throw new InvalidArgumentException('El comprobante debe ser JPG, PNG o PDF.');
        }
        $directory = __DIR__ . '/uploads/pagos';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('No fue posible preparar el almacenamiento.');
        }
        $filename = 'pago_' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        $uploadedAbsolutePath = $directory . '/' . $filename;
        if (!move_uploaded_file($_FILES['comprobante']['tmp_name'], $uploadedAbsolutePath)) {
            throw new RuntimeException('No fue posible guardar el comprobante.');
        }
        $proofRelative = 'uploads/pagos/' . $filename;
    }

    $pdo->beginTransaction();
    $resolved = $service->resolveUser($_POST, isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
    $user = $resolved['user'];
    if ($motivo = $service->duplicateReason((int)$user['id'], $type, $id)) {
        throw new InvalidArgumentException($motivo);
    }
    $courseIds = $service->productCourseIds($type, $id);
    $status = $amount <= 0 ? 'verificado' : 'pendiente';
    $verifiedAt = $amount <= 0 ? new DateTimeImmutable('now', new DateTimeZone('UTC')) : null;
    $stmt = $pdo->prepare(
        'INSERT INTO pagos
            (usuario_id, curso_id, producto_id, producto_tipo, nombre_temp, email_temp,
             telefono_temp, monto, metodo_pago, referencia, comprobante_ruta, estado, verificado_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int)$user['id'], $type === 'curso' ? $id : null, $id, $type,
        $user['nombre'], $user['email'], $phone, $amount, $method,
        $reference, $proofRelative, $status, $verifiedAt?->format('Y-m-d H:i:s'),
    ]);
    $paymentId = (int)$pdo->lastInsertId();
    if ($amount <= 0) {
        $service->ensureEnrollments((int)$user['id'], $courseIds);
        $accessService = new AccessService($pdo);
        $accessService->grantProductAccess(
            (int)$user['id'], $type, $id, $paymentId, 'gratis', $verifiedAt
        );
    }
    $pdo->commit();

    if ($resolved['created']) {
        emailCuentaCreadaPago($user['nombre'], $user['email'], $resolved['temporary_password']);
    }
    emailComprobanteRecibido($user['nombre'], $user['email'], $product['titulo'], $amount, ucfirst($method), $reference);

    if ($amount <= 0) {
        if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$user['id']) {
            header('Location: ' . ($type === 'ruta' ? 'dashboard/index' : "dashboard/clase?curso={$id}"));
        } else {
            header('Location: login?msg=' . rawurlencode('Inscripción creada. Inicia sesión para acceder.'));
        }
        exit;
    }

    emailNuevoPagoAdmin($user['nombre'], $user['email'], $phone, $product['titulo'], $amount, ucfirst($method), $reference, $paymentId);
    $_SESSION['confirmacion'] = [
        'nombre' => $user['nombre'], 'curso_titulo' => $product['titulo'],
        'curso_id' => $id, 'tipo' => $type, 'metodo' => $method, 'referencia' => $reference,
    ];
    header('Location: confirmacion');
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($uploadedAbsolutePath && is_file($uploadedAbsolutePath)) {
        @unlink($uploadedAbsolutePath);
    }
    if ($e instanceof CuentaExistenteException) {
        header('Location: ' . $returnUrl . '&cuenta_existente=1');
        exit;
    }
    // Al usuario solo llegan los mensajes de validación propios; un error de base de datos u
    // otro interno se registra con un código de referencia y se muestra un mensaje genérico
    $esValidacion = $e instanceof InvalidArgumentException
        || ($e instanceof RuntimeException && !($e instanceof PDOException));
    if ($esValidacion) {
        $mensaje = $e->getMessage();
    } else {
        $ref = 'ERR-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 8));
        error_log("[$ref] Error al registrar pago: " . get_class($e) . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
        $mensaje = "No fue posible registrar la inscripción. Intenta de nuevo; si el problema continúa, escríbenos con el código $ref.";
    }
    header('Location: ' . $returnUrl . '&error=' . rawurlencode($mensaje));
    exit;
}

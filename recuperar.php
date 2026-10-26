<?php
require_once 'config.php';
require_once 'mail_helper.php';
require_once __DIR__ . '/helpers/csrf_helper.php';
require_once __DIR__ . '/helpers/limite_intentos.php';

$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!csrf_token_valido(csrf_token_de_peticion())) {
        $error = "La sesión del formulario expiró. Recarga la página e inténtalo de nuevo.";
    } elseif ($espera = intentos_bloqueado($pdo, 'recuperar', $email)) {
        $error = "Has solicitado demasiados enlaces. Espera {$espera} minutos e inténtalo de nuevo.";
    } else {
        intentos_registrar($pdo, 'recuperar', $email);

        $stmt = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        if ($usuario) {
            $token = bin2hex(random_bytes(32));
            $expiracion = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // En la BD se guarda solo el hash del token; el token en claro viaja únicamente en el correo
            $pdo->prepare("INSERT INTO password_resets (email, token, fecha_expiracion) VALUES (?, ?, ?)")
                ->execute([$email, hash('sha256', $token), $expiracion]);

            $enlace = rtrim(BASE_URL, '/') . "/reset_password?token=$token";

            if (!emailRecuperarPassword($usuario['nombre'], $email, $enlace)) {
                error_log("recuperar: no se pudo enviar el correo de recuperación al usuario #{$usuario['id']}");
            }
        }

        // Mismo mensaje exista o no el correo, para no revelar qué correos están registrados
        $mensaje = "Si el correo existe en nuestro sistema, recibirás un enlace de recuperación.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recuperar Contraseña - <?= htmlspecialchars(ACADEMY_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        body { background: var(--primary); display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 2rem; }
        .card { background: white; padding: 2.5rem; border-radius: 16px; width: 100%; max-width: 400px; box-shadow: 0 20px 50px rgba(0,0,0,0.3); }
        h1 { font-size: 1.5rem; margin-bottom: 1rem; color: var(--primary); text-align: center; }
        p { color: #666; font-size: 0.9rem; margin-bottom: 2rem; text-align: center; }
        .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: 0.9rem; text-align: center; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #10b981; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #ef4444; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Recuperar Contraseña</h1>
        <p>Ingresa tu correo electrónico y te enviaremos las instrucciones para restablecer tu clave.</p>
        
        <?php if($mensaje): ?> <div class="alert alert-success"><?= htmlspecialchars($mensaje) ?></div> <?php endif; ?>
        <?php if($error): ?> <div class="alert alert-error"><?= htmlspecialchars($error) ?></div> <?php endif; ?>

        <form method="POST">
            <?= csrf_input() ?>
            <div class="form-group">
                <label>Correo Electrónico</label>
                <input type="email" name="email" required placeholder="tu@email.com" style="width:100%; padding:0.8rem; border:1px solid #ddd; border-radius:8px;">
            </div>
            <button type="submit" class="btn btn-primary btn-block" style="margin-top:1.5rem;">Enviar Instrucciones</button>
        </form>
        
        <div style="text-align:center; margin-top:1.5rem;">
            <a href="login" style="color:var(--accent); font-weight:600; font-size:0.9rem;">← Volver al Login</a>
        </div>
    </div>
</body>
</html>

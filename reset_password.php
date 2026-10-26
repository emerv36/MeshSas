<?php
require_once 'config.php';
require_once __DIR__ . '/helpers/csrf_helper.php';

$token = (string)($_GET['token'] ?? '');
$error = '';
$exito = false;

if ($token === '') {
    header('Location: login');
    exit;
}

// Validar token (en la BD se guarda su hash SHA-256)
$stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token = ? AND fecha_expiracion > NOW()");
$stmt->execute([hash('sha256', $token)]);
$reset = $stmt->fetch();

if (!$reset) {
    $error = "El enlace ha expirado o es inválido.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reset) {
    $pass1 = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['confirm_password'] ?? '');

    if (!csrf_token_valido(csrf_token_de_peticion())) {
        $error = "La sesión del formulario expiró. Recarga la página e inténtalo de nuevo.";
    } elseif ($pass1 !== $pass2) {
        $error = "Las contraseñas no coinciden.";
    } elseif (mb_strlen($pass1) < 8) {
        $error = "La contraseña debe tener al menos 8 caracteres.";
    } else {
        $hash = password_hash($pass1, PASSWORD_DEFAULT);
        
        // Actualizar contraseña
        $pdo->prepare("UPDATE usuarios SET password = ? WHERE email = ?")->execute([$hash, $reset['email']]);
        
        // Eliminar token usado
        $pdo->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$reset['email']]);
        
        $exito = true;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nueva Contraseña - <?= htmlspecialchars(ACADEMY_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        body { background: var(--primary); display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 2rem; }
        .card { background: white; padding: 2.5rem; border-radius: 16px; width: 100%; max-width: 400px; box-shadow: 0 20px 50px rgba(0,0,0,0.3); }
        h1 { font-size: 1.5rem; margin-bottom: 1rem; color: var(--primary); text-align: center; }
        .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: 0.9rem; text-align: center; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #10b981; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #ef4444; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Nueva Contraseña</h1>
        
        <?php if($exito): ?>
            <div class="alert alert-success">¡Tu contraseña ha sido actualizada con éxito!</div>
            <a href="login" class="btn btn-primary btn-block">Ir al Login</a>
        <?php else: ?>
            <?php if($error): ?> <div class="alert alert-error"><?= htmlspecialchars($error) ?></div> <?php endif; ?>

            <?php if(!$error || ($error && $_SERVER['REQUEST_METHOD'] === 'POST')): ?>
            <form method="POST">
                <?= csrf_input() ?>
                <div class="form-group">
                    <label>Nueva Contraseña</label>
                    <input type="password" name="password" required placeholder="••••••••" style="width:100%; padding:0.8rem; border:1px solid #ddd; border-radius:8px;">
                </div>
                <div class="form-group" style="margin-top:1rem;">
                    <label>Confirmar Contraseña</label>
                    <input type="password" name="confirm_password" required placeholder="••••••••" style="width:100%; padding:0.8rem; border:1px solid #ddd; border-radius:8px;">
                </div>
                <button type="submit" class="btn btn-primary btn-block" style="margin-top:1.5rem;">Restablecer Clave</button>
            </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>

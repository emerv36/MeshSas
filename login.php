<?php
require_once 'config.php';

// Tras iniciar sesión, un estudiante puede volver al formulario de inscripción (solo esa ruta interna)
$volver = (string)($_GET['volver'] ?? '');
if (!preg_match('/^pago\?tipo=(curso|ruta)&id=\d+$/', $volver)) {
    $volver = '';
}

// Redirigir si ya está logueado
if (isset($_SESSION['user_id'])) {
    $rol = $_SESSION['user_rol'] ?? '';
    if ($rol === 'admin' || $rol === 'docente' || $rol === 'tesoreria') {
        header('Location: admin/index');
        exit;
    } elseif ($rol === 'estudiante' || $rol === 'administrativo') {
        header('Location: ' . ($volver ?: 'dashboard/index'));
        exit;
    } else {
        // Rol desconocido o sesión corrupta, mejor cerrar sesión
        header('Location: logout');
        exit;
    }
}

require_once __DIR__ . '/helpers/csrf_helper.php';
require_once __DIR__ . '/helpers/limite_intentos.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!csrf_token_valido(csrf_token_de_peticion())) {
        $error = "La sesión del formulario expiró. Recarga la página e inténtalo de nuevo.";
    } elseif (!$email || !$password) {
        $error = "Todos los campos son obligatorios.";
    } elseif ($espera = intentos_bloqueado($pdo, 'login', $email)) {
        $error = "Demasiados intentos fallidos. Espera {$espera} minutos e inténtalo de nuevo.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($usuario && password_verify($password, $usuario['password'])) {
            intentos_limpiar($pdo, 'login', $email);
            session_regenerate_id(true);
            unset($_SESSION['csrf_token']); // token nuevo tras iniciar sesión
            $_SESSION['user_id']   = $usuario['id'];
            $_SESSION['user_name'] = $usuario['nombre'];
            $_SESSION['user_rol']  = $usuario['rol'];

            // Lógica de Rachas (Streaks)
            $today = date('Y-m-d');
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $last_activity = $usuario['ultima_actividad_racha'];
            $current_streak = (int)$usuario['racha_actual'];
            $best_streak = (int)$usuario['mejor_racha'];

            if ($last_activity !== $today) {
                if ($last_activity === $yesterday) {
                    $current_streak++;
                } else {
                    $current_streak = 1;
                }
                if ($current_streak > $best_streak) {
                    $best_streak = $current_streak;
                }

                $stmt_update = $pdo->prepare("UPDATE usuarios SET racha_actual = ?, mejor_racha = ?, ultima_actividad_racha = ? WHERE id = ?");
                $stmt_update->execute([$current_streak, $best_streak, $today, $usuario['id']]);
            }

            if (in_array($usuario['rol'], ['admin', 'docente', 'tesoreria'])) {
                header('Location: admin/index');
            } else {
                header('Location: ' . ($volver ?: 'dashboard/index'));
            }
            exit;
        } else {
            intentos_registrar($pdo, 'login', $email);
            $error = "Email o contraseña incorrectos.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - <?= htmlspecialchars(ACADEMY_NAME) ?></title>
    <link rel="icon" href="<?= FAVICON_URL ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        :root {
            --primary: <?= $ajustes['color_primario'] ?? '#2A3547' ?>;
            --accent: <?= $ajustes['color_acento'] ?? '#FF6B35' ?>;
        }
        body { background: var(--primary); min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 2rem; }
        .login-card { background: white; border-radius: 16px; padding: 2.5rem 2rem; width: 100%; max-width: 420px; box-shadow: 0 20px 50px rgba(0,0,0,0.3); }
        .login-logo { text-align: center; margin-bottom: 2rem; }
        .login-logo i { font-size: 2.5rem; color: var(--accent); }
        .login-logo h1 { color: var(--primary); font-size: 1.5rem; margin: 0.5rem 0 0; }
        .error-msg { background: #f8d7da; color: #721c24; padding: 0.8rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.9rem; }
        .form-group label { display: block; margin-bottom: 0.4rem; font-weight: 600; color: var(--primary); font-size: 0.9rem; }
        .input-icon { position: relative; }
        .input-icon i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #aaa; }
        .input-icon input { padding-left: 38px; }
        hr { border-color: #e2e8f0; margin: 1.5rem 0; }
        .link-text { text-align: center; font-size: 0.9rem; color: #666; }
        .link-text a { color: var(--accent); font-weight: 600; }
    </style>
</head>
<body>
<div class="login-card">
    <div class="login-logo">
        <img src="img/logo.png?v=<?= LOGO_VERSION ?>" alt="<?= htmlspecialchars(ACADEMY_NAME) ?> Logo" style="height: 100px; width: auto; margin-bottom: 1rem;">
        <p style="color:#666;font-size:0.9rem;margin:0.3rem 0 0;">Accede a tu área de aprendizaje</p>
    </div>
    <?php if($error): ?><div class="error-msg"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
        <?= csrf_input() ?>
        <div class="form-group">
            <label>Correo electrónico</label>
            <div class="input-icon">
                <i class="fa-solid fa-envelope"></i>
                <input type="email" name="email" required placeholder="tu@email.com">
            </div>
        </div>
        <div class="form-group" style="margin-top:1rem;">
            <label>Contraseña</label>
            <div class="input-icon">
                <i class="fa-solid fa-lock"></i>
                <input type="password" name="password" required placeholder="••••••••">
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-block" style="margin-top:1.5rem;font-size:1rem;">
            <i class="fa-solid fa-right-to-bracket"></i> Iniciar Sesión
        </button>
    </form>
    <hr>
    <div class="link-text">
        <p>¿Olvidaste tu contraseña? <a href="recuperar">Recupérala aquí</a></p>
        <hr>
        <p>¿Aún no tienes acceso? <a href="cursos">Inscríbete a un curso</a></p>
        <p style="margin-top:0.5rem;"><a href="index">? Volver al inicio</a></p>
    </div>
</div>
</body>
</html>

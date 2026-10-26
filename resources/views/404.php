<?php
$nombreAcademia = htmlspecialchars(($ajustes['nombre_academia'] ?? '') ?: 'Mesh LMS', ENT_QUOTES, 'UTF-8');
$colorPrimario  = htmlspecialchars($ajustes['color_primario'] ?? '#0B4A83', ENT_QUOTES, 'UTF-8');
$colorAcento    = htmlspecialchars($ajustes['color_acento'] ?? '#FF6B35', ENT_QUOTES, 'UTF-8');
$urlBase        = rtrim(defined('BASE_URL') ? BASE_URL : '', '/');

$rol = $_SESSION['user_rol'] ?? '';
$linkRetorno = $urlBase . '/index';
$textoRetorno = 'Ir a la Página Principal';

if (in_array($rol, ['admin', 'docente', 'tesoreria'], true)) {
    $linkRetorno = $urlBase . '/admin/cursos';
    $textoRetorno = 'Volver al Panel Administrativo';
} elseif ($rol === 'estudiante') {
    $linkRetorno = $urlBase . '/dashboard/index';
    $textoRetorno = 'Volver a Mi Aula Virtual';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Página No Encontrada (404) - <?= $nombreAcademia ?></title>
    <link rel="icon" href="<?= htmlspecialchars(defined('FAVICON_URL') ? FAVICON_URL : $urlBase . '/img/logo.png', ENT_QUOTES, 'UTF-8') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: <?= $colorPrimario ?>;
            --accent: <?= $colorAcento ?>;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            background: linear-gradient(135deg, #0b132b 0%, #1c2541 50%, #070c18 100%);
            color: #f8fafc;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }
        .error-card {
            width: min(100%, 600px);
            padding: clamp(32px, 6vw, 48px);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 24px;
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(16px);
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.6);
            text-align: center;
            animation: fadeIn 0.4s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .error-logo { max-width: 200px; max-height: 70px; margin-bottom: 24px; object-fit: contain; }
        .number-badge {
            font-size: clamp(3.5rem, 12vw, 5.5rem);
            font-weight: 900;
            color: var(--accent);
            line-height: 1;
            margin-bottom: 12px;
            letter-spacing: -0.04em;
            text-shadow: 0 0 35px rgba(255, 107, 53, 0.4);
        }
        h1 {
            color: #ffffff;
            font-size: clamp(1.4rem, 4vw, 1.8rem);
            font-weight: 700;
            margin-bottom: 12px;
        }
        p {
            color: #94a3b8;
            font-size: 0.98rem;
            line-height: 1.65;
            max-width: 480px;
            margin: 0 auto 28px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 13px 26px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.95rem;
            text-decoration: none;
            background: var(--accent);
            color: white;
            box-shadow: 0 4px 15px rgba(255, 107, 53, 0.35);
            transition: all 0.2s ease;
        }
        .btn:hover {
            filter: brightness(1.1);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255, 107, 53, 0.45);
        }
    </style>
</head>
<body>
    <main class="error-card" role="main">
        <img class="error-logo" src="<?= htmlspecialchars($urlBase, ENT_QUOTES, 'UTF-8') ?>/img/logo.png?v=<?= defined('LOGO_VERSION') ? LOGO_VERSION : 2 ?>" alt="<?= $nombreAcademia ?>">
        <div class="number-badge">404</div>
        <h1>Página No Encontrada</h1>
        <p>El enlace que intentas abrir no existe, ha sido movido o cambió de dirección. Puedes regresar a tu contenido desde aquí:</p>
        <a href="<?= htmlspecialchars($linkRetorno, ENT_QUOTES, 'UTF-8') ?>" class="btn">
            <i class="fa-solid fa-arrow-left"></i> <?= htmlspecialchars($textoRetorno, ENT_QUOTES, 'UTF-8') ?>
        </a>
    </main>
</body>
</html>

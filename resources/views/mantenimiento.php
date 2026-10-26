<?php
$nombreAcademia = htmlspecialchars(($ajustes['nombre_academia'] ?? '') ?: 'Mesh LMS', ENT_QUOTES, 'UTF-8');
$colorPrimario = htmlspecialchars($ajustes['color_primario'] ?? '#0B4A83', ENT_QUOTES, 'UTF-8');
$colorAcento = htmlspecialchars($ajustes['color_acento'] ?? '#FF6B35', ENT_QUOTES, 'UTF-8');
$urlBase = rtrim(defined('BASE_URL') ? BASE_URL : '', '/');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Mantenimiento - <?= $nombreAcademia ?></title>
    <link rel="icon" href="<?= FAVICON_URL ?>">
    <style>
        :root {
            --primary: <?= $colorPrimario ?>;
            --accent: <?= $colorAcento ?>;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            background: linear-gradient(145deg, var(--primary), #071525 72%);
            color: #172033;
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .maintenance-card {
            width: min(100%, 620px);
            padding: clamp(32px, 7vw, 58px);
            border: 1px solid rgba(255,255,255,.35);
            border-radius: 24px;
            background: rgba(255,255,255,.97);
            box-shadow: 0 24px 70px rgba(0,0,0,.3);
            text-align: center;
        }
        .maintenance-logo { max-width: 220px; max-height: 86px; margin-bottom: 28px; object-fit: contain; }
        .maintenance-icon {
            width: 68px;
            height: 68px;
            display: grid;
            place-items: center;
            margin: 0 auto 22px;
            border-radius: 20px;
            background: #fff7ed;
            color: var(--accent);
            font-size: 32px;
        }
        h1 { margin: 0 0 14px; color: var(--primary); font-size: clamp(1.7rem, 5vw, 2.35rem); }
        p { margin: 0 auto; max-width: 470px; color: #64748b; font-size: 1.02rem; line-height: 1.7; }
        .status {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            margin-top: 28px;
            padding: 10px 16px;
            border-radius: 999px;
            background: #fff7ed;
            color: #9a3412;
            font-size: .88rem;
            font-weight: 700;
        }
        .status-dot { width: 9px; height: 9px; border-radius: 50%; background: var(--accent); }
        .staff-link { display: block; margin-top: 28px; color: #64748b; font-size: .82rem; text-decoration: none; }
        .staff-link:hover { color: var(--primary); text-decoration: underline; }
    </style>
</head>
<body>
    <main class="maintenance-card" role="main">
        <img class="maintenance-logo" src="<?= htmlspecialchars($urlBase, ENT_QUOTES, 'UTF-8') ?>/img/logo.png?v=<?= LOGO_VERSION ?>" alt="<?= $nombreAcademia ?>">
        <div class="maintenance-icon" aria-hidden="true">?</div>
        <h1>Estamos realizando mejoras</h1>
        <p>La plataforma está temporalmente en mantenimiento. Nuestro equipo trabaja para restablecer el servicio lo antes posible.</p>
        <div class="status"><span class="status-dot"></span> Servicio temporalmente pausado</div>
        <a class="staff-link" href="<?= htmlspecialchars($urlBase, ENT_QUOTES, 'UTF-8') ?>/login">Acceso para personal autorizado</a>
    </main>
</body>
</html>


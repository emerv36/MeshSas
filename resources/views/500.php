<?php
$nombreAcademia = htmlspecialchars(($ajustes['nombre_academia'] ?? '') ?: 'Mesh LMS', ENT_QUOTES, 'UTF-8');
$colorPrimario  = htmlspecialchars($ajustes['color_primario'] ?? '#0B4A83', ENT_QUOTES, 'UTF-8');
$colorAcento    = htmlspecialchars($ajustes['color_acento'] ?? '#FF6B35', ENT_QUOTES, 'UTF-8');
$urlBase        = rtrim(defined('BASE_URL') ? BASE_URL : '', '/');
$whatsapp       = defined('WHATSAPP_NUMBER') ? preg_replace('/\D/', '', WHATSAPP_NUMBER) : '';

$refId   = $error_info['ref_id'] ?? 'ERR-' . strtoupper(substr(md5((string)time()), 0, 8));
$esDebug = $error_info['es_debug'] ?? false;

// Determinar enlace de regreso según sesión
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

// Mensaje para WhatsApp
$msgWhatsApp = "Hola Soporte {$nombreAcademia}, he tenido un error en la plataforma con el código de referencia: {$refId}";
$linkWhatsApp = $whatsapp ? "https://wa.me/{$whatsapp}?text=" . urlencode($msgWhatsApp) : "#";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Inconveniente Temporal (500) - <?= $nombreAcademia ?></title>
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
            width: min(100%, 640px);
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
        .error-logo {
            max-width: 200px;
            max-height: 70px;
            margin-bottom: 24px;
            object-fit: contain;
        }
        .icon-pulse {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            border-radius: 50%;
            background: rgba(239, 68, 68, 0.12);
            border: 2px solid rgba(239, 68, 68, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #f87171;
            font-size: 2.2rem;
            box-shadow: 0 0 25px rgba(239, 68, 68, 0.2);
        }
        h1 {
            color: #ffffff;
            font-size: clamp(1.5rem, 4.5vw, 1.95rem);
            font-weight: 800;
            margin-bottom: 12px;
            letter-spacing: -0.02em;
        }
        p {
            color: #94a3b8;
            font-size: 0.98rem;
            line-height: 1.65;
            max-width: 500px;
            margin: 0 auto 24px;
        }
        .ref-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px dashed rgba(255, 255, 255, 0.2);
            padding: 8px 16px;
            border-radius: 12px;
            font-size: 0.85rem;
            color: #cbd5e1;
            margin-bottom: 30px;
            cursor: pointer;
            transition: 0.2s;
            user-select: all;
        }
        .ref-badge:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--accent);
            color: white;
        }
        .ref-badge strong {
            color: var(--accent);
            letter-spacing: 0.05em;
        }
        .btn-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
            max-width: 440px;
            margin: 0 auto;
        }
        @media (min-width: 500px) {
            .btn-group {
                flex-direction: row;
            }
        }
        .btn {
            flex: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 13px 22px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.92rem;
            text-decoration: none;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .btn-primary {
            background: var(--accent);
            color: white;
            box-shadow: 0 4px 15px rgba(255, 107, 53, 0.35);
        }
        .btn-primary:hover {
            filter: brightness(1.1);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255, 107, 53, 0.45);
        }
        .btn-secondary {
            background: rgba(255, 255, 255, 0.06);
            color: #e2e8f0;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.12);
            color: white;
            transform: translateY(-2px);
        }
        .debug-box {
            margin-top: 30px;
            text-align: left;
            background: #020617;
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 16px;
            font-family: monospace;
            font-size: 0.8rem;
            color: #fca5a5;
            overflow-x: auto;
        }
        .debug-box summary {
            cursor: pointer;
            color: #fbbf24;
            font-weight: bold;
            margin-bottom: 8px;
        }
    </style>
</head>
<body>
    <main class="error-card" role="main">
        <img class="error-logo" src="<?= htmlspecialchars($urlBase, ENT_QUOTES, 'UTF-8') ?>/img/logo.png?v=<?= defined('LOGO_VERSION') ? LOGO_VERSION : 2 ?>" alt="<?= $nombreAcademia ?>">
        
        <div class="icon-pulse" aria-hidden="true">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        
        <h1>Inconveniente Temporal</h1>
        <p>Hemos registrado este evento en nuestro sistema para solucionarlo de inmediato. Tu cuenta y avance académico están 100% seguros.</p>
        
        <div class="ref-badge" title="Haz clic para copiar" onclick="navigator.clipboard.writeText('<?= $refId ?>'); alert('Código copiado al portapapeles: <?= $refId ?>');">
            <i class="fa-regular fa-copy"></i>
            <span>Referencia: <strong><?= htmlspecialchars($refId, ENT_QUOTES, 'UTF-8') ?></strong></span>
        </div>
        
        <div class="btn-group">
            <a href="<?= htmlspecialchars($linkRetorno, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary">
                <i class="fa-solid fa-arrow-left"></i> <?= htmlspecialchars($textoRetorno, ENT_QUOTES, 'UTF-8') ?>
            </a>
            <?php if ($whatsapp): ?>
            <a href="<?= htmlspecialchars($linkWhatsApp, ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="btn btn-secondary">
                <i class="fa-brands fa-whatsapp" style="color:#22c55e;"></i> Soporte Técnico
            </a>
            <?php endif; ?>
        </div>

        <?php if ($esDebug): ?>
        <details class="debug-box">
            <summary><i class="fa-solid fa-bug"></i> Detalles Técnicos (Modo Debug)</summary>
            <p style="color:#f87171; margin:6px 0;"><strong>Error:</strong> <?= htmlspecialchars($error_info['mensaje'] ?? '') ?></p>
            <p style="color:#cbd5e1; margin:6px 0;"><strong>Archivo:</strong> <?= htmlspecialchars($error_info['archivo'] ?? '') ?> (Línea <?= $error_info['linea'] ?? 0 ?>)</p>
            <pre style="margin-top:10px; white-space:pre-wrap; color:#94a3b8; font-size:0.75rem;"><?= htmlspecialchars($error_info['trace'] ?? '') ?></pre>
        </details>
        <?php endif; ?>
    </main>
</body>
</html>

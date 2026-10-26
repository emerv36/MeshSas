<?php
require_once 'config.php';

if (!isset($_SESSION['confirmacion'])) { header('Location: index'); exit; }
$data = $_SESSION['confirmacion'];
unset($_SESSION['confirmacion']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprobante Enviado - <?= htmlspecialchars(ACADEMY_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        body { background: #F5F5F5; display: flex; flex-direction: column; min-height: 100vh; }
        .confirm-wrap { flex: 1; display: flex; align-items: center; justify-content: center; padding: 3rem 1rem; }
        .confirm-card { background: white; border-radius: 16px; padding: 3rem 2.5rem; text-align: center; max-width: 550px; width: 100%; box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .check-icon { font-size: 5rem; color: #4CAF50; margin-bottom: 1.5rem; animation: pop 0.5s ease; }
        @keyframes pop { 0%{transform:scale(0)} 80%{transform:scale(1.1)} 100%{transform:scale(1)} }
        .confirm-card h1 { color: var(--primary); font-size: 1.8rem; margin-bottom: 0.5rem; }
        .info-box { background: #f8fafc; border-radius: 10px; padding: 1.2rem 1.5rem; margin: 1.5rem 0; text-align: left; }
        .info-box p { margin: 0.4rem 0; font-size: 0.95rem; color: #444; }
        .info-box strong { color: var(--primary); }
        .steps { text-align: left; margin: 1.5rem 0; }
        .step { display: flex; gap: 1rem; margin-bottom: 1rem; align-items: flex-start; }
        .step-num { background: var(--accent); color: white; width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; flex-shrink: 0; }
        .step p { margin: 0; font-size: 0.9rem; color: #555; }
        .btn-group { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; margin-top: 1.5rem; }
    </style>
</head>
<body>
<nav class="navbar">
    <div class="container nav-container">
        <a href="index" class="logo"><i class="fa-solid fa-rocket"></i> <?= htmlspecialchars(ACADEMY_NAME) ?></a>
    </div>
</nav>
<div class="confirm-wrap">
    <div class="confirm-card">
        <div class="check-icon"><i class="fa-solid fa-circle-check"></i></div>
        <h1>¡Comprobante enviado con éxito!</h1>
        <p style="color:#666;">Hola <strong><?= htmlspecialchars($data['nombre']) ?></strong>, hemos recibido tu comprobante de pago.</p>

        <div class="info-box">
            <p><strong>📚 Curso:</strong> <?= htmlspecialchars($data['curso_titulo']) ?></p>
            <p><strong>💳 Método:</strong> <?= ucfirst(htmlspecialchars($data['metodo'])) ?></p>
            <p><strong>🔖 Referencia:</strong> <?= htmlspecialchars($data['referencia']) ?></p>
        </div>

        <div class="steps">
            <div class="step"><div class="step-num">1</div><p>Recibirás un correo de confirmación en los próximos minutos.</p></div>
            <div class="step"><div class="step-num">2</div><p>Verificaremos tu pago en las próximas <strong>24 horas hábiles</strong>.</p></div>
            <div class="step"><div class="step-num">3</div><p>Recibirás un correo con tus credenciales de acceso al curso.</p></div>
        </div>

        <p style="font-size:0.85rem;color:#888;"><i class="fa-solid fa-envelope"></i> ¿No ves el correo? Revisa la carpeta de <strong>spam</strong> o contáctanos.</p>

        <div class="btn-group">
            <a href="cursos" class="btn btn-outline-dark">Ver otros cursos</a>
            <a href="index" class="btn btn-primary">Volver al inicio</a>
        </div>
    </div>
</div>
</body>
</html>

<?php
require_once 'config.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Política de Privacidad | <?= htmlspecialchars(ACADEMY_NAME) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; color: #334155; line-height: 1.6; padding: 2rem; margin: 0; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 3rem; border-radius: 16px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        h1 { color: #0f172a; border-bottom: 2px solid #e2e8f0; padding-bottom: 1rem; }
        h2 { color: #1e293b; margin-top: 2rem; }
        .back-link { display: inline-block; margin-bottom: 2rem; color: #3b82f6; text-decoration: none; font-weight: 600; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-link">← Volver al inicio</a>
        
        <?php if (!empty($ajustes['politica_privacidad'])): ?>
            <?= $ajustes['politica_privacidad'] ?>
        <?php else: ?>
            <h1>Política de Privacidad</h1>
            <p><em>Última actualización: <?= date('d/m/Y') ?></em></p>

            <h2>1. Información del Responsable</h2>
            <p>El responsable del tratamiento de sus datos personales es <strong>[RAZÓN SOCIAL DE LA EMPRESA]</strong>, con NIF/CIF <strong>[NÚMERO]</strong> y domicilio en <strong>[DIRECCIÓN COMPLETA]</strong>.</p>

            <h2>2. Datos que recopilamos</h2>
            <p>Recopilamos los siguientes datos: Nombre completo, correo electrónico, documento de identidad, y metadatos de acceso a la plataforma con fines estrictamente educativos y de gestión académica.</p>

            <h2>3. Finalidad del Tratamiento</h2>
            <p>Sus datos se utilizan exclusivamente para:
                <ul>
                    <li>Procesar su inscripción en la academia.</li>
                    <li>Otorgarle acceso a la plataforma LMS y gestionar sus progresos.</li>
                    <li>Generar los certificados de finalización a su nombre.</li>
                </ul>
            </p>

            <h2>4. Derechos del Usuario (ARCO)</h2>
            <p>Usted tiene derecho a acceder, rectificar, cancelar u oponerse al tratamiento de sus datos. Para ejercer estos derechos, puede enviar un correo electrónico a <strong>[EMAIL DE CONTACTO LEGAL]</strong>.</p>

            <hr style="margin: 3rem 0; border: none; border-top: 1px solid #e2e8f0;">
            <p style="font-size: 0.9rem; color: #64748b; text-align: center;">
                <strong>Aviso para el Administrador:</strong> Puede editar este contenido directamente desde el panel de administración, en <em>Estructuración > Textos Legales</em>.
            </p>
        <?php endif; ?>
    </div>
</body>
</html>

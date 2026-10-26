<?php
require_once 'config.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Política de Cookies | <?= htmlspecialchars(ACADEMY_NAME) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; color: #334155; line-height: 1.6; padding: 2rem; margin: 0; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 3rem; border-radius: 16px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        h1 { color: #0f172a; border-bottom: 2px solid #e2e8f0; padding-bottom: 1rem; }
        h2 { color: #1e293b; margin-top: 2rem; }
        .back-link { display: inline-block; margin-bottom: 2rem; color: #3b82f6; text-decoration: none; font-weight: 600; }
        .back-link:hover { text-decoration: underline; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { padding: 1rem; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f1f5f9; color: #475569; }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-link">← Volver al inicio</a>
        
        <?php if (!empty($ajustes['politica_cookies'])): ?>
            <?= $ajustes['politica_cookies'] ?>
        <?php else: ?>
            <h1>Política de Cookies</h1>
            <p><em>Última actualización: <?= date('d/m/Y') ?></em></p>

            <h2>1. ¿Qué son las cookies?</h2>
            <p>Las cookies son pequeños archivos de texto que los sitios web almacenan en su dispositivo mientras navega. Tienen diversas finalidades, desde recordar sus preferencias hasta recopilar estadísticas.</p>

            <h2>2. Cookies que utilizamos en esta plataforma</h2>
            <p>Esta plataforma académica se enorgullece de respetar su privacidad. <strong>No utilizamos cookies de seguimiento publicitario ni marketing a terceros.</strong> Las únicas cookies que utilizamos son estrictamente técnicas y necesarias para el funcionamiento del sistema:</p>

            <table>
                <thead>
                    <tr>
                        <th>Nombre de la Cookie</th>
                        <th>Tipo</th>
                        <th>Finalidad</th>
                        <th>Duración</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>PHPSESSID</code></td>
                        <td>Técnica (Estrictamente necesaria)</td>
                        <td>Mantiene su sesión abierta de forma segura para que no tenga que iniciar sesión en cada página. Sin esta cookie, no podría acceder a sus cursos ni enviar tareas.</td>
                        <td>Sesión (se borra al cerrar el navegador)</td>
                    </tr>
                </tbody>
            </table>

            <h2>3. Consentimiento</h2>
            <p>Dado que las cookies utilizadas son de tipo técnico y estrictamente necesarias para la prestación de un servicio expresamente solicitado por el usuario (acceder a la academia virtual), <strong>no se requiere su consentimiento previo</strong> según las directrices de la Agencia Española de Protección de Datos (AEPD).</p>

            <h2>4. Contenido Incrustado (YouTube)</h2>
            <p>Es posible que en algunas lecciones encuentre videos incrustados. Para proteger su privacidad, hemos configurado estos videos utilizando el dominio <code>youtube-nocookie.com</code>, lo que significa que YouTube no instalará cookies de rastreo en su dispositivo a menos que interactúe activamente con el reproductor.</p>

            <hr style="margin: 3rem 0; border: none; border-top: 1px solid #e2e8f0;">
            <p style="font-size: 0.9rem; color: #64748b; text-align: center;">
                <strong>Aviso para el Administrador:</strong> Puede editar este contenido directamente desde el panel de administración, en <em>Estructuración > Textos Legales</em>.
            </p>
        <?php endif; ?>
    </div>
</body>
</html>

<?php
require_once __DIR__ . '/correo.php';

/**
 * Plantilla para correo de bienvenida y credenciales
 */
function enviarCredenciales($usuario, $password_plana) {
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $academia = $h(ACADEMY_NAME);
    $asunto = "Bienvenido a " . ACADEMY_NAME . " - Tus Credenciales";
    $cuerpo = "
    <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
        <h2 style='color: #6366f1;'>¡Hola, {$h($usuario['nombre'] ?? '')}!</h2>
        <p>Te damos la bienvenida a <strong>{$academia}</strong>. Tu cuenta ha sido creada exitosamente.</p>
        <div style='background: #f3f4f6; padding: 1.5rem; border-radius: 8px; margin: 1rem 0;'>
            <p style='margin: 0;'><strong>Tus datos de acceso:</strong></p>
            <p style='margin: 0.5rem 0 0;'>Email: <code>{$h($usuario['email'])}</code></p>
            <p style='margin: 0.2rem 0 0;'>Contraseña: <code>{$h($password_plana)}</code></p>
        </div>
        <p>Puedes iniciar sesión en el siguiente enlace:</p>
        <p><a href='" . BASE_URL . "/login' style='background: #6366f1; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Iniciar Sesión Ahora</a></p>
        <hr style='border: none; border-top: 1px solid #eee; margin: 2rem 0;'>
        <p style='font-size: 0.8rem; color: #999;'>Si no solicitaste esta cuenta, por favor ignora este correo.</p>
    </div>";
    
    return correo_enviar($usuario['email'], $usuario['nombre'] ?? '', $asunto, $cuerpo);
}

/**
 * Plantilla para cambio de estado de pago
 */
function enviarEstadoPago($pago, $curso_titulo) {
    $estado_txt = ($pago['estado'] === 'verificado') ? 'Aprobado ✅' : 'Rechazado ❌';
    $es_ruta = ($pago['producto_tipo'] ?? 'curso') === 'ruta';
    $producto_txt = $es_ruta ? 'la ruta' : 'el curso';
    $asunto = "Actualización de tu pago - " . ($es_ruta ? 'Ruta' : 'Curso') . ": $curso_titulo";

    // Datos escritos por el comprador: se escapan antes de meterlos en el HTML
    $nombre_html = htmlspecialchars((string)($pago['nombre_temp'] ?? ''), ENT_QUOTES, 'UTF-8');
    $titulo_html = htmlspecialchars((string)$curso_titulo, ENT_QUOTES, 'UTF-8');
    // Los pagos de ruta no llevan curso_id: se enlaza a la ruta comprada
    $enlace = BASE_URL . ($es_ruta
        ? '/dashboard/ruta?id=' . (int)$pago['producto_id']
        : '/dashboard/clase?curso=' . (int)$pago['producto_id']);

    $cuerpo = "
    <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
        <h2 style='color: #6366f1;'>Hola, {$nombre_html}</h2>
        <p>Te informamos que el estado de tu pago para {$producto_txt} <strong>$titulo_html</strong> ha sido actualizado.</p>
        <p>Estado actual: <strong style='color: " . ($pago['estado'] === 'verificado' ? '#10b981' : '#ef4444') . ";'>$estado_txt</strong></p>";

    if ($pago['estado'] === 'verificado') {
        $cuerpo .= "<p>¡Ya puedes acceder a todo el contenido!</p>
        <p><a href='" . htmlspecialchars($enlace, ENT_QUOTES, 'UTF-8') . "' style='background: #10b981; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>" . ($es_ruta ? 'Ir a mi ruta' : 'Ir a mi curso') . "</a></p>";
    } else {
        $cuerpo .= "<p>Lamentablemente tu pago no pudo ser verificado. Por favor contáctanos o intenta realizar la inscripción nuevamente con un comprobante válido.</p>";
    }

    $cuerpo .= "
        <hr style='border: none; border-top: 1px solid #eee; margin: 2rem 0;'>
        <p style='font-size: 0.8rem; color: #999;'>Este es un correo automático, por favor no respondas.</p>
    </div>";

    return correo_enviar($pago['email_temp'], $pago['nombre_temp'] ?? '', $asunto, $cuerpo);
}

<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once 'config.php';
require_once __DIR__ . '/helpers/correo.php';

// Colores dinámicos para emails
$primary_color = $ajustes['color_primario'] ?? '#2A3547';
$accent_color  = $ajustes['color_acento'] ?? '#FF6B35';

// Escapa datos (muchos escritos por el comprador) antes de meterlos en el HTML del correo
function correo_h($valor): string {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function emailComprobanteRecibido($nombre, $email, $curso_titulo, $monto, $metodo, $referencia) {
    global $primary_color, $accent_color;
    [$nombre_h, $curso_h, $metodo_h, $referencia_h, $admin_h] = array_map('correo_h', [$nombre, $curso_titulo, $metodo, $referencia, MAIL_ADMIN]);
    $asunto = "Comprobante recibido - " . ACADEMY_NAME;
    $cuerpo = "
    <div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;'>
        <div style='background:$primary_color;padding:30px;text-align:center;'>
            <h1 style='color:$accent_color;margin:0;'>" . correo_h(ACADEMY_NAME) . "</h1>
        </div>
        <div style='padding:30px;background:#fff;border:1px solid #eee;'>
            <h2 style='color:$primary_color;'>¡Comprobante recibido, $nombre_h!</h2>
            <p>Hemos recibido tu comprobante de pago para el curso:</p>
            <div style='background:#F5F5F5;padding:20px;border-radius:8px;margin:20px 0;'>
                <p><strong>📚 Curso:</strong> $curso_h</p>
                <p><strong>💰 Monto:</strong> $ " . number_format($monto, 0, ',', '.') . " COP</p>
                <p><strong>💳 Método:</strong> $metodo_h</p>
                <p><strong>🔖 Referencia:</strong> $referencia_h</p>
            </div>
            <p>En las próximas <strong>24 horas</strong>, verificaremos tu pago y te enviaremos un correo con tus credenciales de acceso al curso.</p>
            <p style='color:#888;font-size:0.9rem;'>Si tienes dudas, contáctanos por WhatsApp o escribe a $admin_h</p>
        </div>
        <div style='background:$primary_color;padding:15px;text-align:center;'>
            <p style='color:#aaa;font-size:0.8rem;margin:0;'>© " . date('Y') . " " . htmlspecialchars(ACADEMY_NAME) . ". Todos los derechos reservados.</p>
        </div>
    </div>";
    return correo_enviar($email, $nombre, $asunto, $cuerpo);
}

function emailNuevoPagoAdmin($nombre, $email, $telefono, $curso_titulo, $monto, $metodo, $referencia, $pago_id) {
    global $primary_color, $accent_color;
    [$nombre_h, $email_h, $telefono_h, $curso_h, $metodo_h, $referencia_h] = array_map('correo_h', [$nombre, $email, $telefono, $curso_titulo, $metodo, $referencia]);
    $asunto = "Nuevo comprobante de pago - $curso_titulo";
    $enlace_verificar = correo_h(BASE_URL . "/admin/pagos");
    $cuerpo = "
    <div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;'>
        <div style='background:$primary_color;padding:30px;text-align:center;'>
            <h1 style='color:$accent_color;margin:0;'>Nuevo Pago Pendiente</h1>
        </div>
        <div style='padding:30px;background:#fff;border:1px solid #eee;'>
            <h2 style='color:$primary_color;'>Datos del comprobante</h2>
            <div style='background:#F5F5F5;padding:20px;border-radius:8px;margin:20px 0;'>
                <p><strong>👤 Usuario:</strong> $nombre_h</p>
                <p><strong>📧 Email:</strong> $email_h</p>
                <p><strong>📱 Teléfono:</strong> $telefono_h</p>
                <p><strong>📚 Curso:</strong> $curso_h</p>
                <p><strong>💰 Monto:</strong> $ " . number_format($monto, 0, ',', '.') . " COP</p>
                <p><strong>💳 Método:</strong> $metodo_h</p>
                <p><strong>🔖 Referencia:</strong> $referencia_h</p>
            </div>
            <a href='$enlace_verificar' style='display:inline-block;background:$accent_color;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;'>
                ✅ Verificar este pago
            </a>
        </div>
    </div>";
    return correo_enviar(MAIL_ADMIN, 'Administrador', $asunto, $cuerpo);
}

function emailCuentaCreadaPago($nombre, $email, $passwordTemporal) {
    $asunto = 'Tu cuenta de acceso a ' . ACADEMY_NAME;
    $cuerpo = "<h2>Hola, " . htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') . "</h2>"
        . "<p>Creamos una cuenta para asociar tu inscripción.</p>"
        . "<p>Usuario: <strong>" . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . "</strong><br>"
        . "Contraseña temporal: <strong>" . htmlspecialchars($passwordTemporal, ENT_QUOTES, 'UTF-8') . "</strong></p>"
        . "<p>Inicia sesión y cambia tu contraseña. Una cuenta existente nunca se autentica automáticamente.</p>";
    return correo_enviar($email, $nombre, $asunto, $cuerpo);
}

function emailRecuperarPassword($nombre, $email, $enlace) {
    global $primary_color, $accent_color;
    $nombre_h = correo_h($nombre);
    $enlace_h = correo_h($enlace);
    $asunto = "Recuperar Contraseña - " . ACADEMY_NAME;
    $cuerpo = "
    <div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;'>
        <div style='background:$primary_color;padding:30px;text-align:center;'>
            <h1 style='color:$accent_color;margin:0;'>" . correo_h(ACADEMY_NAME) . "</h1>
        </div>
        <div style='padding:30px;background:#fff;border:1px solid #eee;'>
            <h2 style='color:$primary_color;'>Hola, $nombre_h</h2>
            <p>Has solicitado restablecer tu contraseña. Para continuar, haz clic en el siguiente botón:</p>
            <div style='text-align:center;margin:30px 0;'>
                <a href='$enlace_h' style='display:inline-block;background:$accent_color;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;'>
                    RESTABLECER CONTRASEÑA
                </a>
            </div>
            <p>Este enlace expirará en 1 hora.</p>
            <p style='color:#888;font-size:0.8rem;'>Si no solicitaste este cambio, puedes ignorar este correo.</p>
        </div>
        <div style='background:$primary_color;padding:15px;text-align:center;'>
            <p style='color:#aaa;font-size:0.8rem;margin:0;'>© " . date('Y') . " " . htmlspecialchars(ACADEMY_NAME) . ". Todos los derechos reservados.</p>
        </div>
    </div>";
    return correo_enviar($email, $nombre, $asunto, $cuerpo);
}

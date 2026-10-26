<?php
/**
 * Envío de correo SMTP (PHPMailer). Única implementación: las plantillas de
 * mail_helper.php y helpers/mailer.php la usan.
 * Requiere las constantes MAIL_* definidas en config.php.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function correo_enviar(?string $email, ?string $nombre, string $asunto, string $cuerpo_html): bool
{
    $email = trim((string)$email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        error_log('correo_enviar: dirección vacía o inválida, no se envía «' . $asunto . '»');
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = MAIL_USERNAME !== '';
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_USERNAME !== '' ? PHPMailer::ENCRYPTION_STARTTLS : '';
        $mail->Port       = (int)MAIL_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($email, (string)$nombre);
        $mail->addReplyTo(MAIL_FROM, MAIL_FROM_NAME);

        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body    = $cuerpo_html;
        $mail->AltBody = trim(html_entity_decode(strip_tags($cuerpo_html), ENT_QUOTES, 'UTF-8'));

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Error enviando correo «{$asunto}»: {$mail->ErrorInfo}");
        return false;
    }
}

<?php
/**
 * QR y enlace de verificación de los certificados PDF (curso y ruta).
 *
 * El QR lleva a /verificar/{codigo} en BASE_URL. Si la librería chillerlan/php-qrcode o
 * GD no están disponibles, o FPDF no puede leer la imagen, el certificado se genera igual
 * sin QR (el error queda en el log).
 */

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/** URL pública de verificación del código. */
function certificado_url_verificacion(string $codigo): string
{
    return BASE_URL . '/verificar/' . rawurlencode($codigo);
}

/** La misma URL sin el protocolo, para imprimirla en el certificado. */
function certificado_url_verificacion_texto(string $codigo): string
{
    return preg_replace('#^https?://#i', '', rawurldecode(certificado_url_verificacion($codigo)));
}

/**
 * Dibuja el QR de verificación en el PDF sobre un recuadro blanco (legible con cualquier
 * fondo o plantilla). $x, $y y $tam en mm; el recuadro incluye el margen del QR.
 */
function certificado_dibujar_qr(FPDF $pdf, string $codigo, float $x, float $y, float $tam): bool
{
    if (!class_exists(QRCode::class)) {
        return false;
    }
    try {
        $options = new QROptions([
            'outputType'       => QRCode::OUTPUT_IMAGE_PNG,
            'eccLevel'         => QRCode::ECC_M,
            'scale'            => 6,
            'imageBase64'      => false,
            'imageTransparent' => false,
        ]);
        $png = (new QRCode($options))->render(certificado_url_verificacion($codigo));

        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($x, $y, $tam, $tam, 'F');
        $pdf->Image('data://text/plain;base64,' . base64_encode($png), $x, $y, $tam, $tam, 'PNG');
        return true;
    } catch (\Throwable $e) {
        error_log('Certificado sin QR (' . $codigo . '): ' . $e->getMessage());
        return false;
    }
}

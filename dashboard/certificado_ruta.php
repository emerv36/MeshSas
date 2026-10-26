<?php
ob_start();
require_once '../config.php';
require_once __DIR__ . '/../helpers/certificado_helper.php';
require_once __DIR__ . '/../helpers/certificado_qr.php';

$is_preview = isset($_GET['preview']) && isset($_SESSION['user_rol']) && $_SESSION['user_rol'] === 'admin';

if (!$is_preview) {
    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_rol'], ['estudiante', 'administrativo', 'admin'])) {
        header('Location: ../login'); exit;
    }

    $usuario_id = $_SESSION['user_id'];
    $ruta_id    = intval($_GET['id'] ?? 0);

    // 1. El certificado ya emitido, o se emite ahora si la ruta está completa y tiene pago
    //    verificado (aunque el acceso haya vencido). Emitido, no cambia.
    $certificado = certificado_emitir($pdo, 'ruta', (int)$usuario_id, (int)$ruta_id);
    if (!$certificado) {
        $motivo = calcularProgresoRuta($usuario_id, $ruta_id, $pdo)['progreso'] >= 100 ? 'sin_pago' : 'incompleto';
        header("Location: index?msg=$motivo"); exit;
    }

    // Título, duración, nivel y firmante: los del día de emisión
    $ruta = [
        'titulo'             => $certificado['titulo'],
        'duracion_estimada'  => (string)$certificado['duracion'],
        'nivel'              => (string)$certificado['nivel'],
        'firmante_nombre'    => $certificado['firmante_nombre'],
        'firmante_cargo'     => $certificado['firmante_cargo'],
        'firmante_firma_img' => $certificado['firmante_firma_img'],
    ];

    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id=?");
    $stmt->execute([$usuario_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    $fecha_fin = certificado_fecha($certificado);
    $codigo    = $certificado['codigo'];
} else {
    $usuario = ['nombre' => 'Nombre del Estudiante (Prueba)'];
    $ruta   = [
        'titulo' => 'Ruta de Aprendizaje de Prueba',
        'duracion_estimada' => '120 horas',
        'nivel' => 'Avanzado'
    ];
    $fecha_fin = date('d/m/Y');
    $codigo    = 'PREVIEWRUTA';
}

// Helper functions
function hex2rgb($hex) {
    $hex = str_replace("#", "", $hex);
    if(strlen($hex) == 3) {
        $r = hexdec(substr($hex,0,1).substr($hex,0,1));
        $g = hexdec(substr($hex,1,1).substr($hex,1,1));
        $b = hexdec(substr($hex,2,1).substr($hex,2,1));
    } else {
        $r = hexdec(substr($hex,0,2));
        $g = hexdec(substr($hex,2,2));
        $b = hexdec(substr($hex,4,2));
    }
    return [$r, $g, $b];
}

function blendColor($fg, $bg, $alpha = 0.8) {
    return [
        intval($fg[0] * $alpha + $bg[0] * (1 - $alpha)),
        intval($fg[1] * $alpha + $bg[1] * (1 - $alpha)),
        intval($fg[2] * $alpha + $bg[2] * (1 - $alpha))
    ];
}

$c_bg = hex2rgb($ajustes['cert_color_fondo'] ?? '#2A3547');
$c_tx = hex2rgb($ajustes['cert_color_texto'] ?? '#FFFFFF');
$c_ac = hex2rgb($ajustes['cert_color_acento'] ?? '#FF6B35');

$c_sec1 = blendColor($c_tx, $c_bg, 0.85);
$c_sec2 = blendColor($c_tx, $c_bg, 0.75);
$c_sec3 = blendColor($c_tx, $c_bg, 0.50);

require_once '../vendor/autoload.php';

if (!class_exists('FPDF') && class_exists('\setasign\Fpdi\Fpdi')) {
    class_alias('\setasign\Fpdi\Fpdi', 'FPDF');
}

class PDF extends FPDF {
    function Header() {}
    function Footer() {}
}

$pdf = new PDF('L', 'mm', 'A4');
$fuente = $ajustes['cert_fuente'] ?? 'Helvetica';
if (!in_array($fuente, ['Helvetica', 'Times', 'Courier', 'Arial', 'Symbol', 'ZapfDingbats'])) {
    $pdf->AddFont($fuente, '', $fuente.'.php');
    $pdf->AddFont($fuente, 'B', $fuente.'.php'); // Reuse for bold
}
$pdf->AddPage();

if (!empty($ajustes['cert_plantilla_fondo']) && file_exists('../' . $ajustes['cert_plantilla_fondo'])) {
    $pdf->Image('../' . $ajustes['cert_plantilla_fondo'], 0, 0, 297, 210);
} else {
    $pdf->SetFillColor($c_bg[0], $c_bg[1], $c_bg[2]);
    $pdf->Rect(0, 0, 297, 210, 'F');
    $pdf->SetFillColor($c_ac[0], $c_ac[1], $c_ac[2]);
    $pdf->Rect(0, 0, 297, 12, 'F');
    $pdf->Rect(0, 198, 297, 12, 'F');
    $pdf->SetDrawColor($c_tx[0], $c_tx[1], $c_tx[2]);
    $pdf->SetLineWidth(1);
    $pdf->Rect(15, 18, 267, 174);
    $pdf->SetDrawColor($c_ac[0], $c_ac[1], $c_ac[2]);
    $pdf->SetLineWidth(0.5);
    $pdf->Rect(18, 21, 261, 168);
}

$pdf->SetTextColor($c_ac[0], $c_ac[1], $c_ac[2]);
$pdf->SetFont($fuente, 'B', 22);
$pdf->SetY(30);
$pdf->Cell(297, 10, ACADEMY_NAME, 0, 1, 'C');

$pdf->SetTextColor($c_sec1[0], $c_sec1[1], $c_sec1[2]);
$pdf->SetFont($fuente, '', 11);
$pdf->Cell(297, 8, utf8_decode('Formacion Profesional Integral'), 0, 1, 'C');

$pdf->SetDrawColor($c_ac[0], $c_ac[1], $c_ac[2]);
$pdf->SetLineWidth(0.8);
$pdf->Line(60, 52, 237, 52);

$pdf->SetTextColor($c_sec1[0], $c_sec1[1], $c_sec1[2]);
$pdf->SetFont($fuente, '', 13);
$pdf->SetY(58);
$pdf->Cell(297, 10, 'Certifica que:', 0, 1, 'C');

$pdf->SetTextColor($c_tx[0], $c_tx[1], $c_tx[2]);
$pdf->SetFont($fuente, 'B', 32);
$pdf->SetY(70);
$pdf->Cell(297, 14, utf8_decode($usuario['nombre']), 0, 1, 'C');

$pdf->SetTextColor($c_sec1[0], $c_sec1[1], $c_sec1[2]);
$pdf->SetFont($fuente, '', 13);
$pdf->SetY(88);
$pdf->Cell(297, 8, utf8_decode('ha completado satisfactoriamente la Ruta de Aprendizaje:'), 0, 1, 'C');

$pdf->SetTextColor($c_ac[0], $c_ac[1], $c_ac[2]);
$pdf->SetFont($fuente, 'B', 26);
$pdf->SetY(100);
$titulo_ruta = utf8_decode($ruta['titulo']);
$w = $pdf->GetStringWidth($titulo_ruta);
$pdf->SetX((297 - $w) / 2);
$pdf->Cell($w, 12, $titulo_ruta, 0, 1, 'C');
$pdf->Line((297 - $w)/2, 113, (297 + $w)/2, 113);

$pdf->SetTextColor($c_sec2[0], $c_sec2[1], $c_sec2[2]);
$pdf->SetFont($fuente, '', 11);
$pdf->SetY(122);
$duracion = $ruta['duracion_estimada'] ?? 'Varias horas';
$nivel    = $ruta['nivel'] ?? 'Multinivel';
$pdf->Cell(297, 6, utf8_decode("Nivel: $nivel  |  Duracion estimada: $duracion"), 0, 1, 'C');

// Determinar qué firma usar
$firma_img = !empty($ruta['firmante_firma_img']) ? $ruta['firmante_firma_img'] : ($ajustes['cert_firma_img'] ?? '');
$firma_texto = (!empty($ruta['firmante_nombre']) && !empty($ruta['firmante_cargo'])) 
                ? $ruta['firmante_nombre'] . ' - ' . $ruta['firmante_cargo']
                : ($ajustes['cert_firma'] ?? 'Director Académico - ' . ACADEMY_NAME);

// Renderizar la firma si existe
if (!empty($firma_img) && file_exists('../' . $firma_img)) {
    $pdf->Image('../' . $firma_img, 123.5, 144, 50, 20);
}

$pdf->SetY(148);
$pdf->SetDrawColor($c_tx[0], $c_tx[1], $c_tx[2]);
$pdf->SetLineWidth(0.3);
$pdf->Line(99, 165, 198, 165);

$pdf->SetTextColor($c_sec1[0], $c_sec1[1], $c_sec1[2]);
$pdf->SetFont($fuente, '', 10);
$pdf->SetY(167);
$pdf->Cell(297, 6, utf8_decode($firma_texto), 0, 1, 'C');

$pdf->SetTextColor($c_sec3[0], $c_sec3[1], $c_sec3[2]);
$pdf->SetFont($fuente, '', 9);
$pdf->SetY(175);
$pdf->Cell(297, 5, utf8_decode("Fecha de finalizacion: $fecha_fin"), 0, 1, 'C');
$pdf->Cell(297, 5, utf8_decode('Verifica este certificado en: ' . certificado_url_verificacion_texto($codigo)), 0, 1, 'C');

// QR de verificación a la derecha de la firma
certificado_dibujar_qr($pdf, $codigo, 236, 142, 30);

// Emitir PDF limpiando el búfer
while (ob_get_level()) {
    ob_end_clean();
}
$pdf->Output('I', 'Certificado_Ruta_'.$codigo.'.pdf');

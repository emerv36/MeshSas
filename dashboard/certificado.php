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
    $curso_id   = intval($_GET['curso'] ?? 0);

    // 1. El certificado ya emitido, o se emite ahora si el curso está completo y cumple los
    //    requisitos (inscripción y, si tiene precio, pago verificado). Emitido, no cambia.
    $certificado = certificado_emitir($pdo, 'curso', (int)$usuario_id, (int)$curso_id);
    if (!$certificado) {
        $motivo = cursoCompletado($usuario_id, $curso_id, $pdo) ? 'sin_pago' : 'incompleto';
        header("Location: index?msg=$motivo"); exit;
    }

    // Título, duración, nivel y firmante: los del día de emisión
    $curso = [
        'titulo'             => $certificado['titulo'],
        'duracion'           => (string)$certificado['duracion'],
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
    $curso   = [
        'titulo' => 'Curso de Especialización de Prueba',
        'duracion' => '40 horas',
        'nivel' => 'Intermedio'
    ];
    $fecha_fin = date('d/m/Y');
    $codigo    = 'PREVIEW12345';
}

// Helper functions to convert HEX to RGB and blend colors
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

$c_sec1 = blendColor($c_tx, $c_bg, 0.85); // Para subtítulos
$c_sec2 = blendColor($c_tx, $c_bg, 0.75); // Para duración/fechas
$c_sec3 = blendColor($c_tx, $c_bg, 0.50); // Para verificación inferior

// 5. Generar PDF con FPDF
require_once '../vendor/autoload.php';

// Usar el namespace global o la clase cargada por composer
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
    $pdf->SetFillColor($c_bg[0], $c_bg[1], $c_bg[2]);    // Fondo
    $pdf->Rect(0, 0, 297, 210, 'F');
    $pdf->SetFillColor($c_ac[0], $c_ac[1], $c_ac[2]);    // Color acento (franja superior/inferior)
    $pdf->Rect(0, 0, 297, 12, 'F');
    $pdf->Rect(0, 198, 297, 12, 'F');
    $pdf->SetDrawColor($c_tx[0], $c_tx[1], $c_tx[2]);   // Borde exterior blanco/texto
    $pdf->SetLineWidth(1);
    $pdf->Rect(15, 18, 267, 174);
    $pdf->SetDrawColor($c_ac[0], $c_ac[1], $c_ac[2]);   // Borde interior color acento
    $pdf->SetLineWidth(0.5);
    $pdf->Rect(18, 21, 261, 168);
}

$pdf->SetTextColor($c_ac[0], $c_ac[1], $c_ac[2]);   // Academia
$pdf->SetFont($fuente, 'B', 22);
$pdf->SetY(30);
$pdf->Cell(297, 10, ACADEMY_NAME, 0, 1, 'C');

$pdf->SetTextColor($c_sec1[0], $c_sec1[1], $c_sec1[2]);
$pdf->SetFont($fuente, '', 11);
$pdf->Cell(297, 8, utf8_decode($ajustes['cert_subtitulo'] ?? 'Formacion Profesional en Habilidades Laborales'), 0, 1, 'C');

$pdf->SetDrawColor($c_ac[0], $c_ac[1], $c_ac[2]);
$pdf->SetLineWidth(0.8);
$pdf->Line(60, 52, 237, 52);

$pdf->SetTextColor($c_sec1[0], $c_sec1[1], $c_sec1[2]);
$pdf->SetFont($fuente, '', 13);
$pdf->SetY(58);
$pdf->Cell(297, 10, 'Certifica que:', 0, 1, 'C');

$pdf->SetTextColor($c_tx[0], $c_tx[1], $c_tx[2]); // Nombre del alumno
$pdf->SetFont($fuente, 'B', 32);
$pdf->SetY(70);
$pdf->Cell(297, 14, utf8_decode($usuario['nombre']), 0, 1, 'C');

$pdf->SetTextColor($c_sec1[0], $c_sec1[1], $c_sec1[2]);
$pdf->SetFont($fuente, '', 13);
$pdf->SetY(88);
$pdf->Cell(297, 8, utf8_decode($ajustes['cert_leyenda'] ?? 'ha completado satisfactoriamente el curso:'), 0, 1, 'C');

$pdf->SetTextColor($c_ac[0], $c_ac[1], $c_ac[2]); // Título del curso
$pdf->SetFont($fuente, 'B', 20);
$pdf->SetY(98);
$pdf->Cell(297, 12, utf8_decode($curso['titulo']), 0, 1, 'C');

$pdf->SetTextColor($c_sec2[0], $c_sec2[1], $c_sec2[2]);
$pdf->SetFont($fuente, '', 11);
$pdf->SetY(113);
$pdf->Cell(297, 7, 'Duracion: ' . utf8_decode($curso['duracion']) . '   |   Nivel: ' . utf8_decode($curso['nivel']), 0, 1, 'C');

$pdf->SetDrawColor($c_ac[0], $c_ac[1], $c_ac[2]);
$pdf->SetLineWidth(0.5);
$pdf->Line(80, 126, 217, 126);

$pdf->SetTextColor($c_sec2[0], $c_sec2[1], $c_sec2[2]);
$pdf->SetFont($fuente, '', 10);
$pdf->SetY(130);
$pdf->Cell(148, 7, 'Fecha de finalizacion: ' . $fecha_fin, 0, 0, 'C');
$pdf->Cell(149, 7, 'Codigo de verificacion: ' . $codigo, 0, 1, 'C');

// Determinar qué firma usar
$firma_img = !empty($curso['firmante_firma_img']) ? $curso['firmante_firma_img'] : ($ajustes['cert_firma_img'] ?? '');
$firma_texto = (!empty($curso['firmante_nombre']) && !empty($curso['firmante_cargo'])) 
                ? $curso['firmante_nombre'] . ' - ' . $curso['firmante_cargo']
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
$pdf->SetY(178);
$pdf->Cell(297, 5, utf8_decode('Verifica este certificado en: ' . certificado_url_verificacion_texto($codigo)), 0, 1, 'C');

// QR de verificación a la derecha de la firma
certificado_dibujar_qr($pdf, $codigo, 236, 142, 30);

// Emitir PDF limpiando el búfer
$nombre_archivo = 'Certificado_' . preg_replace('/[^a-zA-Z0-9]/', '_', $usuario['nombre']) . '_' . ($curso_id ?? 'preview') . '.pdf';
while (ob_get_level()) {
    ob_end_clean();
}
$dest = $is_preview ? 'I' : 'D';
$pdf->Output($dest, $nombre_archivo);

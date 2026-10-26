<?php
require_once 'config.php';

$tipo = $_GET['tipo'] ?? 'curso';
$id = intval($_GET['id'] ?? 0);
$es_ruta = ($tipo === 'ruta');

if ($es_ruta) {
    $stmt = $pdo->prepare("SELECT * FROM rutas_aprendizaje WHERE id = ?");
} else {
    $stmt = $pdo->prepare("SELECT * FROM cursos WHERE id = ?");
}
$stmt->execute([$id]);
$producto = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$producto) { header('Location: index'); exit; }

$precio_final = (!empty($producto['precio_oferta']) && $producto['precio_oferta'] < $producto['precio']) ? $producto['precio_oferta'] : $producto['precio'];

// --- BLOQUEO CRUZADO DE ACCESO ---
$rol_sesion = $_SESSION['user_rol'] ?? '';
$es_admin_staff = ($rol_sesion === 'administrativo' || $rol_sesion === 'admin');
$es_privado = ($producto['estado'] === 'privado');

if ($es_admin_staff && !$es_privado && $rol_sesion === 'administrativo') {
    header('Location: index');
    exit;
}
if ($es_privado && !$es_admin_staff) {
    header('Location: index');
    exit;
}
// Los mismos estados que acepta procesar_pago.php (borrador, archivado o inactivo no se venden)
if (!in_array($producto['estado'], ['activo', 'privado'], true)) {
    header('Location: index');
    exit;
}

$es_gratis = (floatval($precio_final) <= 0) || ($es_privado && $es_admin_staff);
$error = '';

$u_logueado = null;
if (isset($_SESSION['user_id'])) {
    $stmt_u = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmt_u->execute([$_SESSION['user_id']]);
    $u_logueado = $stmt_u->fetch(PDO::FETCH_ASSOC);
}

if (empty($_SESSION['checkout_csrf'])) {
    $_SESSION['checkout_csrf'] = bin2hex(random_bytes(32));
}
$error = trim((string)($_GET['error'] ?? ''));
// Sin sesión y con un correo ya registrado: iniciar sesión y volver a este formulario
$cuenta_existente = !$u_logueado && isset($_GET['cuenta_existente']);
$url_login = 'login?volver=' . rawurlencode('pago?tipo=' . ($es_ruta ? 'ruta' : 'curso') . '&id=' . $id);

// El registro se procesa exclusivamente en procesar_pago.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Location: pago?tipo=' . rawurlencode($tipo) . '&id=' . $id . '&error=' . rawurlencode('Recarga el formulario e intenta nuevamente.'));
    exit;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Inscripción - <?= htmlspecialchars(ACADEMY_NAME) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        body { background: #f1f5f9; font-family: 'Inter', sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; }
        .checkout-box { background: white; width: 95%; max-width: 1000px; display: grid; grid-template-columns: 1fr 1.2fr; border-radius: 24px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.15); }
        .side-info { background: #1e293b; color: white; padding: 3rem; }
        .form-side { padding: 3rem; overflow-y: auto; max-height: 90vh; }
        .price { font-size: 2.5rem; font-weight: 800; color: #fbbf24; margin: 1rem 0; display: block; }
    </style>
</head>
<body>
<div class="checkout-box">
        <div class="side-info">
            <div style="text-transform: uppercase; font-size: 0.8rem; font-weight: bold; color: #fbbf24; margin-bottom: 0.5rem;"><?= $es_ruta ? 'Ruta de Aprendizaje' : 'Curso' ?></div>
            <h1 style="margin-top:0; color: white;"><?= htmlspecialchars($producto['titulo']) ?></h1>
            <?php if (!empty($producto['precio_oferta']) && $producto['precio_oferta'] < $producto['precio'] && !$es_gratis): ?>
                <span class="price" style="text-decoration: line-through; font-size: 1.5rem; color: #94a3b8; display: inline-block; margin-right: 10px;">$ <?= number_format($producto['precio'],0,',','.') ?></span>
            <?php endif; ?>
            <span class="price" style="display: inline-block; margin-top: 0;"><?= $es_gratis ? 'GRATIS' : '$ '.number_format($precio_final,0,',','.') . ' COP' ?></span>
            <p style="opacity: 0.8;"><?= htmlspecialchars($producto['descripcion']) ?></p>
            
            <?php if (!$es_gratis): ?>
            <div style="margin-top: 2rem; background: rgba(255,255,255,0.1); padding: 1.5rem; border-radius: 12px; font-size: 0.9rem; border: 1px solid rgba(255,255,255,0.2);">
                <h4 style="margin:0 0 1rem 0; color:#fbbf24;"><i class="fa-solid fa-credit-card"></i> Paga en línea de forma segura</h4>
                <p style="margin-bottom:1.5rem; font-size:0.85rem; opacity:0.9;">Puedes pagar con Tarjeta de Crédito, PSE o Débito bancario a través de nuestro portal oficial de AvalPayCenter.</p>
                <a href="https://micrositios.avalpaycenter.com/instituto-centro-sistemas-ma" target="_blank" class="btn btn-primary btn-block" style="background:#fbbf24; color:#1e293b; border:none; font-weight:800; display:flex; align-items:center; justify-content:center; gap:10px;">
                    PAGAR CON AVALPAYCENTER <i class="fa-solid fa-external-link" style="font-size:0.8rem;"></i>
                </a>
                <p style="margin-top:1.5rem; font-size:0.75rem; font-style:italic; opacity:0.7;">* Una vez realizado el pago, descarga tu comprobante y adjúntalo en el formulario de la derecha para activar tu acceso.</p>
            </div>

            <div style="margin-top: 2rem; background: rgba(255,255,255,0.05); padding: 1.5rem; border-radius: 12px; font-size: 0.9rem; border: 1px solid rgba(255,255,255,0.1);">
                <h4 style="margin:0 0 1rem 0; color:#fbbf24;"><i class="fa-solid fa-building-columns"></i> Datos de Transferencia</h4>
                <div style="opacity: 0.9; line-height: 1.6;">
                    <?= !empty($ajustes['instrucciones_pago']) ? nl2br(htmlspecialchars($ajustes['instrucciones_pago'])) : '<p>Contacta a soporte para obtener los datos de pago.</p>' ?>
                </div>
            </div>
            <?php else: ?>
            <div style="margin-top: 2rem; background: rgba(16, 185, 129, 0.1); padding: 1.5rem; border-radius: 12px; font-size: 0.9rem; border: 1px solid rgba(16, 185, 129, 0.3);">
                <h4 style="margin:0 0 1rem 0; color:#10b981;"><i class="fa-solid fa-gift"></i> Programa Gratuito</h4>
                <p style="font-size:0.85rem; opacity:0.9;">Este programa no tiene costo. Solo completa tus datos a la derecha para obtener acceso inmediato a todo el contenido.</p>
            </div>
            <?php endif; ?>
        </div>
        <div class="form-side">
            <form method="POST" action="procesar_pago" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['checkout_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="tipo" value="<?= htmlspecialchars($tipo, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <?php if ($error): ?>
                <div style="background:#fef2f2;color:#991b1b;padding:1rem;border-radius:8px;margin-bottom:1rem;"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <?php if ($cuenta_existente): ?>
                <div style="background:#eff6ff;color:#1e3a8a;padding:1rem;border-radius:8px;margin-bottom:1rem;">Ya existe una cuenta con este correo. <a href="<?= htmlspecialchars($url_login) ?>" style="font-weight:700;color:#1d4ed8;">Inicia sesión</a> para inscribirte.</div>
                <?php endif; ?>
                <h2 style="margin-top:0;">Tus Datos</h2>
                <div class="form-group"><label>Nombre Completo</label><input type="text" name="nombre" value="<?= htmlspecialchars($u_logueado['nombre'] ?? '') ?>" required></div>
                <div class="form-group"><label>Email (Acceso)</label><input type="email" name="email" value="<?= htmlspecialchars($u_logueado['email'] ?? '') ?>" <?= $u_logueado ? 'readonly style="background:#eee"' : '' ?> required></div>
                <div class="form-group"><label>WhatsApp</label><input type="text" name="telefono" value="<?= htmlspecialchars($u_logueado['telefono'] ?? '') ?>"></div>
                <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 1rem;">
                    <div class="form-group">
                        <label>Tipo Doc.</label>
                        <select name="tipo_documento" style="width:100%; padding:0.8rem; border-radius:8px; border:1px solid #ddd;">
                            <option value="CC" <?= ($u_logueado['tipo_documento'] ?? '') === 'CC' ? 'selected' : '' ?>>C.C.</option>
                            <option value="TI" <?= ($u_logueado['tipo_documento'] ?? '') === 'TI' ? 'selected' : '' ?>>T.I.</option>
                            <option value="CE" <?= ($u_logueado['tipo_documento'] ?? '') === 'CE' ? 'selected' : '' ?>>C.E.</option>
                            <option value="PAS" <?= ($u_logueado['tipo_documento'] ?? '') === 'PAS' ? 'selected' : '' ?>>Pasaporte</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Número de Documento</label>
                        <input type="text" name="numero_documento" value="<?= htmlspecialchars($u_logueado['numero_documento'] ?? '') ?>" required placeholder="Ej: 1001234567">
                    </div>
                </div>

                <?php if (!$es_gratis): ?>
                <hr style="margin:1.5rem 0; opacity:0.1;">
                <h2 style="margin-top:0;">Datos de Pago</h2>
                <div class="form-group">
                    <label>Método utilizado</label>
                    <select name="metodo_pago" style="width:100%; padding:0.8rem; border-radius:8px; border:1px solid #ddd;">
                        <option value="transferencia">Transferencia Bancaria</option>
                        <option value="pse">Nequi / Daviplata / PSE</option>
                        <option value="efecty">Efecty</option>
                    </select>
                </div>
                <div class="form-group"><label>Referencia / ID Transacción</label><input type="text" name="referencia" placeholder="Ej: 982341"></div>
                <div class="form-group"><label>Adjuntar Comprobante (foto o PDF)</label><input type="file" name="comprobante" accept="image/jpeg,image/png,application/pdf" required></div>
                <?php endif; ?>

                <div class="form-group" style="margin-top: 1.5rem; background: rgba(0,0,0,0.02); padding: 1rem; border-radius: 8px; border: 1px solid #eee;">
                    <label style="display: flex; align-items: flex-start; gap: 0.8rem; cursor: pointer; font-weight: normal; font-size: 0.9rem; color: #475569;">
                        <input type="checkbox" name="acepta_politicas" required style="width: 18px; height: 18px; margin-top: 2px;">
                        <span>He leído y acepto la <a href="politica-privacidad.php" target="_blank" style="color: var(--primary); text-decoration: underline;">Política de Privacidad</a> y los Términos de Servicio. Doy mi consentimiento para el tratamiento de mis datos personales.</span>
                    </label>
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="padding:1.2rem; margin-top:1.5rem; <?= $es_gratis ? 'background:#10b981; border-color:#10b981;' : '' ?>">
                    <?= $es_gratis ? 'CONFIRMAR INSCRIPCIÓN GRATUITA' : 'FINALIZAR INSCRIPCIÓN' ?>
                </button>
            </form>
        </div>
</div>
</body>
</html>

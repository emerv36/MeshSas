<?php
require_once __DIR__ . '/admin_auth.php';
require_rol('admin');
require_once 'admin_header.php';

// Validar permisos (Solo admin puede configurar)
if ($rol !== 'admin') {
    echo "<div class='admin-content'><div class='alert alert-danger'>Acceso denegado.</div></div>";
    require_once 'admin_footer.php';
    exit;
}

require_once __DIR__ . '/../helpers/upload_helper.php';

$msg = '';
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $msg = "Ajustes guardados correctamente.";
    if (isset($_GET['error_logo']) && $_GET['error_logo'] == 1 && !empty($_GET['msg'])) {
        $msg .= " Sin embargo, hubo un problema con el logo: " . htmlspecialchars(urldecode($_GET['msg']));
    } elseif (isset($_GET['error_logo']) && $_GET['error_logo'] == 1) {
        $msg .= " Sin embargo, el logo debe ser un archivo PNG para mantener la transparencia.";
    }
    if (!empty($_GET['error_favicon'])) {
        $msg .= " El favicon no se guardó: " . htmlspecialchars($_GET['error_favicon']);
    }
}

// Procesar Formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Token CSRF inválido.");
    }
    $nombre = trim($_POST['nombre_academia']);
    $whatsapp = trim($_POST['whatsapp']);
    $link_linkedin = trim($_POST['link_linkedin'] ?? '');
    $link_instagram = trim($_POST['link_instagram'] ?? '');
    $link_facebook = trim($_POST['link_facebook'] ?? '');
    $email = trim($_POST['email_admin']);
    // Colores: solo #RGB o #RRGGBB (se imprimen dentro de <style>); un valor inválido conserva el actual
    $color1 = color_hex_valido($_POST['color_primario'] ?? '', $ajustes['color_primario'] ?? COLORES_AJUSTES['color_primario']);
    $color2 = color_hex_valido($_POST['color_acento'] ?? '', $ajustes['color_acento'] ?? COLORES_AJUSTES['color_acento']);
    $nota = min(100, max(1, intval($_POST['nota_aprobacion'] ?? 70)));
    $instrucciones = $_POST['instrucciones_pago'];
    $mantenimiento = isset($_POST['mantenimiento']) ? 1 : 0;

    // Campos del Certificado
    $cert_color_fondo = color_hex_valido($_POST['cert_color_fondo'] ?? '', $ajustes['cert_color_fondo'] ?? COLORES_AJUSTES['cert_color_fondo']);
    $cert_color_texto = color_hex_valido($_POST['cert_color_texto'] ?? '', $ajustes['cert_color_texto'] ?? COLORES_AJUSTES['cert_color_texto']);
    $cert_color_acento = color_hex_valido($_POST['cert_color_acento'] ?? '', $ajustes['cert_color_acento'] ?? COLORES_AJUSTES['cert_color_acento']);
    $cert_subtitulo = trim($_POST['cert_subtitulo']);
    $cert_leyenda = trim($_POST['cert_leyenda']);
    $cert_firma = trim($_POST['cert_firma']);
    // La fuente se usa como nombre de archivo en el PDF (AddFont): solo letras y números
    $cert_fuente = preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['cert_fuente'] ?? '')) ?: 'Helvetica';
    $cert_plantilla_fondo = $ajustes['cert_plantilla_fondo'] ?? null;

    if (isset($_POST['eliminar_plantilla']) && $_POST['eliminar_plantilla'] == '1') {
        if ($cert_plantilla_fondo && file_exists('../uploads/' . basename($cert_plantilla_fondo))) {
            unlink('../uploads/' . basename($cert_plantilla_fondo));
        }
        $cert_plantilla_fondo = null;
    } elseif (hay_archivo_subido('cert_plantilla_fondo')) {
        // MIME real con finfo, extensión derivada del MIME y nombre aleatorio (upload_helper)
        try {
            $cert_plantilla_fondo = 'uploads/' . guardar_imagen_subida($_FILES['cert_plantilla_fondo'], __DIR__ . '/../uploads', 'plantilla_certificado', ['image/png', 'image/jpeg']);
        } catch (RuntimeException $e) {
            $msg = "La plantilla de fondo debe ser una imagen válida (JPG o PNG).";
        }
    }

    if (isset($_FILES['nueva_fuente']) && $_FILES['nueva_fuente']['error'] === UPLOAD_ERR_OK) {
        $tmp_name = $_FILES['nueva_fuente']['tmp_name'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmp_name);
        finfo_close($finfo);

        $ext = strtolower(pathinfo($_FILES['nueva_fuente']['name'], PATHINFO_EXTENSION));
        if ($ext === 'ttf' && (strpos($mime, 'font') !== false || strpos($mime, 'octet-stream') !== false || strpos($mime, 'x-font') !== false)) {
            $font_name = strtolower(pathinfo($_FILES['nueva_fuente']['name'], PATHINFO_FILENAME));
            $font_name = preg_replace('/[^a-z0-9]/', '', $font_name);
            if (!is_dir('../fonts')) { mkdir('../fonts', 0755, true); }
            $target_ttf = '../fonts/' . $font_name . '.ttf';
            if ($font_name !== '' && move_uploaded_file($_FILES['nueva_fuente']['tmp_name'], $target_ttf)) {
                $current_dir = getcwd();
                chdir('../fonts');
                require_once '../vendor/setasign/fpdf/makefont/makefont.php';
                ob_start();
                MakeFont($font_name . '.ttf', 'cp1252');
                ob_end_clean();
                chdir($current_dir);
                $cert_fuente = $font_name; // Select it automatically
            }
        } else {
            $msg = "La fuente debe ser un archivo .ttf";
        }
    }

    // Procesar Logo si se subió uno (debe ser PNG real para mantener la transparencia)
    $error_logo = false;
    $error_logo_msg = "";
    if (hay_archivo_subido('logo')) {
        try {
            validar_imagen_subida($_FILES['logo'], ['image/png']);
            if (!move_uploaded_file($_FILES['logo']['tmp_name'], __DIR__ . '/../img/logo.png')) {
                $error_logo = true;
                $error_logo_msg = "Error de permisos al guardar el logo en el servidor.";
            }
        } catch (RuntimeException $e) {
            $error_logo = true;
            $error_logo_msg = "El logo debe ser una imagen PNG válida.";
        }
    }

    // Favicon: se guarda en banner.img_favicon, que config.php publica como FAVICON_URL
    $error_favicon = '';
    if (hay_archivo_subido('img_favicon')) {
        try {
            $favicon = 'uploads/' . guardar_imagen_subida($_FILES['img_favicon'], __DIR__ . '/../uploads', 'img_favicon', ['image/png', 'image/jpeg', 'image/webp', 'image/gif']);
            $pdo->prepare("UPDATE banner SET img_favicon = ? WHERE id = 1")->execute([$favicon]);
        } catch (RuntimeException $e) {
            $error_favicon = $e->getMessage();
        }
    }

    // Procesar Firma del Certificado si se subió una
    $cert_firma_img = $ajustes['cert_firma_img'] ?? null;
    if (hay_archivo_subido('cert_firma_img')) {
        try {
            $cert_firma_img = 'uploads/' . guardar_imagen_subida($_FILES['cert_firma_img'], __DIR__ . '/../uploads', 'firma_certificado', ['image/png', 'image/jpeg']);
        } catch (RuntimeException $e) {
            $msg = "La firma debe ser una imagen (PNG o JPG).";
        }
    }

    $stmt = $pdo->prepare("INSERT INTO ajustes 
        (id, nombre_academia, whatsapp, link_linkedin, link_instagram, link_facebook, email_admin,
         color_primario, color_acento, nota_aprobacion,
         instrucciones_pago, mantenimiento,
         cert_color_fondo, cert_color_texto, cert_color_acento,
         cert_subtitulo, cert_leyenda, cert_firma, cert_firma_img, cert_plantilla_fondo, cert_fuente)
        VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        nombre_academia = VALUES(nombre_academia), whatsapp = VALUES(whatsapp),
        link_linkedin = VALUES(link_linkedin), link_instagram = VALUES(link_instagram),
        link_facebook = VALUES(link_facebook), email_admin = VALUES(email_admin),
        color_primario = VALUES(color_primario), color_acento = VALUES(color_acento),
        nota_aprobacion = VALUES(nota_aprobacion), instrucciones_pago = VALUES(instrucciones_pago),
        mantenimiento = VALUES(mantenimiento),
        cert_color_fondo = VALUES(cert_color_fondo), cert_color_texto = VALUES(cert_color_texto),
        cert_color_acento = VALUES(cert_color_acento), cert_subtitulo = VALUES(cert_subtitulo),
        cert_leyenda = VALUES(cert_leyenda), cert_firma = VALUES(cert_firma),
        cert_firma_img = VALUES(cert_firma_img), cert_plantilla_fondo = VALUES(cert_plantilla_fondo),
        cert_fuente = VALUES(cert_fuente)");

    if ($stmt->execute([
        $nombre, $whatsapp, $link_linkedin, $link_instagram, $link_facebook, $email,
        $color1, $color2, $nota,
        $instrucciones, $mantenimiento,
        $cert_color_fondo, $cert_color_texto, $cert_color_acento,
        $cert_subtitulo, $cert_leyenda, $cert_firma, $cert_firma_img, $cert_plantilla_fondo, $cert_fuente
    ])) {
        $url = "configuracion?success=1";
        if ($error_logo) {
            $url .= "&error_logo=1&msg=" . urlencode($error_logo_msg);
        }
        if ($error_favicon !== '') {
            $url .= "&error_favicon=" . urlencode($error_favicon);
        }
        echo "<script>window.location.href = '$url';</script>";
        exit;
    } else {
        $msg = "Error al guardar los ajustes.";
    }
}
?>

<style>
.maintenance-switch {
    position: relative;
    display: inline-block;
    flex: 0 0 50px;
    width: 50px;
    height: 26px;
    margin-left: 1rem;
    cursor: pointer;
}
.maintenance-switch__input {
    position: absolute;
    z-index: 2;
    inset: 0;
    width: 100%;
    height: 100%;
    margin: 0;
    opacity: 0;
    cursor: pointer;
}
.maintenance-switch__track {
    position: absolute;
    inset: 0;
    border-radius: 34px;
    background: #cbd5e1;
    transition: background-color .2s ease, box-shadow .2s ease;
}
.maintenance-switch__thumb {
    position: absolute;
    left: 4px;
    bottom: 4px;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 3px rgba(15, 23, 42, .3);
    transition: transform .2s ease;
}
.maintenance-switch__input:checked + .maintenance-switch__track {
    background: var(--accent);
}
.maintenance-switch__input:checked + .maintenance-switch__track .maintenance-switch__thumb {
    transform: translateX(24px);
}
.maintenance-switch__input:focus-visible + .maintenance-switch__track {
    outline: 3px solid rgba(255, 107, 53, .3);
    outline-offset: 3px;
}
</style>

<div class="admin-content">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2rem;">
        <div>
            <h2 style="margin:0; color:var(--primary);">Configuración del Sistema</h2>
            <p style="color:#64748b; margin-top:0.3rem;">Personaliza el comportamiento y apariencia de <?= htmlspecialchars(ACADEMY_NAME) ?>.</p>
        </div>
        <?php if($msg): ?>
            <div style="background:#dcfce7; color:#166534; padding:10px 20px; border-radius:10px; font-weight:600; animation: fadeIn 0.3s;">
                <i class="fa-solid fa-circle-check"></i> <?= $msg ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Navegación de Pestañas -->
    <div style="display: flex; gap: 0.5rem; margin-bottom: 2rem; border-bottom: 2px solid #e2e8f0; padding-bottom: 0.5rem; flex-wrap: wrap;">
        <button type="button" class="tab-btn active" onclick="switchTab(event, 'tab-identidad')" style="background: none; border: none; padding: 0.75rem 1.25rem; font-weight: 600; color: var(--primary); cursor: pointer; border-bottom: 3px solid var(--accent); margin-bottom: -0.7rem; transition: 0.2s; font-size: 0.95rem; font-family: inherit;">
            <i class="fa-solid fa-building"></i> Identidad y Redes
        </button>
        <button type="button" class="tab-btn" onclick="switchTab(event, 'tab-apariencia')" style="background: none; border: none; padding: 0.75rem 1.25rem; font-weight: 600; color: #64748b; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -0.7rem; transition: 0.2s; font-size: 0.95rem; font-family: inherit;">
            <i class="fa-solid fa-palette"></i> Apariencia
        </button>
        <button type="button" class="tab-btn" onclick="switchTab(event, 'tab-evaluacion')" style="background: none; border: none; padding: 0.75rem 1.25rem; font-weight: 600; color: #64748b; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -0.7rem; transition: 0.2s; font-size: 0.95rem; font-family: inherit;">
            <i class="fa-solid fa-graduation-cap"></i> Evaluación
        </button>
        <button type="button" class="tab-btn" onclick="switchTab(event, 'tab-pagos')" style="background: none; border: none; padding: 0.75rem 1.25rem; font-weight: 600; color: #64748b; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -0.7rem; transition: 0.2s; font-size: 0.95rem; font-family: inherit;">
            <i class="fa-solid fa-credit-card"></i> Pagos
        </button>
        <button type="button" class="tab-btn" onclick="switchTab(event, 'tab-certificado')" style="background: none; border: none; padding: 0.75rem 1.25rem; font-weight: 600; color: #64748b; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -0.7rem; transition: 0.2s; font-size: 0.95rem; font-family: inherit;">
            <i class="fa-solid fa-certificate"></i> Certificado
        </button>
        <button type="button" class="tab-btn" onclick="switchTab(event, 'tab-sistema')" style="background: none; border: none; padding: 0.75rem 1.25rem; font-weight: 600; color: #64748b; cursor: pointer; border-bottom: 3px solid transparent; margin-bottom: -0.7rem; transition: 0.2s; font-size: 0.95rem; font-family: inherit;">
            <i class="fa-solid fa-gears"></i> Sistema
        </button>
    </div>

    <form method="POST" enctype="multipart/form-data" class="glass-section" style="max-width:900px; padding: 2rem;">
        <?= csrf_input() ?>
        
        <!-- Pestaña 1: Identidad y Redes -->
        <div id="tab-identidad" class="tab-pane" style="display:block;">
            <h4 style="margin-top:0; margin-bottom:1.5rem; color:var(--primary); display:flex; align-items:center; gap:0.6rem;">
                <i class="fa-solid fa-building" style="color:var(--accent);"></i> Identidad de la Academia
            </h4>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.5rem;">
                <div style="margin-bottom:1rem;">
                    <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.4rem;">Nombre de la Academia</label>
                    <input type="text" name="nombre_academia" value="<?= htmlspecialchars($ajustes['nombre_academia'] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #e2e8f0; border-radius:8px;">
                </div>
                <div style="margin-bottom:1rem;">
                    <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.4rem;">Email Administrativo</label>
                    <input type="email" name="email_admin" value="<?= htmlspecialchars($ajustes['email_admin'] ?? '') ?>" style="width:100%; padding:10px; border:1px solid #e2e8f0; border-radius:8px;">
                </div>
            </div>
            
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1.5rem; margin-top:0.5rem;">
                <div style="margin-bottom:1rem;">
                    <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.4rem;">WhatsApp de Soporte</label>
                    <input type="text" name="whatsapp" value="<?= htmlspecialchars($ajustes['whatsapp'] ?? '') ?>" placeholder="Ej: 573001234567" style="width:100%; padding:10px; border:1px solid #e2e8f0; border-radius:8px;">
                </div>
                <div style="margin-bottom:1rem;">
                    <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.4rem;">Logo de la Plataforma</label>
                    <div style="display:flex; align-items:center; gap:1rem;">
                        <img src="../img/logo.png?v=<?= time() ?>" alt="Logo Actual" style="height:40px; background:#f1f5f9; padding:5px; border-radius:6px;">
                        <input type="file" name="logo" accept="image/png" style="width:100%; padding:8px; border:1px solid #e2e8f0; border-radius:8px; font-size:0.85rem;">
                    </div>
                </div>
            </div>
            <p style="font-size:0.75rem; color:#64748b; margin-top:-0.5rem; margin-bottom:1rem;"><i class="fa-solid fa-circle-info"></i> Logo: <b>PNG con fondo transparente</b>. Altura recomendada: <b>65px a 80px</b>.</p>

            <div style="margin-bottom:2rem; max-width:50%;">
                <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.4rem;">Favicon (ícono de la pestaña del navegador)</label>
                <div style="display:flex; align-items:center; gap:1rem;">
                    <img src="<?= htmlspecialchars(FAVICON_URL) ?>" alt="Favicon actual" style="width:40px; height:40px; object-fit:contain; background:#f1f5f9; padding:5px; border-radius:6px;">
                    <input type="file" name="img_favicon" accept="image/png, image/jpeg, image/webp, image/gif" style="width:100%; padding:8px; border:1px solid #e2e8f0; border-radius:8px; font-size:0.85rem;">
                </div>
                <p style="font-size:0.75rem; color:#64748b; margin:0.4rem 0 0;">Imagen cuadrada (PNG recomendado, 64×64 o más). Si no subes ninguno, se usa el logo.</p>
            </div>

            <h4 style="margin-bottom:1.5rem; color:var(--primary); display:flex; align-items:center; gap:0.6rem; border-top:1px solid #f1f5f9; padding-top:1.5rem;">
                <i class="fa-solid fa-share-nodes" style="color:var(--accent);"></i> Redes Sociales
            </h4>
            <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:1.5rem; margin-bottom:1rem;">
                <div style="margin-bottom:1rem;">
                    <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.4rem;"><i class="fa-brands fa-linkedin" style="color:#0a66c2;"></i> LinkedIn</label>
                    <input type="url" name="link_linkedin" value="<?= htmlspecialchars($ajustes['link_linkedin'] ?? '') ?>" placeholder="https://linkedin.com/company/..." style="width:100%; padding:10px; border:1px solid #e2e8f0; border-radius:8px;">
                </div>
                <div style="margin-bottom:1rem;">
                    <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.4rem;"><i class="fa-brands fa-instagram" style="color:#e1306c;"></i> Instagram</label>
                    <input type="url" name="link_instagram" value="<?= htmlspecialchars($ajustes['link_instagram'] ?? '') ?>" placeholder="https://instagram.com/..." style="width:100%; padding:10px; border:1px solid #e2e8f0; border-radius:8px;">
                </div>
                <div style="margin-bottom:1rem;">
                    <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.4rem;"><i class="fa-brands fa-facebook" style="color:#1877f2;"></i> Facebook</label>
                    <input type="url" name="link_facebook" value="<?= htmlspecialchars($ajustes['link_facebook'] ?? '') ?>" placeholder="https://facebook.com/..." style="width:100%; padding:10px; border:1px solid #e2e8f0; border-radius:8px;">
                </div>
            </div>
        </div>

        <!-- Pestaña 2: Apariencia -->
        <div id="tab-apariencia" class="tab-pane" style="display:none;">
            <h4 style="margin-top:0; margin-bottom:1.5rem; color:var(--primary); display:flex; align-items:center; gap:0.6rem;">
                <i class="fa-solid fa-palette" style="color:var(--accent);"></i> Paleta de Colores
            </h4>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-bottom:1.5rem;">
                <div>
                    <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.4rem;">Color Primario</label>
                    <div style="display:flex; gap:0.5rem;">
                        <input type="color" id="cp_picker" value="<?= htmlspecialchars($ajustes['color_primario'] ?? '#2A3547') ?>" style="width:50px; height:40px; border:1px solid #e2e8f0; border-radius:8px; cursor:pointer; padding:0;">
                        <input type="text" name="color_primario" id="cp_text" value="<?= htmlspecialchars($ajustes['color_primario'] ?? '#2A3547') ?>" placeholder="#000000" style="flex:1; padding:10px; border:1px solid #e2e8f0; border-radius:8px;" maxlength="7">
                    </div>
                </div>
                <div>
                    <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.4rem;">Color Acento</label>
                    <div style="display:flex; gap:0.5rem;">
                        <input type="color" id="ca_picker" value="<?= htmlspecialchars($ajustes['color_acento'] ?? '#FF6B35') ?>" style="width:50px; height:40px; border:1px solid #e2e8f0; border-radius:8px; cursor:pointer; padding:0;">
                        <input type="text" name="color_acento" id="ca_text" value="<?= htmlspecialchars($ajustes['color_acento'] ?? '#FF6B35') ?>" placeholder="#000000" style="flex:1; padding:10px; border:1px solid #e2e8f0; border-radius:8px;" maxlength="7">
                    </div>
                </div>
            </div>
        </div>

        <!-- Pestaña: Evaluación -->
        <div id="tab-evaluacion" class="tab-pane" style="display:none;">
            <h4 style="margin-top:0; margin-bottom:1.5rem; color:var(--primary); display:flex; align-items:center; gap:0.6rem;">
                <i class="fa-solid fa-graduation-cap" style="color:var(--accent);"></i> Evaluación
            </h4>
            <div style="max-width:320px; margin-bottom:0.75rem;">
                <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.4rem;">Nota mínima por defecto (%)</label>
                <input type="number" name="nota_aprobacion" value="<?= PASSING_GRADE ?>" min="1" max="100" required style="width:100%; padding:10px; border:1px solid #e2e8f0; border-radius:8px;">
            </div>
            <p style="font-size:0.8rem; color:#64748b; margin:0; max-width:640px;"><i class="fa-solid fa-circle-info"></i> Es la nota con la que empiezan las lecciones calificables nuevas (quizzes, ejercicios y H5P). El docente puede cambiarla en cada lección. No modifica las lecciones que ya existen, así que ningún estudiante gana ni pierde aprobaciones al cambiarla.</p>
        </div>

        <!-- Pestaña 3: Pagos -->
        <div id="tab-pagos" class="tab-pane" style="display:none;">
            <h4 style="margin-top:0; margin-bottom:1.5rem; color:var(--primary); display:flex; align-items:center; gap:0.6rem;">
                <i class="fa-solid fa-credit-card" style="color:var(--accent);"></i> Configuración de Pagos
            </h4>
            <div style="margin-bottom:1rem;">
                <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.4rem;">Instrucciones para Transferencia Bancaria</label>
                <textarea name="instrucciones_pago" rows="8" style="width:100%; padding:15px; border:1px solid #e2e8f0; border-radius:12px; font-family:inherit; line-height:1.5;" placeholder="Escribe aquí los datos bancarios o pasos para que el alumno realice el pago..."><?= htmlspecialchars($ajustes['instrucciones_pago'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- Pestaña 4: Certificado -->
        <div id="tab-certificado" class="tab-pane" style="display:none;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:1rem;">
                <h4 style="margin:0; color:var(--primary); display:flex; align-items:center; gap:0.6rem;">
                    <i class="fa-solid fa-certificate" style="color:var(--accent);"></i> Personalización del Certificado
                </h4>
                <a href="../dashboard/certificado?preview=1" target="_blank" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; padding:8px 16px; border-radius:8px; font-weight:600; font-size:0.85rem; text-decoration:none; display:inline-flex; align-items:center; gap:0.5rem; transition:0.2s; cursor:pointer;">
                    <i class="fa-solid fa-eye"></i> Vista Previa (PDF)
                </a>
            </div>
            
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:1.5rem; margin-bottom:1.5rem;">
                <h5 style="margin:0 0 1rem 0; color:var(--primary); font-size:1rem;">Diseño y Plantilla</h5>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem;">
                    <div>
                        <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.4rem;">Plantilla de Fondo (Opcional)</label>
                        <div style="display:flex; flex-direction:column; gap:0.5rem;">
                            <?php if (!empty($ajustes['cert_plantilla_fondo'])): ?>
                                <div style="display:flex; align-items:center; justify-content:space-between;">
                                    <img src="../<?= htmlspecialchars($ajustes['cert_plantilla_fondo']) ?>?v=<?= time() ?>" alt="Plantilla" style="height:45px; border-radius:6px; border:1px solid #cbd5e1;">
                                    <label style="font-size:0.8rem; color:#ef4444; display:flex; align-items:center; gap:0.3rem;"><input type="checkbox" name="eliminar_plantilla" value="1"> Eliminar</label>
                                </div>
                            <?php else: ?>
                                <span style="font-size:0.8rem; color:#94a3b8; font-style:italic;">Actualmente usando diseño por código (colores)</span>
                            <?php endif; ?>
                            <input type="file" name="cert_plantilla_fondo" accept="image/png, image/jpeg" style="width:100%; padding:8px; border:1px solid #e2e8f0; border-radius:8px; font-size:0.85rem; background:white; box-sizing:border-box;">
                        </div>
                        <p style="font-size:0.75rem; color:#64748b; margin-top:0.4rem;">Si subes una plantilla A4 (JPG/PNG), reemplazará los colores de fondo y bordes.</p>
                    </div>
                    <div>
                        <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.4rem;">Tipo de Fuente</label>
                        <?php
                            $fonts_disp = ['Helvetica' => 'Helvetica (Moderno)', 'Times' => 'Times (Clásico)', 'Courier' => 'Courier (Máquina)'];
                            if (is_dir('../fonts')) {
                                foreach (glob('../fonts/*.php') as $ff) {
                                    $bn = basename($ff, '.php');
                                    $fonts_disp[$bn] = ucfirst($bn) . ' (Importada)';
                                }
                            }
                            $selected_font = $ajustes['cert_fuente'] ?? 'Helvetica';
                        ?>
                        <div style="display:flex; gap:0.5rem; flex-direction:column;">
                            <select name="cert_fuente" style="width:100%; padding:10px; border:1px solid #e2e8f0; border-radius:8px; font-size:0.9rem; box-sizing:border-box;">
                                <?php foreach($fonts_disp as $fk => $fv): ?>
                                    <option value="<?= $fk ?>" <?= $selected_font == $fk ? 'selected' : '' ?>><?= $fv ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div style="display:flex; flex-direction:column; gap:0.4rem; margin-top:0.5rem;">
                                <span style="font-size:0.8rem; font-weight:600; color:#475569;">Importar nueva fuente (.ttf)</span>
                                <input type="file" name="nueva_fuente" accept=".ttf" style="width:100%; padding:8px; border:1px dashed #cbd5e1; border-radius:8px; font-size:0.85rem; background:white; box-sizing:border-box;">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:1.5rem; margin-bottom:2rem;">
                <div>
                    <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.4rem;">Color de Fondo</label>
                    <div style="display:flex; gap:0.5rem;">
                        <input type="color" id="cert_bg_picker" value="<?= htmlspecialchars($ajustes['cert_color_fondo'] ?? '#2A3547') ?>" style="width:50px; height:40px; border:1px solid #e2e8f0; border-radius:8px; cursor:pointer; padding:0;">
                        <input type="text" name="cert_color_fondo" id="cert_bg_text" value="<?= htmlspecialchars($ajustes['cert_color_fondo'] ?? '#2A3547') ?>" placeholder="#2A3547" style="flex:1; padding:10px; border:1px solid #e2e8f0; border-radius:8px;" maxlength="7">
                    </div>
                </div>
                <div>
                    <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.4rem;">Color de Texto (Nombre)</label>
                    <div style="display:flex; gap:0.5rem;">
                        <input type="color" id="cert_tx_picker" value="<?= htmlspecialchars($ajustes['cert_color_texto'] ?? '#FFFFFF') ?>" style="width:50px; height:40px; border:1px solid #e2e8f0; border-radius:8px; cursor:pointer; padding:0;">
                        <input type="text" name="cert_color_texto" id="cert_tx_text" value="<?= htmlspecialchars($ajustes['cert_color_texto'] ?? '#FFFFFF') ?>" placeholder="#FFFFFF" style="flex:1; padding:10px; border:1px solid #e2e8f0; border-radius:8px;" maxlength="7">
                    </div>
                </div>
                <div>
                    <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.4rem;">Color de Acento (Bordes/Logo)</label>
                    <div style="display:flex; gap:0.5rem;">
                        <input type="color" id="cert_ac_picker" value="<?= htmlspecialchars($ajustes['cert_color_acento'] ?? '#FF6B35') ?>" style="width:50px; height:40px; border:1px solid #e2e8f0; border-radius:8px; cursor:pointer; padding:0;">
                        <input type="text" name="cert_color_acento" id="cert_ac_text" value="<?= htmlspecialchars($ajustes['cert_color_acento'] ?? '#FF6B35') ?>" placeholder="#FF6B35" style="flex:1; padding:10px; border:1px solid #e2e8f0; border-radius:8px;" maxlength="7">
                    </div>
                </div>
            </div>

            <div style="margin-bottom:1.5rem;">
                <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.4rem;">Subtítulo del Certificado</label>
                <input type="text" name="cert_subtitulo" value="<?= htmlspecialchars($ajustes['cert_subtitulo'] ?? '') ?>" placeholder="Ej: Formación Profesional en Habilidades Laborales" style="width:100%; padding:10px; border:1px solid #e2e8f0; border-radius:8px;">
            </div>

            <div style="margin-bottom:1.5rem;">
                <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.4rem;">Leyenda del Certificado</label>
                <input type="text" name="cert_leyenda" value="<?= htmlspecialchars($ajustes['cert_leyenda'] ?? '') ?>" placeholder="Ej: ha completado satisfactoriamente el curso:" style="width:100%; padding:10px; border:1px solid #e2e8f0; border-radius:8px;">
            </div>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:1.5rem; margin-bottom:1.5rem;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                    <h5 style="margin:0; color:var(--primary); font-size:1rem;">Firma Global (Por Defecto)</h5>
                    <a href="firmantes" style="background:var(--primary); color:white; padding:6px 12px; border-radius:6px; font-size:0.8rem; text-decoration:none; font-weight:600;"><i class="fa-solid fa-users"></i> Catálogo de Firmantes</a>
                </div>
                <p style="font-size:0.8rem; color:#64748b; margin-top:0; margin-bottom:1.5rem;">Esta firma se usará automáticamente en todos los cursos y rutas que no tengan un firmante específico asignado. Para crear otras firmas, usa el Catálogo de Firmantes.</p>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem;">
                    <div>
                        <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.4rem;">Nombre / Cargo de la Firma Global</label>
                        <input type="text" name="cert_firma" value="<?= htmlspecialchars($ajustes['cert_firma'] ?? '') ?>" placeholder="Ej: Director Académico - <?= htmlspecialchars(ACADEMY_NAME) ?>" style="width:100%; padding:10px; border:1px solid #e2e8f0; border-radius:8px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:0.85rem; font-weight:600; margin-bottom:0.4rem;">Imagen de la Firma Global</label>
                        <div style="display:flex; align-items:center; gap:1rem;">
                            <?php if (!empty($ajustes['cert_firma_img'])): ?>
                                <img src="../<?= htmlspecialchars($ajustes['cert_firma_img']) ?>?v=<?= time() ?>" alt="Firma Actual" style="height:45px; background:#fff; padding:5px; border-radius:6px; border:1px solid #cbd5e1;">
                            <?php else: ?>
                                <span style="font-size:0.8rem; color:#94a3b8; font-style:italic;">Sin firma cargada</span>
                            <?php endif; ?>
                            <input type="file" name="cert_firma_img" accept="image/*" style="width:100%; padding:8px; border:1px solid #e2e8f0; border-radius:8px; font-size:0.85rem; background:white;">
                        </div>
                        <p style="font-size:0.75rem; color:#64748b; margin-top:0.4rem;"><i class="fa-solid fa-circle-info"></i> Imagen recomendada: <b>PNG transparente</b> de aprox. 200x80px.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pestaña 5: Sistema -->
        <div id="tab-sistema" class="tab-pane" style="display:none;">
            <h4 style="margin-top:0; margin-bottom:1.5rem; color:var(--primary); display:flex; align-items:center; gap:0.6rem;">
                <i class="fa-solid fa-gears" style="color:var(--accent);"></i> Ajustes del Sistema
            </h4>
            <div style="background:#fff7ed; padding:1.5rem; border-radius:16px; border:1px solid #ffedd5; display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem;">
                <div>
                    <h5 style="margin:0; color:#9a3412; font-size:0.95rem; font-weight:700;">Modo Mantenimiento</h5>
                    <p style="margin:0.2rem 0 0; font-size:0.8rem; color:#c2410c;">Bloquea visitantes y estudiantes con un mensaje de la plataforma. El personal autorizado conserva el acceso.</p>
                </div>
                <label class="maintenance-switch" title="Activar o desactivar el modo mantenimiento">
                    <input class="maintenance-switch__input" type="checkbox" name="mantenimiento" value="1" <?= !empty($ajustes['mantenimiento']) ? 'checked' : '' ?> aria-label="Modo mantenimiento">
                    <span class="maintenance-switch__track" aria-hidden="true">
                        <span class="maintenance-switch__thumb"></span>
                    </span>
                </label>
            </div>
        </div>

        <hr style="margin:2rem 0 1.5rem; border:0; border-top:1px solid #f1f5f9;">

        <div style="display:flex; justify-content:flex-end;">
            <button type="submit" class="btn-complete" style="padding:12px 35px; font-size:0.95rem; border-radius:10px;">
                <i class="fa-solid fa-save"></i> Guardar Cambios
            </button>
        </div>
    </form>
</div>

<script>
function switchTab(evt, tabId) {
    // Ocultar todas las pestañas
    document.querySelectorAll('.tab-pane').forEach(pane => {
        pane.style.display = 'none';
    });
    // Quitar clase activa y estilos a los botones
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
        btn.style.color = '#64748b';
        btn.style.borderBottomColor = 'transparent';
    });
    // Mostrar pestaña seleccionada
    document.getElementById(tabId).style.display = 'block';
    // Aplicar estado activo al botón pulsado
    evt.currentTarget.classList.add('active');
    evt.currentTarget.style.color = 'var(--primary)';
    evt.currentTarget.style.borderBottomColor = 'var(--accent)';
}

document.addEventListener('DOMContentLoaded', function() {
    const cpPicker = document.getElementById('cp_picker');
    const cpText = document.getElementById('cp_text');
    const caPicker = document.getElementById('ca_picker');
    const caText = document.getElementById('ca_text');

    if(cpPicker && cpText) {
        cpPicker.addEventListener('input', function() {
            cpText.value = cpPicker.value.toUpperCase();
        });
        cpText.addEventListener('input', function() {
            let val = cpText.value;
            if(!val.startsWith('#')) {
                val = '#' + val;
            }
            if(/^#[0-9A-F]{6}$/i.test(val)) {
                cpPicker.value = val;
            }
        });
    }

    if(caPicker && caText) {
        caPicker.addEventListener('input', function() {
            caText.value = caPicker.value.toUpperCase();
        });
        caText.addEventListener('input', function() {
            let val = caText.value;
            if(!val.startsWith('#')) {
                val = '#' + val;
            }
            if(/^#[0-9A-F]{6}$/i.test(val)) {
                caPicker.value = val;
            }
        });
    }

    // Sincronizar colores del Certificado
    const certBgPicker = document.getElementById('cert_bg_picker');
    const certBgText   = document.getElementById('cert_bg_text');
    const certTxPicker = document.getElementById('cert_tx_picker');
    const certTxText   = document.getElementById('cert_tx_text');
    const certAcPicker = document.getElementById('cert_ac_picker');
    const certAcText   = document.getElementById('cert_ac_text');

    if(certBgPicker && certBgText) {
        certBgPicker.addEventListener('input', () => certBgText.value = certBgPicker.value.toUpperCase());
        certBgText.addEventListener('input', () => {
            let val = certBgText.value;
            if(!val.startsWith('#')) val = '#' + val;
            if(/^#[0-9A-F]{6}$/i.test(val)) certBgPicker.value = val;
        });
    }
    if(certTxPicker && certTxText) {
        certTxPicker.addEventListener('input', () => certTxText.value = certTxPicker.value.toUpperCase());
        certTxText.addEventListener('input', () => {
            let val = certTxText.value;
            if(!val.startsWith('#')) val = '#' + val;
            if(/^#[0-9A-F]{6}$/i.test(val)) certTxPicker.value = val;
        });
    }
    if(certAcPicker && certAcText) {
        certAcPicker.addEventListener('input', () => certAcText.value = certAcPicker.value.toUpperCase());
        certAcText.addEventListener('input', () => {
            let val = certAcText.value;
            if(!val.startsWith('#')) val = '#' + val;
            if(/^#[0-9A-F]{6}$/i.test(val)) certAcPicker.value = val;
        });
    }
});
</script>

<?php require_once 'admin_footer.php'; ?>

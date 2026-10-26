<?php
require_once 'admin_header.php';

$mensaje_tipo = ''; // 'success' o 'error'
$mensaje = '';

// Procesar actualizaciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Token CSRF inválido.");
    }
    if (isset($_POST['guardar_perfil'])) {
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $tipo_documento = $_POST['tipo_documento'] ?? 'CC';
        $numero_documento = trim($_POST['numero_documento'] ?? '');
        $bio = trim($_POST['bio'] ?? '');

        if (empty($nombre) || empty($email)) {
            $mensaje_tipo = 'error';
            $mensaje = 'El nombre y el correo electrónico son campos obligatorios.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $mensaje_tipo = 'error';
            $mensaje = 'El correo electrónico ingresado no es válido.';
        } else {
            // Verificar si el correo ya está en uso
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE email = ? AND id != ?");
            $stmt_check->execute([$email, $user_id]);
            if ($stmt_check->fetchColumn() > 0) {
                $mensaje_tipo = 'error';
                $mensaje = 'El correo electrónico ya está registrado por otro usuario.';
            } else {
                // Actualizar info principal
                $stmt_upd = $pdo->prepare("UPDATE usuarios SET nombre = ?, email = ?, telefono = ?, tipo_documento = ?, numero_documento = ?, bio = ? WHERE id = ?");
                $stmt_upd->execute([$nombre, $email, $telefono, $tipo_documento, $numero_documento, $bio, $user_id]);

                $_SESSION['user_name'] = $nombre;

                // Procesar foto de perfil
                if (isset($_FILES['foto']) && $_FILES['foto']['error'] === 0) {
                    $dir_destino = "../uploads/perfiles/";
                    if (!is_dir($dir_destino)) {
                        mkdir($dir_destino, 0755, true);
                    }

                    $tmp_name = $_FILES['foto']['tmp_name'];
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = finfo_file($finfo, $tmp_name);
                    finfo_close($finfo);

                    $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
                    $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];
                    $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp'];

                    if (in_array($ext, $allowed_exts) && in_array($mime, $allowed_mimes)) {
                        $nombre_foto = "perfil_" . $user_id . "_" . time() . "." . $ext;
                        $ruta_destino = $dir_destino . $nombre_foto;

                        if (move_uploaded_file($tmp_name, $ruta_destino)) {
                            // Obtener foto anterior para borrarla
                            $stmt_old = $pdo->prepare("SELECT foto FROM usuarios WHERE id = ?");
                            $stmt_old->execute([$user_id]);
                            $old_foto = $stmt_old->fetchColumn();

                            if ($old_foto && file_exists("../" . $old_foto)) {
                                @unlink("../" . $old_foto);
                            }

                            // Actualizar en DB
                            $pdo->prepare("UPDATE usuarios SET foto = ? WHERE id = ?")->execute(["uploads/perfiles/" . $nombre_foto, $user_id]);
                        } else {
                            $mensaje_tipo = 'error';
                            $mensaje = 'No se pudo guardar la imagen en el servidor.';
                        }
                    } else {
                        $mensaje_tipo = 'error';
                        $mensaje = 'Formato de imagen inválido. Use JPG, JPEG, PNG o WEBP.';
                    }
                }

                if ($mensaje_tipo !== 'error') {
                    $mensaje_tipo = 'success';
                    $mensaje = 'Perfil actualizado correctamente.';
                }
            }
        }
    } elseif (isset($_POST['cambiar_password'])) {
        $pass_actual = $_POST['pass_actual'] ?? '';
        $pass_nueva = $_POST['pass_nueva'] ?? '';
        $pass_confirmar = $_POST['pass_confirmar'] ?? '';

        if (empty($pass_actual) || empty($pass_nueva) || empty($pass_confirmar)) {
            $mensaje_tipo = 'error';
            $mensaje = 'Todos los campos de contraseña son obligatorios.';
        } elseif ($pass_nueva !== $pass_confirmar) {
            $mensaje_tipo = 'error';
            $mensaje = 'La nueva contraseña y su confirmación no coinciden.';
        } elseif (strlen($pass_nueva) < 6) {
            $mensaje_tipo = 'error';
            $mensaje = 'La nueva contraseña debe tener al menos 6 caracteres.';
        } else {
            // Verificar contraseña actual
            $stmt_pass = $pdo->prepare("SELECT password FROM usuarios WHERE id = ?");
            $stmt_pass->execute([$user_id]);
            $hash_actual = $stmt_pass->fetchColumn();

            if ($hash_actual && password_verify($pass_actual, $hash_actual)) {
                $pass_hash_nueva = password_hash($pass_nueva, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE usuarios SET password = ? WHERE id = ?")->execute([$pass_hash_nueva, $user_id]);

                $mensaje_tipo = 'success';
                $mensaje = 'Contraseña actualizada correctamente.';
            } else {
                $mensaje_tipo = 'error';
                $mensaje = 'La contraseña actual ingresada es incorrecta.';
            }
        }
    }
}

// Obtener datos actualizados del usuario
$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$user_id]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    header('Location: ../logout'); exit;
}
?>

<style>
    /* Grid Layout */
    .profile-grid { display: grid; grid-template-columns: 280px 1fr; gap: 2rem; align-items: start; }
    @media (max-width: 992px) {
        .profile-grid { grid-template-columns: 1fr; }
    }

    /* Card Styles */
    .profile-card { background: white; border-radius: 16px; padding: 2rem; border: 1px solid #e2e8f0; box-shadow: 0 4px 12px rgba(0,0,0,0.03); display: flex; flex-direction: column; align-items: center; text-align: center; }
    .form-card { background: white; border-radius: 16px; padding: 2rem; border: 1px solid #e2e8f0; box-shadow: 0 4px 12px rgba(0,0,0,0.03); margin-bottom: 2rem; }
    .form-card-title { font-size: 1.2rem; font-weight: 700; color: var(--primary); margin: 0 0 1.5rem 0; border-bottom: 1px solid #e2e8f0; padding-bottom: 0.8rem; display: flex; align-items: center; gap: 0.5rem; }

    /* Avatar Upload with live preview */
    .avatar-container { position: relative; width: 140px; height: 140px; margin-bottom: 1.5rem; }
    .avatar-img { width: 140px; height: 140px; border-radius: 50%; object-fit: cover; border: 4px solid white; box-shadow: 0 4px 15px rgba(0,0,0,0.1); background-color: #f1f5f9; transition: 0.3s; display: block; }
    .avatar-placeholder { width: 140px; height: 140px; border-radius: 50%; background: var(--primary); color: white; display: flex; align-items: center; justify-content: center; font-size: 3.5rem; font-weight: 700; border: 4px solid white; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
    .avatar-edit { position: absolute; bottom: 5px; right: 5px; background: var(--accent); color: white; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; border: 3px solid white; box-shadow: 0 2px 10px rgba(0,0,0,0.15); transition: 0.2s; }
    .avatar-edit:hover { transform: scale(1.1); background: #e0531f; }
    .avatar-container:hover .avatar-img { filter: brightness(0.9); }

    /* Form elements */
    .form-group { margin-bottom: 1.25rem; }
    .form-group label { display: block; font-size: 0.85rem; font-weight: 600; color: #475569; margin-bottom: 0.5rem; }
    .form-control { width: 100%; padding: 0.75rem 1rem; border-radius: 8px; border: 1px solid #cbd5e1; box-sizing: border-box; font-family: 'Inter', sans-serif; font-size: 0.9rem; transition: 0.2s; color: #475569; }
    .form-control:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.15); }
    .form-control:disabled { background: #f8fafc; color: #94a3b8; cursor: not-allowed; }

    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    @media (max-width: 600px) {
        .form-row { grid-template-columns: 1fr; }
    }

    .btn-save { background: var(--primary); color: white; border: none; padding: 0.8rem 1.5rem; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; }
    .btn-save:hover { background: #1e293b; transform: translateY(-1px); }
    .btn-save:active { transform: translateY(0); }

    .user-meta-name { font-size: 1.2rem; font-weight: 700; color: var(--primary); margin: 0 0 0.2rem; }
    .user-meta-role { font-size: 0.8rem; background: #e2e8f0; color: #475569; padding: 3px 10px; border-radius: 20px; font-weight: 600; text-transform: uppercase; display: inline-block; margin-bottom: 1rem; }
    .user-meta-info { font-size: 0.8rem; color: #94a3b8; margin: 0.3rem 0; display: flex; align-items: center; gap: 0.5rem; justify-content: center; }
</style>

<div style="margin-bottom: 2rem;">
    <h2 style="margin:0; color:var(--primary);">Configuración de Perfil</h2>
    <p style="margin:0.3rem 0 0; font-size:0.9rem; color:#64748b;">Administra tu información personal y la seguridad de tu cuenta.</p>
</div>

<div class="profile-grid">
    <!-- Tarjeta Izquierda (Foto y Resumen) -->
    <div class="profile-card">
        <form id="avatarForm" action="perfil" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div class="avatar-container">
                <?php if ($usuario['foto'] && file_exists('../' . $usuario['foto'])): ?>
                    <img src="../<?= htmlspecialchars($usuario['foto']) ?>" class="avatar-img" id="avatarPreview" alt="Foto de perfil">
                <?php else: ?>
                    <div class="avatar-placeholder" id="avatarInitials">
                        <?= strtoupper(substr($usuario['nombre'], 0, 1)) ?>
                    </div>
                    <img src="" class="avatar-img" id="avatarPreview" alt="Foto de perfil" style="display:none;">
                <?php endif; ?>

                <label for="fotoInput" class="avatar-edit" title="Cambiar foto de perfil">
                    <i class="fa-solid fa-camera"></i>
                </label>
                <input type="file" name="foto" id="fotoInput" accept="image/jpeg,image/png,image/webp" style="display:none;">
            </div>
        </form>

        <h3 class="user-meta-name"><?= htmlspecialchars($usuario['nombre']) ?></h3>
        <span class="user-meta-role"><?= htmlspecialchars($usuario['rol']) ?></span>

        <div style="border-top:1px solid #e2e8f0; width:100%; padding-top:1rem; margin-top:0.5rem;">
            <div class="user-meta-info">
                <i class="fa-solid fa-envelope"></i>
                <span><?= htmlspecialchars($usuario['email']) ?></span>
            </div>
            <div class="user-meta-info">
                <i class="fa-solid fa-calendar-days"></i>
                <span>Miembro desde <?= date('M Y', strtotime($usuario['fecha_registro'])) ?></span>
            </div>
        </div>
    </div>

    <!-- Columna Derecha (Formularios) -->
    <div>
        <!-- Formulario de Información Personal -->
        <div class="form-card">
            <h3 class="form-card-title"><i class="fa-solid fa-address-card" style="color:var(--accent);"></i> Información Personal</h3>

            <form action="perfil" method="POST" id="personalInfoForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <div class="form-group">
                    <label for="nombre">Nombre Completo</label>
                    <input type="text" name="nombre" id="nombre" class="form-control" value="<?= htmlspecialchars($usuario['nombre']) ?>" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="email">Correo Electrónico</label>
                        <input type="email" name="email" id="email" class="form-control" value="<?= htmlspecialchars($usuario['email']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="telefono">Teléfono</label>
                        <input type="text" name="telefono" id="telefono" class="form-control" value="<?= htmlspecialchars($usuario['telefono'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="tipo_documento">Tipo de Documento</label>
                        <select name="tipo_documento" id="tipo_documento" class="form-control">
                            <option value="CC" <?= ($usuario['tipo_documento'] ?? 'CC') === 'CC' ? 'selected' : '' ?>>Cédula de Ciudadanía (CC)</option>
                            <option value="TI" <?= ($usuario['tipo_documento'] ?? '') === 'TI' ? 'selected' : '' ?>>Tarjeta de Identidad (TI)</option>
                            <option value="CE" <?= ($usuario['tipo_documento'] ?? '') === 'CE' ? 'selected' : '' ?>>Cédula de Extranjería (CE)</option>
                            <option value="PAS" <?= ($usuario['tipo_documento'] ?? '') === 'PAS' ? 'selected' : '' ?>>Pasaporte (PAS)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="numero_documento">Número de Documento</label>
                        <input type="text" name="numero_documento" id="numero_documento" class="form-control" value="<?= htmlspecialchars($usuario['numero_documento'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="bio">Biografía / Perfil Profesional</label>
                    <textarea name="bio" id="bio" rows="4" class="form-control" style="resize:vertical;" placeholder="Cuéntanos un poco sobre ti, tu profesión o experiencia..."><?= htmlspecialchars($usuario['bio'] ?? '') ?></textarea>
                </div>

                <button type="submit" name="guardar_perfil" class="btn-save">
                    <i class="fa-solid fa-floppy-disk"></i> Guardar Cambios
                </button>
            </form>
        </div>

        <!-- Formulario de Seguridad -->
        <div class="form-card">
            <h3 class="form-card-title"><i class="fa-solid fa-shield-halved" style="color:var(--accent);"></i> Seguridad y Contraseña</h3>

            <form action="perfil" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <div class="form-group">
                    <label for="pass_actual">Contraseña Actual</label>
                    <input type="password" name="pass_actual" id="pass_actual" class="form-control" placeholder="••••••••" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="pass_nueva">Nueva Contraseña</label>
                        <input type="password" name="pass_nueva" id="pass_nueva" class="form-control" placeholder="Mínimo 6 caracteres" required>
                    </div>
                    <div class="form-group">
                        <label for="pass_confirmar">Confirmar Nueva Contraseña</label>
                        <input type="password" name="pass_confirmar" id="pass_confirmar" class="form-control" placeholder="Confirma tu contraseña" required>
                    </div>
                </div>

                <button type="submit" name="cambiar_password" class="btn-save">
                    <i class="fa-solid fa-key"></i> Actualizar Contraseña
                </button>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    function showCustomAlert(title, message, iconClass, colorHex) {
        const overlay = $('<div style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); backdrop-filter:blur(5px); z-index:9999; display:flex; align-items:center; justify-content:center; opacity:0; transition:0.3s;"></div>');
        const modal = $('<div style="background:var(--bg); border:1px solid #e2e8f0; padding:2.5rem; border-radius:24px; text-align:center; max-width:400px; transform:translateY(20px); transition:0.3s; box-shadow:0 25px 50px -12px rgba(0,0,0,0.5);"></div>');

        modal.append(`<i class="${iconClass}" style="font-size:3.5rem; color:${colorHex}; margin-bottom:1.5rem;"></i>`);
        modal.append(`<h3 style="font-family:\'Inter\', sans-serif; margin-bottom:1rem; font-size:1.4rem; color:var(--primary);">${title}</h3>`);
        modal.append(`<p style="color:#475569; margin-bottom:2rem; line-height:1.6;">${message}</p>`);

        const btn = $(`<button style="width:100%; justify-content:center; background:${colorHex}; border:none; color:white; padding:12px; border-radius:10px; font-weight:600; cursor:pointer; transition:0.2s;">Aceptar</button>`);
        
        function closeModal() {
            overlay.css('opacity', '0');
            modal.css('transform', 'translateY(20px)');
            setTimeout(() => overlay.remove(), 300);
            $(document).off('keydown.customAlert');
        }

        btn.on('click', closeModal);
        overlay.on('click', function(e) {
            if (e.target === this) closeModal();
        });
        $(document).on('keydown.customAlert', function(e) {
            if (e.key === "Escape") closeModal();
        });

        modal.append(btn);
        overlay.append(modal);
        $('body').append(overlay);

        setTimeout(() => {
            overlay.css('opacity', '1');
            modal.css('transform', 'translateY(0)');
        }, 10);
    }

    $(document).ready(function() {
        // Previsualizar la foto de perfil en vivo y enviarla automáticamente al cambiar
        $('#fotoInput').on('change', function() {
            const input = this;
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    $('#avatarInitials').hide();
                    $('#avatarPreview').attr('src', e.target.result).show();
                };
                reader.readAsDataURL(input.files[0]);

                // Enviar el formulario de foto automáticamente
                $('#avatarForm').submit();
            }
        });

        // Alerta de respuesta backend
        <?php if (!empty($mensaje)): ?>
            <?php if ($mensaje_tipo === 'success'): ?>
                showCustomAlert('¡Éxito!', '<?= htmlspecialchars($mensaje) ?>', 'fa-solid fa-circle-check', '#10b981');
            <?php else: ?>
                showCustomAlert('Error', '<?= htmlspecialchars($mensaje) ?>', 'fa-solid fa-triangle-exclamation', '#ef4444');
            <?php endif; ?>
        <?php endif; ?>
    });
</script>

<?php require_once 'admin_footer.php'; ?>

<?php
require_once __DIR__ . '/admin_auth.php';
require_rol('admin');
require_once 'admin_header.php';

if ($rol !== 'admin') {
    echo "<div class='admin-content'><div class='alert alert-danger'>Acceso denegado.</div></div>";
    require_once 'admin_footer.php';
    exit;
}

require_once __DIR__ . '/../helpers/upload_helper.php';

$msg = '';

// Procesar Eliminar
if (isset($_GET['del'])) {
    verify_csrf_get();
    $id_del = intval($_GET['del']);
    
    // Obtener imagen para borrarla si existe
    $stmt_img = $pdo->prepare("SELECT firma_img FROM firmantes WHERE id = ?");
    $stmt_img->execute([$id_del]);
    $img_to_del = $stmt_img->fetchColumn();
    
    if ($img_to_del && file_exists('../' . $img_to_del)) {
        unlink('../' . $img_to_del);
    }

    $pdo->prepare("DELETE FROM firmantes WHERE id = ?")->execute([$id_del]);
    $msg = "Firmante eliminado correctamente.";
}

// Procesar Guardar (Nuevo/Editar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_firmante'])) {
    $id = $_POST['id'] ?? '';
    $nombre = trim($_POST['nombre']);
    $cargo = trim($_POST['cargo']);
    // La ruta actual se toma de la BD, no del formulario: luego se usa para borrar el archivo
    $img_path = '';
    if ($id) {
        $stmt_img = $pdo->prepare("SELECT firma_img FROM firmantes WHERE id = ?");
        $stmt_img->execute([intval($id)]);
        $img_path = (string)($stmt_img->fetchColumn() ?: '');
    }

    // Subir nueva firma si se proporcionó
    if (hay_archivo_subido('firma_img')) {
        try {
            $img_path = 'uploads/' . guardar_imagen_subida($_FILES['firma_img'], __DIR__ . '/../uploads', 'firma', ['image/png', 'image/jpeg']);
        } catch (RuntimeException $e) {
            $msg = 'La firma no se guardó: ' . $e->getMessage();
        }
    }

    if ($id) {
        $pdo->prepare("UPDATE firmantes SET nombre=?, cargo=?, firma_img=? WHERE id=?")->execute([$nombre, $cargo, $img_path, $id]);
        $msg = "Firmante actualizado correctamente.";
    } else {
        $pdo->prepare("INSERT INTO firmantes (nombre, cargo, firma_img) VALUES (?,?,?)")->execute([$nombre, $cargo, $img_path]);
        $msg = "Firmante agregado correctamente.";
    }
}

$firmantes = $pdo->query("SELECT * FROM firmantes ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2rem;">
    <div>
        <h2 style="margin:0; color:var(--primary);">Catálogo de Firmantes</h2>
        <p style="color:#64748b; margin-top:0.3rem;">Gestiona las firmas que aparecerán en los certificados.</p>
    </div>
    <button onclick="abrirModalFirmante()" class="btn btn-primary">+ Nuevo Firmante</button>
</div>

<?php if($msg): ?><div style="background:#dcfce7;color:#166534;padding:1rem;border-radius:6px;margin-bottom:1.5rem; font-weight:600;"><i class="fa-solid fa-circle-check"></i> <?= $msg ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(300px, 1fr));gap:1.5rem;">
    <?php foreach($firmantes as $f): ?>
    <div style="background:white;border-radius:12px;padding:1.5rem;box-shadow:0 4px 12px rgba(0,0,0,0.05);border:1px solid #e2e8f0;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1rem;">
            <div>
                <h3 style="margin:0;font-size:1.15rem;color:var(--primary);"><?= htmlspecialchars($f['nombre']) ?></h3>
                <p style="margin:0;color:#64748b;font-size:0.85rem;"><?= htmlspecialchars($f['cargo']) ?></p>
            </div>
            <a href="?del=<?= (int)$f['id'] ?>&<?= csrf_query() ?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar firmante? Esto afectará a los cursos que lo usen.')"><i class="fa-solid fa-trash"></i></a>
        </div>
        
        <div style="background:#f8fafc; border-radius:8px; padding:1rem; text-align:center; height:100px; display:flex; align-items:center; justify-content:center;">
            <?php if ($f['firma_img']): ?>
                <img src="../<?= htmlspecialchars($f['firma_img']) ?>" style="max-width:100%; max-height:80px; object-fit:contain;">
            <?php else: ?>
                <span style="color:#94a3b8; font-style:italic;">Sin firma subida</span>
            <?php endif; ?>
        </div>

        <button onclick='editarFirmante(<?= htmlspecialchars(json_encode($f), ENT_QUOTES, "UTF-8") ?>)' class="btn btn-sm btn-outline-dark" style="width:100%; margin-top:1rem;">Editar</button>
    </div>
    <?php endforeach; ?>
</div>

<!-- Modal -->
<div id="modal-firmante" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:1000;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px);">
    <div style="background:white;width:100%;max-width:500px;padding:2.5rem;border-radius:16px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;">
            <h3 id="modal-titulo" style="margin:0;color:var(--primary);font-size:1.5rem;">Nuevo Firmante</h3>
            <button type="button" onclick="cerrarModalFirmante()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#94a3b8;">&times;</button>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <?= csrf_input() ?>
            <input type="hidden" name="id" id="firmante_id">
            <input type="hidden" name="firma_img_actual" id="firma_img_actual">
            
            <div class="form-group">
                <label>Nombre</label>
                <input type="text" name="nombre" id="nombre" required placeholder="Ej: Juan Pérez">
            </div>

            <div class="form-group">
                <label>Cargo</label>
                <input type="text" name="cargo" id="cargo" required placeholder="Ej: Instructor Senior">
            </div>

            <div class="form-group">
                <label>Imagen de la Firma</label>
                <input type="file" name="firma_img" accept="image/*">
                <p style="font-size:0.75rem; color:#64748b; margin-top:0.4rem;">Se recomienda PNG con fondo transparente.</p>
            </div>

            <div style="display:flex;gap:1rem;margin-top:2rem;">
                <button type="button" onclick="cerrarModalFirmante()" class="btn btn-block" style="background:#f1f5f9;color:#475569;font-weight:600;">Cancelar</button>
                <button type="submit" name="guardar_firmante" class="btn btn-primary btn-block">Guardar Firmante</button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('modal-firmante').style.display = 'none';

function abrirModalFirmante() {
    document.getElementById('firmante_id').value = '';
    document.getElementById('firma_img_actual').value = '';
    document.getElementById('nombre').value = '';
    document.getElementById('cargo').value = '';
    document.getElementById('modal-titulo').textContent = 'Nuevo Firmante';
    document.getElementById('modal-firmante').style.display = 'flex';
}

function cerrarModalFirmante() {
    document.getElementById('modal-firmante').style.display = 'none';
}

function editarFirmante(f) {
    document.getElementById('firmante_id').value = f.id;
    document.getElementById('nombre').value = f.nombre;
    document.getElementById('cargo').value = f.cargo;
    document.getElementById('firma_img_actual').value = f.firma_img;
    document.getElementById('modal-titulo').textContent = 'Editar Firmante';
    document.getElementById('modal-firmante').style.display = 'flex';
}
</script>

<?php require_once 'admin_footer.php'; ?>

<?php
require_once __DIR__ . '/admin_auth.php';
require_rol('admin');
require_once __DIR__ . '/../helpers/upload_helper.php';
require_once __DIR__ . '/../helpers/html_sanitizer.php';
require_once __DIR__ . '/../helpers/imagen_helper.php';

$mensaje = '';

// Guardar Ruta
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['guardar_ruta'])) {
        $titulo = trim($_POST['titulo']);
        $desc = trim($_POST['descripcion'] ?? '');
        // El editor deja `<p><br></p>` cuando el campo queda vacío: se guarda vacío
        if (extracto_texto($desc) === '' && stripos($desc, '<img') === false && stripos($desc, '<iframe') === false) {
            $desc = '';
        }
        $duracion = trim($_POST['duracion_estimada']);
        $nivel = trim($_POST['nivel']);
        $instructor = trim($_POST['instructor']);
        $precio = floatval($_POST['precio']);
        $precio_oferta = !empty($_POST['precio_oferta']) ? floatval($_POST['precio_oferta']) : null;
        $estado = $_POST['estado'] ?? 'borrador';
        $es_gratis = isset($_POST['es_gratis']) ? 1 : 0;
        $categoria = isset($_POST['categorias']) ? implode(' ', $_POST['categorias']) : '';

        // SEO
        $meta_title = trim($_POST['meta_title'] ?? '');
        $meta_description = trim($_POST['meta_description'] ?? '');

        if (isset($_POST['id']) && $_POST['id']) {
            $id_ruta = $_POST['id'];
            $pdo->prepare("UPDATE rutas_aprendizaje SET titulo=?, descripcion=?, duracion_estimada=?, nivel=?, instructor=?, precio=?, precio_oferta=?, estado=?, es_gratis=?, meta_title=?, meta_description=?, categoria=? WHERE id=?")
                ->execute([$titulo, $desc, $duracion, $nivel, $instructor, $precio, $precio_oferta, $estado, $es_gratis, $meta_title, $meta_description, $categoria, $id_ruta]);
        } else {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $titulo)));
            // Asegurar que el slug sea único
            $s_slug = $pdo->prepare("SELECT id FROM rutas_aprendizaje WHERE slug=?");
            $s_slug->execute([$slug]);
            if ($s_slug->rowCount() > 0) {
                $slug .= '-' . time();
            }

            $pdo->prepare("INSERT INTO rutas_aprendizaje (titulo, slug, descripcion, duracion_estimada, nivel, instructor, precio, precio_oferta, estado, es_gratis, meta_title, meta_description, categoria) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$titulo, $slug, $desc, $duracion, $nivel, $instructor, $precio, $precio_oferta, $estado, $es_gratis, $meta_title, $meta_description, $categoria]);
            $id_ruta = $pdo->lastInsertId();
        }

        foreach (['imagen' => 'ruta_', 'banner' => 'ruta_banner_'] as $campo => $prefijo) {
            if (!hay_archivo_subido($campo)) {
                continue;
            }
            try {
                $nombre_img = guardar_imagen_subida($_FILES[$campo], __DIR__ . '/../uploads', $prefijo . (int)$id_ruta);
                // $campo proviene de la lista fija de arriba (imagen | banner), no del usuario
                $pdo->prepare("UPDATE rutas_aprendizaje SET $campo=? WHERE id=?")->execute(["uploads/".$nombre_img, $id_ruta]);
            } catch (RuntimeException $e) {
                error_log('Imagen de ruta rechazada: ' . $e->getMessage());
            }
        }
        header("Location: rutas.php?msg=saved", true, 303);
        exit;
    }

    if (isset($_POST['del_ruta']) && $rol === 'admin') {
        $pdo->prepare("DELETE FROM rutas_aprendizaje WHERE id=?")->execute([$_POST['del_ruta']]);
        header("Location: rutas.php?msg=deleted", true, 303);
        exit;
    }
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'saved') $mensaje = "Ruta guardada correctamente.";
    if ($_GET['msg'] == 'deleted') $mensaje = "Ruta eliminada.";
}

// Obtener Rutas
if ($rol === 'admin') {
    $stmt = $pdo->query("SELECT * FROM rutas_aprendizaje ORDER BY id DESC");
} else {
    // Si los docentes no ven todas las rutas, aquí se filtraría. Por ahora todos los roles administrativos ven todas las rutas.
    $stmt = $pdo->query("SELECT * FROM rutas_aprendizaje ORDER BY id DESC");
}
$rutas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lista de Categorías
$categorias_lista = [];
try {
    $stmt_cats = $pdo->query("SELECT * FROM categorias ORDER BY nombre ASC");
    if ($stmt_cats) {
        $categorias_lista = $stmt_cats->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Tabla categorias probablemente no existe aún
}

require_once 'admin_header.php';
?>

<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2rem;">
    <h2>Gestión de Rutas de Aprendizaje</h2>
    <?php if($rol === 'admin'): ?>
    <button onclick="abrirModalNuevo()" class="btn btn-primary">+ Nueva Ruta</button>
    <?php endif; ?>
</div>

<?php if($mensaje): ?><div style="background:#d4edda;color:#155724;padding:1rem;border-radius:6px;margin-bottom:1.5rem;">✅ <?= $mensaje ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(320px, 1fr));gap:1.5rem;">
    <?php foreach($rutas as $r): ?>
    <div style="background:white;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,0.05);display:flex;flex-direction:column;border:1px solid #e2e8f0;">
        <div style="height:160px;background:#eee url('../<?= htmlspecialchars(imagen_curso($r['imagen']), ENT_QUOTES, 'UTF-8') ?>') center/cover no-repeat; position:relative;">
            <?php if($r['es_gratis']): ?>
                <span style="position:absolute; top:10px; right:10px; background:#10b981; color:white; padding:4px 8px; border-radius:4px; font-size:0.75rem; font-weight:bold;">GRATIS</span>
            <?php endif; ?>
        </div>
        <div style="padding:1.5rem;flex:1;display:flex;flex-direction:column;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:0.75rem;">
                <h3 style="margin:0;font-size:1.15rem;color:var(--primary);"><?= htmlspecialchars($r['titulo']) ?></h3>
                <span class="role-tag" style="background:<?= $r['estado']==='privado' ? '#fee2e2' : ($r['estado']==='activo'?'#dcfce7':'#fef9c3') ?>;color:<?= $r['estado']==='privado' ? '#991b1b' : ($r['estado']==='activo'?'#166534':'#854d0e') ?>"><?= $r['estado'] ?></span>
            </div>

            <div style="margin-bottom:1.2rem;">
                <p style="color:#64748b;font-size:0.85rem;line-height:1.4;margin:0;"><?= htmlspecialchars(extracto_texto($r['descripcion'], 100) ?: 'Sin descripción') ?></p>
            </div>

            <div style="display:flex;gap:0.75rem;margin-top:auto;">
                <button onclick='editarRuta(<?= htmlspecialchars(json_encode($r), ENT_QUOTES, "UTF-8") ?>)' class="btn btn-sm btn-outline-dark" style="flex:1;">Editar</button>
                <a href="ruta_cursos?ruta_id=<?= $r['id'] ?>" class="btn btn-sm btn-primary" style="flex:1;text-align:center;">Cursos Incluidos</a>
                <?php if($rol === 'admin'): ?>
                <form method="POST" style="flex:1; display:flex;" onsubmit="return confirm('¿Eliminar ruta? Esto no elimina los cursos, solo la agrupación.')">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <button type="submit" name="del_ruta" value="<?= $r['id'] ?>" class="btn btn-sm btn-danger" style="width:100%;"><i class="fa-solid fa-trash"></i></button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Modal -->
<div id="modal-ruta" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:1000;overflow-y:auto;backdrop-filter:blur(4px);">
    <div style="background:white;max-width:700px;margin:3rem auto;padding:2.5rem;border-radius:16px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;">
            <h3 id="modal-titulo" style="margin:0;color:var(--primary);font-size:1.5rem;">Nueva Ruta</h3>
            <button onclick="cerrarModal()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#94a3b8;">&times;</button>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="id" id="ruta_id">

            <div class="form-group">
                <label>Título de la Ruta *</label>
                <input type="text" name="titulo" id="titulo" required placeholder="Ej: Analista Profesional de Datos">
            </div>

            <div class="form-group">
                <label>Descripción</label>
                <input type="hidden" name="descripcion" id="descripcion">
                <div id="quill_descripcion" style="height: 150px; background: white; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px;"></div>
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label>Áreas / Categorías</label>
                <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:0.5rem; background:#f8fafc; padding:1rem; border-radius:8px; border:1px solid #e2e8f0;">
                    <?php foreach($categorias_lista as $cat): ?>
                    <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer;">
                        <input type="checkbox" name="categorias[]" value="<?= htmlspecialchars($cat['slug']) ?>">
                        <?= htmlspecialchars($cat['nombre']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
                <div class="form-group">
                    <label>Duración Estimada</label>
                    <input type="text" name="duracion_estimada" id="duracion_estimada" placeholder="Ej: 120 horas">
                </div>
                <div class="form-group">
                    <label>Nivel</label>
                    <input type="text" name="nivel" id="nivel" placeholder="Ej: Principiante a Avanzado">
                </div>
            </div>

            <div class="form-group">
                <label>Instructor(es) General(es)</label>
                <input type="text" name="instructor" id="instructor" placeholder="Ej: Equipo <?= htmlspecialchars(ACADEMY_NAME) ?>">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1.5rem;">
                <div class="form-group">
                    <label>Precio Normal *</label>
                    <input type="number" name="precio" id="precio" required value="0">
                </div>
                <div class="form-group">
                    <label>Precio Oferta</label>
                    <input type="number" name="precio_oferta" id="precio_oferta" placeholder="Opcional">
                </div>
                <div class="form-group">
                    <label>Estado</label>
                    <select name="estado" id="estado" style="width:100%;padding:0.75rem;border:1px solid #cbd5e1;border-radius:8px;">
                        <option value="activo">Activo (Visible en web)</option>
                        <option value="borrador">Borrador (Solo admin)</option>
                        <option value="archivado">Archivado</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label style="display:flex; align-items:center; gap:0.5rem; font-weight:bold;">
                    <input type="checkbox" name="es_gratis" id="es_gratis" value="1">
                    Marcar como ruta gratuita
                </label>
            </div>

            <hr style="margin:1.5rem 0; opacity:0.2;">
            <h4 style="margin-bottom:1rem;">Imágenes</h4>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
                <div class="form-group">
                    <label>Imagen Tarjeta</label>
                    <input type="file" name="imagen" accept="image/*">
                </div>
                <div class="form-group">
                    <label>Banner Detalle</label>
                    <input type="file" name="banner" accept="image/*">
                </div>
            </div>

            <hr style="margin:1.5rem 0; opacity:0.2;">
            <h4 style="margin-bottom:1rem;">SEO</h4>

            <div class="form-group">
                <label>Meta Title</label>
                <input type="text" name="meta_title" id="meta_title">
            </div>

            <div class="form-group">
                <label>Meta Description</label>
                <textarea name="meta_description" id="meta_description" rows="2" style="width:100%;padding:0.75rem;border:1px solid #cbd5e1;border-radius:8px;font-family:inherit;"></textarea>
            </div>

            <div style="display:flex;gap:1rem;margin-top:2rem;">
                <button type="button" onclick="cerrarModal()" class="btn btn-block" style="background:#f1f5f9;color:#475569;font-weight:600;">Cerrar</button>
                <button type="submit" name="guardar_ruta" class="btn btn-primary btn-block" style="box-shadow:0 10px 15px -3px rgba(255,107,53,0.3);" onclick="sincronizarQuill()">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<script>
let quillDesc;
document.addEventListener('DOMContentLoaded', function() {
    quillDesc = new Quill('#quill_descripcion', {
        theme: 'snow',
        placeholder: 'Escribe la descripción de la ruta aquí...'
    });
});

function sincronizarQuill() {
    document.getElementById('descripcion').value = quillDesc.root.innerHTML;
}

function abrirModalNuevo() {
    limpiarForm();
    document.getElementById('modal-titulo').textContent = 'Crear Nueva Ruta';
    document.getElementById('modal-ruta').style.display = 'block';
}

function cerrarModal() {
    document.getElementById('modal-ruta').style.display = 'none';
}

function editarRuta(r) {
    limpiarForm();
    document.getElementById('modal-titulo').textContent = 'Editar Ruta';
    document.getElementById('ruta_id').value = r.id;
    document.getElementById('titulo').value = r.titulo;

    // Quill
    quillDesc.root.innerHTML = r.descripcion || '';

    document.getElementById('duracion_estimada').value = r.duracion_estimada || '';
    document.getElementById('nivel').value = r.nivel || '';
    document.getElementById('instructor').value = r.instructor || '';
    document.getElementById('precio').value = r.precio;
    document.getElementById('precio_oferta').value = r.precio_oferta || '';
    document.getElementById('estado').value = r.estado;
    document.getElementById('es_gratis').checked = (r.es_gratis == 1);

    document.getElementById('meta_title').value = r.meta_title || '';
    document.getElementById('meta_description').value = r.meta_description || '';

    // Checkboxes categorias
    if(r.categoria) {
        const cats = r.categoria.split(' ');
        cats.forEach(cat => {
            const cb = document.querySelector(`input[name="categorias[]"][value="${cat}"]`);
            if(cb) cb.checked = true;
        });
    }

    document.getElementById('modal-ruta').style.display = 'block';
}

function limpiarForm() {
    document.getElementById('ruta_id').value = '';
    document.getElementById('titulo').value = '';
    if(quillDesc) quillDesc.root.innerHTML = '';
    document.getElementById('descripcion').value = '';
    document.getElementById('duracion_estimada').value = '';
    document.getElementById('nivel').value = '';
    document.getElementById('instructor').value = '';
    document.getElementById('precio').value = '0';
    document.getElementById('precio_oferta').value = '';
    document.getElementById('estado').value = 'borrador';
    document.getElementById('es_gratis').checked = false;
    document.getElementById('meta_title').value = '';
    document.getElementById('meta_description').value = '';

    // Limpiar checkboxes
    document.querySelectorAll('input[name="categorias[]"]').forEach(cb => cb.checked = false);
}
</script>

<?php require_once 'admin_footer.php'; ?>

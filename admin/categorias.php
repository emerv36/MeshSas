<?php
require_once __DIR__ . '/admin_auth.php';
require_rol('admin');

$msg = '';

// Procesar Eliminar
if (isset($_POST['del'])) {
    $id_del = intval($_POST['del']);
    // Check if category is in use? For now we just delete or let it orphan (courses will still have the slug).
    $pdo->prepare("DELETE FROM categorias WHERE id = ?")->execute([$id_del]);
    header("Location: categorias.php?msg=deleted", true, 303);
    exit;
}

// Procesar Guardar (Insertar o Actualizar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar'])) {
    $id = intval($_POST['id'] ?? 0);
    $nombre = trim($_POST['nombre']);
    $slug = trim($_POST['slug']);

    // Auto-generate slug if empty
    if (empty($slug)) {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $nombre)));
    }

    if ($id > 0) {
        $pdo->prepare("UPDATE categorias SET nombre=?, slug=? WHERE id=?")->execute([$nombre, $slug, $id]);
        $msg = "<div class='alert alert-success'>Categoría actualizada correctamente.</div>";
    } else {
        // Verificar slug único
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM categorias WHERE slug=?");
        $stmt_check->execute([$slug]);
        if ($stmt_check->fetchColumn() > 0) {
             $msg = "<div class='alert alert-danger'>El slug ya existe.</div>";
        } else {
            $pdo->prepare("INSERT INTO categorias (nombre, slug) VALUES (?, ?)")->execute([$nombre, $slug]);
            $msg = "<div class='alert alert-success'>Categoría creada correctamente.</div>";
        }
    }
}

// Mensajes GET
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'deleted') $msg = "<div class='alert alert-success'>Categoría eliminada.</div>";
}

$categorias = $pdo->query("SELECT * FROM categorias ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

require_once 'admin_header.php';
?>

<div class="admin-content">
    <div class="admin-header-flex" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
        <h2 style="margin:0;"><i class="fa-solid fa-tags" style="color:var(--accent);"></i> Categorías / Áreas</h2>
        <button class="btn btn-primary" onclick="openModal()"><i class="fa-solid fa-plus"></i> Nueva Categoría</button>
    </div>

    <?= $msg ?>

    <div class="admin-card">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nombre</th>
                    <th>Slug</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($categorias as $c): ?>
                <tr>
                    <td><?= $c['id'] ?></td>
                    <td><strong><?= htmlspecialchars($c['nombre']) ?></strong></td>
                    <td><span style="background:#f1f5f9; padding:2px 8px; border-radius:4px; font-size:0.85rem; color:#64748b;"><?= htmlspecialchars($c['slug']) ?></span></td>
                    <td>
                        <button class="btn btn-primary" style="padding:0.4rem 0.8rem; font-size:0.9rem;"
                                onclick='editCategoria(<?= json_encode($c) ?>)'>
                            <i class="fa-solid fa-pen"></i>
                        </button>
                        <form method="POST" action="" style="display:inline;" onsubmit="return confirm('¿Eliminar esta categoría?');">
                            <?= csrf_input() ?>
                            <input type="hidden" name="del" value="<?= $c['id'] ?>">
                            <button type="submit" class="btn btn-danger" style="padding:0.4rem 0.8rem; font-size:0.9rem;">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (count($categorias) === 0): ?>
                <tr><td colspan="4" style="text-align:center;">No hay categorías registradas.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Formulario -->
<div id="modalCategoria" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:1000;align-items:center;justify-content:center;backdrop-filter:blur(4px);">
    <div style="background:white;width:100%;max-width:500px;padding:2.5rem;border-radius:16px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;">
            <h3 id="modalTitle" style="margin:0;color:var(--primary);font-size:1.5rem;">Nueva Categoría</h3>
            <button type="button" onclick="closeModal()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#94a3b8;">&times;</button>
        </div>
        <form method="POST" action="">
            <?= csrf_input() ?>
            <input type="hidden" name="id" id="cat_id" value="0">
            <div class="form-group">
                <label>Nombre</label>
                <input type="text" name="nombre" id="cat_nombre" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Slug (identificador URL, sin espacios)</label>
                <input type="text" name="slug" id="cat_slug" class="form-control" placeholder="Dejar en blanco para autogenerar">
            </div>
            <div style="display:flex;gap:1rem;margin-top:2rem;">
                <button type="button" onclick="closeModal()" class="btn btn-block" style="background:#f1f5f9;color:#475569;font-weight:600;">Cancelar</button>
                <button type="submit" name="guardar" class="btn btn-primary btn-block">Guardar Categoría</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal() {
    document.getElementById('modalTitle').innerText = 'Nueva Categoría';
    document.getElementById('cat_id').value = '0';
    document.getElementById('cat_nombre').value = '';
    document.getElementById('cat_slug').value = '';
    document.getElementById('modalCategoria').style.display = 'flex';
}

function editCategoria(c) {
    document.getElementById('modalTitle').innerText = 'Editar Categoría';
    document.getElementById('cat_id').value = c.id;
    document.getElementById('cat_nombre').value = c.nombre;
    document.getElementById('cat_slug').value = c.slug;
    document.getElementById('modalCategoria').style.display = 'flex';
}

function closeModal() {
    document.getElementById('modalCategoria').style.display = 'none';
}
</script>

<?php require_once 'admin_footer.php'; ?>

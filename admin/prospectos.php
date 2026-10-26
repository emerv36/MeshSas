<?php
// Solo admin; admin_auth valida el token CSRF de todo POST
require_once __DIR__ . '/admin_auth.php';
require_rol('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $id = (int)$_POST['id'];
    $pdo->prepare("DELETE FROM prospectos WHERE id = ?")->execute([$id]);
    header("Location: prospectos.php?msg=deleted");
    exit;
}

require_once 'admin_header.php';

// Obtener lista de prospectos
$prospectos = $pdo->query("SELECT * FROM prospectos ORDER BY fecha_registro DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<style>
    .table-container { background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #f8fafc; padding: 1rem; text-align: left; font-size: 0.9rem; color: #475569; border-bottom: 2px solid #e2e8f0; }
    td { padding: 1rem; border-bottom: 1px solid #e2e8f0; color: #334155; }
    tr:last-child td { border-bottom: none; }
    .empty-state { padding: 3rem; text-align: center; color: #64748b; }
</style>

<h2><i class="fa-solid fa-envelope-open-text"></i> Prospectos (Leads)</h2>
<p style="color:var(--text-dim); margin-bottom: 2rem;">Personas que han solicitado más información desde el formulario de la página principal.</p>

<?php if(isset($_GET['msg']) && $_GET['msg'] === 'deleted'): ?>
<div style="background:#dcfce7;color:#166534;padding:1rem;border-radius:8px;margin-bottom:1.5rem;font-weight:600;">
    <i class="fa-solid fa-check-circle"></i> Prospecto eliminado correctamente.
</div>
<?php endif; ?>

<div class="table-container">
    <?php if (count($prospectos) > 0): ?>
    <table>
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Email</th>
                <th>Teléfono / WhatsApp</th>
                <th>Fecha de Registro</th>
                <th style="width: 80px; text-align:center;">Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($prospectos as $p): ?>
            <tr>
                <td style="font-weight:600;"><?= htmlspecialchars($p['nombre']) ?></td>
                <td><a href="mailto:<?= htmlspecialchars($p['email']) ?>" style="color:var(--primary);"><?= htmlspecialchars($p['email']) ?></a></td>
                <td><a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $p['telefono']) ?>" target="_blank" style="color:#25D366; text-decoration:none;"><i class="fa-brands fa-whatsapp"></i> <?= htmlspecialchars($p['telefono']) ?></a></td>
                <td><?= date('d/m/Y H:i', strtotime($p['fecha_registro'])) ?></td>
                <td style="text-align:center;">
                    <form method="POST" onsubmit="return confirm('¿Estás seguro de eliminar este prospecto?')">
<?= csrf_input() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                        <button type="submit" class="btn btn-danger" style="padding:0.4rem 0.6rem; font-size:0.9rem;"><i class="fa-solid fa-trash"></i></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state">
        <i class="fa-solid fa-inbox" style="font-size:3rem; color:#cbd5e1; margin-bottom:1rem;"></i>
        <h3>Bandeja vacía</h3>
        <p>Aún no hay prospectos registrados. Cuando alguien llene el formulario de "Más Información", aparecerá aquí.</p>
    </div>
    <?php endif; ?>
</div>

<?php require_once 'admin_footer.php'; ?>

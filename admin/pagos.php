<?php
require_once __DIR__ . '/admin_auth.php';
require_rol(['admin', 'tesoreria']);
require_once __DIR__ . '/admin_header.php';

// Solo admin y tesoreria pueden acceder a pagos
if (!in_array($rol, ['admin', 'tesoreria'])) {
    header('Location: index');
    exit;
}
$mensaje = '';
$tipo_msg = 'success';

// Filtros
$where  = "WHERE 1=1";
$params = [];
if (!empty($_GET['estado'])) { $where .= " AND p.estado = ?"; $params[] = $_GET['estado']; }
if (!empty($_GET['producto'])) { 
    $parts = explode('-', $_GET['producto']);
    if (count($parts) == 2) {
        $where .= " AND p.producto_tipo = ? AND p.producto_id = ?"; 
        $params[] = $parts[0];
        $params[] = intval($parts[1]);
    }
}

$stmt = $pdo->prepare("SELECT p.*, 
    CASE WHEN p.producto_tipo = 'ruta' THEN r.titulo ELSE c.titulo END AS producto_titulo,
    (u.id IS NOT NULL) AS usuario_valido,
    CASE
        WHEN p.producto_tipo = 'curso' THEN (c.id IS NOT NULL)
        WHEN p.producto_tipo = 'ruta' THEN (r.id IS NOT NULL)
        ELSE 0
    END AS producto_valido,
    CASE WHEN p.producto_tipo = 'ruta'
        THEN (SELECT COUNT(*) FROM ruta_cursos rc WHERE rc.ruta_id = p.producto_id)
        ELSE 1
    END AS cursos_producto
    FROM pagos p 
    LEFT JOIN usuarios u ON u.id = p.usuario_id
    LEFT JOIN cursos c ON p.producto_id = c.id AND p.producto_tipo = 'curso'
    LEFT JOIN rutas_aprendizaje r ON p.producto_id = r.id AND p.producto_tipo = 'ruta'
    $where 
    ORDER BY p.estado = 'pendiente' DESC, p.fecha DESC");
$stmt->execute($params);
$pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cursos_list = $pdo->query("SELECT id, titulo FROM cursos ORDER BY titulo")->fetchAll(PDO::FETCH_ASSOC);
$rutas_list = $pdo->query("SELECT id, titulo FROM rutas_aprendizaje ORDER BY titulo")->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid #e2e8f0;
    }

    .filter-card {
        background: white;
        padding: 1.2rem;
        border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        display: flex;
        gap: 1rem;
        align-items: center;
        flex-wrap: wrap;
        margin-bottom: 2rem;
        border: 1px solid #f1f5f9;
    }

    .filter-card select, .filter-card button {
        padding: 0.6rem 1rem;
        border-radius: 8px;
        border: 1px solid #cbd5e1;
        font-size: 0.9rem;
        outline: none;
        transition: 0.2s;
    }

    .filter-card select:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1); }

    .btn-filter { background: #6366f1; color: white; border: none !important; cursor: pointer; font-weight: 600; }
    .btn-filter:hover { background: #4f46e5; }
    
    .btn-clear { background: #f1f5f9; color: #475569; text-decoration: none; font-weight: 500; }
    .btn-clear:hover { background: #e2e8f0; }

    /* Table Improvements */
    .table-container {
        background: white;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.03);
        overflow: hidden;
        border: 1px solid #f1f5f9;
    }

    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    th { 
        background: #f8fafc; 
        padding: 1rem 1.5rem; 
        text-align: left; 
        font-size: 0.75rem; 
        text-transform: uppercase; 
        letter-spacing: 0.05em; 
        color: #64748b;
        border-bottom: 2px solid #f1f5f9;
    }

    td { 
        padding: 1.2rem 1.5rem; 
        border-bottom: 1px solid #f1f5f9; 
        vertical-align: middle; 
        word-wrap: break-word; 
        font-size: 0.9rem;
    }

    tr:hover { background: #fcfdfe; }

    /* Column Widths */
    .col-id { width: 60px; }
    .col-user { width: 220px; }
    .col-course { width: 200px; }
    .col-amount { width: 110px; }
    .col-method { width: 130px; }
    .col-ref { width: 120px; }
    .col-proof { width: 110px; }
    .col-date { width: 140px; }
    .col-status { width: 130px; }
    .col-actions { width: 100px; text-align: center; }

    .user-info { display: flex; flex-direction: column; gap: 0.2rem; }
    .user-info strong { color: #1e293b; font-size: 0.95rem; }
    .user-info span { color: #64748b; font-size: 0.8rem; }

    .badge {
        padding: 0.4rem 0.8rem;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
    }
    .badge-pendiente { background: #fffbeb; color: #92400e; border: 1px solid #fef3c7; }
    .badge-verificado { background: #f0fdf4; color: #166534; border: 1px solid #dcfce7; }
    .badge-rechazado { background: #fef2f2; color: #991b1b; border: 1px solid #fee2e2; }

    .amount-text { font-family: 'Courier New', monospace; font-weight: 700; color: #1e293b; font-size: 1rem; }
    .free-badge { color: #10b981; font-weight: 800; font-size: 0.75rem; }

    .btn-action {
        padding: 0.5rem 1rem;
        border-radius: 6px;
        text-decoration: none;
        font-size: 0.85rem;
        font-weight: 600;
        transition: 0.2s;
    }
    .btn-verify { background: #6366f1; color: white; }
    .btn-verify:hover { background: #4f46e5; }
    
    .proof-link { color: #6366f1; text-decoration: none; display: flex; align-items: center; gap: 0.4rem; font-weight: 500; }
    .proof-link:hover { text-decoration: underline; }

    /* Vista compacta sin desplazamiento horizontal */
    .table-container {
        background: transparent;
        border: 0;
        box-shadow: none;
        overflow: visible;
    }
    .table-container table,
    .table-container tbody { display: block; width: 100%; }
    .table-container thead { display: none; }
    .table-container tbody { display: grid; gap: 1rem; }
    .table-container tbody tr:not(.empty-row) {
        display: grid;
        grid-template-columns: repeat(12, minmax(0, 1fr));
        gap: 1rem;
        align-items: center;
        padding: 1.25rem 1.5rem;
        background: white;
        border: 1px solid #eef2f7;
        border-radius: 14px;
        box-shadow: 0 4px 16px rgba(15, 23, 42, 0.04);
    }
    .table-container tbody tr:not(.empty-row):hover {
        border-color: #c7d2fe;
        box-shadow: 0 8px 22px rgba(15, 23, 42, 0.07);
    }
    .table-container tbody tr:not(.empty-row) td {
        width: auto;
        min-width: 0;
        padding: 0;
        border: 0;
        overflow-wrap: anywhere;
    }
    .table-container tbody tr:not(.empty-row) td::before {
        content: attr(data-label);
        display: block;
        margin-bottom: 0.35rem;
        color: #94a3b8;
        font-size: 0.66rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
    }
    .table-container tbody tr:not(.empty-row) td:nth-child(1)  { grid-column: 1 / span 1; grid-row: 1; }
    .table-container tbody tr:not(.empty-row) td:nth-child(2)  { grid-column: 2 / span 3; grid-row: 1; }
    .table-container tbody tr:not(.empty-row) td:nth-child(3)  { grid-column: 5 / span 3; grid-row: 1; }
    .table-container tbody tr:not(.empty-row) td:nth-child(4)  { grid-column: 8 / span 2; grid-row: 1; }
    .table-container tbody tr:not(.empty-row) td:nth-child(9)  { grid-column: 10 / span 3; grid-row: 1; }
    .table-container tbody tr:not(.empty-row) td:nth-child(5)  { grid-column: 1 / span 2; grid-row: 2; }
    .table-container tbody tr:not(.empty-row) td:nth-child(6)  { grid-column: 3 / span 2; grid-row: 2; }
    .table-container tbody tr:not(.empty-row) td:nth-child(7)  { grid-column: 5 / span 2; grid-row: 2; }
    .table-container tbody tr:not(.empty-row) td:nth-child(8)  { grid-column: 7 / span 3; grid-row: 2; }
    .table-container tbody tr:not(.empty-row) td:nth-child(10) { grid-column: 10 / span 3; grid-row: 2; }
    .table-container .proof-link { display: inline-flex; }
    .table-container .col-actions { text-align: left; }
    .table-container .empty-row {
        display: block;
        background: white;
        border: 1px dashed #cbd5e1;
        border-radius: 14px;
    }
    .table-container .empty-row td { display: block; width: auto; border: 0; }

    @media (max-width: 1100px) {
        .table-container tbody tr:not(.empty-row) {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .table-container tbody tr:not(.empty-row) td:nth-child(n) {
            grid-column: auto;
            grid-row: auto;
        }
    }
    @media (max-width: 720px) {
        .table-container tbody tr:not(.empty-row) {
            grid-template-columns: minmax(0, 1fr);
            padding: 1rem;
        }
    }
</style>

<div class="page-header">
    <h2 style="margin:0;"><i class="fa-solid fa-receipt" style="color:#6366f1; margin-right:10px;"></i> Gestión de Pagos</h2>
</div>

<form method="GET" class="filter-card">
    <div style="display:flex; align-items:center; gap:0.5rem;">
        <i class="fa-solid fa-filter" style="color:#94a3b8; font-size:0.9rem;"></i>
        <span style="font-weight:600; color:#475569; font-size:0.9rem;">Filtrar por:</span>
    </div>
    <select name="estado">
        <option value="">Todos los estados</option>
        <option value="pendiente" <?= ($_GET['estado']??'')==='pendiente'?'selected':'' ?>>⏳ Pendientes</option>
        <option value="verificado" <?= ($_GET['estado']??'')==='verificado'?'selected':'' ?>>✅ Verificados</option>
        <option value="rechazado" <?= ($_GET['estado']??'')==='rechazado'?'selected':'' ?>>❌ Rechazados</option>
    </select>
    <select name="producto" style="max-width:250px;">
        <option value="">Todos los productos</option>
        <optgroup label="Cursos">
            <?php foreach($cursos_list as $c): ?>
            <option value="curso-<?= $c['id'] ?>" <?= ($_GET['producto']??'')==('curso-'.$c['id'])?'selected':'' ?>><?= htmlspecialchars($c['titulo']) ?></option>
            <?php endforeach; ?>
        </optgroup>
        <optgroup label="Rutas">
            <?php foreach($rutas_list as $r): ?>
            <option value="ruta-<?= $r['id'] ?>" <?= ($_GET['producto']??'')==('ruta-'.$r['id'])?'selected':'' ?>><?= htmlspecialchars($r['titulo']) ?></option>
            <?php endforeach; ?>
        </optgroup>
    </select>
    <button type="submit" class="btn-filter">Aplicar Filtros</button>
    <?php if(!empty($_GET)): ?>
        <a href="pagos" class="btn-clear btn-action">Limpiar</a>
    <?php endif; ?>
</form>

<?php if(isset($_GET['ok'])): ?>
<div style="background:#f0fdf4; color:#166534; padding:1rem; border-radius:12px; margin-bottom:1.5rem; border:1px solid #dcfce7; display:flex; align-items:center; gap:0.8rem;">
    <i class="fa-solid fa-circle-check"></i>
    <span><?= htmlspecialchars($_GET['ok']) ?></span>
</div>
<?php endif; ?>
<?php if(isset($_GET['err'])): ?>
<div style="background:#fef2f2; color:#991b1b; padding:1rem; border-radius:12px; margin-bottom:1.5rem; border:1px solid #fecaca; display:flex; align-items:center; gap:0.8rem;">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <span><?= htmlspecialchars($_GET['err']) ?></span>
</div>
<?php endif; ?>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th class="col-id">ID</th>
                <th class="col-user">Estudiante</th>
                <th class="col-course">Producto</th>
                <th class="col-amount">Monto</th>
                <th class="col-method">Método</th>
                <th class="col-ref">Referencia</th>
                <th class="col-proof">Comprobante</th>
                <th class="col-date">Fecha</th>
                <th class="col-status">Estado</th>
                <th class="col-actions">Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php if(count($pagos) == 0): ?>
            <tr class="empty-row">
                <td colspan="10" style="text-align:center; padding:4rem; color:#94a3b8;">
                    <i class="fa-solid fa-folder-open" style="font-size:3rem; margin-bottom:1rem; display:block; opacity:0.3;"></i>
                    No se encontraron registros de pagos.
                </td>
            </tr>
            <?php endif; ?>
            <?php foreach($pagos as $p): 
                $estado_class = "badge-" . $p['estado'];
                $estado_texto = match($p['estado']) {
                    'pendiente'  => '⏳ Pendiente',
                    'verificado' => '✅ Verificado',
                    'rechazado'  => '❌ Rechazado',
                    default      => '?'
                };
                $integridad_ok = (bool)$p['usuario_valido']
                    && (bool)$p['producto_valido']
                    && (int)$p['cursos_producto'] > 0;
            ?>
            <tr<?= $integridad_ok ? '' : ' style="background:#fff7ed;"' ?>>
                <td data-label="ID" style="color:#94a3b8; font-weight:600;">#<?= $p['id'] ?></td>
                <td data-label="Estudiante">
                    <div class="user-info">
                        <strong><?= htmlspecialchars($p['nombre_temp']) ?></strong>
                        <span><i class="fa-solid fa-envelope" style="font-size:0.7rem;"></i> <?= htmlspecialchars($p['email_temp']) ?></span>
                        <?php if($p['telefono_temp']): ?>
                            <span><i class="fa-solid fa-whatsapp" style="font-size:0.7rem;"></i> <?= htmlspecialchars($p['telefono_temp']) ?></span>
                        <?php endif; ?>
                    </div>
                </td>
                <td data-label="Producto" style="color:#475569; font-weight:500;">
                    <div style="font-size:0.7rem; color:#94a3b8; text-transform:uppercase; font-weight:700; margin-bottom:2px;"><?= $p['producto_tipo'] ?></div>
                    <?= htmlspecialchars($p['producto_titulo'] ?? 'Producto inexistente') ?>
                    <?php if (!$integridad_ok): ?>
                    <div style="color:#c2410c;font-size:0.72rem;margin-top:4px;">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <?php if (!$p['usuario_valido']): ?>Usuario inexistente.<?php endif; ?>
                        <?php if (!$p['producto_valido']): ?>Producto inexistente.<?php endif; ?>
                        <?php if ((int)$p['cursos_producto'] === 0): ?>Ruta sin cursos.<?php endif; ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td data-label="Monto">
                    <?php if($p['monto'] <= 0): ?>
                        <span class="free-badge">GRATIS</span>
                    <?php else: ?>
                        <span class="amount-text">$<?= number_format($p['monto'], 0, ',', '.') ?></span>
                    <?php endif; ?>
                </td>
                <td data-label="Método" style="color:#64748b;"><?= ucfirst(htmlspecialchars($p['metodo_pago'])) ?></td>
                <td data-label="Referencia">
                    <code style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-size:0.8rem;"><?= htmlspecialchars($p['referencia']) ?></code>
                </td>
                <td data-label="Comprobante">
                    <?php if($p['comprobante_ruta'] !== 'N/A'): ?>
                    <a href="ver_comprobante?id=<?= (int)$p['id'] ?>" target="_blank" rel="noopener" class="proof-link">
                        <i class="fa-solid fa-image"></i> Ver Pago
                    </a>
                    <?php else: ?>
                    <span style="color:#cbd5e1; font-size:0.8rem;">Sin adjunto</span>
                    <?php endif; ?>
                </td>
                <td data-label="Fecha" style="font-size:0.8rem; color:#64748b;">
                    <i class="fa-regular fa-calendar" style="margin-right:4px;"></i> <?= date('d/m/y', strtotime($p['fecha'])) ?><br>
                    <i class="fa-regular fa-clock" style="margin-right:4px;"></i> <?= date('H:i', strtotime($p['fecha'])) ?>
                </td>
                <td data-label="Estado">
                    <span class="badge <?= $estado_class ?>">
                        <?= $estado_texto ?>
                    </span>
                </td>
                <td data-label="Acciones" class="col-actions">
                    <?php if($p['estado'] === 'pendiente' && $integridad_ok): ?>
                    <form method="POST" action="verificar_pago" onsubmit="return confirmarVerificacionPago(event, this)">
                        <?= csrf_input() ?>
                        <input type="hidden" name="pago_id" value="<?= (int)$p['id'] ?>">
                        <button type="submit" class="btn-action btn-verify" style="border:0;cursor:pointer;">Verificar</button>
                    </form>
                    <?php else: ?>
                    <span style="color:#cbd5e1;">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
function confirmarVerificacionPago(event, form) {
    event.preventDefault();

    const overlay = document.createElement('div');
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'payment-confirm-title');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.65);backdrop-filter:blur(5px);z-index:9999;display:flex;align-items:center;justify-content:center;padding:1rem;opacity:0;transition:opacity .25s;';

    const modal = document.createElement('div');
    modal.style.cssText = 'background:white;border:1px solid #e2e8f0;padding:2.5rem;border-radius:16px;text-align:center;width:min(100%,420px);transform:translateY(20px);transition:transform .25s;box-shadow:0 25px 50px -12px rgba(15,23,42,.45);';
    modal.innerHTML = `
        <div style="width:72px;height:72px;margin:0 auto 1.25rem;border-radius:50%;background:#eef2ff;color:#6366f1;display:flex;align-items:center;justify-content:center;">
            <i class="fa-solid fa-circle-check" style="font-size:2rem;"></i>
        </div>
        <h3 id="payment-confirm-title" style="margin:0 0 .75rem;color:#1e293b;font-size:1.4rem;">Verificar pago</h3>
        <p style="color:#64748b;margin:0 0 2rem;line-height:1.6;">Se aprobará el pago y se activarán las inscripciones correspondientes para el estudiante.</p>
        <div style="display:flex;gap:.75rem;justify-content:center;">
            <button type="button" data-cancel style="padding:.7rem 1.4rem;border:1px solid #cbd5e1;background:white;color:#475569;border-radius:8px;cursor:pointer;font-weight:600;">Cancelar</button>
            <button type="button" data-confirm style="padding:.7rem 1.4rem;border:0;background:#6366f1;color:white;border-radius:8px;cursor:pointer;font-weight:600;">Sí, verificar pago</button>
        </div>`;

    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    const close = () => {
        overlay.style.opacity = '0';
        modal.style.transform = 'translateY(20px)';
        setTimeout(() => overlay.remove(), 250);
    };
    const cancelButton = modal.querySelector('[data-cancel]');
    const confirmButton = modal.querySelector('[data-confirm]');

    cancelButton.addEventListener('click', close);
    overlay.addEventListener('click', e => {
        if (e.target === overlay) close();
    });
    overlay.addEventListener('keydown', e => {
        if (e.key === 'Escape') close();
    });
    confirmButton.addEventListener('click', () => {
        confirmButton.disabled = true;
        confirmButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Verificando...';
        form.submit();
    });

    requestAnimationFrame(() => {
        overlay.style.opacity = '1';
        modal.style.transform = 'translateY(0)';
        confirmButton.focus();
    });
    return false;
}
</script>

<?php require_once 'admin_footer.php'; ?>

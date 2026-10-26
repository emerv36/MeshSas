<?php
require_once __DIR__ . '/admin_auth.php';
require_rol(['admin', 'docente']);

// Fetch upcoming and past classes
$upcoming_sql = "
    SELECT l.*, m.curso_id, c.titulo as curso_titulo, m.titulo as modulo_titulo 
    FROM lecciones l
    JOIN modulos m ON l.modulo_id = m.id
    JOIN cursos c ON m.curso_id = c.id
    WHERE l.tipo = 'clase_vivo' 
    AND (l.fecha_programada IS NULL OR l.fecha_programada >= DATE_SUB(NOW(), INTERVAL 4 HOUR))
";

$past_sql = "
    SELECT l.*, m.curso_id, c.titulo as curso_titulo, m.titulo as modulo_titulo 
    FROM lecciones l
    JOIN modulos m ON l.modulo_id = m.id
    JOIN cursos c ON m.curso_id = c.id
    WHERE l.tipo = 'clase_vivo' 
    AND l.fecha_programada IS NOT NULL 
    AND l.fecha_programada < DATE_SUB(NOW(), INTERVAL 4 HOUR)
";

if ($rol === 'docente') {
    $upcoming_sql .= " AND EXISTS (SELECT 1 FROM curso_docentes cd WHERE cd.curso_id = c.id AND cd.docente_id = " . intval($user_id) . ")";
    $past_sql .= " AND EXISTS (SELECT 1 FROM curso_docentes cd WHERE cd.curso_id = c.id AND cd.docente_id = " . intval($user_id) . ")";
}

$upcoming_sql .= " ORDER BY l.fecha_programada ASC";
$past_sql .= " ORDER BY l.fecha_programada DESC";

$upcoming = $pdo->query($upcoming_sql)->fetchAll(PDO::FETCH_ASSOC);
$past = $pdo->query($past_sql)->fetchAll(PDO::FETCH_ASSOC);

require_once 'admin_header.php';
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2rem;">
    <h2>🎥 Clases en Vivo</h2>
    <p style="color:var(--text-dim);">Administra y arranca tus clases en vivo programadas.</p>
</div>

<div class="nav-tabs-modern" style="margin-bottom:2rem; display:flex; gap:1rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 0;">
    <button onclick="switchTab('proximas')" id="tab-proximas" class="tab-item active" style="background:none; border:none; padding:1rem; font-weight:600; cursor:pointer; color:var(--primary); border-bottom:3px solid var(--accent);">Próximas Clases (<?= count($upcoming) ?>)</button>
    <button onclick="switchTab('finalizadas')" id="tab-finalizadas" class="tab-item" style="background:none; border:none; padding:1rem; font-weight:600; cursor:pointer; color:var(--text-dim); border-bottom:3px solid transparent;">Clases Finalizadas (<?= count($past) ?>)</button>
</div>

<!-- PRÓXIMAS CLASES -->
<div id="content-proximas" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:1.5rem;">
    <?php if(empty($upcoming)): ?>
        <div style="grid-column: 1 / -1; text-align:center; padding: 4rem; background:white; border-radius:12px; border:1px dashed #cbd5e1;">
            <i class="fa-solid fa-calendar-check" style="font-size:3rem; color:#cbd5e1; margin-bottom:1rem;"></i>
            <h3 style="color:#64748b;">No hay próximas clases programadas</h3>
            <p style="color:#94a3b8;">Ve a Gestionar Curso para programar una nueva Clase en Vivo.</p>
        </div>
    <?php endif; ?>

    <?php foreach($upcoming as $lec): 
        $fecha = $lec['fecha_programada'] ? date('d M Y - h:i A', strtotime($lec['fecha_programada'])) : 'Sin fecha';
    ?>
    <div style="background:white; border-radius:12px; overflow:hidden; border:1px solid #e2e8f0; display:flex; flex-direction:column; box-shadow:0 4px 12px rgba(0,0,0,0.05);">
        <div style="padding:1.5rem; flex:1;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.75rem;">
                <h3 style="margin:0; font-size:1.15rem; color:var(--primary);"><?= htmlspecialchars($lec['titulo']) ?></h3>
                <span class="role-tag" style="background:#dbeafe; color:#1e40af;"><i class="fa-solid fa-video"></i> <?= ucfirst($lec['plataforma_video']) ?></span>
            </div>
            <p style="color:#64748b; font-size:0.85rem; margin-bottom:1rem;"><strong>Curso:</strong> <?= htmlspecialchars($lec['curso_titulo']) ?><br><strong>Módulo:</strong> <?= htmlspecialchars($lec['modulo_titulo']) ?></p>
            <div style="background:#f8fafc; padding:0.75rem; border-radius:8px; border:1px solid #f1f5f9; display:flex; align-items:center; gap:0.5rem;">
                <i class="fa-regular fa-clock" style="color:var(--accent);"></i>
                <span style="font-weight:600; font-size:0.9rem;"><?= $fecha ?></span>
            </div>
        </div>
        <div style="padding:1rem; border-top:1px solid #f1f5f9; background:#f8fafc; display:flex; gap:0.5rem;">
            <a href="../dashboard/clase?curso=<?= $lec['curso_id'] ?>&leccion=<?= $lec['id'] ?>&as_host=1" target="_blank" class="btn btn-primary" style="flex:1; text-align:center; display:flex; justify-content:center; align-items:center; gap:0.5rem;"><i class="fa-solid fa-play"></i> Iniciar Anfitrión</a>
            <a href="gestionar_curso?id=<?= $lec['curso_id'] ?>" class="btn btn-outline-dark" title="Ir al Curso"><i class="fa-solid fa-pen"></i></a>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- CLASES FINALIZADAS -->
<div id="content-finalizadas" style="display:none; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:1.5rem;">
    <?php if(empty($past)): ?>
        <div style="grid-column: 1 / -1; text-align:center; padding: 4rem; background:white; border-radius:12px; border:1px dashed #cbd5e1;">
            <i class="fa-solid fa-box-archive" style="font-size:3rem; color:#cbd5e1; margin-bottom:1rem;"></i>
            <h3 style="color:#64748b;">No hay clases en el historial</h3>
        </div>
    <?php endif; ?>

    <?php foreach($past as $lec): 
        $fecha = $lec['fecha_programada'] ? date('d M Y - h:i A', strtotime($lec['fecha_programada'])) : 'Sin fecha';
    ?>
    <div style="background:white; border-radius:12px; overflow:hidden; border:1px solid #e2e8f0; display:flex; flex-direction:column; box-shadow:0 4px 12px rgba(0,0,0,0.05); opacity:0.8;">
        <div style="padding:1.5rem; flex:1;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.75rem;">
                <h3 style="margin:0; font-size:1.15rem; color:#64748b; text-decoration:line-through;"><?= htmlspecialchars($lec['titulo']) ?></h3>
                <span class="role-tag" style="background:#f1f5f9; color:#64748b;"><i class="fa-solid fa-check"></i> Finalizada</span>
            </div>
            <p style="color:#94a3b8; font-size:0.85rem; margin-bottom:1rem;"><strong>Curso:</strong> <?= htmlspecialchars($lec['curso_titulo']) ?></p>
            <div style="background:#f8fafc; padding:0.75rem; border-radius:8px; border:1px solid #f1f5f9; display:flex; align-items:center; gap:0.5rem; color:#94a3b8;">
                <i class="fa-regular fa-clock"></i>
                <span style="font-size:0.9rem;"><?= $fecha ?></span>
            </div>
        </div>
        <div style="padding:1rem; border-top:1px solid #f1f5f9; background:#f8fafc;">
            <a href="gestionar_curso?id=<?= $lec['curso_id'] ?>" class="btn btn-outline-dark" style="width:100%; text-align:center; display:block;">Ver en Curso</a>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
function switchTab(tab) {
    document.getElementById('tab-proximas').style.borderColor = 'transparent';
    document.getElementById('tab-proximas').style.color = 'var(--text-dim)';
    document.getElementById('tab-finalizadas').style.borderColor = 'transparent';
    document.getElementById('tab-finalizadas').style.color = 'var(--text-dim)';
    
    document.getElementById('tab-' + tab).style.borderColor = 'var(--accent)';
    document.getElementById('tab-' + tab).style.color = 'var(--primary)';
    
    document.getElementById('content-proximas').style.display = 'none';
    document.getElementById('content-finalizadas').style.display = 'none';
    
    document.getElementById('content-' + tab).style.display = 'grid';
}
</script>

<?php require_once 'admin_footer.php'; ?>

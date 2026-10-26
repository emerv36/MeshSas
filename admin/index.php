<?php
require_once 'admin_header.php';

// Estadísticas filtradas por ROL
if ($rol === 'admin') {
    $total_cursos = $pdo->query("SELECT COUNT(*) FROM cursos")->fetchColumn();
    $total_estudiantes = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol='estudiante'")->fetchColumn();
    $pagos_pendientes = $pdo->query("SELECT COUNT(*) FROM pagos WHERE estado='pendiente'")->fetchColumn();
    $verificados_semana = $pdo->query("SELECT COUNT(*) FROM pagos WHERE estado='verificado' AND fecha >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    
    // Actividad reciente (admin)
    $actividad_reciente = $pdo->query("
        SELECT p.*, 
        CASE WHEN p.producto_tipo = 'ruta' THEN r.titulo ELSE c.titulo END AS curso_titulo
        FROM pagos p
        LEFT JOIN cursos c ON p.producto_id = c.id AND p.producto_tipo = 'curso'
        LEFT JOIN rutas_aprendizaje r ON p.producto_id = r.id AND p.producto_tipo = 'ruta'
        ORDER BY p.fecha DESC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
} elseif ($rol === 'tesoreria') {
    // Tesorería: solo pagos (no tiene acceso a cursos ni estudiantes)
    $pagos_pendientes = $pdo->query("SELECT COUNT(*) FROM pagos WHERE estado='pendiente'")->fetchColumn();
    $verificados_semana = $pdo->query("SELECT COUNT(*) FROM pagos WHERE estado='verificado' AND COALESCE(verificado_at, fecha) >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    $rechazados_semana = $pdo->query("SELECT COUNT(*) FROM pagos WHERE estado='rechazado' AND fecha >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    $recaudado_mes = (float)$pdo->query("SELECT COALESCE(SUM(monto), 0) FROM pagos WHERE estado='verificado' AND COALESCE(verificado_at, fecha) >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn();

    $actividad_reciente = $pdo->query("
        SELECT p.*,
        CASE WHEN p.producto_tipo = 'ruta' THEN r.titulo ELSE c.titulo END AS curso_titulo
        FROM pagos p
        LEFT JOIN cursos c ON p.producto_id = c.id AND p.producto_tipo = 'curso'
        LEFT JOIN rutas_aprendizaje r ON p.producto_id = r.id AND p.producto_tipo = 'ruta'
        ORDER BY p.fecha DESC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Para el docente, solo estadísticas de sus cursos
    $total_cursos = $pdo->prepare("SELECT COUNT(*) FROM curso_docentes WHERE docente_id=?");
    $total_cursos->execute([$user_id]);
    $total_cursos = $total_cursos->fetchColumn();

    $total_estudiantes = $pdo->prepare("SELECT COUNT(DISTINCT i.usuario_id) FROM inscripciones i JOIN curso_docentes cd ON i.curso_id = cd.curso_id WHERE cd.docente_id=?");
    $total_estudiantes->execute([$user_id]);
    $total_estudiantes = $total_estudiantes->fetchColumn();

    $pagos_pendientes = $count_pendientes; // Quizzes pendientes
    $verificados_semana = $pdo->prepare("SELECT COUNT(DISTINCT i.id) FROM inscripciones i JOIN curso_docentes cd ON i.curso_id = cd.curso_id WHERE cd.docente_id=? AND i.fecha >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $verificados_semana->execute([$user_id]);
    $verificados_semana = $verificados_semana->fetchColumn();

    // Actividad reciente (docente)
    $actividad_reciente = $pdo->prepare("SELECT i.*, c.titulo as curso_titulo, u.nombre as user_nombre FROM inscripciones i JOIN cursos c ON i.curso_id = c.id JOIN usuarios u ON i.usuario_id = u.id JOIN curso_docentes cd ON c.id = cd.curso_id WHERE cd.docente_id=? ORDER BY i.fecha DESC LIMIT 5");
    $actividad_reciente->execute([$user_id]);
    $actividad_reciente = $actividad_reciente->fetchAll(PDO::FETCH_ASSOC);
}

// Indicadores y accesos rápidos de cada rol: [icono, color, etiqueta, valor, pie, icono del pie]
if ($rol === 'tesoreria') {
    $kpis = [
        ['fa-clock-rotate-left', '#f59e0b', 'Pagos Pendientes', $pagos_pendientes, 'Por verificar', 'fa-bell'],
        ['fa-circle-check', '#10b981', 'Verificados', $verificados_semana, 'Últimos 7 días', 'fa-calendar-week'],
        ['fa-circle-xmark', '#ef4444', 'Rechazados', $rechazados_semana, 'Últimos 7 días', 'fa-calendar-week'],
        ['fa-sack-dollar', '#3b82f6', 'Recaudado', '$' . number_format($recaudado_mes, 0, ',', '.'), 'Este mes', 'fa-chart-line'],
    ];
    $acciones = [
        ['pagos?estado=pendiente', 'fa-receipt', 'Verificar pagos pendientes'],
        ['pagos', 'fa-list', 'Historial de pagos'],
    ];
} else {
    $kpis = [
        ['fa-graduation-cap', '#6366f1', $rol === 'admin' ? 'Total Cursos' : 'Mis Cursos', $total_cursos, 'Catálogo Activo', 'fa-check-circle'],
        ['fa-users', '#10b981', $rol === 'admin' ? 'Estudiantes' : 'Mis Estudiantes', $total_estudiantes, 'Activos', 'fa-user-check'],
        ['fa-clock-rotate-left', '#f59e0b', $rol === 'admin' ? 'Pagos Pendientes' : 'Quizzes x Revisar', $pagos_pendientes, 'Acción requerida', 'fa-bell'],
        ['fa-calendar-check', '#3b82f6', 'Nuevos Inscritos', $verificados_semana, '+ Estudiantes', 'fa-chart-line'],
    ];
    $acciones = [
        ['cursos', 'fa-book', $rol === 'admin' ? 'Gestionar cursos' : 'Mis cursos'],
        ['cursos', 'fa-graduation-cap', 'Calificar evaluaciones', 'Los quizzes se califican en la pestaña Evaluaciones de cada curso'],
    ];
    if ($rol === 'admin') {
        $acciones[] = ['usuarios', 'fa-user-plus', 'Administrar usuarios'];
        $acciones[] = ['pagos?estado=pendiente', 'fa-receipt', 'Verificar pagos'];
    } else {
        $acciones[] = ['clases_vivo', 'fa-video', 'Clases en vivo'];
    }
}
// El historial de la actividad reciente: pagos para admin y tesorería (el docente no tiene página de inscripciones)
$historial_url = in_array($rol, ['admin', 'tesoreria'], true) ? 'pagos' : '';
?>

<style>
    :root {
        /* Los colores se heredan del header dinámicamente */
        --accent-soft: rgba(255, 107, 53, 0.1);
        --bg-glass: rgba(255, 255, 255, 0.7);
    }

    .welcome-card {
        background: linear-gradient(135deg, var(--primary), #1e293b);
        color: white;
        padding: 2.5rem;
        border-radius: 24px;
        margin-bottom: 2.5rem;
        position: relative;
        overflow: hidden;
        box-shadow: 0 15px 35px rgba(0,0,0,0.1);
    }
    .welcome-card:after {
        content: ""; position: absolute; top: -50px; right: -50px; width: 200px; height: 200px;
        background: var(--accent); opacity: 0.1; border-radius: 50%;
    }

    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2.5rem;
    }
    .kpi-card {
        background: white;
        padding: 1.8rem;
        border-radius: 24px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.02);
        border: 1px solid #f1f5f9;
        display: flex;
        flex-direction: column;
        gap: 0.8rem;
        transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
    }
    .kpi-card:hover { transform: translateY(-8px); box-shadow: 0 20px 40px rgba(0,0,0,0.08); border-color: var(--accent-soft); }
    .kpi-icon { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; margin-bottom: 0.5rem; }
    .kpi-label { font-size: 0.75rem; color: #94a3b8; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }
    .kpi-value { font-size: 2.2rem; font-weight: 800; color: var(--primary); line-height: 1; }
    .kpi-trend { font-size: 0.75rem; font-weight: 600; display: flex; align-items: center; gap: 6px; }

    .dashboard-grid {
        display: grid;
        grid-template-columns: 1.6fr 1fr;
        gap: 2rem;
    }

    .glass-section {
        background: white;
        padding: 2rem;
        border-radius: 24px;
        border: 1px solid #f1f5f9;
        box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    }

    .action-btn {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1.2rem;
        border-radius: 16px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        text-decoration: none;
        color: var(--primary);
        font-weight: 600;
        transition: 0.2s;
    }
    .action-btn:hover { background: var(--accent-soft); border-color: var(--accent); transform: translateX(5px); }
    .action-icon { width: 40px; height: 40px; border-radius: 10px; background: white; display: flex; align-items: center; justify-content: center; color: var(--accent); box-shadow: 0 4px 10px rgba(0,0,0,0.05); }

    .activity-list { margin-top: 1.5rem; }
    .activity-item {
        display: flex;
        align-items: center;
        gap: 1.2rem;
        padding: 1.2rem;
        border-radius: 16px;
        transition: 0.2s;
    }
    .activity-item:hover { background: #f8fafc; }
    .avatar-mini { width: 44px; height: 44px; border-radius: 12px; background: white; display: flex; align-items: center; justify-content: center; color: #64748b; box-shadow: 0 4px 10px rgba(0,0,0,0.05); font-size: 1.1rem; }

    @media (max-width: 768px) {
        .dashboard-grid { grid-template-columns: 1fr; }
        .welcome-card-content { flex-direction: column; text-align: center; gap: 1.5rem; }
        .welcome-card-content > div:last-child { width: 100%; text-align: center; }
        .welcome-card-content h1 { font-size: 1.8rem !important; }
        .kpi-grid { grid-template-columns: 1fr; }
        .glass-section > div[style*="grid-template-columns"] { grid-template-columns: 1fr !important; }
    }
</style>

<div class="welcome-card">
    <div class="welcome-card-content" style="display:flex; justify-content:space-between; align-items:center; position:relative; z-index:2;">
        <div>
            <div style="background:rgba(255,255,255,0.1); display:inline-block; padding:4px 12px; border-radius:20px; font-size:0.7rem; letter-spacing:1.5px; margin-bottom:1rem;"><?= date('l, d M Y') ?></div>
            <h1 style="margin: 0; font-size:2.5rem; font-weight:800; line-height:1.2; color: white;">Panel de Control <span style="color:var(--accent);"><?= htmlspecialchars(ACADEMY_NAME) ?></span></h1>
            <p style="margin:0.5rem 0 0; opacity:0.8; font-size:1.1rem;">Bienvenido de nuevo, <strong><?= explode(' ', $_SESSION['user_name'])[0] ?></strong>.</p>
        </div>
        <div style="text-align:right;">
            <div style="background:rgba(255,255,255,0.05); backdrop-filter:blur(10px); border:1px solid rgba(255,255,255,0.1); padding:20px; border-radius:24px; min-width:180px;">
                <span style="display:block; font-size:0.65rem; text-transform:uppercase; color:rgba(255,255,255,0.5); margin-bottom:5px;">Tu Perfil</span>
                <div style="display:flex; align-items:center; gap:10px; justify-content:flex-end;">
                    <span style="font-weight:700; color:var(--accent);"><?= strtoupper($rol) ?></span>
                    <i class="fa-solid fa-shield-halved" style="font-size:1.2rem; color:var(--accent);"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="kpi-grid">
    <?php foreach ($kpis as [$icono, $color, $etiqueta, $valor, $pie, $icono_pie]): ?>
    <div class="kpi-card">
        <div class="kpi-icon" style="background:<?= $color ?>1a; color:<?= $color ?>;"><i class="fa-solid <?= $icono ?>"></i></div>
        <span class="kpi-label"><?= htmlspecialchars($etiqueta) ?></span>
        <span class="kpi-value"><?= htmlspecialchars((string)$valor) ?></span>
        <span class="kpi-trend" style="color:<?= $color ?>;"><i class="fa-solid <?= $icono_pie ?>"></i> <?= htmlspecialchars($pie) ?></span>
    </div>
    <?php endforeach; ?>
</div>

<div class="dashboard-grid">
    <div class="glass-section">
        <h3 style="margin:0 0 1.5rem; display:flex; align-items:center; gap:0.8rem;">
            <i class="fa-solid fa-bolt" style="color:var(--accent);"></i> Acciones Rápidas
        </h3>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.2rem;">
            <?php foreach ($acciones as $accion): ?>
            <a href="<?= $accion[0] ?>" class="action-btn"<?= isset($accion[3]) ? ' title="' . htmlspecialchars($accion[3]) . '"' : '' ?>>
                <div class="action-icon"><i class="fa-solid <?= $accion[1] ?>"></i></div>
                <span><?= htmlspecialchars($accion[2]) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="glass-section">
        <h3 style="margin:0 0 1.5rem; display:flex; align-items:center; gap:0.8rem;">
            <i class="fa-solid fa-clock-rotate-left" style="color:var(--accent);"></i> Actividad Reciente
        </h3>
        <div class="activity-list">
            <?php if(empty($actividad_reciente)): ?>
                <p style="text-align:center; color:#94a3b8; padding:2rem;">No hay actividad reciente.</p>
            <?php else: ?>
                <?php foreach($actividad_reciente as $act): 
                    $nombre = $historial_url ? $act['nombre_temp'] : $act['user_nombre'];
                ?>
                <div class="activity-item">
                    <div class="avatar-mini"><i class="fa-solid fa-user"></i></div>
                    <div style="flex:1;">
                        <p style="margin:0; font-size:0.85rem; font-weight:600;"><?= htmlspecialchars($nombre) ?></p>
                        <p style="margin:0; font-size:0.75rem; color:#94a3b8;"><?= htmlspecialchars($act['curso_titulo']) ?></p>
                    </div>
                    <span style="font-size:0.7rem; color:#cbd5e1;"><?= date('H:i', strtotime($act['fecha'])) ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php if ($historial_url): ?>
        <div style="margin-top:1.5rem; text-align:center;">
            <a href="<?= $historial_url ?>" style="font-size:0.8rem; color:var(--primary); font-weight:700; text-decoration:none;">Ver todo el historial <i class="fa-solid fa-arrow-right"></i></a>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'admin_footer.php'; ?>

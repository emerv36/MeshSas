<?php
require_once __DIR__ . '/admin_auth.php';
require_rol('admin');
require_once '../app/Shared/Support/config.php';

// Global Metrics Queries
$stmt_total_horas = $pdo->query("SELECT SUM(segundos_vistos) as total FROM progreso");
$total_segundos_plataforma = $stmt_total_horas->fetchColumn() ?: 0;
$horas_totales = floor($total_segundos_plataforma / 3600);

$stmt_activos = $pdo->query("SELECT COUNT(DISTINCT usuario_id) FROM progreso WHERE fecha >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$estudiantes_activos = $stmt_activos->fetchColumn() ?: 0;

// All students with metrics
$stmt_estudiantes = $pdo->query("
    SELECT u.id, u.nombre, u.email, u.telefono,
           COUNT(DISTINCT i.curso_id) as cursos_inscritos,
           SUM(p.segundos_vistos) as total_segundos,
           MAX(p.fecha) as ultima_actividad
    FROM usuarios u
    LEFT JOIN inscripciones i ON u.id = i.usuario_id
    LEFT JOIN progreso p ON u.id = p.usuario_id
    WHERE u.rol = 'estudiante'
    GROUP BY u.id
    ORDER BY total_segundos DESC
");
$estudiantes = $stmt_estudiantes->fetchAll(PDO::FETCH_ASSOC);

// If inscripciones are empty, try counting from pagos
if (empty(array_filter($estudiantes, fn($e) => $e['cursos_inscritos'] > 0))) {
    $stmt_estudiantes2 = $pdo->query("
        SELECT u.id, u.nombre, u.email, u.telefono,
               COUNT(DISTINCT CASE WHEN pg.estado = 'verificado' AND pg.producto_tipo = 'curso' THEN pg.curso_id END) as cursos_inscritos,
               SUM(p.segundos_vistos) as total_segundos,
               MAX(p.fecha) as ultima_actividad
        FROM usuarios u
        LEFT JOIN pagos pg ON u.id = pg.usuario_id
        LEFT JOIN progreso p ON u.id = p.usuario_id
        WHERE u.rol = 'estudiante'
        GROUP BY u.id
        ORDER BY total_segundos DESC
    ");
    $estudiantes = $stmt_estudiantes2->fetchAll(PDO::FETCH_ASSOC);
}

function formatSegundosGlobal($segs) {
    if (!$segs) return '0m';
    $horas = floor($segs / 3600);
    $mins = floor(($segs % 3600) / 60);
    if ($horas > 0) return "{$horas}h {$mins}m";
    return "{$mins}m";
}

$page_title = "Métricas Globales";
?>
<?php require_once 'admin_header.php'; ?>
<style>
    /* Variables propias de esta página (el panel define --primary y --accent) */
    .metricas-page {
        --bg-body: #f9fafb;
        --bg-surface: #ffffff;
        --text-main: #111827;
        --text-dim: #6b7280;
        --border: #e5e7eb;
        --border-glass: rgba(229, 231, 235, 0.5);
    }
    .header-title { font-size: 1.8rem; font-weight: 700; color: var(--text-main); margin: 0; }
    .header-subtitle { color: var(--text-dim); margin-top: 0.5rem; font-size: 0.95rem; }
    
    .metrics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1.5rem;
        margin-top: 2rem;
        margin-bottom: 2rem;
    }
    .metric-card {
        background: var(--bg-surface);
        border: 1px solid var(--border);
        border-radius: 16px;
        padding: 1.5rem;
        display: flex;
        align-items: center;
        gap: 1.5rem;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        transition: transform 0.2s;
    }
    .metric-card:hover { transform: translateY(-5px); }
    .metric-icon {
        width: 60px; height: 60px;
        border-radius: 16px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.8rem;
    }
    .icon-blue { background: #e0e7ff; color: #4f46e5; }
    .icon-green { background: #dcfce7; color: #16a34a; }
    .metric-info h4 { margin: 0; font-size: 0.9rem; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.5px; }
    .metric-info h2 { margin: 0.5rem 0 0 0; font-size: 2rem; color: var(--text-main); }
    
    .table-container {
        background: var(--bg-surface);
        border-radius: 16px;
        border: 1px solid var(--border);
        overflow-x: auto;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
    }
    table { width: 100%; border-collapse: collapse; text-align: left; }
    th { padding: 1rem 1.5rem; color: var(--text-dim); font-weight: 600; font-size: 0.85rem; background: var(--bg-body); border-bottom: 1px solid var(--border); }
    td { padding: 1rem 1.5rem; border-bottom: 1px solid var(--border-glass); }
    tr:last-child td { border-bottom: none; }
    
    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
    }
    .badge-success { background: #dcfce7; color: #16a34a; }
    .badge-danger { background: #fee2e2; color: #b91c1c; }
    .badge-neutral { background: #f3f4f6; color: #4b5563; }
</style>
    
    <div class="metricas-page">
        <h1 class="header-title">Métricas Globales de Retención</h1>
        <p class="header-subtitle">Monitorea el tiempo invertido y el riesgo de deserción de todos los estudiantes.</p>

        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-icon icon-blue"><i class="fa-solid fa-clock"></i></div>
                <div class="metric-info">
                    <h4>Horas Impartidas</h4>
                    <h2><?= number_format($horas_totales) ?> <span style="font-size:1rem; color:var(--text-dim);">hrs</span></h2>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-icon icon-green"><i class="fa-solid fa-users"></i></div>
                <div class="metric-info">
                    <h4>Estudiantes Activos (7 Días)</h4>
                    <h2><?= number_format($estudiantes_activos) ?></h2>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-icon" style="background:#fef3c7; color:#d97706;"><i class="fa-solid fa-graduation-cap"></i></div>
                <div class="metric-info">
                    <h4>Total Alumnos</h4>
                    <h2><?= number_format(count($estudiantes)) ?></h2>
                </div>
            </div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Estudiante</th>
                        <th>Cursos</th>
                        <th>Tiempo Total</th>
                        <th>Última Actividad</th>
                        <th style="text-align:center;">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($estudiantes as $est): 
                        $dias_inactivo = 0;
                        if ($est['ultima_actividad']) {
                            $ultima = new DateTime($est['ultima_actividad']);
                            $ahora = new DateTime();
                            $dias_inactivo = $ahora->diff($ultima)->days;
                        }
                    ?>
                    <tr>
                        <td>
                            <div style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($est['nombre']) ?></div>
                            <div style="font-size:0.8rem; color:var(--text-dim);"><?= htmlspecialchars($est['email']) ?></div>
                        </td>
                        <td style="font-weight:600;">
                            <?= $est['cursos_inscritos'] ?>
                        </td>
                        <td style="font-family:monospace; font-size:1.1rem; color:var(--primary); font-weight:700;">
                            <?= formatSegundosGlobal($est['total_segundos']) ?>
                        </td>
                        <td style="font-size:0.9rem; color:var(--text-dim);">
                            <?= $est['ultima_actividad'] ? date('d M Y', strtotime($est['ultima_actividad'])) : '-' ?>
                        </td>
                        <td style="text-align:center;">
                            <?php if(!$est['ultima_actividad']): ?>
                                <span class="badge badge-neutral">Sin Iniciar</span>
                            <?php elseif($dias_inactivo > 14): ?>
                                <span class="badge badge-danger"><i class="fa-solid fa-triangle-exclamation"></i> Riesgo Deserción</span>
                            <?php else: ?>
                                <span class="badge badge-success"><i class="fa-solid fa-fire"></i> Activo</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php require_once 'admin_footer.php'; ?>

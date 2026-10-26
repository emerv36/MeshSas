<?php
require_once '../config.php';
require_once '../helpers/acceso_helper.php';
require_once '../helpers/imagen_helper.php';
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_rol'], ['estudiante', 'administrativo'])) {
    header('Location: ../login'); exit;
}
$usuario_id = $_SESSION['user_id'];

// Obtener datos del usuario (racha)
$stmt_usr = $pdo->prepare("SELECT racha_actual FROM usuarios WHERE id = ?");
$stmt_usr->execute([$usuario_id]);
$usr_data = $stmt_usr->fetch(PDO::FETCH_ASSOC);
$racha = (int)($usr_data['racha_actual'] ?? 0);

// Obtener Rutas
$stmt_rutas = $pdo->prepare("
    SELECT DISTINCT r.* 
    FROM rutas_aprendizaje r
    -- Rutas con algún acceso no revocado (vigente o vencido: se muestra como Acceso vencido)
    WHERE EXISTS (SELECT 1 FROM accesos_productos a WHERE a.usuario_id = ? AND a.producto_tipo = 'ruta' AND a.producto_id = r.id AND a.revocado_at IS NULL)
");
$stmt_rutas->execute([$usuario_id]);
$mis_rutas = $stmt_rutas->fetchAll(PDO::FETCH_ASSOC);

foreach ($mis_rutas as &$mr) {
    $mr['acceso'] = obtenerEstadoAccesoRuta($usuario_id, (int)$mr['id'], $pdo);
    // Calcular progreso: # de cursos completados / total de cursos de la ruta
    $stmt_c = $pdo->prepare("SELECT curso_id FROM ruta_cursos WHERE ruta_id = ?");
    $stmt_c->execute([$mr['id']]);
    $cursos_ruta = $stmt_c->fetchAll(PDO::FETCH_COLUMN);
    
    $cursos_completados = 0;
    $total_cursos = count($cursos_ruta);
    
    foreach ($cursos_ruta as $cid) {
        $s_tot = $pdo->prepare("SELECT COUNT(*) FROM lecciones l JOIN modulos m ON l.modulo_id = m.id WHERE m.curso_id = ? AND l.estado = 'publicado' AND m.estado = 'publicado'");
        $s_tot->execute([$cid]);
        $t_lec = intval($s_tot->fetchColumn());
        
        $s_comp = $pdo->prepare("SELECT COUNT(*) FROM progreso p JOIN lecciones l ON p.leccion_id = l.id JOIN modulos m ON l.modulo_id = m.id WHERE m.curso_id = ? AND p.usuario_id = ? AND p.completado = 1 AND l.estado = 'publicado' AND m.estado = 'publicado'");
        $s_comp->execute([$cid, $usuario_id]);
        $c_lec = intval($s_comp->fetchColumn());
        
        if ($t_lec > 0 && $c_lec == $t_lec) {
            $cursos_completados++;
        }
    }
    
    $mr['total_cursos'] = $total_cursos;
    $mr['cursos_completados'] = $cursos_completados;
    $mr['progreso'] = $total_cursos > 0 ? round(($cursos_completados / $total_cursos) * 100) : 0;
}
unset($mr);

// Obtener Cursos Sueltos (comprados directamente)
$stmt = $pdo->prepare("
    SELECT DISTINCT c.*,
    (SELECT COUNT(*) FROM lecciones l JOIN modulos m ON l.modulo_id = m.id WHERE m.curso_id = c.id AND l.estado = 'publicado' AND m.estado = 'publicado') AS total_lecciones,
    (SELECT COUNT(*) FROM progreso p JOIN lecciones l ON p.leccion_id = l.id JOIN modulos m ON l.modulo_id = m.id WHERE m.curso_id = c.id AND p.usuario_id = ? AND p.completado = 1 AND l.estado = 'publicado' AND m.estado = 'publicado') AS completadas
    FROM cursos c
    -- Cursos con algún acceso no revocado, propio o por una ruta que los incluya
    WHERE EXISTS (SELECT 1 FROM accesos_productos a WHERE a.usuario_id = ? AND a.producto_tipo = 'curso' AND a.producto_id = c.id AND a.revocado_at IS NULL)
       OR EXISTS (SELECT 1 FROM accesos_productos a JOIN ruta_cursos rc ON rc.ruta_id = a.producto_id WHERE a.usuario_id = ? AND a.producto_tipo = 'ruta' AND rc.curso_id = c.id AND a.revocado_at IS NULL)
");
$stmt->execute([$usuario_id, $usuario_id, $usuario_id]);
$mis_cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($mis_cursos as &$curso_acceso) {
    $curso_acceso['acceso'] = obtenerEstadoAccesoCurso($usuario_id, (int)$curso_acceso['id'], $pdo);
}
unset($curso_acceso);

// Certificados: se emiten al completar el curso o la ruta y se conservan aunque después se
// publiquen lecciones nuevas
require_once '../helpers/certificado_helper.php';
$certificados = certificados_del_usuario($pdo, (int)$usuario_id);
foreach($mis_cursos as $mc) {
    if($mc['total_lecciones'] > 0 && $mc['completadas'] == $mc['total_lecciones'] && !isset($certificados['curso:' . $mc['id']])) {
        if ($cert = certificado_emitir($pdo, 'curso', (int)$usuario_id, (int)$mc['id'])) $certificados['curso:' . $mc['id']] = $cert;
    }
}
foreach($mis_rutas as $mr) {
    if($mr['total_cursos'] > 0 && $mr['cursos_completados'] == $mr['total_cursos'] && !isset($certificados['ruta:' . $mr['id']])) {
        if ($cert = certificado_emitir($pdo, 'ruta', (int)$usuario_id, (int)$mr['id'])) $certificados['ruta:' . $mr['id']] = $cert;
    }
}

// Estadísticas rápidas
$total_inscritos = count($mis_cursos) + count($mis_rutas);
$completados = count($certificados);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Aprendizaje - <?= htmlspecialchars(ACADEMY_NAME) ?></title>
    <link rel="icon" href="<?= FAVICON_URL ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
    <style>
        :root { 
            --primary: <?= $ajustes['color_primario'] ?? '#2A3547' ?>; 
            --accent: <?= $ajustes['color_acento'] ?? '#FF6B35' ?>; 
            --bg: #f4f7fa; 
            --text: #475569; 
        }
        body { font-family: 'Inter', sans-serif; background: var(--bg); margin: 0; display: flex; color: var(--text); }
        
        /* Sidebar */
        .sidebar { width: 260px; background: var(--primary); height: 100vh; position: fixed; color: white; padding: 1.5rem; box-sizing: border-box; display: flex; flex-direction: column; }
        .sidebar-brand { margin-bottom: 2.5rem; display: flex; align-items: center; justify-content: center; }
        .sidebar-brand img { max-width: 200px; height: auto; filter: drop-shadow(0 0 1px rgba(255,255,255,0.8)); }
        .sidebar-nav { flex: 1; }
        .nav-item { display: flex; align-items: center; gap: 1rem; color: rgba(255,255,255,0.7); text-decoration: none; padding: 0.8rem 1rem; border-radius: 8px; margin-bottom: 0.5rem; transition: 0.3s; }
        .nav-item:hover, .nav-item.active { background: rgba(255,255,255,0.1); color: white; }
        .logout-btn { margin-top: auto; color: #f87171; border: 1px solid rgba(248,113,113,0.3); }

        /* Main Content */
        .main-content { flex: 1; margin-left: 260px; padding: 2rem 3rem; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        
        /* Stats Cards */
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; margin-bottom: 2.5rem; }
        .stat-card { background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); display: flex; align-items: center; gap: 1.2rem; }
        .stat-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
        .stat-data h3 { margin: 0; font-size: 1.5rem; color: var(--primary); }
        .stat-data p { margin: 0; font-size: 0.85rem; color: #94a3b8; }

        /* Course Cards */
        .course-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 2rem; margin-bottom: 3rem; }
        .course-card { background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05); transition: 0.3s; display: flex; flex-direction: column; border: 1px solid #e2e8f0; }
        .course-card:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(0,0,0,0.1); }
        .course-img { height: 160px; background-size: cover; background-position: center; position: relative; }
        .course-badge { position: absolute; top: 1rem; right: 1rem; background: rgba(255,255,255,0.9); padding: 4px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; color: var(--primary); }
        
        .course-body { padding: 1.5rem; flex: 1; display: flex; flex-direction: column; }
        .course-title { font-size: 1.1rem; font-weight: 700; color: var(--primary); margin: 0 0 0.5rem; }
        .course-instructor { font-size: 0.8rem; color: #64748b; margin-bottom: 1.2rem; display: flex; align-items: center; gap: 0.5rem; }
        
        .progress-container { margin-top: auto; }
        .progress-header { display: flex; justify-content: space-between; font-size: 0.75rem; margin-bottom: 0.5rem; }
        .progress-bar { height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, #FF6B35, #ffb035); transition: 0.5s; }
        
        .btn-play { background: var(--primary); color: white; text-decoration: none; padding: 0.8rem; border-radius: 8px; text-align: center; margin-top: 1.2rem; font-weight: 600; font-size: 0.9rem; transition: 0.2s; display: block; }
        .btn-play:hover { background: #1e293b; }
        .btn-play.disabled { background:#cbd5e1; color:#64748b; cursor:not-allowed; pointer-events:none; }
        .access-note { margin:.7rem 0 0; padding:.55rem .7rem; border-radius:8px; font-size:.75rem; font-weight:600; }
        .access-note.active { background:#ecfdf5; color:#047857; }
        .access-note.expired { background:#fef2f2; color:#b91c1c; }
        .btn-cert { background: #10b981; color: white; text-decoration: none; padding: 0.8rem; border-radius: 8px; text-align: center; margin-top: 0.5rem; font-weight: 600; font-size: 0.9rem; display: block; }

        .empty-state { grid-column: 1 / -1; text-align: center; padding: 5rem; background: white; border-radius: 16px; border: 2px dashed #e2e8f0; }
        .streak-badge { transition: transform 0.2s, box-shadow 0.2s; }
        .streak-badge:hover { transform: scale(1.05); box-shadow: 0 4px 10px rgba(0,0,0,0.05); }

        /* Mobile topbar */
        .mobile-topbar {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0;
            height: 56px;
            background: var(--primary);
            align-items: center;
            justify-content: space-between;
            padding: 0 1.2rem;
            z-index: 1100;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        .mobile-topbar img { height: 32px; }
        .mob-hamburger {
            background: none; border: none; cursor: pointer;
            display: flex; flex-direction: column; gap: 5px; padding: 4px;
        }
        .mob-hamburger span {
            display: block; width: 22px; height: 2px;
            background: white; border-radius: 2px; transition: 0.3s;
        }
        .mob-hamburger.open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
        .mob-hamburger.open span:nth-child(2) { opacity: 0; }
        .mob-hamburger.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

        /* Sidebar overlay for mobile */
        .sidebar-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1050;
        }
        .sidebar-overlay.show { display: block; }

        @media (max-width: 768px) {
            .mobile-topbar { display: flex; }
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
                z-index: 1200;
                top: 0;
            }
            .sidebar.open { transform: translateX(0); }
            .main-content {
                margin-left: 0;
                padding: 5rem 1rem 2rem;
            }
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 1rem; }
            .course-grid { grid-template-columns: 1fr; gap: 1.2rem; }
            .header { flex-direction: column; align-items: flex-start; gap: 0.8rem; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- Mobile topbar (visible only on small screens) -->
<div class="mobile-topbar">
    <a href="../index"><img src="../img/logo.png?v=<?= LOGO_VERSION ?>" alt="<?= htmlspecialchars(ACADEMY_NAME) ?> Logo"></a>
    <button class="mob-hamburger" id="mobHamburger" aria-label="Abrir menú">
        <span></span><span></span><span></span>
    </button>
</div>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="sidebar" id="dashSidebar">
    <a href="../index" class="sidebar-brand">
        <img src="../img/logo.png?v=<?= LOGO_VERSION ?>" alt="<?= htmlspecialchars(ACADEMY_NAME) ?> Logo">
    </a>
    <nav class="sidebar-nav">
        <a href="index" class="nav-item active"><i class="fa-solid fa-house"></i> Mi Dashboard</a>
        <a href="../cursos" class="nav-item"><i class="fa-solid fa-search"></i> Explorar Cursos</a>
        <a href="../rutas" class="nav-item"><i class="fa-solid fa-route"></i> Explorar Rutas</a>
        <a href="logros" class="nav-item"><i class="fa-solid fa-certificate"></i> Mis Logros</a>
        <a href="perfil" class="nav-item"><i class="fa-solid fa-user"></i> Mi Perfil</a>
    </nav>
    <a href="../logout" class="nav-item logout-btn"><i class="fa-solid fa-sign-out"></i> Cerrar Sesión</a>
</div>

<main class="main-content">
    <div class="header">
        <div>
            <h2 style="margin:0; color:var(--primary);">¡Hola, <?= explode(' ', $_SESSION['user_name'])[0] ?>!</h2>
            <p style="margin:0.3rem 0 0; font-size:0.9rem; color:#64748b;">Es un gran día para aprender algo nuevo.</p>
        </div>
        <div class="streak-badge" style="background:white; padding:0.5rem 1rem; border-radius:30px; border:1px solid #e2e8f0; display:flex; align-items:center; gap:0.8rem; cursor:pointer;" onclick="showCustomAlert('Racha de Estudio', '¡Llevas una racha de <?= $racha ?> días de estudio! Mantén tu racha activa ingresando a tus clases todos los días para no perder el impulso.', 'fa-solid fa-fire', '#ef4444')">
            <i class="fa-solid fa-fire" style="color:#ef4444;"></i>
            <span style="font-weight:600; font-size:0.9rem;">Racha de <?= $racha ?> días</span>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background:#eff6ff; color:#3b82f6;"><i class="fa-solid fa-book-open"></i></div>
            <div class="stat-data"><h3><?= $total_inscritos ?></h3><p>Programas Inscritos</p></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#fefce8; color:#eab308;"><i class="fa-solid fa-clock"></i></div>
            <div class="stat-data"><h3><?= $completados ?></h3><p>Completados</p></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#ecfdf5; color:#10b981;"><i class="fa-solid fa-award"></i></div>
            <div class="stat-data"><h3><?= $completados ?></h3><p>Certificados</p></div>
        </div>
    </div>

    <?php if(!empty($mis_rutas)): ?>
    <h3 style="margin-bottom:1.5rem;"><i class="fa-solid fa-route" style="color:var(--accent);"></i> Mis Rutas de Aprendizaje</h3>
    <div class="course-grid">
        <?php foreach($mis_rutas as $mr): ?>
        <div class="course-card">
            <div class="course-img" style="background-image: url('../<?= htmlspecialchars(imagen_curso($mr['imagen']), ENT_QUOTES, 'UTF-8') ?>')">
                <span class="course-badge"><?= htmlspecialchars((string)($mr['nivel'] ?: 'Multinivel')) ?></span>
            </div>
            <div class="course-body">
                <h4 class="course-title"><?= htmlspecialchars($mr['titulo']) ?></h4>
                <div class="course-instructor"><i class="fa-solid fa-user-tie"></i> <?= htmlspecialchars((string)$mr['instructor']) ?></div>
                <?php if ($mr['acceso']['status'] === 'activo' && $mr['acceso']['ends_at']): ?>
                    <div class="access-note active"><i class="fa-regular fa-clock"></i> Acceso hasta <?= (new DateTimeImmutable($mr['acceso']['ends_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Bogota'))->format('d/m/Y H:i') ?></div>
                <?php elseif ($mr['acceso']['status'] === 'vencido'): ?>
                    <div class="access-note expired"><i class="fa-solid fa-lock"></i> Acceso vencido</div>
                <?php endif; ?>
                
                <div class="progress-container">
                    <div class="progress-header">
                        <span>Progreso General</span>
                        <span style="font-weight:700; color:var(--primary);"><?= $mr['progreso'] ?>%</span>
                    </div>
                    <div class="progress-bar"><div class="progress-fill" style="width:<?= $mr['progreso'] ?>%"></div></div>
                    <p style="font-size:0.7rem; color:#64748b; margin-top:0.4rem;"><?= $mr['cursos_completados'] ?> de <?= $mr['total_cursos'] ?> cursos completados</p>
                </div>

                <?php if ($mr['acceso']['active']): ?>
                    <a href="ruta?id=<?= $mr['id'] ?>" class="btn-play">
                        Ver mis cursos <i class="fa-solid fa-arrow-right" style="margin-left:5px; font-size:0.8rem;"></i>
                    </a>
                <?php else: ?>
                    <?php 
                    $alertTitle = $mr['acceso']['status'] === 'vencido' ? 'Acceso Vencido' : 'Acceso Denegado';
                    $alertMsg = $mr['acceso']['status'] === 'vencido' ? 'Tu periodo de acceso a esta ruta ha terminado. Comunícate con soporte para renovar tu acceso.' : 'No tienes acceso activo a esta ruta.';
                    ?>
                    <a href="#" onclick="showCustomAlert('<?= $alertTitle ?>', '<?= $alertMsg ?>', 'fa-solid fa-lock', '#ef4444'); return false;" class="btn-play" style="background:#cbd5e1; color:#475569;">
                        <?= $mr['acceso']['status'] === 'vencido' ? 'Acceso vencido' : 'Acceso no disponible' ?> <i class="fa-solid fa-lock" style="margin-left:5px; font-size:0.8rem;"></i>
                    </a>
                <?php endif; ?>

                <?php if($mr['progreso'] >= 100 || isset($certificados['ruta:' . $mr['id']])): ?>
                    <a href="certificado_ruta?id=<?= $mr['id'] ?>" class="btn-cert">
                        <i class="fa-solid fa-trophy"></i> Certificado de Ruta
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h3 style="margin-bottom:1.5rem;"><i class="fa-solid fa-book" style="color:var(--accent);"></i> Mis Cursos</h3>
    <div class="course-grid">
        <?php if(empty($mis_cursos)): ?>
        <div class="empty-state">
            <img src="https://cdn-icons-png.flaticon.com/512/7486/7486744.png" width="120" style="opacity:0.2; margin-bottom:1.5rem;">
            <h4 style="margin:0; color:var(--primary);">Aún no te has inscrito a ningún curso suelto</h4>
            <a href="../cursos" class="btn-play" style="display:inline-block; padding:0.8rem 2rem;">Explorar Catálogo</a>
        </div>
        <?php else: ?>
            <?php foreach($mis_cursos as $c): 
                $total = intval($c['total_lecciones']);
                $completadas = intval($c['completadas']);
                $pct = $total > 0 ? round(($completadas/$total)*100) : 0;
                
                $s_doc = $pdo->prepare("SELECT u.nombre FROM usuarios u JOIN curso_docentes cd ON u.id = cd.docente_id WHERE cd.curso_id = ? LIMIT 1");
                $s_doc->execute([$c['id']]);
                $docente = $s_doc->fetchColumn() ?: 'Equipo ' . ACADEMY_NAME;
            ?>
            <div class="course-card">
                <div class="course-img" style="background-image: url('../<?= htmlspecialchars(imagen_curso($c['imagen']), ENT_QUOTES, 'UTF-8') ?>')">
                    <span class="course-badge"><?= htmlspecialchars((string)($c['nivel'] ?: 'Multinivel')) ?></span>
                </div>
                <div class="course-body">
                    <h4 class="course-title"><?= htmlspecialchars($c['titulo']) ?></h4>
                    <div class="course-instructor"><i class="fa-solid fa-user-tie"></i> <?= htmlspecialchars($docente) ?></div>
                    <?php if ($c['acceso']['status'] === 'activo' && $c['acceso']['ends_at']): ?>
                        <div class="access-note active"><i class="fa-regular fa-clock"></i> Acceso hasta <?= (new DateTimeImmutable($c['acceso']['ends_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Bogota'))->format('d/m/Y H:i') ?></div>
                    <?php elseif ($c['acceso']['status'] === 'vencido'): ?>
                        <div class="access-note expired"><i class="fa-solid fa-lock"></i> Acceso vencido</div>
                    <?php endif; ?>
                    
                    <div class="progress-container">
                        <div class="progress-header">
                            <span>Progreso</span>
                            <span style="font-weight:700; color:var(--primary);"><?= $pct ?>%</span>
                        </div>
                        <div class="progress-bar"><div class="progress-fill" style="width:<?= $pct ?>%"></div></div>
                        <p style="font-size:0.7rem; color:#64748b; margin-top:0.4rem;"><?= $completadas ?> de <?= $total ?> lecciones completadas</p>
                    </div>

                    <?php if ($c['acceso']['active']): ?>
                        <a href="<?= $c['estado'] === 'externo' ? htmlspecialchars($c['enlace_externo'] ?? '') : 'clase?curso=' . $c['id'] ?>" class="btn-play" <?= $c['estado'] === 'externo' ? 'target="_blank"' : '' ?>>
                            <?= $c['estado'] === 'externo' ? 'Ir al recurso externo' : ($pct > 0 ? 'Continuar aprendiendo' : 'Empezar curso') ?> <i class="fa-solid fa-arrow-right" style="margin-left:5px; font-size:0.8rem;"></i>
                        </a>
                    <?php else: ?>
                        <?php 
                        $alertTitle = $c['acceso']['status'] === 'vencido' ? 'Acceso Vencido' : 'Acceso Denegado';
                        $alertMsg = $c['acceso']['status'] === 'vencido' ? 'Tu periodo de acceso a este curso ha terminado. Comunícate con soporte para renovar tu acceso.' : 'No tienes acceso activo a este curso.';
                        ?>
                        <a href="#" onclick="showCustomAlert('<?= $alertTitle ?>', '<?= $alertMsg ?>', 'fa-solid fa-lock', '#ef4444'); return false;" class="btn-play" style="background:#cbd5e1; color:#475569;">
                            <?= $c['acceso']['status'] === 'vencido' ? 'Acceso vencido' : 'Acceso no disponible' ?> <i class="fa-solid fa-lock" style="margin-left:5px; font-size:0.8rem;"></i>
                        </a>
                    <?php endif; ?>

                    <?php if($pct >= 100 || isset($certificados['curso:' . $c['id']])): ?>
                        <a href="certificado?curso=<?= $c['id'] ?>" class="btn-cert">
                            <i class="fa-solid fa-trophy"></i> Descargar Certificado
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    // Mobile sidebar toggle
    const mobBtn = document.getElementById('mobHamburger');
    const sidebar = document.getElementById('dashSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    function closeSidebar() {
        mobBtn.classList.remove('open');
        sidebar.classList.remove('open');
        overlay.classList.remove('show');
    }
    if (mobBtn) {
        mobBtn.addEventListener('click', () => {
            mobBtn.classList.toggle('open');
            sidebar.classList.toggle('open');
            overlay.classList.toggle('show');
        });
        overlay.addEventListener('click', closeSidebar);
        sidebar.querySelectorAll('.nav-item').forEach(l => l.addEventListener('click', closeSidebar));
    }

    function showCustomAlert(title, message, iconClass, colorHex) {
        const overlay = $('<div style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); backdrop-filter:blur(5px); z-index:9999; display:flex; align-items:center; justify-content:center; opacity:0; transition:0.3s;"></div>');
        const modal = $('<div style="background:var(--bg); border:1px solid #e2e8f0; padding:2.5rem; border-radius:24px; text-align:center; max-width:400px; transform:translateY(20px); transition:0.3s; box-shadow:0 25px 50px -12px rgba(0,0,0,0.5);"></div>');
        
        modal.append(`<i class="${iconClass}" style="font-size:3.5rem; color:${colorHex}; margin-bottom:1.5rem;"></i>`);
        modal.append(`<h3 style="font-family:\'Inter\', sans-serif; margin-bottom:1rem; font-size:1.4rem; color:var(--primary);">${title}</h3>`);
        modal.append(`<p style="color:var(--text); margin-bottom:2rem; line-height:1.6;">${message}</p>`);
        
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
        <?php if(isset($_GET['msg'])): ?>
            <?php if($_GET['msg'] === 'sin_pago'): ?>
                showCustomAlert('Acceso Denegado', 'No se ha encontrado un pago verificado para este curso o ruta. Por favor, verifica el estado de tu pago con administración.', 'fa-solid fa-file-invoice-dollar', '#f59e0b');
            <?php elseif($_GET['msg'] === 'acceso_vencido'): ?>
                showCustomAlert('Acceso Vencido', 'Tu periodo de acceso de 30 días terminó. Conservamos todo tu progreso y certificados obtenidos.', 'fa-solid fa-clock', '#ef4444');
            <?php elseif($_GET['msg'] === 'incompleto'): ?>
                showCustomAlert('Incompleto', 'Debes completar el 100% para poder descargar tu certificado.', 'fa-solid fa-list-check', '#ef4444');
            <?php elseif($_GET['msg'] === 'bloqueo_secuencial'): ?>
                showCustomAlert('Curso Bloqueado', 'Para continuar con la ruta, primero debes completar al 100% el curso: <strong><?= htmlspecialchars($_GET['curso'] ?? '') ?></strong>.', 'fa-solid fa-lock', '#d97706');
            <?php endif; ?>
        <?php endif; ?>
    });
</script>
</body>
</html>

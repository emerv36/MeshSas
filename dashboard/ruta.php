<?php
require_once '../config.php';
require_once '../helpers/acceso_helper.php';
require_once '../helpers/imagen_helper.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_rol'], ['estudiante', 'administrativo'])) {
    header('Location: ../login'); exit;
}
$usuario_id = $_SESSION['user_id'];
$ruta_id = intval($_GET['id'] ?? 0);

$estado_acceso_ruta = obtenerEstadoAccesoRuta($usuario_id, $ruta_id, $pdo);
if (!$estado_acceso_ruta['active']) {
    $msg = $estado_acceso_ruta['status'] === 'vencido' ? 'acceso_vencido' : 'sin_pago';
    header("Location: index?msg={$msg}");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM rutas_aprendizaje WHERE id = ?");
$stmt->execute([$ruta_id]);
$ruta = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ruta) {
    header('Location: index?msg=sin_pago');
    exit;
}

// Obtener cursos de la ruta con progreso y estado de bloqueo
$stmt_c = $pdo->prepare("
    SELECT c.*, rc.orden, rc.bloqueado_hasta_completar_anterior,
    (SELECT COUNT(*) FROM lecciones l JOIN modulos m ON l.modulo_id = m.id WHERE m.curso_id = c.id AND l.estado = 'publicado' AND m.estado = 'publicado') AS total_lecciones,
    (SELECT COUNT(*) FROM progreso p JOIN lecciones l ON p.leccion_id = l.id JOIN modulos m ON l.modulo_id = m.id WHERE m.curso_id = c.id AND p.usuario_id = ? AND p.completado = 1 AND l.estado = 'publicado' AND m.estado = 'publicado') AS completadas,
    (SELECT u.nombre FROM usuarios u JOIN curso_docentes cd ON u.id = cd.docente_id WHERE cd.curso_id = c.id LIMIT 1) as docente
    FROM ruta_cursos rc
    JOIN cursos c ON rc.curso_id = c.id
    WHERE rc.ruta_id = ?
    ORDER BY rc.orden ASC
");
$stmt_c->execute([$usuario_id, $ruta_id]);
$cursos = $stmt_c->fetchAll(PDO::FETCH_ASSOC);

$cursos_completados = 0;
$total_cursos = count($cursos);

// Procesar estados
foreach ($cursos as &$c) {
    $c['docente'] = $c['docente'] ?: 'Equipo ' . ACADEMY_NAME;
    $t_lec = intval($c['total_lecciones']);
    $c_lec = intval($c['completadas']);
    $c['pct'] = $t_lec > 0 ? round(($c_lec / $t_lec) * 100) : 0;
    
    if ($t_lec > 0 && $c_lec == $t_lec) {
        $cursos_completados++;
    }

    $c['estado_bloqueo'] = false;
    $c['razon_bloqueo'] = '';

    if ($c['bloqueado_hasta_completar_anterior']) {
        $c_faltante_id = null;
        $c_faltante_titulo = null;
        if (!verificarBloqueoSecuencial($usuario_id, $c['id'], $pdo, $c_faltante_titulo)) {
            $c['estado_bloqueo'] = true;
            $c['razon_bloqueo'] = $c_faltante_titulo;
        }
    }
}
unset($c);

$progreso_general = $total_cursos > 0 ? round(($cursos_completados / $total_cursos) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($ruta['titulo']) ?> - Mi Aprendizaje</title>
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
        
        .main-content { flex: 1; margin-left: 260px; padding: 2rem 3rem; }
        .header-ruta { display: flex; gap: 2rem; align-items: center; background: white; padding: 2rem; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); margin-bottom: 2rem; border: 1px solid #e2e8f0; }
        .ruta-img { width: 120px; height: 120px; border-radius: 12px; object-fit: cover; }
        
        .progress-box { flex: 1; }
        .progress-bar-large { height: 12px; background: #e2e8f0; border-radius: 6px; overflow: hidden; margin: 1rem 0; }
        .progress-fill-large { height: 100%; background: linear-gradient(90deg, #10b981, #34d399); transition: 0.5s; }

        .curso-list { display: flex; flex-direction: column; gap: 1.5rem; }
        .curso-item { background: white; border-radius: 12px; padding: 1.5rem; display: flex; gap: 1.5rem; align-items: center; box-shadow: 0 4px 6px rgba(0,0,0,0.02); border: 1px solid #e2e8f0; position: relative; }
        .curso-item.bloqueado { background: #f8fafc; border-color: #cbd5e1; }
        .curso-item.bloqueado .curso-info { opacity: 0.6; }
        .curso-item.completado { border-color: #10b981; }

        .curso-icon { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; background: #f1f5f9; color: var(--primary); font-weight: 700; flex-shrink: 0; }
        .curso-item.completado .curso-icon { background: #dcfce7; color: #166534; }
        
        .curso-info { flex: 1; }
        .curso-title { font-size: 1.1rem; font-weight: 700; color: var(--primary); margin: 0 0 0.4rem; }
        .curso-meta { font-size: 0.8rem; color: #64748b; margin-bottom: 0.5rem; }
        
        .curso-progreso-mini { width: 150px; }
        .progress-bar-mini { height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; margin-top: 0.3rem; }
        .progress-fill-mini { height: 100%; background: var(--accent); transition: 0.5s; }
        .curso-item.completado .progress-fill-mini { background: #10b981; }

        .btn-action { padding: 0.6rem 1.2rem; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 0.9rem; transition: 0.2s; white-space: nowrap; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: #1e293b; }
        .btn-outline { background: transparent; color: var(--primary); border: 1px solid var(--primary); }
        .btn-outline:hover { background: #f1f5f9; }
        .btn-disabled { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; }

        .lock-overlay { position: absolute; inset: 0; background: rgba(255,255,255,0.8); backdrop-filter: blur(2px); border-radius: 12px; display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 10; color: #d97706; }
        .lock-icon { font-size: 2rem; margin-bottom: 0.5rem; }

        /* Mobile topbar */
        .mobile-topbar { display: none; position: fixed; top: 0; left: 0; right: 0; height: 56px; background: var(--primary); align-items: center; justify-content: space-between; padding: 0 1.2rem; z-index: 1100; box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
        .mobile-topbar img { height: 32px; }
        .mob-hamburger { background: none; border: none; cursor: pointer; display: flex; flex-direction: column; gap: 5px; padding: 4px; }
        .mob-hamburger span { display: block; width: 22px; height: 2px; background: white; border-radius: 2px; transition: 0.3s; }
        .mob-hamburger.open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
        .mob-hamburger.open span:nth-child(2) { opacity: 0; }
        .mob-hamburger.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1050; }
        .sidebar-overlay.show { display: block; }

        @media (max-width: 768px) {
            .mobile-topbar { display: flex; }
            .sidebar { transform: translateX(-100%); transition: transform 0.3s ease; z-index: 1200; top: 0; }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 5rem 1rem 2rem; }
        }
    </style>
</head>
<body>

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
    <div class="sidebar-nav">
        <a href="index" class="nav-item"><i class="fa-solid fa-house"></i> Mi Dashboard</a>
        <a href="../cursos" class="nav-item"><i class="fa-solid fa-search"></i> Explorar Cursos</a>
        <a href="../rutas" class="nav-item"><i class="fa-solid fa-route"></i> Explorar Rutas</a>
        <a href="logros" class="nav-item"><i class="fa-solid fa-certificate"></i> Mis Logros</a>
        <a href="perfil" class="nav-item"><i class="fa-solid fa-user"></i> Mi Perfil</a>
    </div>
    <a href="../logout" class="nav-item logout-btn"><i class="fa-solid fa-sign-out"></i> Cerrar Sesión</a>
</div>

<div class="main-content">
    <a href="index" style="color:#64748b; text-decoration:none; display:inline-block; margin-bottom:1rem; font-size:0.9rem;"><i class="fa-solid fa-arrow-left"></i> Volver al Dashboard</a>
    
    <div class="header-ruta">
        <img src="../<?= htmlspecialchars(imagen_curso($ruta['imagen']), ENT_QUOTES, 'UTF-8') ?>" alt="Ruta" class="ruta-img">
        <div class="progress-box">
            <div style="text-transform:uppercase; font-size:0.75rem; font-weight:700; color:var(--accent); margin-bottom:0.3rem;">Ruta de Aprendizaje</div>
            <h2 style="margin:0 0 0.5rem; color:var(--primary); font-size:1.8rem;"><?= htmlspecialchars($ruta['titulo']) ?></h2>
            
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:0.9rem; color:#64748b;">Progreso General</span>
                <span style="font-weight:800; color:#10b981; font-size:1.2rem;"><?= $progreso_general ?>%</span>
            </div>
            <div class="progress-bar-large"><div class="progress-fill-large" style="width:<?= $progreso_general ?>%"></div></div>
            <div style="font-size:0.8rem; color:#64748b; display:flex; justify-content:space-between;">
                <span><?= $cursos_completados ?> de <?= $total_cursos ?> cursos completados</span>
                <?php if($progreso_general >= 100): ?>
                    <a href="certificado_ruta?id=<?= $ruta['id'] ?>" style="color:#10b981; text-decoration:none; font-weight:600;"><i class="fa-solid fa-trophy"></i> Descargar Certificado de Ruta</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <h3 style="margin-bottom:1.5rem; color:var(--primary);">Cursos de la ruta</h3>

    <div class="curso-list">
        <?php foreach ($cursos as $index => $c): ?>
            <div class="curso-item <?= $c['estado_bloqueo'] ? 'bloqueado' : '' ?> <?= $c['pct'] == 100 ? 'completado' : '' ?>">
                
                <?php if($c['estado_bloqueo']): ?>
                <div class="lock-overlay">
                    <i class="fa-solid fa-lock lock-icon"></i>
                    <h4 style="margin:0;">Curso Bloqueado</h4>
                    <p style="margin:0; font-size:0.8rem;">Debes completar "<?= htmlspecialchars($c['razon_bloqueo']) ?>" para acceder.</p>
                </div>
                <?php endif; ?>

                <div class="curso-icon">
                    <?php if($c['pct'] == 100): ?>
                        <i class="fa-solid fa-check"></i>
                    <?php else: ?>
                        <?= $index + 1 ?>
                    <?php endif; ?>
                </div>

                <div class="curso-info">
                    <h4 class="curso-title"><?= htmlspecialchars($c['titulo']) ?></h4>
                    <div class="curso-meta">
                        <i class="fa-solid fa-user-tie"></i> <?= htmlspecialchars($c['docente']) ?> &nbsp;|&nbsp; 
                        <i class="fa-solid fa-list-check"></i> <?= $c['total_lecciones'] ?> lecciones
                    </div>
                    
                    <div class="curso-progreso-mini">
                        <div style="display:flex; justify-content:space-between; font-size:0.7rem; color:#64748b;">
                            <span>Progreso</span><span><?= $c['pct'] ?>%</span>
                        </div>
                        <div class="progress-bar-mini"><div class="progress-fill-mini" style="width:<?= $c['pct'] ?>%"></div></div>
                    </div>
                </div>

                <div>
                    <?php if($c['estado_bloqueo']): ?>
                        <span class="btn-action btn-disabled"><i class="fa-solid fa-lock"></i> Bloqueado</span>
                    <?php elseif($c['estado'] === 'externo'): ?>
                        <a href="<?= htmlspecialchars($c['enlace_externo'] ?? '') ?>" target="_blank" class="btn-action btn-primary"><i class="fa-solid fa-external-link-alt"></i> Ir al recurso</a>
                    <?php elseif($c['pct'] == 100): ?>
                        <a href="clase?curso=<?= $c['id'] ?>" class="btn-action btn-outline">Repasar</a>
                        <a href="certificado?curso=<?= $c['id'] ?>" class="btn-action btn-primary"><i class="fa-solid fa-certificate"></i> Certificado</a>
                    <?php else: ?>
                        <a href="clase?curso=<?= $c['id'] ?>" class="btn-action btn-primary"><?= $c['pct'] > 0 ? 'Continuar' : 'Empezar' ?></a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
    const mobBtn = document.getElementById('mobHamburger');
    const dashSidebar = document.getElementById('dashSidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    function closeSidebar() {
        mobBtn.classList.remove('open');
        dashSidebar.classList.remove('open');
        sidebarOverlay.classList.remove('show');
    }
    if (mobBtn) {
        mobBtn.addEventListener('click', () => {
            mobBtn.classList.toggle('open');
            dashSidebar.classList.toggle('open');
            sidebarOverlay.classList.toggle('show');
        });
        sidebarOverlay.addEventListener('click', closeSidebar);
        dashSidebar.querySelectorAll('.nav-item').forEach(l => l.addEventListener('click', closeSidebar));
    }
</script>
</body>
</html>

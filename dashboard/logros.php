<?php
require_once '../config.php';
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_rol'], ['estudiante', 'administrativo'])) {
    header('Location: ../login'); exit;
}
$usuario_id = $_SESSION['user_id'];

// --- LÓGICA DE GAMIFICACIÓN ---

// 1-4. Lecciones completadas, quizzes y prácticas aprobados, y XP acumulado
require_once '../helpers/logros_helper.php';
$puntos = xp_usuario($pdo, (int)$usuario_id);
$completed_lessons = $puntos['lecciones'];
$approved_quizzes = $puntos['quizzes'];
$approved_prácticas = $puntos['practicas'];
$xp = $puntos['xp'];

// 5. Verificar si tiene alguna nota perfecta (100%)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM quiz_intentos WHERE usuario_id = ? AND puntaje = 100 AND estado IN ('graded', 'aprobado')");
$stmt->execute([$usuario_id]);
$perfect_quizzes = intval($stmt->fetchColumn());

$stmt = $pdo->prepare("SELECT COUNT(*) FROM respuestas_excel WHERE usuario_id = ? AND puntaje = 100");
$stmt->execute([$usuario_id]);
$perfect_excels = intval($stmt->fetchColumn());

$has_perfect_score = ($perfect_quizzes > 0 || $perfect_excels > 0);

// 6. Rutas de aprendizaje inscritas y evaluación de rutas completas
$stmt_rutas = $pdo->prepare("
    SELECT DISTINCT r.id
    FROM rutas_aprendizaje r
    -- Rutas con algún acceso no revocado (vigente o vencido: se muestra como Acceso vencido)
    WHERE EXISTS (SELECT 1 FROM accesos_productos a WHERE a.usuario_id = ? AND a.producto_tipo = 'ruta' AND a.producto_id = r.id AND a.revocado_at IS NULL)
");
$stmt_rutas->execute([$usuario_id]);
$mis_rutas_ids = $stmt_rutas->fetchAll(PDO::FETCH_COLUMN);

$completed_routes_count = 0;
$rutas_certificados = [];

foreach ($mis_rutas_ids as $ruta_id) {
    $stmt_c = $pdo->prepare("SELECT curso_id FROM ruta_cursos WHERE ruta_id = ?");
    $stmt_c->execute([$ruta_id]);
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

    if ($total_cursos > 0 && $cursos_completados == $total_cursos) {
        $completed_routes_count++;
        // Guardar detalles de la ruta completada para los certificados
        $stmt_r_det = $pdo->prepare("SELECT * FROM rutas_aprendizaje WHERE id = ?");
        $stmt_r_det->execute([$ruta_id]);
        $r_info = $stmt_r_det->fetch(PDO::FETCH_ASSOC);
        if ($r_info) {
            $rutas_certificados[] = $r_info;
        }
    }
}

// 7. Cursos completados para descarga de certificados
$stmt_cursos = $pdo->prepare("
    SELECT DISTINCT c.*,
    (SELECT COUNT(*) FROM lecciones l JOIN modulos m ON l.modulo_id = m.id WHERE m.curso_id = c.id AND l.estado = 'publicado' AND m.estado = 'publicado') AS total_lecciones,
    (SELECT COUNT(*) FROM progreso p JOIN lecciones l ON p.leccion_id = l.id JOIN modulos m ON l.modulo_id = m.id WHERE m.curso_id = c.id AND p.usuario_id = ? AND p.completado = 1 AND l.estado = 'publicado' AND m.estado = 'publicado') AS completadas
    FROM cursos c
    -- Cursos con algún acceso no revocado, propio o por una ruta que los incluya
    WHERE EXISTS (SELECT 1 FROM accesos_productos a WHERE a.usuario_id = ? AND a.producto_tipo = 'curso' AND a.producto_id = c.id AND a.revocado_at IS NULL)
       OR EXISTS (SELECT 1 FROM accesos_productos a JOIN ruta_cursos rc ON rc.ruta_id = a.producto_id WHERE a.usuario_id = ? AND a.producto_tipo = 'ruta' AND rc.curso_id = c.id AND a.revocado_at IS NULL)
");
$stmt_cursos->execute([$usuario_id, $usuario_id, $usuario_id]);
$todos_cursos = $stmt_cursos->fetchAll(PDO::FETCH_ASSOC);

$cursos_certificados = [];
foreach ($todos_cursos as $c) {
    if (intval($c['total_lecciones']) > 0 && intval($c['completadas']) == intval($c['total_lecciones'])) {
        $cursos_certificados[] = $c;
    }
}

// Se emiten los certificados de lo completado y se listan los emitidos, que se conservan
// aunque después se publiquen lecciones nuevas
require_once '../helpers/certificado_helper.php';
foreach ($rutas_certificados as $r) certificado_emitir($pdo, 'ruta', (int)$usuario_id, (int)$r['id']);
foreach ($cursos_certificados as $c) certificado_emitir($pdo, 'curso', (int)$usuario_id, (int)$c['id']);
$rutas_certificados = [];
$cursos_certificados = [];
$stmt_rc = $pdo->prepare("SELECT COUNT(*) FROM ruta_cursos WHERE ruta_id = ?");
foreach (certificados_del_usuario($pdo, (int)$usuario_id) as $cert) {
    $item = ['id' => (int)$cert['producto_id'], 'titulo' => $cert['titulo'], 'fecha' => certificado_fecha($cert)];
    if ($cert['tipo'] === 'ruta') {
        $stmt_rc->execute([$cert['producto_id']]);
        $item['total_cursos'] = (int)$stmt_rc->fetchColumn();
        $rutas_certificados[] = $item;
    } else {
        $cursos_certificados[] = $item;
    }
}

$total_certificados = count($cursos_certificados) + count($rutas_certificados);

// 8. Determinar estado de insignias (Booleans)
$badge_primer_paso = ($completed_lessons >= 1);

// Racha real
$stmt_racha = $pdo->prepare("SELECT racha_actual FROM usuarios WHERE id = ?");
$stmt_racha->execute([$usuario_id]);
$racha_actual = (int)$stmt_racha->fetchColumn();
$badge_constante = ($racha_actual >= 3);
$badge_brillante = $has_perfect_score;
$badge_practica = ($approved_prácticas >= 1);
$badge_especialista = ($completed_routes_count >= 1);
$badge_coleccionista = ($total_certificados >= 3);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Logros - <?= htmlspecialchars(ACADEMY_NAME) ?></title>
    <link rel="icon" href="<?= FAVICON_URL ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: <?= $ajustes['color_primario'] ?? '#2A3547' ?>;
            --accent: <?= $ajustes['color_acento'] ?? '#FF6B35' ?>;
            --bg: #f4f7fa;
            --text: #475569;
            --border: #e2e8f0;
        }
        body { font-family: 'Inter', sans-serif; background: var(--bg); margin: 0; display: flex; color: var(--text); min-height: 100vh; }

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

        /* Stats Cards */
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; margin-bottom: 2.5rem; }

        @media (max-width: 768px) {
            .mobile-topbar { display: flex; }
            .sidebar { transform: translateX(-100%); transition: transform 0.3s ease; z-index: 1200; top: 0; }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 5rem 1rem 2rem; }
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 1rem; }
            .header { flex-direction: column; align-items: flex-start; gap: 0.8rem; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
        .stat-card { background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); display: flex; align-items: center; gap: 1.2rem; border: 1px solid var(--border); }
        .stat-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
        .stat-data h3 { margin: 0; font-size: 1.5rem; color: var(--primary); }
        .stat-data p { margin: 0; font-size: 0.85rem; color: #94a3b8; }

        /* Section layout */
        .section-title { font-size: 1.2rem; font-weight: 700; color: var(--primary); margin: 2rem 0 1rem 0; display: flex; align-items: center; gap: 0.5rem; }

        /* Badges grid */
        .badges-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 3rem; }
        .badge-card { background: white; border-radius: 16px; border: 1px solid var(--border); padding: 1.5rem; text-align: center; display: flex; flex-direction: column; align-items: center; transition: 0.3s; cursor: pointer; position: relative; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
        .badge-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.05); }

        .badge-icon-wrapper { width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.2rem; margin-bottom: 1rem; position: relative; transition: 0.3s; }
        .badge-card.unlocked .badge-icon-wrapper { background: linear-gradient(135deg, rgba(255, 107, 53, 0.1), rgba(255, 176, 53, 0.1)); color: var(--accent); }
        .badge-card.locked .badge-icon-wrapper { background: #f1f5f9; color: #94a3b8; filter: grayscale(1); opacity: 0.6; }

        .badge-lock { position: absolute; bottom: 0; right: 0; width: 24px; height: 24px; border-radius: 50%; background: #475569; color: white; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; border: 2px solid white; }

        .badge-title { font-size: 0.95rem; font-weight: 700; color: var(--primary); margin: 0 0 0.4rem; }
        .badge-desc { font-size: 0.75rem; color: #64748b; line-height: 1.4; margin: 0; }
        .badge-status { margin-top: auto; font-size: 0.7rem; font-weight: 700; padding: 3px 8px; border-radius: 20px; text-transform: uppercase; margin-top: 0.8rem; }
        .badge-card.unlocked .badge-status { background: #dcfce7; color: #15803d; }
        .badge-card.locked .badge-status { background: #f1f5f9; color: #64748b; }

        /* Certificates grid */
        .certs-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.5rem; }
        .cert-card { background: white; border-radius: 12px; border: 1px solid var(--border); padding: 1.25rem; display: flex; align-items: center; gap: 1.2rem; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
        .cert-icon { width: 50px; height: 50px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; background: #dcfce7; color: #15803d; flex-shrink: 0; }
        .cert-info { flex: 1; }
        .cert-title { font-size: 0.95rem; font-weight: 700; color: var(--primary); margin: 0 0 0.2rem 0; }
        .cert-meta { font-size: 0.75rem; color: #94a3b8; margin: 0; }
        .btn-download { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 50%; background: #10b981; color: white; text-decoration: none; transition: 0.2s; box-shadow: 0 2px 5px rgba(16, 185, 129, 0.3); }
        .btn-download:hover { background: #059669; transform: scale(1.05); }

        .empty-state { text-align: center; padding: 4rem 2rem; background: white; border-radius: 16px; border: 2px dashed #e2e8f0; width: 100%; box-sizing: border-box; }
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
        <a href="logros" class="nav-item active"><i class="fa-solid fa-certificate"></i> Mis Logros</a>
        <a href="perfil" class="nav-item"><i class="fa-solid fa-user"></i> Mi Perfil</a>
    </div>
    <a href="../logout" class="nav-item logout-btn"><i class="fa-solid fa-sign-out"></i> Cerrar Sesión</a>
</div>

<div class="main-content">
    <div class="header">
        <div>
            <h2 style="margin:0; color:var(--primary);">Mis Logros Académicos</h2>
            <p style="margin:0.3rem 0 0; font-size:0.9rem; color:#64748b;">Monitorea tu nivel de experiencia, insignias obtenidas y descarga tus certificados oficiales.</p>
        </div>
    </div>

    <!-- Panel de Métricas -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background:#eff6ff; color:#3b82f6;"><i class="fa-solid fa-star"></i></div>
            <div class="stat-data">
                <h3><?= number_format($xp) ?> XP</h3>
                <p>Puntos de Experiencia</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#fef2f2; color:#ef4444;"><i class="fa-solid fa-fire"></i></div>
            <div class="stat-data">
                <h3>3 Días</h3>
                <p>Racha de Aprendizaje</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#ecfdf5; color:#10b981;"><i class="fa-solid fa-award"></i></div>
            <div class="stat-data">
                <h3><?= $total_certificados ?></h3>
                <p>Certificados Obtenidos</p>
            </div>
        </div>
    </div>

    <!-- Panel de Insignias -->
    <h3 class="section-title"><i class="fa-solid fa-medal" style="color:var(--accent);"></i> Insignias de Aprendizaje</h3>
    <div class="badges-grid">
        <!-- Primer Paso -->
        <div class="badge-card <?= $badge_primer_paso ? 'unlocked' : 'locked' ?>"
             onclick="showBadgeDetails('Primer Paso', 'Completar tu primera lección en la plataforma.', '<?= $badge_primer_paso ? '¡Desbloqueado! Has dado tu primer paso y comenzado tu viaje de aprendizaje en ' . htmlspecialchars(addslashes(ACADEMY_NAME), ENT_QUOTES, 'UTF-8') . '.' : 'Bloqueado. Ingresa a cualquier clase y completa tu primera lección para obtener esta insignia.' ?>', 'fa-solid fa-shoe-prints', '<?= $badge_primer_paso ? '#10b981' : '#64748b' ?>')">
            <div class="badge-icon-wrapper">
                <i class="fa-solid fa-shoe-prints"></i>
                <?php if (!$badge_primer_paso): ?><div class="badge-lock"><i class="fa-solid fa-lock"></i></div><?php endif; ?>
            </div>
            <h4 class="badge-title">Primer Paso</h4>
            <p class="badge-desc">Completar tu primera lección.</p>
            <span class="badge-status"><?= $badge_primer_paso ? 'Obtenido' : 'Bloqueado' ?></span>
        </div>

        <!-- Estudiante Constante -->
        <div class="badge-card <?= $badge_constante ? 'unlocked' : 'locked' ?>"
             onclick="showBadgeDetails('Estudiante Constante', 'Mantener una racha de estudio activa por 3 días o más.', '<?= $badge_constante ? "¡Desbloqueado! Llevas 3 días o más seguidos ingresando a la plataforma para avanzar en tus estudios." : "Bloqueado. Ingresa a la plataforma 3 días consecutivos para obtener esta insignia. Llevas {$racha_actual} días." ?>', 'fa-solid fa-fire', '<?= $badge_constante ? "#ef4444" : "#64748b" ?>')">
            <div class="badge-icon-wrapper" <?= $badge_constante ? 'style="color: #ef4444;"' : '' ?>>
                <i class="fa-solid fa-fire"></i>
                <?php if (!$badge_constante): ?><div class="badge-lock"><i class="fa-solid fa-lock"></i></div><?php endif; ?>
            </div>
            <h4 class="badge-title">Estudiante Constante</h4>
            <p class="badge-desc">Mantener racha de 3 días.</p>
            <span class="badge-status" <?= $badge_constante ? 'style="background:#fef2f2; color:#ef4444;"' : '' ?>><?= $badge_constante ? 'Obtenido' : 'Bloqueado' ?></span>
        </div>

        <!-- Mente Brillante -->
        <div class="badge-card <?= $badge_brillante ? 'unlocked' : 'locked' ?>"
             onclick="showBadgeDetails('Mente Brillante', 'Obtener un puntaje perfecto (100%) en cualquier evaluación.', '<?= $badge_brillante ? '¡Desbloqueado! Has obtenido una calificación de 100% en un examen o actividad práctica. ¡Increíble!' : 'Bloqueado. Resuelve cualquier quiz o ejercicio práctico con un puntaje perfecto de 100% para obtenerlo.' ?>', 'fa-solid fa-brain', '<?= $badge_brillante ? '#8b5cf6' : '#64748b' ?>')">
            <div class="badge-icon-wrapper">
                <i class="fa-solid fa-brain"></i>
                <?php if (!$badge_brillante): ?><div class="badge-lock"><i class="fa-solid fa-lock"></i></div><?php endif; ?>
            </div>
            <h4 class="badge-title">Mente Brillante</h4>
            <p class="badge-desc">Obtener 100% en un quiz.</p>
            <span class="badge-status"><?= $badge_brillante ? 'Obtenido' : 'Bloqueado' ?></span>
        </div>

        <!-- Práctica Activa -->
        <div class="badge-card <?= $badge_practica ? 'unlocked' : 'locked' ?>"
             onclick="showBadgeDetails('Práctica Activa', 'Aprobar cualquier ejercicio o taller práctico en la plataforma.', '<?= $badge_practica ? '¡Desbloqueado! Completaste satisfactoriamente tu primera lección práctica.' : 'Bloqueado. Resuelve y aprueba cualquier taller o lección práctica de tus cursos para obtenerlo.' ?>', 'fa-solid fa-laptop-code', '<?= $badge_practica ? '#3b82f6' : '#64748b' ?>')">
            <div class="badge-icon-wrapper">
                <i class="fa-solid fa-laptop-code"></i>
                <?php if (!$badge_practica): ?><div class="badge-lock"><i class="fa-solid fa-lock"></i></div><?php endif; ?>
            </div>
            <h4 class="badge-title">Práctica Activa</h4>
            <p class="badge-desc">Aprobar un taller práctico.</p>
            <span class="badge-status"><?= $badge_practica ? 'Obtenido' : 'Bloqueado' ?></span>
        </div>

        <!-- Especialista -->
        <div class="badge-card <?= $badge_especialista ? 'unlocked' : 'locked' ?>"
             onclick="showBadgeDetails('Especialista', 'Completar una ruta de aprendizaje al 100%.', '<?= $badge_especialista ? '¡Desbloqueado! Has completado exitosamente una ruta de aprendizaje completa, dominando todos sus módulos.' : 'Bloqueado. Finaliza al 100% todos los cursos vinculados a cualquiera de tus rutas de aprendizaje.' ?>', 'fa-solid fa-route', '<?= $badge_especialista ? '#eab308' : '#64748b' ?>')">
            <div class="badge-icon-wrapper">
                <i class="fa-solid fa-route"></i>
                <?php if (!$badge_especialista): ?><div class="badge-lock"><i class="fa-solid fa-lock"></i></div><?php endif; ?>
            </div>
            <h4 class="badge-title">Especialista</h4>
            <p class="badge-desc">Completar 1 ruta de aprendizaje.</p>
            <span class="badge-status"><?= $badge_especialista ? 'Obtenido' : 'Bloqueado' ?></span>
        </div>

        <!-- Coleccionista -->
        <div class="badge-card <?= $badge_coleccionista ? 'unlocked' : 'locked' ?>"
             onclick="showBadgeDetails('Coleccionista', 'Acumular 3 o más certificados descargables en la plataforma.', '<?= $badge_coleccionista ? '¡Desbloqueado! Has coleccionado al menos 3 certificados oficiales en tu cuenta. ¡Eres imparable!' : 'Bloqueado. Sigue completando cursos o rutas de aprendizaje hasta acumular 3 certificados.' ?>', 'fa-solid fa-folder-open', '<?= $badge_coleccionista ? '#10b981' : '#64748b' ?>')">
            <div class="badge-icon-wrapper">
                <i class="fa-solid fa-folder-open"></i>
                <?php if (!$badge_coleccionista): ?><div class="badge-lock"><i class="fa-solid fa-lock"></i></div><?php endif; ?>
            </div>
            <h4 class="badge-title">Coleccionista</h4>
            <p class="badge-desc">Obtener 3 certificados.</p>
            <span class="badge-status"><?= $badge_coleccionista ? 'Obtenido' : 'Bloqueado' ?></span>
        </div>
    </div>

    <!-- Certificados Descargables -->
    <h3 class="section-title"><i class="fa-solid fa-trophy" style="color:#eab308;"></i> Mis Certificados Oficiales</h3>

    <?php if (empty($cursos_certificados) && empty($rutas_certificados)): ?>
        <div class="empty-state">
            <i class="fa-solid fa-graduation-cap" style="font-size:3.5rem; color:#cbd5e1; margin-bottom:1rem; display:inline-block;"></i>
            <h4 style="margin:0; color:var(--primary); font-size:1.1rem;">Aún no tienes certificados disponibles</h4>
            <p style="margin:0.5rem 0 1.5rem; font-size:0.85rem; color:#94a3b8;">Completa tus cursos o rutas de aprendizaje al 100% para obtener tu acreditación oficial.</p>
            <a href="index" class="btn-download" style="width:auto; border-radius:8px; padding:0.6rem 1.5rem; font-weight:600; font-size:0.85rem;">Ir a mis cursos</a>
        </div>
    <?php else: ?>
        <div class="certs-grid">
            <!-- Certificados de Rutas -->
            <?php foreach ($rutas_certificados as $rc): ?>
                <div class="cert-card">
                    <div class="cert-icon" style="background:#fef3c7; color:#d97706;"><i class="fa-solid fa-medal"></i></div>
                    <div class="cert-info">
                        <h4 class="cert-title"><?= htmlspecialchars($rc['titulo']) ?></h4>
                        <p class="cert-meta">Certificado de Ruta • <?= $rc['total_cursos'] ?> cursos • <?= $rc['fecha'] ?></p>
                    </div>
                    <a href="certificado_ruta?id=<?= $rc['id'] ?>" class="btn-download" title="Descargar Certificado de Ruta">
                        <i class="fa-solid fa-arrow-down"></i>
                    </a>
                </div>
            <?php endforeach; ?>

            <!-- Certificados de Cursos -->
            <?php foreach ($cursos_certificados as $cc): ?>
                <div class="cert-card">
                    <div class="cert-icon"><i class="fa-solid fa-graduation-cap"></i></div>
                    <div class="cert-info">
                        <h4 class="cert-title"><?= htmlspecialchars($cc['titulo']) ?></h4>
                        <p class="cert-meta">Certificado de Curso • Completado el <?= $cc['fecha'] ?></p>
                    </div>
                    <a href="certificado?curso=<?= $cc['id'] ?>" class="btn-download" title="Descargar Certificado de Curso">
                        <i class="fa-solid fa-arrow-down"></i>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    function showBadgeDetails(title, requirement, description, iconClass, colorHex) {
        const overlay = $('<div style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); backdrop-filter:blur(5px); z-index:9999; display:flex; align-items:center; justify-content:center; opacity:0; transition:0.3s;"></div>');
        const modal = $('<div style="background:var(--bg); border:1px solid var(--border); padding:2.5rem; border-radius:24px; text-align:center; max-width:420px; transform:translateY(20px); transition:0.3s; box-shadow:0 25px 50px -12px rgba(0,0,0,0.5);"></div>');

        modal.append(`<i class="${iconClass}" style="font-size:4rem; color:${colorHex}; margin-bottom:1.5rem;"></i>`);
        modal.append(`<h3 style="font-family:\'Inter\', sans-serif; margin-bottom:0.5rem; font-size:1.4rem; color:var(--primary);">${title}</h3>`);
        modal.append(`<p style="color:#94a3b8; font-size:0.8rem; font-weight:600; text-transform:uppercase; margin-bottom:1.5rem; letter-spacing:0.5px;">Requisito: ${requirement}</p>`);
        modal.append(`<p style="color:var(--text); margin-bottom:2rem; line-height:1.6; font-size:0.95rem;">${description}</p>`);

        const btn = $(`<button style="width:100%; justify-content:center; background:${colorHex}; border:none; color:white; padding:12px; border-radius:10px; font-weight:600; cursor:pointer; transition:0.2s;">Entendido</button>`);
        btn.on('click', function() {
            overlay.css('opacity', '0');
            modal.css('transform', 'translateY(20px)');
            setTimeout(() => overlay.remove(), 300);
        });

        modal.append(btn);
        overlay.append(modal);
        $('body').append(overlay);

        setTimeout(() => {
            overlay.css('opacity', '1');
            modal.css('transform', 'translateY(0)');
        }, 10);
    }

    // Mobile sidebar toggle
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

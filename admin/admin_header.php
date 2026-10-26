<?php
require_once '../config.php';

// Validar que haya sesión iniciada
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login");
    exit;
}

// Validar que el rol sea administrativo (admin, docente o tesoreria)
$rol = $_SESSION['user_rol'] ?? 'estudiante';
if (!in_array($rol, ['admin', 'docente', 'tesoreria'])) {
    header("Location: ../dashboard/index");
    exit;
}

$user_id = $_SESSION['user_id'];

// Generar token CSRF si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Contador de quizzes pendientes:
// Si es admin, todos. Si es docente, solo de sus cursos. Tesorería no califica.
$count_pendientes = 0;
if ($rol === 'admin') {
    $count_pendientes = $pdo->query("SELECT COUNT(i.id) FROM quiz_intentos i WHERE i.estado IN ('pendiente', 'pending_review')")->fetchColumn();
} elseif ($rol === 'docente') {
    $count_pendientes = $pdo->prepare("SELECT COUNT(i.id) FROM quiz_intentos i
                                       JOIN lecciones l ON i.leccion_id = l.id
                                       JOIN modulos m ON l.modulo_id = m.id
                                       JOIN cursos c ON m.curso_id = c.id
                                       WHERE i.estado IN ('pendiente', 'pending_review')
                                       AND EXISTS (
                                           SELECT 1 FROM curso_docentes cd
                                           WHERE cd.curso_id = c.id AND cd.docente_id = ?
                                       )");
    $count_pendientes->execute([$user_id]);
    $count_pendientes = $count_pendientes->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - <?= htmlspecialchars(ACADEMY_NAME) ?></title>
    <link rel="icon" href="<?= FAVICON_URL ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        :root {
            --primary: <?= $ajustes['color_primario'] ?? '#2A3547' ?>;
            --accent: <?= $ajustes['color_acento'] ?? '#FF6B35' ?>;
            --bg: #f4f7f6;
        }
        body { background: var(--bg); font-family: 'Inter', sans-serif; margin: 0; display: flex; min-height: 100vh; }
        .sidebar-admin { width: 260px; background: var(--primary); color: white; display: flex; flex-direction: column; flex-shrink: 0; }
        .sidebar-header { padding: 3rem 1.5rem; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .sidebar-header img {
            max-width: 200px;
            height: auto;
            display: block;
            margin: 0 auto;
            filter: drop-shadow(0 0 1px rgba(255,255,255,0.8)) drop-shadow(0 0 10px rgba(255,255,255,0.2));
            transition: 0.3s;
        }
        .sidebar-header img:hover { transform: scale(1.05); }
        .sidebar-header p { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 2px; color: rgba(255,255,255,0.4); margin-top: 1rem; font-weight: 700; }
        .nav-links-admin { display: flex; flex-direction: column; gap: 0.5rem; }
        .nav-group-title { padding: 1rem 1.5rem 0.25rem; font-size: 0.68rem; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: rgba(255,255,255,0.4); }
        .nav-links-admin a, .nav-links-admin button {
            display: block; padding: 0.8rem 1.5rem; color: #cbd5e1; text-decoration: none;
            transition: 0.3s; display: flex; align-items: center; gap: 0.8rem;
            background: none; border: none; width: 100%; text-align: left;
            font-family: inherit; font-size: 1rem; cursor: pointer;
        }
        .nav-links-admin a:hover, .nav-links-admin a.active,
        .nav-links-admin button:hover, .nav-links-admin button.active {
            background: #334155; color: white; border-left: 4px solid var(--accent); padding-left: calc(1.5rem - 4px);
        }
        .nav-links-admin i { width: 20px; }
        .admin-main { flex: 1; display: flex; flex-direction: column; }
        .admin-top { background: white; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .admin-content { padding: 2rem; flex: 1; }
        .badge-pending { background: #ef4444; color: white; font-size: 0.7rem; padding: 2px 6px; border-radius: 10px; margin-left: auto; }
        .user-info-top { display: flex; align-items: center; gap: 0.8rem; font-size: 0.9rem; font-weight: 500; }
        .role-tag { font-size: 0.7rem; padding: 4px 10px; border-radius: 4px; text-transform: uppercase; display: inline-block; background: #eee; color: #666; }
        .role-admin { background: #dcfce7; color: #166534; }
        .role-docente { background: #fef9c3; color: #854d0e; }
        .role-estudiante { background: #e0f2fe; color: #0369a1; }
        .role-tesoreria { background: #fef3c7; color: #92400e; }
        @media (max-width: 768px) {
            .sidebar-admin {
                position: fixed;
                left: -260px;
                top: 0;
                bottom: 0;
                z-index: 1000;
                transition: left 0.3s ease;
            }
            .sidebar-admin.show {
                left: 0;
            }
            .admin-main {
                width: 100vw;
                max-width: 100vw;
                overflow-x: hidden;
            }
            .btn-toggle-sidebar {
                display: block !important;
            }
            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(0,0,0,0.5);
                z-index: 999;
            }
            .sidebar-overlay.show {
                display: block;
            }
        }
    </style>
    <script>
        function toggleSubmenu(e, el) {
            e.preventDefault();
            const submenu = el.nextElementSibling;
            const chevron = el.querySelector('.fa-chevron-down');
            const isExpanded = el.getAttribute('aria-expanded') === 'true';

            el.setAttribute('aria-expanded', !isExpanded);
            if (!isExpanded) {
                submenu.style.display = 'block';
                if(chevron) chevron.style.transform = 'rotate(180deg)';
            } else {
                submenu.style.display = 'none';
                if(chevron) chevron.style.transform = 'none';
            }
        }
        function toggleSidebar() {
            document.querySelector('.sidebar-admin').classList.toggle('show');
            document.querySelector('.sidebar-overlay').classList.toggle('show');
        }
    </script>
</head>
<body>
    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>
    <div class="sidebar-admin">
        <div class="sidebar-header">
            <img src="../img/logo.png?v=<?= LOGO_VERSION ?>" alt="<?= htmlspecialchars(ACADEMY_NAME) ?> Logo">
            <p><?= ['admin' => 'Administración', 'docente' => 'Panel docente', 'tesoreria' => 'Tesorería'][$rol] ?? 'Panel' ?></p>
        </div>
        <nav class="nav-links-admin">
            <?php
            // Menú por grupos. Cada opción: [enlace, icono, etiqueta, roles, clave de opción activa].
            // La calificación de quizzes no tiene opción propia: se hace en la pestaña
            // Evaluaciones de cada curso (gestionar_curso.php).
            $menu_grupos = [
                '' => [
                    ['index', 'fa-chart-line', 'Dashboard', ['admin', 'docente', 'tesoreria'], 'index'],
                ],
                'Académico' => [
                    ['cursos', 'fa-book', $rol === 'docente' ? 'Mis cursos' : 'Cursos', ['admin', 'docente'], 'cursos'],
                    ['clases_vivo', 'fa-video', 'Clases en vivo', ['admin', 'docente'], 'clases_vivo'],
                    ['rutas', 'fa-route', 'Rutas de aprendizaje', ['admin'], 'rutas'],
                    ['categorias', 'fa-tags', 'Categorías', ['admin'], 'categorias'],
                ],
                'Estudiantes y ventas' => [
                    ['usuarios', 'fa-users', 'Usuarios', ['admin'], 'usuarios'],
                    ['metricas', 'fa-user-clock', 'Actividad de estudiantes', ['admin'], 'metricas'],
                    ['pagos', 'fa-credit-card', 'Pagos', ['admin', 'tesoreria'], 'pagos'],
                    ['prospectos', 'fa-envelope-open-text', 'Prospectos', ['admin'], 'prospectos'],
                ],
                'Sitio y sistema' => [
                    ['estructuracion', 'fa-globe', 'Sitio web', ['admin'], 'estructuracion'],
                    ['firmantes', 'fa-signature', 'Firmantes', ['admin'], 'firmantes'],
                    ['configuracion', 'fa-gears', 'Configuración', ['admin'], 'configuracion'],
                ],
            ];
            // Subpáginas: resaltan la opción de la que dependen
            $pagina_actual = basename($_SERVER['PHP_SELF'], '.php');
            $menu_padre = [
                'gestionar_curso' => 'cursos',
                'quiz' => 'cursos', 'ver_h5p' => 'cursos', 'ver_excel' => 'cursos', 'ver_tarea' => 'cursos',
                'ruta_cursos' => 'rutas',
                'verificar_pago' => 'pagos', 'ver_comprobante' => 'pagos',
            ];
            $menu_activo = $menu_padre[$pagina_actual] ?? $pagina_actual;
            foreach ($menu_grupos as $grupo => $opciones):
                $visibles = array_filter($opciones, fn($o) => in_array($rol, $o[3], true));
                if (!$visibles) continue;
            ?>
                <?php if ($grupo !== ''): ?><div class="nav-group-title"><?= htmlspecialchars($grupo) ?></div><?php endif; ?>
                <?php foreach ($visibles as [$href, $icono, $etiqueta, , $clave]): ?>
                <a href="<?= $href ?>" class="<?= $menu_activo === $clave ? 'active' : '' ?>"><i class="fa-solid <?= $icono ?>"></i> <?= htmlspecialchars($etiqueta) ?></a>
                <?php endforeach; ?>
            <?php endforeach; ?>

            <div class="nav-group-title">Mi cuenta</div>
            <a href="perfil" class="<?= $menu_activo === 'perfil' ? 'active' : '' ?>"><i class="fa-solid fa-user-pen"></i> Mi perfil</a>

            <div style="margin-top:2rem; padding:0 1.5rem;">
                <a href="../logout" style="background:#dc3545; color:white; border-radius:6px; padding:0.6rem; justify-content:center; border:none; display: flex; align-items: center; gap: 0.5rem; text-decoration: none;">
                    <i class="fa-solid fa-right-from-bracket"></i> Cerrar Sesión
                </a>
            </div>
            <p style="margin:1.5rem 0 1rem; text-align:center; font-size:0.7rem; color:rgba(255,255,255,0.4);">Mesh LMS v<?= htmlspecialchars(APP_VERSION) ?></p>
        </nav>
    </div>
    <div class="admin-main">
        <div class="admin-top">
            <div style="display:flex; align-items:center; gap: 1rem;">
                <button class="btn-toggle-sidebar" onclick="toggleSidebar()" style="display:none; background:none; border:none; font-size:1.5rem; color:var(--primary); cursor:pointer; padding:0;"><i class="fa-solid fa-bars"></i></button>
                <div class="user-info-top">
                <i class="fa-solid fa-user-circle" style="font-size:1.2rem;"></i>
                <span>Hola, <?= htmlspecialchars($_SESSION['user_name']) ?></span>
                <span class="role-tag role-<?= $rol ?>"><?= $rol ?></span>
            </div>
            </div>
            <a href="../index" target="_blank" style="font-size:0.85rem; color:var(--primary); text-decoration:none;">
                Ver sitio web <i class="fa-solid fa-arrow-up-right-from-square"></i>
            </a>
        </div>
        <div class="admin-content">


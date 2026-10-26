<?php
require_once 'config.php';
require_once __DIR__ . '/helpers/html_sanitizer.php';

$slug = $_GET['slug'] ?? '';
if (empty($slug)) {
    header('Location: rutas');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM rutas_aprendizaje WHERE slug = ?");
$stmt->execute([$slug]);
$ruta = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ruta) {
    header('Location: rutas');
    exit;
}

// Ocultar si está inactiva y no es admin
$rol_sesion = $_SESSION['user_rol'] ?? '';
$es_admin = in_array($rol_sesion, ['administrativo', 'admin']);
if (!$es_admin && !in_array($ruta['estado'], ['activo', 'inactivo'])) {
    header('Location: rutas');
    exit;
}

// Obtener cursos de la ruta
$s_cursos = $pdo->prepare("
    SELECT c.id, c.titulo, c.descripcion, c.duracion, c.nivel, rc.bloqueado_hasta_completar_anterior,
    c.imagen, c.que_aprenderas, c.requisitos, c.publico_objetivo, c.precio, c.precio_oferta, c.estado, c.enlace_externo,
    IF(c.estado = 'externo', c.modulos_externos, (SELECT COUNT(*) FROM modulos m WHERE m.curso_id = c.id AND m.estado = 'publicado')) as cant_modulos,
    IF(c.estado = 'externo', 0, (SELECT COUNT(*) FROM lecciones l JOIN modulos m ON l.modulo_id = m.id WHERE m.curso_id = c.id AND l.estado = 'publicado' AND m.estado = 'publicado')) as cant_lecciones
    FROM ruta_cursos rc
    JOIN cursos c ON rc.curso_id = c.id
    WHERE rc.ruta_id = ?
    ORDER BY rc.orden ASC
");
$s_cursos->execute([$ruta['id']]);
$cursos = $s_cursos->fetchAll(PDO::FETCH_ASSOC);

$total_modulos = array_sum(array_column($cursos, 'cant_modulos'));
$total_lecciones = array_sum(array_column($cursos, 'cant_lecciones'));

// Group courses by level
$cursos_por_nivel = [];
foreach ($cursos as $c) {
    $nivel = $c['nivel'] ?: 'General';
    if (!isset($cursos_por_nivel[$nivel])) {
        $cursos_por_nivel[$nivel] = [];
    }
    $cursos_por_nivel[$nivel][] = $c;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($ruta['meta_title'] ?: $ruta['titulo']) ?> - <?= htmlspecialchars(ACADEMY_NAME) ?></title>
    <meta name="description" content="<?= htmlspecialchars($ruta['meta_description'] ?: extracto_texto($ruta['descripcion'], 160)) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($ruta['titulo']) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($ruta['og_image'] ?: $ruta['imagen']) ?>">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="<?= FAVICON_URL ?>">
    <link rel="stylesheet" href="css/style.css">
    <style>
        :root {
            --primary: <?= $ajustes['color_primario'] ?? '#2A3547' ?>;
            --accent: <?= $ajustes['color_acento'] ?? '#FF6B35' ?>;
        }
        body { background: #f8fafc; }
        .hero-ruta {
            background: linear-gradient(rgba(42, 53, 71, 0.9), rgba(42, 53, 71, 0.95)), url('<?= $ruta['banner'] ?: $ruta['imagen'] ?>') center/cover no-repeat;
            color: white;
            padding: 8rem 0 4rem;
        }
        .breadcrumb { display: flex; gap: 0.5rem; font-size: 0.85rem; opacity: 0.8; margin-bottom: 2rem; }
        .breadcrumb a { color: white; text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }
        
        .ruta-info-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 3rem; margin-top: -2rem; }
        @media(max-width: 900px) { .ruta-info-grid { grid-template-columns: 1fr; } }
        
        .ruta-main { background: white; padding: 3rem; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        .ruta-sidebar { position: sticky; top: 2rem; background: white; padding: 2rem; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
        
        .course-item { display: flex; gap: 1.5rem; padding: 1.5rem; border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 1rem; position: relative; transition: all 0.3s ease; background: white; cursor: pointer; }
        .course-item:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.08); border-color: var(--primary); }
        .course-number { width: 40px; height: 40px; background: #f1f5f9; color: var(--primary); font-weight: 700; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .course-details h4 { margin: 0 0 0.5rem; color: var(--primary); font-size: 1.1rem; }
        .course-meta { display: flex; gap: 1rem; font-size: 0.8rem; color: #64748b; margin-bottom: 0.5rem; }
        .course-meta span { display: flex; align-items: center; gap: 0.3rem; }
        .course-desc { font-size: 0.9rem; color: #475569; line-height: 1.6; margin: 0; }
        
        /* Accordion CSS */
        details.level-accordion { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 1.5rem; overflow: hidden; }
        details.level-accordion summary { padding: 1.2rem 1.5rem; background: #f1f5f9; font-weight: 700; font-size: 1.2rem; color: var(--primary); cursor: pointer; display: flex; justify-content: space-between; align-items: center; user-select: none; }
        details.level-accordion summary::after { content: '\f107'; font-family: 'Font Awesome 6 Free'; font-weight: 900; transition: transform 0.3s; }
        details.level-accordion[open] summary { border-bottom: 1px solid #e2e8f0; }
        details.level-accordion[open] summary::after { transform: rotate(180deg); }
        .level-accordion-content { padding: 1.5rem; }
        
        .price-box { margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid #e2e8f0; }
        .price-box .old { text-decoration: line-through; color: #94a3b8; font-size: 1rem; }
        .price-box .new { font-size: 2.5rem; font-weight: 800; color: var(--primary); line-height: 1.2; }
        
        .sidebar-item { display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem; font-size: 0.95rem; color: #475569; }
        .sidebar-item i { color: var(--accent); width: 20px; font-size: 1.1rem; }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar" style="background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
        <div class="container nav-container">
            <a href="index" class="logo">
                <img src="img/logo.png?v=<?= LOGO_VERSION ?>" alt="<?= htmlspecialchars(ACADEMY_NAME) ?> Logo" style="max-width: 220px; max-height: 60px; width: auto; height: auto; display: block;">
            </a>
            <ul class="nav-links">
                <li><a href="index#features" style="color:var(--primary);">Por qué elegirnos</a></li>
                <li><a href="cursos" style="color:var(--primary);">Cursos</a></li>
                <li><a href="rutas" style="color:var(--accent); font-weight:700;">Rutas de Aprendizaje</a></li>
                <li><a href="index#testimonials" style="color:var(--primary);">Testimonios</a></li>
            </ul>
            <?php if(isset($_SESSION['user_id'])): ?>
                <a href="login" class="btn btn-primary"><i class="fa-solid fa-gauge-high"></i> Mi Panel</a>
            <?php else: ?>
                <a href="login" class="btn btn-primary"><i class="fa-solid fa-right-to-bracket"></i> Acceder</a>
            <?php endif; ?>
        </div>
    </nav>

    <header class="hero-ruta">
        <div class="container">
            <div class="breadcrumb">
                <a href="index">Inicio</a> &gt; <a href="rutas">Rutas de Aprendizaje</a> &gt; <span><?= htmlspecialchars($ruta['titulo']) ?></span>
            </div>
            <h1 style="font-size: 3.5rem; margin-bottom: 1.5rem; max-width: 800px; line-height: 1.2; color: white;"><?= htmlspecialchars($ruta['titulo']) ?></h1>
            <?php if (extracto_texto($ruta['descripcion']) !== ''): ?>
            <div style="font-size: 1.2rem; max-width: 700px; opacity: 0.9; line-height: 1.6; margin-bottom: 2rem;"><?= limpiar_html($ruta['descripcion']) ?></div>
            <?php endif; ?>
            
            <?php if (!empty($ruta['nivel'])): ?>
            <div style="display: flex; gap: 2rem; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 0.5rem;"><i class="fa-solid fa-signal" style="color:var(--accent);"></i> <?= htmlspecialchars($ruta['nivel']) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </header>

    <div class="container" style="position: relative; z-index: 10;">
        <div class="ruta-info-grid">
            <div class="ruta-main">
                <h2 style="color:var(--primary); margin-bottom:2rem; font-size:1.8rem;">Cursos en esta ruta</h2>
                
                <div class="courses-list">
                    <?php if(count($cursos) == 0): ?>
                        <p style="color:#64748b;">No hay cursos configurados para esta ruta aún.</p>
                    <?php else: ?>
                        <?php 
                        $global_index = 1;
                        $is_first = true;
                        foreach($cursos_por_nivel as $nivel => $cursos_nivel): 
                        ?>
                            <details class="level-accordion" <?= $is_first ? 'open' : '' ?>>
                                <summary>Nivel <?= htmlspecialchars($nivel) ?> (<?= count($cursos_nivel) ?> cursos)</summary>
                                <div class="level-accordion-content">
                                    <?php foreach($cursos_nivel as $c): ?>
                                    <div class="course-item" onclick='openModal(<?= htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8') ?>, true)'>
                                        <div class="course-number"><?= $global_index++ ?></div>
                                        <div class="course-details">
                                            <h4><?= htmlspecialchars($c['titulo']) ?></h4>
                                            <div class="course-meta">
                                                <span><i class="fa-solid fa-layer-group"></i> <?= $c['cant_modulos'] ?> Módulos</span>
                                                <span><i class="fa-solid fa-video"></i> <?= $c['cant_lecciones'] ?> Lecciones</span>
                                                <span><i class="fa-solid fa-clock"></i> <?= htmlspecialchars($c['duracion']) ?></span>
                                            </div>
                                            <p class="course-desc"><?= htmlspecialchars($c['descripcion']) ?></p>
                                            
                                            <?php if($c['bloqueado_hasta_completar_anterior']): ?>
                                            <div style="margin-top:0.8rem; font-size:0.75rem; color:#d97706; background:#fffbeb; padding:4px 10px; border-radius:4px; display:inline-block; border:1px solid #fef3c7;">
                                                <i class="fa-solid fa-lock"></i> Debes completar el curso anterior para desbloquear
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        <?php 
                        $is_first = false;
                        endforeach; 
                        ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div>
                <div class="ruta-sidebar">
                    <div class="price-box">
                        <?php if ($ruta['es_gratis']): ?>
                            <div class="new" style="color:#10b981;">GRATIS</div>
                        <?php else: ?>
                            <?php if (!empty($ruta['precio_oferta']) && $ruta['precio_oferta'] < $ruta['precio']): ?>
                                <div class="old">$ <?= number_format($ruta['precio'], 0, ',', '.') ?></div>
                                <div class="new">$ <?= number_format($ruta['precio_oferta'], 0, ',', '.') ?> <span style="font-size:1rem; font-weight:600; color:#64748b;"><?= $ruta['moneda'] ?></span></div>
                            <?php else: ?>
                                <div class="new">$ <?= number_format($ruta['precio'], 0, ',', '.') ?> <span style="font-size:1rem; font-weight:600; color:#64748b;"><?= $ruta['moneda'] ?></span></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div style="margin-bottom: 2rem;">
                        <h4 style="margin:0 0 1rem; color:var(--primary);">Detalles del Programa</h4>
                        <div class="sidebar-item"><i class="fa-solid fa-graduation-cap"></i> <span><?= count($cursos) ?> Cursos en total</span></div>
                        <div class="sidebar-item"><i class="fa-solid fa-layer-group"></i> <span><?= $total_modulos ?> Módulos</span></div>
                        <div class="sidebar-item"><i class="fa-solid fa-certificate"></i> <span>Certificado Profesional al finalizar</span></div>
                    </div>
                    
                    <?php if($ruta['estado'] === 'inactivo'): ?>
                        <button class="btn btn-block" style="background:#e2e8f0; color:#94a3b8; padding:1.2rem; cursor:not-allowed;"><i class="fa-solid fa-clock"></i> Próximamente</button>
                    <?php else: ?>
                        <a href="pago?tipo=ruta&id=<?= $ruta['id'] ?>" class="btn btn-primary btn-block" style="padding: 1.2rem; font-size: 1.1rem; box-shadow: 0 10px 15px -3px rgba(255,107,53,0.3);">
                            <?= $ruta['es_gratis'] ? 'Inscribirme Ahora' : 'Comprar Ruta Completa' ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <footer class="footer" style="background:var(--primary); padding:3rem 0; color:rgba(255,255,255,0.7); text-align:center; margin-top:4rem;">
        <div class="container">
            <h2 style="color:white; margin-bottom:1rem;"><i class="fa-solid fa-rocket"></i> <?= htmlspecialchars(ACADEMY_NAME) ?></h2>
            <p>&copy; <?= date('Y') ?> <?= htmlspecialchars(ACADEMY_NAME) ?>. Todos los derechos reservados.</p>
        </div>
    </footer>
    
    <?php include_once 'components/theme-1/course_modal.php'; ?>
    <?php include_once 'components/theme-1/whatsapp.php'; ?>
    <script src="js/main.js"></script>
</body>
</html>

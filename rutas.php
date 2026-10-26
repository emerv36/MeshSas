<?php
require_once 'config.php';
require_once __DIR__ . '/helpers/html_sanitizer.php';
require_once __DIR__ . '/helpers/imagen_helper.php';

// Fetch categories
$stmt_cats = $pdo->query("SELECT * FROM categorias ORDER BY nombre ASC");
$categorias_lista = $stmt_cats ? $stmt_cats->fetchAll(PDO::FETCH_ASSOC) : [];

$rol_sesion = $_SESSION['user_rol'] ?? '';
$where = [];
$params = [];

if ($rol_sesion === 'administrativo' || $rol_sesion === 'admin') {
    $where[] = "estado IN ('activo','privado')";
} else {
    $where[] = "estado = 'activo'";
}

// Filtro por categoría
$cat_filter = $_GET['cat'] ?? '';
if (!empty($cat_filter)) {
    $where[] = "categoria LIKE ?";
    $params[] = "%{$cat_filter}%";
}

$where_sql = implode(' AND ', $where);

// Ordenamiento
$sort = $_GET['sort'] ?? 'newest';
$order_sql = "ORDER BY id DESC";
if ($sort === 'az') {
    $order_sql = "ORDER BY titulo ASC";
} elseif ($sort === 'za') {
    $order_sql = "ORDER BY titulo DESC";
}

$stmt = $pdo->prepare("SELECT * FROM rutas_aprendizaje WHERE $where_sql $order_sql");
$stmt->execute($params);
$rutas = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rutas de Aprendizaje - <?= htmlspecialchars(ACADEMY_NAME) ?></title>
    <meta name="description" content="Descubre nuestras Rutas de Aprendizaje y conviértete en un experto paso a paso con los mejores cursos de <?= htmlspecialchars(ACADEMY_NAME) ?>.">
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Favicon -->
    <link rel="icon" href="<?= FAVICON_URL ?>">
    <!-- CSS -->
    <link rel="stylesheet" href="css/style.css">
    <style>
        :root {
            --primary: <?= $ajustes['color_primario'] ?? '#2A3547' ?>;
            --accent: <?= $ajustes['color_acento'] ?? '#FF6B35' ?>;
            --accent-hover: <?= !empty($ajustes['color_acento']) ? $ajustes['color_acento'] : '#e55a2b' ?>;
        }
        .route-card { background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.08); transition: 0.3s; display: flex; flex-direction: column; border: 1px solid #e2e8f0; }
        .route-card:hover { transform: translateY(-5px); box-shadow: 0 20px 40px rgba(0,0,0,0.12); }
        .route-img { height: 200px; background-size: cover; background-position: center; position: relative; }
        .route-content { padding: 2rem; flex: 1; display: flex; flex-direction: column; }
        .route-stats { display: flex; gap: 1rem; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #f1f5f9; font-size: 0.85rem; color: #64748b; font-weight: 600; }
        .route-stats span { display: flex; align-items: center; gap: 0.4rem; }
        .route-footer { margin-top: auto; padding-top: 1.5rem; display: flex; justify-content: space-between; align-items: center; }
        
        .price-container { display: flex; flex-direction: column; line-height: 1.2; }
        .price-old { font-size: 0.8rem; text-decoration: line-through; color: #94a3b8; }
        .price-new { font-size: 1.4rem; font-weight: 800; color: var(--primary); }
        .price-badge-free { font-size: 1.4rem; font-weight: 800; color: #10b981; }
        .discount-badge { background: #ef4444; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; font-weight: bold; margin-left: 0.5rem; }
    </style>
</head>
<body style="background: #f8fafc;">
    <!-- Navbar -->
    <nav class="navbar">
        <div class="container nav-container">
            <a href="index" class="logo">
                <img src="img/logo.png?v=<?= LOGO_VERSION ?>" alt="<?= htmlspecialchars(ACADEMY_NAME) ?> Logo">
            </a>
            <ul class="nav-links" id="navLinks">
                <li><a href="index#features" style="color:var(--primary);">Por qué elegirnos</a></li>
                <li><a href="cursos" style="color:var(--primary);">Cursos</a></li>
                <li><a href="rutas" style="color:var(--accent); font-weight:700;">Rutas de Aprendizaje</a></li>
                <li><a href="index#testimonials" style="color:var(--primary);">Testimonios</a></li>
            </ul>
            <div class="nav-right">
                <?php if(isset($_SESSION['user_id'])): ?>
                    <a href="login" class="btn btn-primary"><i class="fa-solid fa-gauge-high"></i> Mi Panel</a>
                <?php else: ?>
                    <a href="login" class="btn btn-primary"><i class="fa-solid fa-right-to-bracket"></i> Acceder</a>
                <?php endif; ?>
                <button class="hamburger" id="hamburgerBtn" aria-label="Abrir menú de navegación" aria-expanded="false">
                    <span></span><span></span><span></span>
                </button>
            </div>
        </div>
    </nav>

    <!-- Page Header -->
    <header class="section" style="padding: 5rem 0 3rem; background: linear-gradient(135deg, var(--primary), #1e293b); color: white; text-align: center;">
        <div class="container">
            <h1 style="font-size: clamp(2rem, 4vw, 3rem); margin-bottom: 1rem; color: white;">Rutas de Aprendizaje</h1>
            <p style="font-size: clamp(1rem, 2vw, 1.2rem); opacity: 0.9; max-width: 600px; margin: 0 auto;">Agrupamos nuestros mejores cursos para formarte como un profesional integral en las áreas más demandadas.</p>
        </div>
    </header>

    <!-- Routes Section -->
    <section class="section">
        <div class="container">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 2rem; flex-wrap:wrap; gap: 1rem;">
                <div class="course-filters" style="margin:0;">
                    <a href="rutas?sort=<?= htmlspecialchars($sort) ?>" class="filter-btn <?= empty($cat_filter) ? 'active' : '' ?>" style="text-decoration:none;">Todas</a>
                    <?php foreach($categorias_lista as $c): ?>
                        <a href="rutas?cat=<?= htmlspecialchars($c['slug']) ?>&sort=<?= htmlspecialchars($sort) ?>" class="filter-btn <?= $cat_filter === $c['slug'] ? 'active' : '' ?>" style="text-decoration:none;"><?= htmlspecialchars($c['nombre']) ?></a>
                    <?php endforeach; ?>
                </div>
                <div>
                    <select onchange="window.location.href=this.value" style="padding:0.6rem 1rem; border-radius:8px; border:1px solid #cbd5e1; font-family:inherit; color:var(--primary); font-weight:600; outline:none;">
                        <option value="rutas?cat=<?= htmlspecialchars($cat_filter) ?>&sort=newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Más recientes</option>
                        <option value="rutas?cat=<?= htmlspecialchars($cat_filter) ?>&sort=az" <?= $sort === 'az' ? 'selected' : '' ?>>Alfabético (A-Z)</option>
                        <option value="rutas?cat=<?= htmlspecialchars($cat_filter) ?>&sort=za" <?= $sort === 'za' ? 'selected' : '' ?>>Alfabético (Z-A)</option>
                    </select>
                </div>
            </div>

            <div class="courses-grid">
                <?php if (count($rutas) > 0): ?>
                    <?php foreach($rutas as $r): 
                        // Calcular totales de la ruta
                        $s_tot = $pdo->prepare("
                            SELECT 
                                COUNT(DISTINCT rc.curso_id) as cant_cursos,
                                SUM(IF(c.estado = 'externo', c.modulos_externos, (SELECT COUNT(*) FROM modulos m WHERE m.curso_id = c.id AND m.estado = 'publicado'))) AS cant_modulos
                            FROM ruta_cursos rc
                            JOIN cursos c ON rc.curso_id = c.id
                            WHERE rc.ruta_id = ?
                        ");
                        $s_tot->execute([$r['id']]);
                        $totales = $s_tot->fetch(PDO::FETCH_ASSOC);
                    ?>
                    <div class="route-card">
                        <div class="route-img" style="background-image: url('<?= htmlspecialchars(imagen_curso($r['imagen']), ENT_QUOTES, 'UTF-8') ?>');">
                            <?php if ($r['es_gratis']): ?>
                                <div style="position:absolute; top:1rem; right:1rem; background:#10b981; color:white; padding:4px 10px; border-radius:20px; font-weight:700; font-size:0.8rem; box-shadow:0 4px 6px rgba(0,0,0,0.1);">GRATIS</div>
                            <?php endif; ?>
                            <div style="position:absolute; bottom:1rem; left:1rem; background:rgba(255,255,255,0.9); padding:4px 10px; border-radius:20px; font-weight:700; font-size:0.75rem; color:var(--primary);">
                                <?= htmlspecialchars($r['nivel']) ?>
                            </div>
                        </div>
                        <div class="route-content">
                            <h3 style="margin-top:0; color:var(--primary); font-size:1.4rem; margin-bottom:0.5rem;"><?= htmlspecialchars($r['titulo']) ?></h3>
                            <p style="color:#64748b; font-size:0.95rem; line-height:1.5; margin-bottom:1rem; flex:1;"><?= htmlspecialchars(extracto_texto($r['descripcion'], 120)) ?></p>
                            
                            <div class="route-stats">
                                <span><i class="fa-solid fa-graduation-cap" style="color:var(--accent);"></i> <?= $totales['cant_cursos'] ?> Cursos</span>
                                <span><i class="fa-solid fa-layer-group" style="color:var(--accent);"></i> <?= $totales['cant_modulos'] ?: 0 ?> Módulos</span>
                            </div>

                            <div class="route-footer">
                                <div class="price-container">
                                    <span style="font-size:0.7rem; color:#94a3b8; text-transform:uppercase; font-weight:700;">Inversión</span>
                                    <?php if ($r['es_gratis']): ?>
                                        <span class="price-badge-free">GRATIS</span>
                                    <?php else: ?>
                                        <?php if (!empty($r['precio_oferta']) && $r['precio_oferta'] < $r['precio']): ?>
                                            <div>
                                                <span class="price-old">$ <?= number_format($r['precio'], 0, ',', '.') ?></span>
                                                <?php if($r['descuento_porcentaje']): ?>
                                                    <span class="discount-badge">-<?= $r['descuento_porcentaje'] ?>%</span>
                                                <?php endif; ?>
                                            </div>
                                            <span class="price-new">$ <?= number_format($r['precio_oferta'], 0, ',', '.') ?> <?= $r['moneda'] ?></span>
                                        <?php else: ?>
                                            <span class="price-new">$ <?= number_format($r['precio'], 0, ',', '.') ?> <?= $r['moneda'] ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <div style="display:flex; flex-direction:column; gap:0.5rem; min-width: 140px;">
                                    <a href="ruta?slug=<?= $r['slug'] ?>" class="btn btn-outline-dark" style="padding:0.6rem 1rem; text-align:center;">Ver Ruta</a>
                                    <a href="pago?tipo=ruta&id=<?= $r['id'] ?>" class="btn btn-primary" style="padding:0.6rem 1rem; text-align:center;">
                                        <?= $r['es_gratis'] ? 'Inscribirme' : 'Comprar' ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="grid-column: 1/-1; text-align:center; color:#94a3b8; padding:3rem;">No hay rutas disponibles por el momento.</p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container footer-grid">
            <div class="footer-brand">
                <a href="index" class="logo light">
                    <i class="fa-solid fa-rocket"></i>
                    <?= htmlspecialchars(ACADEMY_NAME) ?>
                </a>
                <p>Tu aliado estratégico para alcanzar el éxito profesional a través de la educación de vanguardia.</p>
            </div>
            <div class="footer-links">
                <h3>Enlaces rápidos</h3>
                <ul>
                    <li><a href="cursos">Cursos</a></li>
                    <li><a href="index#features">Nosotros</a></li>
                    <li><a href="#">Blog</a></li>
                    <li><a href="index#cta">Contacto</a></li>
                    <li><a href="#">Políticas de privacidad</a></li>
                </ul>
            </div>
            <div class="footer-social">
                <h3>Síguenos</h3>
                <div class="social-icons">
                    <?php if (!empty($ajustes['link_linkedin'])): ?>
                        <a href="<?= htmlspecialchars($ajustes['link_linkedin']) ?>" target="_blank"><i
                                class="fa-brands fa-linkedin-in"></i></a>
                    <?php endif; ?>
                    <?php if (!empty($ajustes['link_instagram'])): ?>
                        <a href="<?= htmlspecialchars($ajustes['link_instagram']) ?>" target="_blank"><i
                                class="fa-brands fa-instagram"></i></a>
                    <?php endif; ?>
                    <?php if (!empty($ajustes['link_facebook'])): ?>
                        <a href="<?= htmlspecialchars($ajustes['link_facebook']) ?>" target="_blank"><i
                                class="fa-brands fa-facebook-f"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="container">
            <div class="footer-bottom">
                <p>&copy; <?= date('Y') ?> <?= htmlspecialchars(ACADEMY_NAME) ?>. Todos los derechos reservados.</p>
            </div>
        </div>
    </footer>
    <?php 
    if (file_exists(__DIR__ . '/components/theme-1/whatsapp.php')) {
        include_once __DIR__ . '/components/theme-1/whatsapp.php';
    }
    ?>
    <script src="js/main.js"></script>
</body>
</html>

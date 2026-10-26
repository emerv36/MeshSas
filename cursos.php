<?php
require_once 'config.php';
require_once __DIR__ . '/helpers/imagen_helper.php';

$stmt_cats = $pdo->query("SELECT * FROM categorias ORDER BY nombre ASC");
$categorias_lista = $stmt_cats ? $stmt_cats->fetchAll(PDO::FETCH_ASSOC) : [];

$rol_sesion = $_SESSION['user_rol'] ?? '';
$where = [];
$params = [];

if ($rol_sesion === 'administrativo') {
    $where[] = "estado = 'privado'";
} elseif ($rol_sesion === 'admin') {
    $where[] = "estado IN ('activo', 'privado')";
} else {
    $where[] = "estado = 'activo'";
}

$cat_filter = trim((string) ($_GET['cat'] ?? ''));
if ($cat_filter !== '') {
    $where[] = "categoria LIKE ?";
    $params[] = "%{$cat_filter}%";
}

$sort = (string) ($_GET['sort'] ?? 'newest');
$order_sql = match ($sort) {
    'az' => 'ORDER BY titulo ASC',
    'za' => 'ORDER BY titulo DESC',
    default => 'ORDER BY id DESC',
};
if (!in_array($sort, ['newest', 'az', 'za'], true)) {
    $sort = 'newest';
}

$where_sql = implode(' AND ', $where);
$stmt = $pdo->prepare("
    SELECT cursos.*,
           IF(cursos.estado = 'externo', cursos.modulos_externos,
              (SELECT COUNT(*) FROM modulos m WHERE m.curso_id = cursos.id AND m.estado = 'publicado')) AS cant_modulos
    FROM cursos
    WHERE {$where_sql}
    {$order_sql}
");
$stmt->execute($params);
$cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$proximos_where = ["estado = 'inactivo'"];
$proximos_params = [];
if ($cat_filter !== '') {
    $proximos_where[] = "categoria LIKE ?";
    $proximos_params[] = "%{$cat_filter}%";
}
$proximos_where_sql = implode(' AND ', $proximos_where);
$stmt_prox = $pdo->prepare("
    SELECT cursos.*,
           IF(cursos.estado = 'externo', cursos.modulos_externos,
              (SELECT COUNT(*) FROM modulos m WHERE m.curso_id = cursos.id AND m.estado = 'publicado')) AS cant_modulos
    FROM cursos
    WHERE {$proximos_where_sql}
    {$order_sql}
");
$stmt_prox->execute($proximos_params);
$cursos_proximos = $stmt_prox->fetchAll(PDO::FETCH_ASSOC);

function catalogUrl(string $sort, string $category = ''): string
{
    $query = ['sort' => $sort];
    if ($category !== '') {
        $query['cat'] = $category;
    }

    return 'cursos?' . http_build_query($query);
}

function modalCourseJson(array $curso): string
{
    return htmlspecialchars(
        json_encode($curso, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ENT_QUOTES,
        'UTF-8'
    );
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo de Cursos - <?= htmlspecialchars(ACADEMY_NAME) ?></title>
    <meta name="description" content="Explora los cursos virtuales de <?= htmlspecialchars(ACADEMY_NAME) ?>, filtra por categoría y encuentra la formación que necesitas para impulsar tu carrera.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="<?= FAVICON_URL ?>">
    <link rel="stylesheet" href="css/style.css">
    <style>
        :root {
            --primary: <?= htmlspecialchars($ajustes['color_primario'] ?? '#2A3547') ?>;
            --accent: <?= htmlspecialchars($ajustes['color_acento'] ?? '#FF6B35') ?>;
        }

        body { background: #f8fafc; }
        .catalog-controls { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 2rem; flex-wrap: wrap; }
        .catalog-controls .course-filters { margin: 0; }
        .catalog-sort { padding: 0.7rem 1rem; border-radius: 8px; border: 1px solid #cbd5e1; background: white; font-family: inherit; color: var(--primary); font-weight: 600; outline: none; }
        .catalog-empty { grid-column: 1 / -1; text-align: center; color: #64748b; padding: 3rem 1rem; }
        .coming-soon-section { padding: 4rem 0; background: #eef2f7; }
        .course-card.coming-soon { position: relative; opacity: 0.82; filter: grayscale(25%); transition: 0.3s; }
        .course-card.coming-soon:hover { opacity: 1; filter: grayscale(0%); }
        .coming-soon-badge { position: absolute; top: 1rem; left: 1rem; background: linear-gradient(135deg, #1e293b, #334155); color: white; font-size: 0.7rem; font-weight: 800; letter-spacing: 1.5px; text-transform: uppercase; padding: 5px 14px; border-radius: 20px; display: flex; align-items: center; gap: 6px; z-index: 2; box-shadow: 0 2px 8px rgba(0,0,0,0.25); }
        .coming-soon-badge i { color: #f59e0b; }
        .btn-coming-soon { background: #e2e8f0; color: #64748b; border-radius: 8px; padding: 0.6rem 1.4rem; font-weight: 600; font-size: 0.875rem; display: inline-flex; align-items: center; gap: 0.4rem; }

        @media (max-width: 768px) {
            .catalog-controls { align-items: stretch; }
            .catalog-controls .course-filters { width: 100%; justify-content: flex-start; overflow-x: auto; padding-bottom: 0.5rem; }
            .catalog-sort { width: 100%; }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container nav-container">
            <a href="index" class="logo">
                <img src="img/logo.png?v=<?= LOGO_VERSION ?>" alt="<?= htmlspecialchars(ACADEMY_NAME) ?> Logo">
            </a>
            <ul class="nav-links" id="navLinks">
                <li><a href="index#features">Por qué elegirnos</a></li>
                <li><a href="cursos" style="color:var(--accent); font-weight:700;">Cursos</a></li>
                <li><a href="rutas">Rutas de Aprendizaje</a></li>
                <li><a href="index#testimonials">Testimonios</a></li>
            </ul>
            <div class="nav-right">
                <?php if (isset($_SESSION['user_id'])): ?>
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

    <header class="section" style="padding: 5rem 0 3rem; background:linear-gradient(135deg, var(--primary), #1e293b); color:white; text-align:center;">
        <div class="container">
            <h1 style="font-size: clamp(2rem, 4vw, 3rem); margin-bottom:1rem; color:white;">Catálogo de Cursos</h1>
            <p style="font-size: clamp(1rem, 2vw, 1.2rem); opacity:0.9; max-width:650px; margin:0 auto;">Desarrolla habilidades prácticas con formación flexible, acompañamiento experto y certificación.</p>
        </div>
    </header>
        </div>
    </header>

    <main>
        <section class="courses section bg-pattern">
            <div class="container">
                <div class="catalog-controls">
                    <div class="course-filters">
                        <a href="<?= htmlspecialchars(catalogUrl($sort)) ?>" class="filter-btn <?= $cat_filter === '' ? 'active' : '' ?>" style="text-decoration:none;">Todos</a>
                        <?php foreach ($categorias_lista as $categoria): ?>
                            <a href="<?= htmlspecialchars(catalogUrl($sort, (string) $categoria['slug'])) ?>"
                               class="filter-btn <?= $cat_filter === $categoria['slug'] ? 'active' : '' ?>"
                               style="text-decoration:none;">
                                <?= htmlspecialchars($categoria['nombre']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <select class="catalog-sort" aria-label="Ordenar cursos" onchange="window.location.href=this.value">
                        <option value="<?= htmlspecialchars(catalogUrl('newest', $cat_filter)) ?>" <?= $sort === 'newest' ? 'selected' : '' ?>>Más recientes</option>
                        <option value="<?= htmlspecialchars(catalogUrl('az', $cat_filter)) ?>" <?= $sort === 'az' ? 'selected' : '' ?>>Alfabético (A-Z)</option>
                        <option value="<?= htmlspecialchars(catalogUrl('za', $cat_filter)) ?>" <?= $sort === 'za' ? 'selected' : '' ?>>Alfabético (Z-A)</option>
                    </select>
                </div>

                <div class="courses-grid">
                    <?php if ($cursos): ?>
                        <?php foreach ($cursos as $curso): ?>
                            <article class="course-card">
                                <div class="course-img">
                                    <?php if (!empty($curso['precio_oferta']) && (float) $curso['precio_oferta'] > 0): ?>
                                        <div class="price-badge" style="background:#ef4444; top:1rem;">OFERTA</div>
                                        <div class="price-badge" style="top:3.5rem; display:flex; flex-direction:column; align-items:center; line-height:1.2;">
                                            <del style="font-size:0.75rem; opacity:0.8;">$ <?= number_format((float) $curso['precio'], 0, ',', '.') ?></del>
                                            <span>$ <?= number_format((float) $curso['precio_oferta'], 0, ',', '.') ?></span>
                                        </div>
                                    <?php elseif ((float) $curso['precio'] <= 0): ?>
                                        <div class="price-badge free">GRATIS</div>
                                    <?php else: ?>
                                        <div class="price-badge">$ <?= number_format((float) $curso['precio'], 0, ',', '.') ?></div>
                                    <?php endif; ?>
                                    <img src="<?= htmlspecialchars(imagen_curso($curso['imagen']), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($curso['titulo']) ?>">
                                </div>
                                <div class="course-content">
                                    <h3><?= htmlspecialchars($curso['titulo']) ?></h3>
                                    <p><?= htmlspecialchars($curso['descripcion']) ?></p>
                                    <div class="course-meta">
                                        <span><i class="fa-solid fa-calendar-days"></i> <?= htmlspecialchars($curso['duracion']) ?></span>
                                        <span><i class="fa-solid fa-layer-group"></i> <?= htmlspecialchars($curso['nivel']) ?></span>
                                    </div>
                                    <div class="course-footer">
                                        <button type="button" class="btn btn-outline-dark" onclick="openModal(<?= modalCourseJson($curso) ?>)">Más info</button>
                                        <?php if (!empty($curso['enlace_externo'])): ?>
                                            <a href="<?= htmlspecialchars($curso['enlace_externo']) ?>" target="_blank" class="btn btn-primary">
                                                Ir al Curso <i class="fa-solid fa-external-link-alt" style="margin-left:5px; font-size:0.85em;"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="pago?id=<?= (int) $curso['id'] ?>" class="btn btn-primary">
                                                <?= (float) $curso['precio'] <= 0 ? 'Inscribirme Gratis' : 'Inscribirme' ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="catalog-empty">
                            <i class="fa-solid fa-book-open" style="font-size:2rem; color:var(--accent); margin-bottom:1rem;"></i>
                            <p>No hay cursos disponibles para esta categoría por el momento.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <?php if ($cursos_proximos): ?>
            <section class="coming-soon-section">
                <div class="container">
                    <div class="section-header" style="margin-bottom:2rem;">
                        <h2 style="font-size:1.8rem;"><i class="fa-solid fa-clock" style="color:var(--accent); margin-right:0.5rem;"></i> Próximamente</h2>
                        <p>Estos cursos estarán disponibles muy pronto. ¡Mantente atento!</p>
                    </div>
                    <div class="courses-grid">
                        <?php foreach ($cursos_proximos as $curso): ?>
                            <article class="course-card coming-soon">
                                <div class="course-img" style="position:relative;">
                                    <span class="coming-soon-badge"><i class="fa-solid fa-hourglass-half"></i> Próximamente</span>
                                    <img src="<?= htmlspecialchars(imagen_curso($curso['imagen']), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($curso['titulo']) ?>">
                                </div>
                                <div class="course-content">
                                    <h3><?= htmlspecialchars($curso['titulo']) ?></h3>
                                    <p><?= htmlspecialchars($curso['descripcion']) ?></p>
                                    <div class="course-meta">
                                        <span><i class="fa-solid fa-calendar-days"></i> <?= htmlspecialchars($curso['duracion']) ?></span>
                                        <span><i class="fa-solid fa-layer-group"></i> <?= htmlspecialchars($curso['nivel']) ?></span>
                                    </div>
                                    <div class="course-footer">
                                        <button type="button" class="btn btn-outline-dark" onclick="openModal(<?= modalCourseJson($curso) ?>, true)">Más info</button>
                                        <span class="btn-coming-soon"><i class="fa-solid fa-lock"></i> No disponible</span>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    </main>

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

    <?php include_once 'components/theme-1/course_modal.php'; ?>
    <?php include_once 'components/theme-1/whatsapp.php'; ?>
    <script src="js/main.js"></script>
</body>
</html>

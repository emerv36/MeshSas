<?php
require_once 'config.php';
require_once __DIR__ . '/helpers/csrf_helper.php';
require_once __DIR__ . '/helpers/plantillas_helper.php';
require_once __DIR__ . '/helpers/imagen_helper.php';

$plantilla = plantilla_activa($ajustes);

// Fetch banner global (botones, features, cta)
$stmt = $pdo->query("SELECT * FROM banner LIMIT 1");
$banner = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch slides
$slides = $pdo->query("SELECT * FROM banner_slides ORDER BY orden ASC")->fetchAll(PDO::FETCH_ASSOC);
if (empty($slides)) {
    $slides[] = [
        'titulo' => 'Potencia tu carrera con ' . ACADEMY_NAME,
        'subtitulo' => 'Cursos virtuales prácticos para adquirir las habilidades que el mercado laboral demanda. Aprende a tu ritmo, con certificados válidos y acompañamiento experto.',
        'imagen_fondo' => 'https://images.unsplash.com/photo-1522071820081-009f0129c71c?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80'
    ];
}

// Fetch testimonios
$testimonios = $pdo->query("SELECT * FROM testimonios ORDER BY orden ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch caracteristicas
$caracteristicas = $pdo->query("SELECT * FROM caracteristicas ORDER BY orden ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(ACADEMY_NAME) ?> - Potencia tu carrera</title>
    <meta name="description" content="Cursos virtuales prácticos para adquirir las habilidades que el mercado laboral demanda. Aprende a tu ritmo, con certificados válidos y acompañamiento experto.">
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Swiper CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css" />
    <!-- Favicon -->
    <link rel="icon" href="<?= FAVICON_URL ?>">
    <!-- CSS -->
    <link rel="stylesheet" href="css/style.css"> <!-- Common global styles -->
    <link rel="stylesheet" href="css/theme-<?= $plantilla ?>.css">
    <style>
        :root {
            --primary: <?= $ajustes['color_primario'] ?? '#2A3547' ?>;
            --accent: <?= $ajustes['color_acento'] ?? '#FF6B35' ?>;
            --accent-hover: <?= !empty($ajustes['color_acento']) ? $ajustes['color_acento'] : '#e55a2b' ?>;
        }
        
        .hero { position: relative; overflow: hidden; }
        .hero-swiper { width: 100%; height: 100%; }
        .swiper-slide { height: auto; }
        .swiper-slide-bg {
            width: 100%; min-height: 85vh;
            display: flex; align-items: center;
            position: relative;
            padding: 6rem 0 4rem;
        }
        @media (max-width: 768px) {
            .swiper-slide-bg { min-height: 520px; padding: 4.5rem 0 3.5rem; }
        }
        @media (max-width: 480px) {
            .swiper-slide-bg { min-height: 480px; padding: 3.5rem 0 3rem; }
        }
        .swiper-pagination-bullet { background: #fff; opacity: 0.5; }
        .swiper-pagination-bullet-active { background: var(--accent); opacity: 1; }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar">
        <div class="container nav-container">
            <a href="#" class="logo">
                <img src="img/logo.png?v=<?= LOGO_VERSION ?>" alt="<?= htmlspecialchars(ACADEMY_NAME) ?> Logo">
            </a>
            <ul class="nav-links" id="navLinks">
                <li><a href="index#features">Por qué elegirnos</a></li>
                <li><a href="cursos">Cursos</a></li>
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

    <?php
    // Fetch active blocks from database
    $stmt_bloques = $pdo->query("SELECT identificador FROM bloques_home WHERE visible = 1 ORDER BY orden ASC");
    $bloques_activos = $stmt_bloques->fetchAll(PDO::FETCH_COLUMN);

    foreach ($bloques_activos as $bloque) {
        $file = __DIR__ . '/components/theme-' . $plantilla . '/' . $bloque . '.php';
        
        // Sistema de Fallback: Si no existe en el tema actual, usa el original (theme-1)
        if (!file_exists($file)) {
            $file = __DIR__ . '/components/theme-1/' . $bloque . '.php';
        }
        
        if (file_exists($file)) {
            include $file;
        }
    }
    ?>

    <!-- Footer -->
    <footer class="footer">
        <div class="container footer-grid">
            <div class="footer-brand">
                <a href="#" class="logo light">
                    <i class="fa-solid fa-rocket"></i>
                    <?= htmlspecialchars(ACADEMY_NAME) ?>
                </a>
                <p><?= htmlspecialchars($banner['footer_leyenda'] ?? 'Tu aliado estratégico para alcanzar el éxito profesional a través de la educación de vanguardia.') ?></p>
            </div>
            <div class="footer-links">
                <h4>Enlaces Rápidos</h4>
                <ul>
                    <li><a href="cursos">Ver Cursos</a></li>
                    <li><a href="rutas">Rutas de Aprendizaje</a></li>
                    <li><a href="index#features">Nosotros</a></li>
                    <li><a href="login">Acceso Alumnos</a></li>
                    <li><a href="politica-privacidad.php">Política de Privacidad</a></li>
                    <li><a href="politica-cookies.php">Política de Cookies</a></li>
                </ul>
            </div>
            <div class="footer-social">
                <h4>Síguenos</h4>
                <div class="social-icons">
                    <a href="#"><i class="fa-brands fa-facebook-f"></i></a>
                    <a href="#"><i class="fa-brands fa-instagram"></i></a>
                    <a href="#"><i class="fa-brands fa-linkedin-in"></i></a>
                    <a href="#"><i class="fa-brands fa-youtube"></i></a>
                </div>
            </div>
        </div>
        <div class="container">
            <div class="footer-bottom">
                <p>&copy; <?= date('Y') ?> <?= htmlspecialchars(ACADEMY_NAME) ?>. Todos los derechos reservados.</p>
            </div>
        </div>
    </footer>

    <!-- Swiper JS -->
    <script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile Menu Toggle
            const hamburgerBtn = document.getElementById('hamburgerBtn');
            const navLinks = document.getElementById('navLinks');
            
            if (hamburgerBtn && navLinks) {
                function toggleMenu(open) {
                    const isOpen = typeof open === 'boolean' ? open : !navLinks.classList.contains('open');
                    hamburgerBtn.classList.toggle('open', isOpen);
                    hamburgerBtn.classList.toggle('active', isOpen);
                    navLinks.classList.toggle('open', isOpen);
                    navLinks.classList.toggle('active', isOpen);
                    hamburgerBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                }

                hamburgerBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleMenu();
                });

                // Close menu when clicking links
                navLinks.querySelectorAll('a').forEach(link => {
                    link.addEventListener('click', () => toggleMenu(false));
                });

                // Close when clicking outside
                document.addEventListener('click', function(e) {
                    if (navLinks.classList.contains('open') && !navLinks.contains(e.target) && !hamburgerBtn.contains(e.target)) {
                        toggleMenu(false);
                    }
                });

                // Close on Escape key
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && navLinks.classList.contains('open')) {
                        toggleMenu(false);
                    }
                });
            }

            // Swiper Init
            const swiper = new Swiper('.hero-swiper', {
                loop: true,
                effect: 'slide',
                autoplay: {
                    delay: 5000,
                    disableOnInteraction: false,
                },
                pagination: {
                    el: '.swiper-pagination',
                    clickable: true,
                },
            });

            // CTA Form AJAX Submission
            const ctaForm = document.getElementById('cta-form');
            if (ctaForm) {
                ctaForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const btn = document.getElementById('cta-submit-btn');
                    const successMsg = document.getElementById('cta-success-msg');
                    const errorMsg = document.getElementById('cta-error-msg');
                    
                    btn.disabled = true;
                    btn.innerText = 'Enviando...';
                    successMsg.style.display = 'none';
                    errorMsg.style.display = 'none';
                    
                    const formData = new FormData(ctaForm);
                    
                    fetch('submit_lead.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            successMsg.innerHTML = '<i class="fa-solid fa-check-circle"></i> ' + data.message;
                            successMsg.style.display = 'block';
                            ctaForm.reset();
                        } else {
                            errorMsg.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + data.message;
                            errorMsg.style.display = 'block';
                        }
                    })
                    .catch(err => {
                        errorMsg.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Ocurrió un error inesperado.';
                        errorMsg.style.display = 'block';
                    })
                    .finally(() => {
                        btn.disabled = false;
                        btn.innerText = 'Solicitar Asesoría';
                    });
                });
            }
        });
    </script>
</body>
</html>

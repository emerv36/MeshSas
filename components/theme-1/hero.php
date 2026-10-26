<!-- Hero Section -->
<header class="hero">
    <div class="swiper hero-swiper">
        <div class="swiper-wrapper">
            <?php foreach($slides as $s): ?>
            <div class="swiper-slide">
                <div class="swiper-slide-bg" style="background: url('<?= htmlspecialchars(strpos($s['imagen_fondo'], 'http') === 0 ? $s['imagen_fondo'] : $s['imagen_fondo']) ?>') center/cover no-repeat;">
                    <div class="hero-overlay"></div>
                    <div class="container hero-content">
                        <h1><?= htmlspecialchars($s['titulo']) ?></h1>
                        <p><?= nl2br(htmlspecialchars($s['subtitulo'])) ?></p>
                        <div class="hero-buttons">
                            <a href="cursos" class="btn btn-primary"><?= htmlspecialchars($banner['btn_principal_texto'] ?? 'Explorar cursos') ?></a>
                            <a href="#cta" class="btn btn-outline-light"><?= htmlspecialchars($banner['btn_secundario_texto'] ?? 'Regístrate gratis') ?></a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="swiper-pagination"></div>
    </div>
</header>


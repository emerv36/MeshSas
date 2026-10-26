<!-- Features Section -->
<section id="features" class="features section">
    <div class="container">
        <div class="section-header">
            <h2><?= htmlspecialchars($banner['features_titulo'] ?? '¿Por qué somos diferentes?') ?></h2>
            <p><?= htmlspecialchars($banner['features_subtitulo'] ?? 'Te preparamos para el mundo real con herramientas prácticas.') ?></p>
        </div>
        <div class="features-grid">
            <div class="features-content">
                <?php foreach($caracteristicas as $c): ?>
                <div class="feature-card">
                    <div class="feature-icon"><i class="<?= htmlspecialchars($c['icono']) ?>"></i></div>
                    <div>
                        <h3><?= htmlspecialchars($c['titulo']) ?></h3>
                        <p><?= htmlspecialchars($c['descripcion']) ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="features-image">
                <img src="<?= !empty($banner['img_features']) ? htmlspecialchars($banner['img_features']) : 'img/WEB.jpg' ?>" alt="Aula Virtual">
            </div>
        </div>
    </div>
</section>

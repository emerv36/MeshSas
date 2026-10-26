<!-- Features Section Corporate Exact (Theme 2) -->
<section id="features" class="features section theme-2-features" style="background-color: #ffffff; padding: 4rem 0;">
    <div class="container" style="max-width: 1100px;">
        
        <div class="section-header" style="text-align: center; margin-bottom: 4rem;">
            <h2 style="font-size: 2rem; color: #0f172a; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; font-family: var(--theme-font);">
                <?= htmlspecialchars($banner['features_titulo'] ?? '¿Por qué elegirnos?') ?>
            </h2>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 3rem; text-align: left;">
            <?php foreach($caracteristicas as $c): ?>
            <div>
                <div style="width: 50px; height: 50px; color: #3b82f6; display: flex; align-items: center; justify-content: flex-start; font-size: 2rem; margin-bottom: 1rem;">
                    <i class="<?= htmlspecialchars($c['icono']) ?>"></i>
                </div>
                <h3 style="color: #0f172a; font-size: 1.1rem; margin-bottom: 0.8rem; font-weight: 800; font-family: var(--theme-font);">
                    <?= htmlspecialchars($c['titulo']) ?>
                </h3>
                <p style="color: #475569; line-height: 1.6; margin: 0; font-size: 0.95rem;">
                    <?= htmlspecialchars($c['descripcion']) ?>
                </p>
            </div>
            <?php endforeach; ?>
        </div>
        
    </div>
</section>

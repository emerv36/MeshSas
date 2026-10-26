<!-- Hero Section Corporate Exact (Theme 2) -->
<header class="hero theme-2-hero" style="background: white; padding: 2rem 0;">
    <div class="container" style="max-width: 1300px; margin: 0 auto; padding: 0 1rem;">
        
        <?php 
        // index.php garantiza al menos una diapositiva; esta plantilla muestra solo la primera
        $main_slide = $slides[0];
        $hero_img = !empty($main_slide['imagen_fondo']) ? $main_slide['imagen_fondo'] : 'https://images.unsplash.com/photo-1522071820081-009f0129c71c?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80';
        ?>
        
        <!-- Contenedor del Hero tipo Caja (Boxed) -->
        <div style="position: relative; border-radius: 24px; overflow: hidden; min-height: 550px; display: flex; align-items: center; box-shadow: 0 20px 40px -10px rgba(0,0,0,0.1);">
            
            <!-- Imagen de fondo -->
            <img src="<?= htmlspecialchars($hero_img) ?>" alt="" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; z-index: 0;">
            
            <!-- Overlay degradado para asegurar lectura del texto -->
            <div style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: linear-gradient(90deg, rgba(15, 23, 42, 0.9) 0%, rgba(15, 23, 42, 0.7) 40%, rgba(15, 23, 42, 0) 100%); z-index: 1;"></div>

            <!-- Contenido Izquierda -->
            <div style="position: relative; z-index: 2; padding: 4rem 4rem; max-width: 700px;">
                <h1 style="font-size: clamp(2.5rem, 5vw, 4.5rem); color: #ffffff; line-height: 1.1; margin-bottom: 1.5rem; font-weight: 800; text-transform: uppercase; font-family: var(--theme-font);">
                    <?= htmlspecialchars($main_slide['titulo'] ?? '') ?>
                </h1>
                <p style="font-size: 1.2rem; color: #e2e8f0; margin-bottom: 2.5rem; line-height: 1.6; max-width: 500px;">
                    <?= nl2br(htmlspecialchars($main_slide['subtitulo'] ?? '')) ?>
                </p>
                <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                    <a href="cursos" style="display: inline-block; padding: 0.9rem 1.8rem; background: white; color: #0f172a; border-radius: 30px; font-weight: 700; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px; transition: all 0.3s ease;" class="hover-btn-solid">
                        <?= htmlspecialchars($banner['btn_principal_texto'] ?? 'Ver cursos') ?>
                    </a>
                    <a href="#cta" style="display: inline-block; padding: 0.9rem 1.8rem; background: transparent; color: white; border: 2px solid white; border-radius: 30px; font-weight: 700; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px; transition: all 0.3s ease;" class="hover-btn-outline">
                        <?= htmlspecialchars($banner['btn_secundario_texto'] ?? 'Conocer más') ?>
                    </a>
                </div>
            </div>

        </div>
    </div>
</header>

<style>
.hover-btn-solid:hover { background: #e2e8f0 !important; transform: translateY(-2px); }
.hover-btn-outline:hover { background: rgba(255,255,255,0.1) !important; transform: translateY(-2px); }
@media (max-width: 768px) {
    .theme-2-hero > div > div { padding: 3rem 2rem !important; }
}
</style>

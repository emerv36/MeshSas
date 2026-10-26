<?php
// Últimos cursos activos (los mismos que muestra el catálogo público)
$cursos_landing = $pdo->query("SELECT id, titulo, descripcion, categoria, imagen FROM cursos WHERE estado = 'activo' ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
?>
<!-- Cursos Section Corporate (Theme 2) -->
<section id="cursos" class="cursos section theme-2-cursos" style="background-color: #f8fafc; padding: 5rem 0;">
    <div class="container" style="max-width: 1000px;">

        <div class="section-header" style="text-align: center; margin-bottom: 3rem;">
            <h2 style="font-size: 2rem; color: #0f172a; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; font-family: var(--theme-font);">
                Nuestros programas
            </h2>
        </div>

        <!-- Course Cards (Horizontal) -->
        <div style="display: flex; flex-direction: column; gap: 2rem;">
            <?php foreach($cursos_landing as $curso): ?>
            <div style="background: white; border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden; display: flex; flex-wrap: wrap; align-items: stretch; transition: all 0.3s ease; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);" class="t2-course-card">

                <!-- Imagen -->
                <div style="flex: 1; min-width: 280px;">
                    <img src="<?= htmlspecialchars(imagen_curso($curso['imagen'])) ?>" alt="<?= htmlspecialchars($curso['titulo']) ?>" style="width: 100%; height: 100%; object-fit: cover; min-height: 250px;">
                </div>

                <!-- Contenido -->
                <div style="flex: 2; min-width: 280px; padding: 2.5rem; display: flex; flex-direction: column; justify-content: center;">
                    <?php if (!empty($curso['categoria'])): ?>
                    <span style="color: #3b82f6; font-weight: 800; font-size: 0.8rem; text-transform: uppercase; margin-bottom: 0.5rem; display: block;"><?= htmlspecialchars($curso['categoria']) ?></span>
                    <?php endif; ?>
                    <h3 style="color: #0f172a; font-size: 1.5rem; margin-bottom: 1rem; font-weight: 800; font-family: var(--theme-font);">
                        <?= htmlspecialchars($curso['titulo']) ?>
                    </h3>
                    <p style="color: #475569; line-height: 1.6; margin-bottom: 1.5rem; font-size: 0.95rem;">
                        <?= htmlspecialchars(mb_strimwidth(trim(html_entity_decode(strip_tags((string)$curso['descripcion']), ENT_QUOTES, 'UTF-8')), 0, 220, '…')) ?>
                    </p>
                    <div style="margin-top: auto;">
                        <a href="cursos" style="display: inline-block; padding: 0.6rem 1.5rem; background: #0f172a; color: white; border-radius: 20px; font-weight: 700; font-size: 0.85rem; text-transform: uppercase;">
                            Ver detalles
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if(empty($cursos_landing)): ?>
                <div style="text-align: center; color: #94a3b8; padding: 3rem;">Aún no hay programas publicados.</div>
            <?php else: ?>
                <div style="text-align: center;">
                    <a href="cursos" style="display: inline-block; padding: 0.8rem 2rem; border: 2px solid #0f172a; color: #0f172a; border-radius: 30px; font-weight: 700; font-size: 0.9rem; text-transform: uppercase;">
                        Ver todos los cursos
                    </a>
                </div>
            <?php endif; ?>
        </div>

    </div>
</section>

<style>
.t2-course-card:hover {
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
}
</style>

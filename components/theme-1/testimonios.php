<!-- Testimonials Section -->
<section id="testimonials" class="testimonials section">
    <div class="testimonials-overlay"></div>
    <div class="container testimonials-container">
        <div class="section-header light-text">
            <h2>Historias de éxito de nuestros estudiantes</h2>
        </div>
        <div class="testimonials-grid">
            <?php foreach($testimonios as $t): ?>
            <div class="testimonial-card">
                <p class="quote"><?= htmlspecialchars($t['quote']) ?></p>
                <div class="author">
                    <img src="<?= htmlspecialchars(strpos($t['image_url'], 'http') === 0 ? $t['image_url'] : $t['image_url']) ?>" alt="<?= htmlspecialchars($t['author_name']) ?>">
                    <div class="author-info">
                        <h4><?= htmlspecialchars($t['author_name']) ?></h4>
                        <span><?= htmlspecialchars($t['author_position']) ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if(empty($testimonios)): ?>
                <p style="color:white;text-align:center;width:100%;grid-column:1/-1;">Aún no hay testimonios registrados.</p>
            <?php endif; ?>
        </div>
    </div>
</section>

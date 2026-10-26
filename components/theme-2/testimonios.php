<!-- Testimonials Section Corporate (Theme 2) -->
<section id="testimonials" class="testimonials section theme-2-testimonios" style="background: #f1f5f9; padding: 6rem 0;">
    <div class="container">
        <div class="section-header" style="text-align: center; max-width: 700px; margin: 0 auto 3rem auto;">
            <span style="color: var(--primary); font-weight: 700; text-transform: uppercase; letter-spacing: 1px; font-size: 0.9rem;">Casos de Éxito</span>
            <h2 style="font-size: 2.5rem; color: #1e293b; margin-top: 0.5rem; font-weight: 800;">Historias de nuestros estudiantes</h2>
        </div>

        <div style="max-width: 800px; margin: 0 auto; position: relative;">
            <div class="swiper testimonials-swiper-t2" style="padding-bottom: 3rem;">
                <div class="swiper-wrapper">
                    <?php foreach($testimonios as $t): ?>
                    <div class="swiper-slide">
                        <div style="background: white; border-radius: 24px; padding: 3rem; text-align: center; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);">
                            <div style="color: #cbd5e1; font-size: 3rem; margin-bottom: 1.5rem; line-height: 1;">
                                <i class="fa-solid fa-quote-right"></i>
                            </div>
                            <p style="font-size: 1.25rem; color: #475569; font-style: italic; line-height: 1.8; margin-bottom: 2rem;">
                                <?= htmlspecialchars($t['quote']) ?>
                            </p>
                            <div style="display: flex; flex-direction: column; align-items: center;">
                                <img src="<?= htmlspecialchars((string)$t['image_url']) ?>" alt="<?= htmlspecialchars($t['author_name']) ?>" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; margin-bottom: 1rem; border: 3px solid #e2e8f0;">
                                <h4 style="margin: 0; color: #1e293b; font-size: 1.1rem; font-weight: 700;"><?= htmlspecialchars($t['author_name']) ?></h4>
                                <span style="color: var(--primary); font-size: 0.9rem; font-weight: 600;"><?= htmlspecialchars($t['author_position']) ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="swiper-pagination"></div>
            </div>
            
            <!-- Navigation arrows -->
            <div class="swiper-button-prev t2-test-prev" style="color: var(--primary);"></div>
            <div class="swiper-button-next t2-test-next" style="color: var(--primary);"></div>
        </div>
        
        <?php if(empty($testimonios)): ?>
            <p style="text-align:center; color:#64748b;">Aún no hay testimonios registrados.</p>
        <?php endif; ?>
    </div>
</section>

<!-- Inicializar Swiper Testimonios T2 -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    new Swiper('.testimonials-swiper-t2', {
        loop: true,
        autoplay: { delay: 6000, disableOnInteraction: false },
        pagination: { el: '.testimonials-swiper-t2 .swiper-pagination', clickable: true },
        navigation: {
            nextEl: '.t2-test-next',
            prevEl: '.t2-test-prev',
        },
        effect: 'slide'
    });
});
</script>

<style>
.t2-test-prev, .t2-test-next {
    background: white;
    width: 50px;
    height: 50px;
    border-radius: 50%;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
}
.t2-test-prev:after, .t2-test-next:after {
    font-size: 1.2rem;
    font-weight: 800;
}
@media (max-width: 768px) {
    .t2-test-prev, .t2-test-next { display: none; }
}
</style>

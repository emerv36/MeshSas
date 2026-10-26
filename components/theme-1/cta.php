<!-- Final CTA Section -->
<section id="cta" class="cta section">
    <div class="container">
        <div class="cta-grid">
            <div class="cta-image">
                <img src="<?= !empty($banner['img_cta']) ? htmlspecialchars($banner['img_cta']) : 'img/cta-illustration.png' ?>" alt="Estudiante en línea">
            </div>
            <div class="cta-content">
                <h2><?= htmlspecialchars($banner['cta_titulo'] ?? '¿Quieres más información?') ?></h2>
                <p><?= htmlspecialchars($banner['cta_subtitulo'] ?? 'Déjanos tus datos y un asesor te contactará pronto.') ?></p>
                
                <div id="cta-success-msg" style="display:none; background:#dcfce7; color:#166534; padding:1rem; border-radius:8px; margin-bottom:1rem; font-weight:600;"></div>
                <div id="cta-error-msg" style="display:none; background:#fee2e2; color:#991b1b; padding:1rem; border-radius:8px; margin-bottom:1rem; font-weight:600;"></div>
                
                <form id="cta-form" class="cta-form" method="POST">
                    <?= csrf_input() ?>
                    <div class="form-group">
                        <input type="text" name="nombre" id="cta-nombre" placeholder="Tu nombre completo" required>
                    </div>
                    <div class="form-group">
                        <input type="email" name="email" id="cta-email" placeholder="Tu correo electrónico" required>
                    </div>
                    <div class="form-group">
                        <input type="text" name="telefono" id="cta-telefono" placeholder="Tu teléfono / WhatsApp" required>
                    </div>
                    <div class="form-group" style="text-align: left; margin-bottom: 1rem;">
                        <label style="display: flex; align-items: flex-start; gap: 0.5rem; cursor: pointer; font-size: 0.85rem; color: #475569;">
                            <input type="checkbox" name="acepta_politicas" required style="margin-top: 3px; width: auto;">
                            <span>He leído y acepto la <a href="politica-privacidad.php" target="_blank" style="color: var(--primary); text-decoration: underline;">Política de Privacidad</a></span>
                        </label>
                    </div>
                    <button type="submit" id="cta-submit-btn" class="btn btn-primary" style="width: 100%;">Solicitar Asesoría</button>
                </form>
            </div>
        </div>
    </div>
</section>

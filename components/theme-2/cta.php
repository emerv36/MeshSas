<!-- CTA Section Corporate (Theme 2) -->
<!-- Mismo formulario de leads que la plantilla 1 (ids y campos): lo envía el script de index.php a submit_lead.php -->
<section id="cta" class="cta section theme-2-cta" style="background-color: #0f172a; padding: 4rem 0;">
    <div class="container" style="max-width: 1100px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 2.5rem;">

        <!-- Izquierda: Texto Persuasivo -->
        <div class="cta-text-col" style="flex: 1; min-width: 280px;">
            <h2 style="color: white; font-size: 2rem; font-weight: 800; margin-bottom: 0.5rem; line-height: 1.2; font-family: var(--theme-font); text-transform: uppercase;">
                <?= htmlspecialchars($banner['cta_titulo'] ?? '¿Quieres más información?') ?>
            </h2>
            <p style="color: #94a3b8; font-size: 1rem; line-height: 1.6; margin: 0;">
                <?= htmlspecialchars($banner['cta_subtitulo'] ?? 'Déjanos tus datos y un asesor te contactará pronto.') ?>
            </p>
        </div>

        <!-- Derecha: formulario de contacto -->
        <div class="cta-form-col" style="flex: 1; min-width: 280px; max-width: 460px;">
            <div id="cta-success-msg" style="display:none; background:#dcfce7; color:#166534; padding:1rem; border-radius:8px; margin-bottom:1rem; font-weight:600;"></div>
            <div id="cta-error-msg" style="display:none; background:#fee2e2; color:#991b1b; padding:1rem; border-radius:8px; margin-bottom:1rem; font-weight:600;"></div>

            <form id="cta-form" class="t2-cta-form" method="POST">
                <?= csrf_input() ?>
                <input type="text" name="nombre" placeholder="Tu nombre completo" required>
                <input type="email" name="email" placeholder="Tu correo electrónico" required>
                <input type="text" name="telefono" placeholder="Tu teléfono / WhatsApp" required>
                <label class="t2-cta-politicas">
                    <input type="checkbox" name="acepta_politicas" required>
                    <span>He leído y acepto la <a href="politica-privacidad.php" target="_blank">Política de Privacidad</a></span>
                </label>
                <button type="submit" id="cta-submit-btn">Solicitar Asesoría</button>
            </form>
        </div>

    </div>
</section>

<style>
.t2-cta-form { display: flex; flex-direction: column; gap: 0.75rem; }
.t2-cta-form input[type="text"],
.t2-cta-form input[type="email"] {
    width: 100%;
    padding: 0.8rem 1.2rem;
    border: 1px solid #334155;
    border-radius: 30px;
    background: #1e293b;
    color: white;
    font-family: var(--theme-font);
    font-size: 0.95rem;
}
.t2-cta-form input::placeholder { color: #94a3b8; }
.t2-cta-form input:focus { outline: none; border-color: white; }
.t2-cta-politicas { display: flex; align-items: flex-start; gap: 0.5rem; cursor: pointer; font-size: 0.85rem; color: #94a3b8; }
.t2-cta-politicas input { margin-top: 3px; width: auto; }
.t2-cta-politicas a { color: white; text-decoration: underline; }
.t2-cta-form button {
    padding: 0.8rem 2rem;
    background: white;
    color: #0f172a;
    border: none;
    border-radius: 30px;
    font-weight: 700;
    font-size: 0.9rem;
    text-transform: uppercase;
    cursor: pointer;
    transition: all 0.3s ease;
}
.t2-cta-form button:hover { background: #e2e8f0; }
.t2-cta-form button:disabled { opacity: 0.7; cursor: wait; }
@media (max-width: 768px) {
    .theme-2-cta .container { text-align: center; justify-content: center; }
    .theme-2-cta .cta-form-col { max-width: none; text-align: left; }
}
</style>

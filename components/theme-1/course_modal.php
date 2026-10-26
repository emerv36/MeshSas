<!-- Modal Informativo -->
<div id="courseModal" class="modal-overlay">
    <div class="modal-content">
        <button class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        <div id="modalHeaderImg" class="modal-header-img">
            <div class="modal-header-overlay">
                <h2 id="modalTitle">Título del Curso</h2>
            </div>
        </div>
        <div class="modal-body">
            <div class="modal-main-info">
                <div class="modal-info-section">
                    <h4><i class="fa-solid fa-graduation-cap"></i> Lo que aprenderás</h4>
                    <p id="modalLearning"></p>
                </div>
                <div class="modal-info-section">
                    <h4><i class="fa-solid fa-list-check"></i> Requisitos</h4>
                    <p id="modalRequirements"></p>
                </div>
                <div class="modal-info-section">
                    <h4><i class="fa-solid fa-users"></i> ¿Para quién es?</h4>
                    <p id="modalTarget"></p>
                </div>
            </div>
            <div class="modal-sidebar">
                <div style="margin-bottom: 1.5rem;">
                    <span
                        style="font-size: 0.8rem; text-transform: uppercase; color: #94a3b8; font-weight: 700;">Detalles
                        del curso</span>
                    <div style="margin-top: 1rem; display: flex; flex-direction: column; gap: 0.8rem;">
                        <span style="font-size: 0.95rem;"><i class="fa-solid fa-clock"
                                style="color: var(--accent); width: 20px;"></i> <span
                                id="modalDuration"></span></span>
                        <span style="font-size: 0.95rem;"><i class="fa-solid fa-layer-group"
                                style="color: var(--accent); width: 20px;"></i> <span id="modalModules"></span></span>
                        <span style="font-size: 0.95rem;"><i class="fa-solid fa-signal"
                                style="color: var(--accent); width: 20px;"></i> <span id="modalLevel"></span></span>
                        <span style="font-size: 0.95rem;"><i class="fa-solid fa-certificate"
                                style="color: var(--accent); width: 20px;"></i> Certificado incluido</span>
                    </div>
                </div>
                <div style="border-top: 1px solid #e2e8f0; padding-top: 1.5rem;">
                    <div id="modalPrice"
                        style="font-size: 1.5rem; font-weight: 800; color: var(--primary); margin-bottom: 1rem;">
                    </div>
                    <a id="modalCta" href="#" class="btn btn-primary btn-block">Inscribirme ahora</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function openModal(curso, hidePriceAndCta = false) {
        document.getElementById('modalTitle').innerText = curso.titulo;
        document.getElementById('modalHeaderImg').style.backgroundImage = `url('${curso.imagen}')`;
        document.getElementById('modalLearning').innerText = curso.que_aprenderas || 'Próximamente disponible.';
        document.getElementById('modalRequirements').innerText = curso.requisitos || 'Sin requisitos especiales.';
        document.getElementById('modalTarget').innerText = curso.publico_objetivo || 'Estudiantes con ganas de aprender.';
        document.getElementById('modalDuration').innerText = curso.duracion;
        document.getElementById('modalModules').innerText = (curso.cant_modulos || 0) + ' Módulos';
        document.getElementById('modalLevel').innerText = curso.nivel;

        if (hidePriceAndCta) {
            document.getElementById('modalPrice').style.display = 'none';
            document.getElementById('modalCta').style.display = 'none';
        } else {
            document.getElementById('modalPrice').style.display = 'block';
            document.getElementById('modalCta').style.display = 'block';

            const precio = parseFloat(curso.precio);
            const precioOferta = curso.precio_oferta ? parseFloat(curso.precio_oferta) : 0;

            if (precioOferta > 0 && precioOferta < precio) {
                document.getElementById('modalPrice').innerHTML = `<span style="font-size: 1rem; text-decoration: line-through; color: #94a3b8; margin-right: 8px;">$ ${precio.toLocaleString('es-CO')}</span>$ ${precioOferta.toLocaleString('es-CO')} COP`;
            } else {
                document.getElementById('modalPrice').innerText = precio <= 0 ? '¡GRATIS!' : `$ ${precio.toLocaleString('es-CO')} COP`;
            }

            const precioFinal = (precioOferta > 0 && precioOferta < precio) ? precioOferta : precio;
            document.getElementById('modalCta').href = `pago?id=${curso.id}`;
            document.getElementById('modalCta').innerText = precioFinal <= 0 ? 'Inscribirme Gratis' : 'Inscribirme ahora';
        }

        document.getElementById('courseModal').classList.add('active');
        document.body.style.overflow = 'hidden'; // Evitar scroll de fondo
    }

    function closeModal() {
        document.getElementById('courseModal').classList.remove('active');
        document.body.style.overflow = 'auto';
    }

    // Cerrar al hacer clic fuera del contenido
    window.onclick = function (event) {
        const modal = document.getElementById('courseModal');
        if (event.target == modal) {
            closeModal();
        }
    }
</script>

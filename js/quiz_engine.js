/**
 * Quiz Engine Frontend v10 (PLAN-V8 Implementation)
 * Handles Idempotency, Timer, Autosave, and Submissions securely.
 */

class QuizFlowError extends Error {
    constructor(kind, message = '', metadata = {}) {
        super(message);
        this.kind = kind; // 'unauthorized', 'expired', 'revision_conflict', 'autosave_server_error', 'autosave_network_error', 'submit_network_error', 'submit_server_error', 'invalid_response', 'terminal_conflict'
        this.metadata = metadata;
    }
}

class QuizEngine {
    constructor(config) {
        this.leccionId = config.leccionId;
        this.apiUrl = config.apiUrl || 'quiz_api.php';
        this.sessionToken = null;
        this.intentoId = null;
        this.fechaLimite = null;
        this.timerInterval = null;
        // Segundos que quedan según el servidor (null = sin límite). El reloj lo mide el
        // servidor y se pausa al cerrar la página; aquí solo se muestra y se resincroniza.
        this.segundosRestantes = null;
        this.autosaveIntervalTime = config.autosaveInterval || 30000;
        this.autosaveIntervalId = null;

        // Estado local de las respuestas para autoguardado optimista
        this.respuestas = {}; // { pregunta_id: { texto: '', revision: 0, sync: true } }

        // V8 States
        this.submissionState = 'editing';
        this.submitPromise = null;
        this.syncPromise = null;
        this.controlStates = new Map();
        this.expiracionPendiente = false;
    }

    /**
     * Cabecera con el token CSRF de la página (definido en clase.php)
     */
    csrfHeaders() {
        return { 'X-CSRF-Token': window.CSRF_TOKEN || '' };
    }

    /**
     * Genera un Hash SHA-256
     */
    async generateHash(string) {
        const utf8 = new TextEncoder().encode(string);
        const hashBuffer = await crypto.subtle.digest('SHA-256', utf8);
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        return hashArray.map(bytes => bytes.toString(16).padStart(2, '0')).join('');
    }

    showModal(title, message, iconClass, colorHex, callback = null) {
        if (typeof showCustomAlert === 'function') {
            showCustomAlert(title, message, iconClass, colorHex, callback);
            return;
        }

        const overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); backdrop-filter:blur(5px); z-index:9999; display:flex; align-items:center; justify-content:center; opacity:0; transition:0.3s; font-family:sans-serif;';

        const modal = document.createElement('div');
        modal.style.cssText = 'background:white; border:1px solid #e2e8f0; padding:2.5rem; border-radius:15px; text-align:center; max-width:400px; transform:translateY(20px); transition:0.3s; box-shadow:0 25px 50px -12px rgba(0,0,0,0.5);';

        modal.innerHTML = `
            <i class="${iconClass}" style="font-size:3.5rem; color:${colorHex}; margin-bottom:1.5rem; display:block;"></i>
            <h3 style="margin-top:0; margin-bottom:1rem; font-size:1.4rem; color:#1e293b;">${title}</h3>
            <p style="color:#64748b; margin-bottom:2rem; line-height:1.6;">${message}</p>
            <button id="btn-alert-modal-${this.leccionId}" style="width:100%; padding:0.8rem; border:none; background:${colorHex}; color:white; border-radius:6px; cursor:pointer; font-weight:600; font-size:1rem;">Aceptar</button>
        `;

        overlay.appendChild(modal);
        document.body.appendChild(overlay);

        setTimeout(() => {
            overlay.style.opacity = '1';
            modal.style.transform = 'translateY(0)';
        }, 10);

        document.getElementById(`btn-alert-modal-${this.leccionId}`).onclick = function() {
            overlay.style.opacity = '0';
            modal.style.transform = 'translateY(20px)';
            setTimeout(() => {
                overlay.remove();
                if(callback) callback();
            }, 300);
        };
    }

    generateUUID() {
        return crypto.randomUUID ? crypto.randomUUID() : Math.random().toString(36).substring(2) + Date.now().toString(36);
    }

    async iniciarQuiz() {
        const btnStart = document.getElementById('btn-iniciar-quiz');
        if (btnStart) btnStart.disabled = true;

        // Recuperar o generar clave de idempotencia
        let startKey = sessionStorage.getItem(`quiz_start_key_${this.leccionId}`);
        if (!startKey) {
            startKey = this.generateUUID();
            sessionStorage.setItem(`quiz_start_key_${this.leccionId}`, startKey);
        }

        const hash = await this.generateHash(startKey);

        const formData = new FormData();
        formData.append('leccion_id', this.leccionId);
        formData.append('start_idempotency_key_hash', hash);

        try {
            const res = await fetch(`${this.apiUrl}?action=start`, {
                method: 'POST',
                headers: this.csrfHeaders(),
                body: formData
            });

            let data;
            try {
                data = await res.json();
            } catch (parseErr) {
                this.showModal('Aviso del sistema', 'El servidor devolvió una respuesta inesperada. Intenta recargar la página.', 'fa-solid fa-triangle-exclamation', '#f59e0b');
                if (btnStart) btnStart.disabled = false;
                return;
            }

            if (res.ok && data.status === 'success') {
                this.intentoId = data.intento_id;
                this.sessionToken = data.session_token || sessionStorage.getItem(`quiz_session_${this.intentoId}`);

                if (data.session_token) {
                    sessionStorage.setItem(`quiz_session_${this.intentoId}`, data.session_token);
                }

                // VERIFICAR ESTADO AMBIGUO PERSISTIDO
                const ambKey = `quiz_ambiguous_submission_${this.leccionId}_${this.intentoId}`;
                const ambDesc = sessionStorage.getItem(ambKey);
                if (ambDesc) {
                    try {
                        const parsedDesc = JSON.parse(ambDesc);
                        if (parsedDesc.version === 1 && parsedDesc.state === 'submit_ambiguous' && parsedDesc.leccionId == this.leccionId && parsedDesc.intentoId == this.intentoId && parsedDesc.submissionKeyRaw) {
                            this.submissionState = 'submit_ambiguous';
                            this.mostrarUIQuiz(); // Show quiz UI
                            this.aplicarOutcome({kind: 'submit_ambiguous'});
                            return;
                        }
                    } catch(e) {
                        sessionStorage.removeItem(ambKey);
                    }
                }

                if (data.segundos_restantes !== null && data.segundos_restantes !== undefined) {
                    this.segundosRestantes = Number(data.segundos_restantes);
                    this.iniciarTemporizador();
                } else {
                    const timerUI = document.getElementById('quiz-timer');
                    if (timerUI) {
                        timerUI.innerHTML = '∞';
                        timerUI.parentElement.style.opacity = '0.5';
                    }
                }

                // Restaurar respuestas previas
                if (data.respuestas_previas) {
                    for (const [pregId, info] of Object.entries(data.respuestas_previas)) {
                        const texto = info.texto;

                        let valoresCheckbox = [];
                        try {
                            const decoded = JSON.parse(texto);
                            if (Array.isArray(decoded)) valoresCheckbox = decoded.map(String);
                        } catch (_) {
                            valoresCheckbox = [String(texto)];
                        }
                        for (const valor of valoresCheckbox) {
                            const chk = document.querySelector(`#quizForm input[name="respuesta[${pregId}][]"][value="${valor}"]`);
                            if (chk) chk.checked = true;
                        }

                        let radio = document.querySelector(`#quizForm input[name="respuesta[${pregId}]"][value="${texto}"]`);
                        if (radio) radio.checked = true;

                        let txt = document.querySelector(`#quizForm textarea[name="respuesta_abierta[${pregId}]"]`);
                        if (txt) txt.value = texto;

                        this.respuestas[pregId] = { texto: texto, revision: info.revision, sync: true };
                    }
                }
                this.actualizarIndicadorRespuestas();

                this.iniciarAutosaveSeguro();
                this.mostrarUIQuiz();
            } else if (data.status === 'expired' || data.status === 'finalizado') {
                // El intento ya está cerrado: se descarta su clave para poder iniciar otro
                sessionStorage.removeItem(`quiz_start_key_${this.leccionId}`);
                this.showModal('Intento finalizado', data.message || 'Este intento ya terminó.', 'fa-solid fa-clock', '#f59e0b', () => window.location.reload());
            } else {
                this.showModal('Aviso', data.message || 'Hubo un inconveniente inesperado.', 'fa-solid fa-circle-info', '#f59e0b');
                if (btnStart) btnStart.disabled = false;
                if (res.status === 403) {
                    setTimeout(() => window.location.reload(), 2500);
                }
            }
        } catch (e) {
            this.showModal('Aviso de conexión', 'Verifica tu conexión a internet e intenta nuevamente.', 'fa-solid fa-wifi', '#f59e0b');
            if (btnStart) btnStart.disabled = false;
        }
    }

    iniciarTemporizador() {
        const timerUI = document.getElementById('quiz-timer');
        if (!timerUI || this.segundosRestantes === null) return;

        const pintar = () => {
            const restante = Math.max(0, this.segundosRestantes);
            const minutos = Math.floor(restante / 60);
            const segundos = Math.floor(restante % 60);
            timerUI.innerText = `${minutos}:${segundos < 10 ? '0' : ''}${segundos}`;
        };
        pintar();
        this.timerInterval = setInterval(() => {
            this.segundosRestantes--;
            pintar();
            if (this.segundosRestantes <= 0) {
                clearInterval(this.timerInterval);
                this.timerInterval = null;
                this.forzarEntrega();
            }
        }, 1000);
    }

    /** Ajusta el contador con el tiempo que informa el servidor en cada autoguardado o latido. */
    sincronizarTiempo(data) {
        if (data && data.segundos_restantes !== null && data.segundos_restantes !== undefined && this.timerInterval) {
            this.segundosRestantes = Number(data.segundos_restantes);
        }
    }

    iniciarAutosaveSeguro() {
        if (this.autosaveIntervalId == null) {
            this.autosaveIntervalId = setInterval(() => {
                this.ejecutarRondaSincronizacion(false).catch(error => this.manejarErrorRonda(error));
            }, this.autosaveIntervalTime);
        }
    }

    /** Errores del autoguardado periódico: si el servidor cerró el intento por tiempo, se informa. */
    async manejarErrorRonda(error) {
        if (!(error instanceof QuizFlowError)) return;
        if (['expired', 'unauthorized', 'terminal_conflict'].includes(error.kind) && this.submissionState === 'editing') {
            this.detenerAutosave();
            const outcome = { kind: error.kind === 'terminal_conflict' ? 'terminal_conflict' : error.kind, error };
            this.aplicarOutcome(outcome);
            await this.renderizarOutcome(outcome);
        }
        // Errores de red: se reintenta en la siguiente ronda
    }

    detenerAutosave() {
        if (this.autosaveIntervalId != null) {
            clearInterval(this.autosaveIntervalId);
            this.autosaveIntervalId = null;
        }
    }

    registrarCambioRespuesta(preguntaId, texto) {
        if (!this.respuestas[preguntaId]) {
            this.respuestas[preguntaId] = { texto: '', revision: 0, sync: true };
        }
        this.respuestas[preguntaId].texto = texto;
        this.respuestas[preguntaId].sync = false;
        this.actualizarIndicadorRespuestas();
    }

    /**
     * Índices (desde 0) de las preguntas sin responder, según lo marcado en el formulario.
     */
    preguntasSinResponder() {
        const pendientes = [];
        document.querySelectorAll('#quizForm .quiz-step').forEach((paso, indice) => {
            const respondida = paso.querySelector('input[type="radio"]:checked, input[type="checkbox"]:checked')
                || Array.from(paso.querySelectorAll('textarea')).some(t => t.value.trim() !== '')
                || Array.from(paso.querySelectorAll('input[type="file"]')).some(f => f.files && f.files.length > 0);
            if (!respondida) pendientes.push(indice);
        });
        return pendientes;
    }

    /**
     * Marca en el navegador de preguntas cuáles están respondidas y cuántas van.
     */
    actualizarIndicadorRespuestas() {
        const botones = document.querySelectorAll('.quiz-nav-num');
        if (!botones.length) return;
        const pendientes = new Set(this.preguntasSinResponder());
        botones.forEach((boton, indice) => {
            const respondida = !pendientes.has(indice);
            boton.classList.toggle('respondida', respondida);
            boton.setAttribute('aria-label', `Pregunta ${indice + 1}${respondida ? ', respondida' : ', sin responder'}`);
        });
        const resumen = document.getElementById('quiz-respondidas');
        if (resumen) resumen.textContent = `${botones.length - pendientes.size} de ${botones.length} respondidas`;
    }

    /**
     * Entrega pedida por el alumno: si quedan preguntas sin responder lo avisa y le deja
     * volver a ellas o enviar de todos modos (cuentan como incorrectas). La entrega por
     * tiempo agotado (forzarEntrega) no pasa por aquí.
     */
    solicitarEntrega() {
        if (this.submitPromise || !['editing', 'pre_submit_recoverable'].includes(this.submissionState)) return;
        const pendientes = this.preguntasSinResponder();
        if (pendientes.length === 0) {
            this.entregarQuiz();
            return;
        }

        this.cerrarConfirmacionEntrega();
        const numeros = pendientes.map(i => i + 1);
        const lista = numeros.length === 1
            ? `la pregunta ${numeros[0]}`
            : `las preguntas ${numeros.slice(0, -1).join(', ')} y ${numeros[numeros.length - 1]}`;

        const overlay = document.createElement('div');
        overlay.id = 'quiz-confirmar-entrega';
        overlay.style.cssText = 'position:fixed; inset:0; background:rgba(0,0,0,0.6); backdrop-filter:blur(5px); z-index:9999; display:flex; align-items:center; justify-content:center; padding:16px;';
        overlay.innerHTML = `
            <div role="dialog" aria-modal="true" aria-labelledby="quiz-confirmar-titulo" aria-describedby="quiz-confirmar-texto"
                 style="background:var(--bg-surface, #fff); border:1px solid var(--border-glass, #e2e8f0); padding:2.5rem; border-radius:24px; text-align:center; max-width:420px; width:100%; box-shadow:0 25px 50px -12px rgba(0,0,0,0.5);">
                <i class="fa-solid fa-triangle-exclamation" style="font-size:3rem; color:#f59e0b; margin-bottom:1.25rem; display:block;" aria-hidden="true"></i>
                <h3 id="quiz-confirmar-titulo" style="margin:0 0 1rem; font-size:1.3rem;">Tienes preguntas sin responder</h3>
                <p id="quiz-confirmar-texto" style="color:var(--text-dim, #64748b); margin:0 0 2rem; line-height:1.6;">
                    ${numeros.length === 1 ? 'Te falta' : 'Te faltan'} ${lista}. Si envías ahora, ${numeros.length === 1 ? 'contará' : 'contarán'} como ${numeros.length === 1 ? 'incorrecta' : 'incorrectas'}.
                </p>
                <div style="display:flex; flex-direction:column; gap:0.75rem;">
                    <button type="button" data-accion="ir" class="btn-complete" style="width:100%; justify-content:center; background:var(--primary, #6366f1); border:none;">Ir a responderlas</button>
                    <button type="button" data-accion="enviar" class="btn-complete" style="width:100%; justify-content:center; background:rgba(255,255,255,0.08); color:inherit; border:1px solid var(--border-glass, #e2e8f0);">Enviar de todos modos</button>
                </div>
            </div>`;

        const irAPendiente = () => {
            this.cerrarConfirmacionEntrega();
            if (typeof window.navQuiz === 'function') window.navQuiz(pendientes[0]);
        };
        overlay.querySelector('[data-accion="ir"]').addEventListener('click', irAPendiente);
        overlay.querySelector('[data-accion="enviar"]').addEventListener('click', () => {
            this.cerrarConfirmacionEntrega();
            this.entregarQuiz();
        });
        overlay.addEventListener('keydown', (e) => { if (e.key === 'Escape') irAPendiente(); });

        document.body.appendChild(overlay);
        overlay.querySelector('[data-accion="ir"]').focus();
    }

    cerrarConfirmacionEntrega() {
        const existente = document.getElementById('quiz-confirmar-entrega');
        if (existente) existente.remove();
    }

    reconciliarDOM() {
        const controls = document.querySelectorAll('#quizForm input[name^="respuesta["], #quizForm textarea[name^="respuesta_abierta["]');
        controls.forEach(ctrl => {
            if (!this.controlStates.has(ctrl)) {
                this.controlStates.set(ctrl, ctrl.disabled);
            }
            ctrl.disabled = true;
        });

        document.querySelectorAll('#quizForm textarea[name^="respuesta_abierta["]').forEach(txt => {
            const match = txt.name.match(/\[(\d+)\]/);
            if (match) {
                const pregId = match[1];
                const val = txt.value;
                if (!this.respuestas[pregId]) {
                    this.respuestas[pregId] = { texto: val, revision: 0, sync: false };
                } else if (this.respuestas[pregId].texto !== val) {
                    this.respuestas[pregId].texto = val;
                    this.respuestas[pregId].sync = false;
                }
            }
        });
    }

    async ejecutarRondaSincronizacion(isFinal = false) {
        if (this.syncPromise) return this.syncPromise;

        const currentPromise = this._procesarRondaSincronizacion(isFinal);
        this.syncPromise = currentPromise;
        try {
            return await currentPromise;
        } finally {
            if (this.syncPromise === currentPromise) this.syncPromise = null;
        }
    }

    async fetchAutosave(preguntaId, texto, revision) {
        const formData = new FormData();
        formData.append('intento_id', this.intentoId);
        formData.append('session_token', this.sessionToken);
        formData.append('pregunta_id', preguntaId);
        formData.append('texto_respuesta', texto);
        formData.append('revision', revision);

        try {
            const res = await fetch(`${this.apiUrl}?action=autosave`, { method: 'POST', headers: this.csrfHeaders(), body: formData });
            if (!res.ok) {
                if (res.status === 401 || res.status === 403) throw new QuizFlowError('unauthorized');
                if (res.status === 409) throw new QuizFlowError('revision_conflict');
                throw new QuizFlowError('autosave_server_error');
            }
            let data;
            try {
                data = await res.json();
            } catch(e) {
                throw new QuizFlowError('invalid_response');
            }
            if (data.status === 'expired') throw new QuizFlowError('expired');
            if (data.status !== 'success') throw new QuizFlowError('autosave_server_error');
            this.sincronizarTiempo(data);
            return data;
        } catch(e) {
            if (e instanceof QuizFlowError) throw e;
            throw new QuizFlowError('autosave_network_error');
        }
    }

    // Pulso con la página abierta: mantiene el reloj del servidor al día aunque no haya
    // respuestas nuevas, de modo que al cerrar la página solo se "pierden" segundos.
    async fetchLatido() {
        const formData = new FormData();
        formData.append('intento_id', this.intentoId);
        formData.append('session_token', this.sessionToken);
        let res, data;
        try {
            res = await fetch(`${this.apiUrl}?action=latido`, { method: 'POST', headers: this.csrfHeaders(), body: formData });
            data = await res.json();
        } catch(e) { return; /* Errores de red: se reintenta en el siguiente latido */ }
        if (data.status === 'expired') throw new QuizFlowError('expired');
        if (res.status === 401 || res.status === 403) throw new QuizFlowError('unauthorized');
        if (res.status === 409) throw new QuizFlowError('terminal_conflict');
        if (res.ok) this.sincronizarTiempo(data);
    }

    async _procesarRondaSincronizacion(isFinal) {
        let hasPending = false;
        for (const [preguntaId, data] of Object.entries(this.respuestas)) {
            if (!data.sync) {
                hasPending = true;
                const sentText = data.texto;
                const sentRevision = data.revision;

                const resData = await this.fetchAutosave(preguntaId, sentText, sentRevision);

                data.revision = resData.server_revision;
                if (data.texto === sentText) {
                    data.sync = true;
                } else {
                    data.sync = false;
                }
            }
        }
        if (!isFinal && !hasPending) {
            await this.fetchLatido();
        }
    }

    async vaciadoFinal() {
        this.reconciliarDOM();
        let rondas = 0;
        while (rondas < 5) {
            await this.ejecutarRondaSincronizacion(true);
            const haySucias = Object.values(this.respuestas).some(d => !d.sync);
            if (!haySucias) return 'clean';
            rondas++;
        }
        return 'unstable';
    }

    async enviarSubmitConMismaClave() {
        let submitKeyRaw = sessionStorage.getItem(`quiz_submit_key_${this.intentoId}`);
        if (!submitKeyRaw) {
            submitKeyRaw = this.generateUUID();
            sessionStorage.setItem(`quiz_submit_key_${this.intentoId}`, submitKeyRaw);
        }

        const hash = await this.generateHash(submitKeyRaw);

        const formData = new FormData();
        formData.append('intento_id', this.intentoId);
        formData.append('session_token', this.sessionToken);
        formData.append('submission_idempotency_key_hash', hash);

        try {
            const res = await fetch(`${this.apiUrl}?action=submit`, {
                method: 'POST',
                headers: this.csrfHeaders(),
                body: formData
            });

            if (!res.ok) {
                if (res.status === 401 || res.status === 403) throw new QuizFlowError('unauthorized');
                if (res.status === 409) throw new QuizFlowError('terminal_conflict');
                throw new QuizFlowError('submit_server_error');
            }

            let data;
            try {
                data = await res.json();
            } catch (e) {
                throw new QuizFlowError('invalid_response');
            }
            if (data.status === 'expired') throw new QuizFlowError('expired');
            if (data.status !== 'success') throw new QuizFlowError('submit_server_error');
            return data;
        } catch (e) {
            if (e instanceof QuizFlowError) throw e;
            throw new QuizFlowError('submit_network_error');
        }
    }

    clasificarErrorPorFase(error, phase) {
        if (error.kind === 'unauthorized') return { kind: 'unauthorized', error };
        if (error.kind === 'expired') return { kind: 'expired', error };
        if (error.kind === 'terminal_conflict') return { kind: 'terminal_conflict', error };
        if (error.kind === 'revision_conflict') return { kind: 'revision_conflict', error };

        if (phase === 'submit_dispatched') {
            return { kind: 'submit_ambiguous', error };
        }
        return { kind: 'pre_submit_recoverable', error };
    }

    async entregarQuiz() {
        if (this.submitPromise || !['editing', 'pre_submit_recoverable'].includes(this.submissionState)) return;

        let phase = 'syncing';
        this.submissionState = 'submitting';
        this.detenerAutosave();

        this.submitPromise = (async () => {
            try {
                const drainResult = await this.vaciadoFinal();
                if (drainResult !== 'clean') {
                    return { kind: 'pre_submit_recoverable' };
                }
                phase = 'submit_dispatched';
                const response = await this.enviarSubmitConMismaClave();
                return { kind: 'submit_confirmed', data: response };
            } catch (error) {
                return this.clasificarErrorPorFase(error, phase);
            }
        })();

        try {
            const outcome = await this.submitPromise;
            this.aplicarOutcome(outcome);
            await this.renderizarOutcome(outcome);
        } catch (criticalUIError) {
            console.error("UI Render failed:", criticalUIError);
            this.mostrarAlertaRecargaSegura();
        } finally {
            this.submitPromise = null;
        }
    }

    async verificarEntregaAmbigua() {
        if (this.submitPromise || this.submissionState !== 'submit_ambiguous') return;

        this.submitPromise = (async () => {
            try {
                const response = await this.enviarSubmitConMismaClave();
                return { kind: 'submit_confirmed', data: response };
            } catch (error) {
                if (error.kind === 'unauthorized') return { kind: 'unauthorized', error };
                if (error.kind === 'expired') return { kind: 'expired', error };
                if (error.kind === 'terminal_conflict') return { kind: 'terminal_conflict', error };

                return { kind: 'submit_ambiguous', error };
            }
        })();

        try {
            const outcome = await this.submitPromise;
            this.aplicarOutcome(outcome);
            await this.renderizarOutcome(outcome);
        } catch (criticalUIError) {
            console.error("UI Render failed:", criticalUIError);
            this.mostrarAlertaRecargaSegura();
        } finally {
            this.submitPromise = null;
        }
    }

    aplicarOutcome(outcome) {
        const btnSubmit = document.getElementById('btn-entregar-quiz');
        const ambKey = `quiz_ambiguous_submission_${this.leccionId}_this.intentoId`;
        // Usar this.intentoId directo es seguro si estamos en un contexto enlazado.
        const ambKeyStr = `quiz_ambiguous_submission_${this.leccionId}_${this.intentoId}`;

        switch (outcome.kind) {
            case 'submit_confirmed':
                this.submissionState = 'submit_confirmed';
                if (this.timerInterval) { clearInterval(this.timerInterval); this.timerInterval = null; }
                sessionStorage.removeItem(ambKeyStr);
                sessionStorage.removeItem(`quiz_session_${this.intentoId}`);
                sessionStorage.removeItem(`quiz_submit_key_${this.intentoId}`);
                sessionStorage.removeItem(`quiz_start_key_${this.leccionId}`);
                break;
            case 'expired':
                this.submissionState = 'expired';
                if (this.timerInterval) { clearInterval(this.timerInterval); this.timerInterval = null; }
                // El intento quedó cerrado en el servidor: sus claves ya no sirven
                sessionStorage.removeItem(ambKeyStr);
                sessionStorage.removeItem(`quiz_session_${this.intentoId}`);
                sessionStorage.removeItem(`quiz_submit_key_${this.intentoId}`);
                sessionStorage.removeItem(`quiz_start_key_${this.leccionId}`);
                break;
            case 'unauthorized':
                this.submissionState = 'unauthorized';
                if (this.timerInterval) { clearInterval(this.timerInterval); this.timerInterval = null; }
                break;
            case 'terminal_conflict':
                this.submissionState = 'terminal_conflict';
                if (this.timerInterval) { clearInterval(this.timerInterval); this.timerInterval = null; }
                sessionStorage.removeItem(ambKeyStr);
                break;
            case 'revision_conflict':
                this.submissionState = 'revision_conflict';
                break;
            case 'pre_submit_recoverable':
                this.submissionState = 'pre_submit_recoverable';
                if (!this.expiracionPendiente) {
                    for (const [ctrl, wasDisabled] of this.controlStates) {
                        if (ctrl.isConnected) ctrl.disabled = wasDisabled;
                    }
                    this.controlStates.clear();
                    this.iniciarAutosaveSeguro();
                    this.submissionState = 'editing';
                }
                break;
            case 'submit_ambiguous':
                this.submissionState = 'submit_ambiguous';
                if (this.timerInterval) { clearInterval(this.timerInterval); this.timerInterval = null; }

                const submitKeyRaw = sessionStorage.getItem(`quiz_submit_key_${this.intentoId}`);
                if (submitKeyRaw) {
                    const descriptor = {
                        version: 1,
                        state: 'submit_ambiguous',
                        leccionId: this.leccionId,
                        intentoId: this.intentoId,
                        submissionKeyRaw: submitKeyRaw,
                        savedAt: new Date().toISOString()
                    };
                    sessionStorage.setItem(ambKeyStr, JSON.stringify(descriptor));
                }

                if (btnSubmit) {
                    btnSubmit.disabled = false;
                    btnSubmit.innerText = "Verificar entrega";
                    btnSubmit.onclick = (e) => { e.preventDefault(); this.verificarEntregaAmbigua(); };
                }
                break;
        }
    }

    async renderizarOutcome(outcome) {
        return new Promise((resolve) => {
            switch (outcome.kind) {
                case 'submit_confirmed':
                    // La nota se muestra aquí; el detalle por pregunta, al recargar la lección
                    const datos = outcome.data || {};
                    const puntaje = Number(datos.puntaje);
                    let mensajeEstado = 'El quiz ha sido entregado exitosamente.';
                    if (datos.estado_final === 'pending_review') {
                        mensajeEstado = 'Tus respuestas han sido enviadas y están pendientes de revisión por el instructor.';
                    } else if (datos.estado_final === 'graded' && Number.isFinite(puntaje)) {
                        const nota = `${Math.round(puntaje * 100) / 100}%`;
                        mensajeEstado = Number(datos.aprobado) === 1
                            ? `Obtuviste ${nota}. ¡Aprobaste! Al cerrar este mensaje verás la revisión de tus respuestas.`
                            : `Obtuviste ${nota}, aún no alcanzas la nota mínima. Al cerrar este mensaje verás qué preguntas repasar.`;
                    }
                    this.showModal('¡Respuestas enviadas!', mensajeEstado, 'fa-solid fa-circle-check', '#22c55e', () => {
                        window.location.reload();
                        resolve();
                    });
                    break;
                case 'expired':
                    this.showModal('Tiempo finalizado', 'El tiempo del cuestionario ha finalizado.', 'fa-solid fa-clock', '#f59e0b', () => {
                        window.location.reload();
                        resolve();
                    });
                    break;
                case 'unauthorized':
                    this.showModal('Sesión inválida', 'Tu sesión ha expirado o no tienes permisos.', 'fa-solid fa-user-lock', '#ef4444', () => {
                        window.location.reload();
                        resolve();
                    });
                    break;
                case 'terminal_conflict':
                    this.showModal('Conflicto terminal', 'El intento ya fue cerrado en otro dispositivo.', 'fa-solid fa-circle-xmark', '#ef4444', () => {
                        window.location.reload();
                        resolve();
                    });
                    break;
                case 'revision_conflict':
                    this.showModal('Conflicto detectado', 'Esta respuesta fue modificada en otra pestaña. Recarga la página para obtener la versión más reciente.', 'fa-solid fa-code-merge', '#f59e0b', () => resolve());
                    break;
                case 'pre_submit_recoverable':
                    this.showModal('Aviso de conexión', 'No pudimos guardar algunas respuestas. Revisa tu conexión a internet e intenta nuevamente.', 'fa-solid fa-wifi', '#f59e0b', () => resolve());
                    break;
                case 'submit_ambiguous':
                    this.showModal('Verificación requerida', 'Se perdió la conexión esperando la confirmación del envío. Haz clic en "Verificar entrega" para confirmar tu estado.', 'fa-solid fa-circle-question', '#3b82f6', () => resolve());
                    break;
            }
        });
    }

    mostrarAlertaRecargaSegura() {
        alert("Ocurrió un error visual. Por favor, recarga la página manualmente.");
    }

    forzarEntrega() {
        if (this.submissionState === 'submitting' || this.submitPromise != null) {
            this.expiracionPendiente = true;
            return;
        }
        this.expiracionPendiente = true;
        this.cerrarConfirmacionEntrega();
        this.showModal('Tiempo agotado', 'El tiempo se ha agotado. Enviando respuestas automáticamente.', 'fa-solid fa-stopwatch', '#f59e0b', () => {
            this.entregarQuiz();
        });
    }

    mostrarUIQuiz() {
        const startDiv = document.getElementById('quiz-start-screen');
        const revisionDiv = document.getElementById('quiz-revision');
        const examDiv = document.getElementById('quiz-exam-screen');
        if (startDiv) startDiv.style.display = 'none';
        if (revisionDiv) revisionDiv.style.display = 'none';
        if (examDiv) examDiv.style.display = 'block';
    }
}

window.QuizEngine = QuizEngine;

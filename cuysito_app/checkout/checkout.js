document.addEventListener('DOMContentLoaded', () => {
    if (window.Snowfall && typeof window.Snowfall.start === 'function') {
        try { window.Snowfall.start(); } catch (_) { }
    }
    // --- FUNCIÓN DE AYUDA ---
    function debounce(func, delay) { let timeout; const debounced = function (...args) { const context = this; clearTimeout(timeout); timeout = setTimeout(() => func.apply(context, args), delay); }; debounced.cancel = function () { clearTimeout(timeout); }; return debounced; }

    // --- CONFIG DE LA COLA DE MODALES ---
    // Se leen de variables globales inyectadas en index.php
    const ALERTA_SIN_REGALOS_ACTIVA = !!window.ALERTA_SIN_REGALOS_ACTIVA;
    const ALERTA_SIN_REGALOS_MENSAJE = window.ALERTA_SIN_REGALOS_MENSAJE || '';
    const USER_IS_LOGGED = !!window.USER_IS_LOGGED;
    // Helper para iconos SVG del sprite (mismo sistema que confirmation.js)
    const svgIcon = (name, cls = 'w-5 h-5') => `<svg class="bi ${cls}" aria-hidden="true"><use href="#i-${name}" xlink:href="#i-${name}"></use></svg>`;
    const TUTORIAL_LS_KEY = 'banano_tutoriales_vistos_v1';

    function getGuestSeenTutorials() {
        try {
            return new Set(JSON.parse(localStorage.getItem(TUTORIAL_LS_KEY) || '[]'));
        } catch (_) {
            return new Set();
        }
    }
    function markGuestTutorialSeen(categoria) {
        const set = getGuestSeenTutorials();
        set.add(categoria);
        try { localStorage.setItem(TUTORIAL_LS_KEY, JSON.stringify([...set])); } catch (_) { }
    }
    async function markTutorialSeen(categoria) {
        if (USER_IS_LOGGED) {
            try {
                await fetch('/web.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ action: 'mtv', categoria })
                });
            } catch (_) { /* silencioso: el frontend ya marcó el tutorial localmente */ }
        } else {
            markGuestTutorialSeen(categoria);
        }
    }

    // Filtramos los tutoriales ya vistos por el invitado (los logueados ya vienen filtrados desde PHP).
    let TUTORIALES_PENDIENTES = Array.isArray(window.TUTORIALES_PENDIENTES) ? window.TUTORIALES_PENDIENTES.slice() : [];
    if (!USER_IS_LOGGED && TUTORIALES_PENDIENTES.length > 0) {
        const seen = getGuestSeenTutorials();
        TUTORIALES_PENDIENTES = TUTORIALES_PENDIENTES.filter(t => !seen.has(t.categoria));
    }

    /**
     * Muestra un modal de la cola y devuelve una Promesa que resuelve true (aceptar) o false (cancelar).
     * @param {{title:string,message:string,ctaText:string,icon?:string,variant?:string,cancelText?:string}} opts
     */
    function showQueueModal({ title, message, ctaText, icon = 'info', variant = 'info', cancelText = 'Cancelar' }) {
        return new Promise(resolve => {
            const overlay = document.getElementById('queue-modal-overlay');
            if (!overlay) { resolve(true); return; }
            const safeMessage = String(message ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            const safeTitle = String(title ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            overlay.innerHTML = `
              <div class="queue-modal queue-modal--${variant}" role="document">
                <div class="queue-modal__body">
                  <div class="queue-modal__icon">${svgIcon(icon, 'w-8 h-8')}</div>
                  <h3 class="queue-modal__title">${safeTitle}</h3>
                  <p class="queue-modal__message">${safeMessage}</p>
                </div>
                <div class="queue-modal__footer">
                  <button type="button" class="queue-modal__btn queue-modal__btn--secondary" data-action="cancel">${cancelText}</button>
                  <button type="button" class="queue-modal__btn queue-modal__btn--primary" data-action="accept">${ctaText}</button>
                </div>
              </div>`;
            overlay.classList.add('is-open');
            document.body.style.overflow = 'hidden';
            const close = () => {
                overlay.classList.remove('is-open');
                overlay.innerHTML = '';
                document.body.style.overflow = '';
            };
            overlay.querySelector('[data-action="accept"]').addEventListener('click', () => { close(); resolve(true); });
            overlay.querySelector('[data-action="cancel"]').addEventListener('click', () => { close(); resolve(false); });
            // Tap fuera del modal en mobile NO cierra (UX: el usuario debe tomar una decisión)
            // pero permitimos ESC para cancelar.
            const onKey = (e) => {
                if (e.key === 'Escape') {
                    document.removeEventListener('keydown', onKey);
                    close();
                    resolve(false);
                }
            };
            document.addEventListener('keydown', onKey, { once: false });
            // Auto-focus en el botón primario (mejora accesibilidad y UX en mobile)
            setTimeout(() => {
                const primary = overlay.querySelector('[data-action="accept"]');
                if (primary) primary.focus();
            }, 50);
        });
    }
    // --- INICIO DEL NUEVO BLOQUE ---
    /**
     * Función central para comunicarse con la API del carrito (cart_manager.php).
     * @param {string} action - La acción a realizar (ej. 'update_quantity', 'remove').
     * @param {string} offerId - El ID del producto.
     * @param {number|null} quantity - La nueva cantidad (solo para 'update_quantity').
     * @returns {Promise<object>} - La respuesta JSON del servidor.
     */
    async function updateCartAPI(action, offerId, quantity = null) {
        // Asegúrate de que esta ruta sea la correcta desde la carpeta donde está el checkout.
        // Si tu checkout está en la raíz, la ruta sería 'api/cart_manager.php'
        const apiUrl = '/web.php'; // Unified API Gateway

        const formData = new FormData();
        formData.append('action', 'c9d');
        formData.append('sub', action);
        formData.append('offerId', offerId);

        if (quantity !== null) {
            formData.append('quantity', quantity);
        }

        try {
            const response = await fetch(apiUrl, {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                // Si el servidor responde con un error (ej. 400, 404, 500), lo capturamos.
                const errorData = await response.json().then(r => _resolveSyncFrame(r)).catch(() => ({ message: 'Error desconocido del servidor.' }));
                throw new Error(errorData.message || `Error del servidor: ${response.statusText}`);
            }

            const result = await _resolveSyncFrame(await response.json());
            return result; // Devuelve { success: true/false, message: '...' }

        } catch (error) {
            console.error('Error al contactar la API del carrito:', error);
            showToast('Error al actualizar tu carrito. Inténtalo de nuevo.', 'error');
            // Devolvemos un objeto de error consistente para que la función que llama pueda manejarlo.
            return { success: false, message: error.message };
        }
    }
    // --- FIN DEL NUEVO BLOQUE ---

    // ==========================================
    // === SISTEMA DE QUIZ / TRIVIA (INTERCEPTOR) ===
    // ==========================================

    // 1. Estilos del Quiz
    const styleQuiz = document.createElement('style');
    styleQuiz.innerHTML = `
#quiz-modal-overlay{position:fixed;inset:0;z-index:99999;background:#bdbdbd;opacity:0;pointer-events:none;transition:opacity .18s ease}
#quiz-modal-overlay.active{opacity:1;pointer-events:all}

#checkout-quiz{height:100%;width:100%;max-width:720px;margin:0 auto;display:flex;flex-direction:column;background:#bdbdbd}
.quiz-topbar{height:64px;background:#eab308;display:flex;align-items:center;justify-content:space-between;padding:0 16px;flex-shrink:0}
.quiz-topbar-left{width:40px}
.quiz-topbar-right{display:flex;align-items:center;gap:8px;color:#fff;font-weight:900;font-size:20px}

.quiz-content{flex:1;overflow:auto;padding:14px 16px 120px}
.quiz-question-hero{background:#fff;border-radius:0 0 26px 26px;min-height:240px;display:flex;align-items:center;justify-content:center;padding:24px;box-shadow:0 12px 24px rgba(0,0,0,.15)}
#quiz-question-text{font-size:clamp(20px,4.5vw,28px);font-weight:900;color:#111827;text-align:center;line-height:1.25}

.quiz-options{display:flex;flex-direction:column;gap:14px;margin-top:18px}
.quiz-option-btn{background:#fff;border:0;border-radius:14px;padding:14px 18px;font-size:18px;font-weight:900;color:#111827;box-shadow:0 10px 18px rgba(0,0,0,.12);cursor:pointer;text-align:center;transition:transform .05s ease,background .15s ease,color .15s ease,box-shadow .15s ease}
.quiz-option-btn:active{transform:scale(.99)}
.quiz-option-btn.selected{background:#22c55e;color:#fff}
.quiz-option-btn.correct{background:#16a34a;color:#fff}
.quiz-option-btn.wrong{background:#ef4444;color:#fff}

#checkout-quiz.is-reading .quiz-options{filter:blur(5px);pointer-events:none}

.quiz-footer{position:fixed;left:0;right:0;bottom:0;padding:12px 16px 18px;background:linear-gradient(to top,rgba(189,189,189,.98),rgba(189,189,189,.6),rgba(189,189,189,0))}
.quiz-footer-inner{max-width:720px;margin:0 auto;display:flex;flex-direction:column;gap:8px}
#quiz-confirm-btn{width:100%;border:0;border-radius:16px;padding:16px 18px;font-size:18px;font-weight:900;letter-spacing:.06em;background:#22c55e;color:#fff;cursor:pointer;box-shadow:0 14px 22px rgba(0,0,0,.16)}
#quiz-confirm-btn:disabled{background:#9ca3af;cursor:not-allowed;box-shadow:none}
#quiz-status-text{min-height:18px;text-align:center;font-size:12px;color:#374151}

#quiz-incorrect-marker{margin:10px auto 10px;max-width:680px;text-align:center;font-weight:1000;font-size:clamp(34px,10vw,54px);letter-spacing:-.02em;color:#ef4444;transform:translateY(-8px) scale(.98) rotate(-2deg);opacity:0;pointer-events:none;filter:drop-shadow(0 10px 0 rgba(0,0,0,.12));transition:opacity .12s ease,transform .12s ease}
#quiz-incorrect-marker.active{opacity:1;transform:translateY(0) scale(1) rotate(-2deg)}
#quiz-incorrect-marker .inner{display:inline-block;padding:2px 12px 8px;line-height:1;-webkit-text-stroke:6px #b91c1c;text-shadow:
  3px 2px 0 #b91c1c,
  -3px 2px 0 #b91c1c,
  4px -1px 0 #b91c1c,
  -4px -1px 0 #b91c1c,
  1px 5px 0 #b91c1c,
  -1px 5px 0 #b91c1c,
  6px 4px 0 rgba(0,0,0,.06),
  -6px 4px 0 rgba(0,0,0,.06)
}

#quiz-correct-overlay{position:fixed;inset:0;z-index:100001;display:flex;align-items:center;justify-content:center;background:#065f46;opacity:0;transform:scale(0);transform-origin:center;pointer-events:none;transition:transform .30s cubic-bezier(.2,1.3,.2,1),opacity .30s cubic-bezier(.2,1.3,.2,1)}
#quiz-correct-overlay.active{opacity:1;transform:scale(1);pointer-events:all}
#quiz-correct-overlay .qco-wrap{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;text-align:center;}
#quiz-correct-overlay .qco-icon{width:118px;height:118px;border-radius:999px;background:#10b981;display:flex;align-items:center;justify-content:center;box-shadow:0 18px 36px rgba(0,0,0,.25)}
#quiz-correct-overlay .qco-text{font-weight:1000;font-size:34px;color:#ffffff;letter-spacing:-.02em}
`;
    document.head.appendChild(styleQuiz);

    // 2. HTML del Modal (Inyectado)
    const quizModalHTML = `
<div id="quiz-modal-overlay">
    <div id="checkout-quiz">
        <div class="quiz-topbar">
            <div class="quiz-topbar-left"></div>
            <div class="quiz-topbar-right">
                <svg class="bi w-4 h-4" aria-hidden="true"><use href="#i-clock" xlink:href="#i-clock"></use></svg>
                <span id="quiz-timer-text">0</span>
            </div>
        </div>
        <div class="quiz-content">
            <div id="quiz-incorrect-marker"><span class="inner">¡Incorrecto!</span></div>
            <div class="quiz-question-hero">
                <div id="quiz-question-text">¿...?</div>
            </div>
            <div id="quiz-options-container" class="quiz-options"></div>
        </div>
        <div class="quiz-footer">
            <div class="quiz-footer-inner">
                <button id="quiz-confirm-btn" type="button" disabled>CONFIRMAR</button>
                <p id="quiz-status-text"></p>
            </div>
        </div>
    </div>

    <div id="quiz-correct-overlay" aria-hidden="true">
        <div class="qco-wrap">
            <div class="qco-icon">
                <svg width="64" height="64" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                    <path d="M26.2 41.9L15.5 31.2a2.8 2.8 0 0 0-4 4l12.7 12.7a3 3 0 0 0 4.2 0L53 23.3a2.8 2.8 0 0 0-4-4L26.2 41.9z" fill="#ffffff"/>
                </svg>
            </div>
            <div class="qco-text">¡Correcto!</div>
        </div>
    </div>
</div>
`;
    document.body.insertAdjacentHTML('beforeend', quizModalHTML);

    // 3. Lógica del Juego
    let quizTimerInterval;
    let quizReadingInterval;
    let quizSelectedAnswer;
    let quizSelectedButton;
    let quizPrevBodyOverflow = '';
    let quizIntroPrevBodyOverflow = null;
    let quizFeedbackTimeout;

    function closeQuizOverlay() {
        const overlay = document.getElementById('quiz-modal-overlay');
        const quizRoot = document.getElementById('checkout-quiz');
        const correctOverlay = document.getElementById('quiz-correct-overlay');
        const incorrectMarker = document.getElementById('quiz-incorrect-marker');

        clearInterval(quizTimerInterval);
        clearInterval(quizReadingInterval);
        clearTimeout(quizFeedbackTimeout);
        if (quizRoot) quizRoot.classList.remove('is-reading');
        if (overlay) overlay.classList.remove('active');
        if (correctOverlay) {
            correctOverlay.classList.remove('active');
            correctOverlay.setAttribute('aria-hidden', 'true');
        }
        if (incorrectMarker) incorrectMarker.classList.remove('active');
        document.body.style.overflow = quizPrevBodyOverflow;
        quizIntroPrevBodyOverflow = null;
    }

    let bananoQuizIntroInjected = false;
    let bananoQuizIntroPlaying = false;

    function ensureBananoQuizIntroInjected() {
        if (bananoQuizIntroInjected) return;
        bananoQuizIntroInjected = true;

        const styleBananoIntro = document.createElement('style');
        styleBananoIntro.id = 'banano-quiz-intro-style';
        styleBananoIntro.innerHTML = `
#banano-quiz-intro{position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;pointer-events:all;isolation:isolate;}
#banano-quiz-intro .bqi-bg{position:absolute;inset:0;background:#FFD400;transform:scaleX(0);transform-origin:left center;will-change:transform,opacity;}
#banano-quiz-intro .bqi-content{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18px;text-align:center;transform:translateZ(0);}
#banano-quiz-intro .bqi-title{color:#fff;font-weight:900;letter-spacing:0.06em;text-transform:uppercase;font-size:clamp(44px,8vw,92px);line-height:0.95;text-shadow:0 12px 32px rgba(0,0,0,.28),0 2px 0 rgba(0,0,0,.16);opacity:0;transform:translateY(64px) scale(.2);will-change:transform,opacity;}
#banano-quiz-intro .bqi-banana{width:clamp(92px,18vw,176px);height:auto;display:block;color:#fff;filter:drop-shadow(0 14px 26px rgba(0,0,0,.22));opacity:0;transform:translateY(46px) scale(.2) rotate(-38deg);will-change:transform,opacity;}

#banano-quiz-intro.bqi-play .bqi-bg{animation:bqi-bg-in 560ms cubic-bezier(.22,1,.36,1) both;}
#banano-quiz-intro.bqi-play .bqi-title{animation:bqi-title-in 760ms cubic-bezier(.18,.9,.2,1.15) 120ms both,bqi-float 2200ms ease-in-out 980ms infinite;}
#banano-quiz-intro.bqi-play .bqi-banana{animation:bqi-banana-in 820ms cubic-bezier(.16,.9,.2,1.15) 0ms both,bqi-banana-float 2100ms ease-in-out 920ms infinite;}

#banano-quiz-intro.bqi-exit .bqi-bg{animation:bqi-bg-out 520ms cubic-bezier(.4,0,1,1) both;}
#banano-quiz-intro.bqi-exit .bqi-title{animation:bqi-title-out 520ms cubic-bezier(.3,0,.2,1) both;}
#banano-quiz-intro.bqi-exit .bqi-banana{animation:bqi-banana-out 560ms cubic-bezier(.25,0,.2,1) both;}

@keyframes bqi-bg-in{0%{opacity:0;transform:scaleX(0);}100%{opacity:1;transform:scaleX(1);}}
@keyframes bqi-title-in{0%{opacity:0;transform:translateY(64px) scale(.2);}58%{opacity:1;transform:translateY(-10px) scale(1.09);}78%{transform:translateY(6px) scale(.98);}100%{opacity:1;transform:translateY(0) scale(1);}}
@keyframes bqi-banana-in{0%{opacity:0;transform:translateY(46px) scale(.18) rotate(-38deg);}60%{opacity:1;transform:translateY(-8px) scale(1.1) rotate(12deg);}82%{transform:translateY(5px) scale(.98) rotate(-3deg);}100%{opacity:1;transform:translateY(0) scale(1) rotate(0deg);}}
@keyframes bqi-float{0%,100%{transform:translateY(0) scale(1);}50%{transform:translateY(-7px) scale(1.01);}}
@keyframes bqi-banana-float{0%,100%{transform:translateY(0) scale(1) rotate(0deg);}50%{transform:translateY(-9px) scale(1.015) rotate(3deg);}}

@keyframes bqi-bg-out{0%{opacity:1;transform:scaleX(1);}100%{opacity:0;transform:scaleX(1.02);}}
@keyframes bqi-title-out{0%{opacity:1;transform:translateY(0) scale(1);}100%{opacity:0;transform:translateY(-52px) scale(1.18);}}
@keyframes bqi-banana-out{0%{opacity:1;transform:translateY(0) scale(1) rotate(0deg);}100%{opacity:0;transform:translateX(180px) translateY(-150px) scale(.55) rotate(28deg);}}
@media (prefers-reduced-motion: reduce){
  #banano-quiz-intro *{animation:none!important;}
}
`;
        document.head.appendChild(styleBananoIntro);
    }

    function playBananoQuizIntro(onReveal) {
        ensureBananoQuizIntroInjected();

        return new Promise((resolve) => {
            bananoQuizIntroPlaying = true;

            const prevOverflow = document.body.style.overflow;
            quizIntroPrevBodyOverflow = prevOverflow;
            document.body.style.overflow = 'hidden';

            const existing = document.getElementById('banano-quiz-intro');
            if (existing) existing.remove();

            const intro = document.createElement('div');
            intro.id = 'banano-quiz-intro';
            intro.setAttribute('aria-hidden', 'true');
            intro.innerHTML = `
<div class="bqi-bg"></div>
<div class="bqi-content">
  <svg class="bqi-banana" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Banana">
    <path fill="currentColor" d="M54.6 12.8c-2.7-2.3-6.7-2.3-10.7.2-6.7 4.2-12 13.4-16.1 21.2-2.9 5.6-5.5 10.4-7.6 13-2.2 2.8-4.3 4-6.8 3.9-2.2-.1-4.5-1.3-6.9-3.6-1.2-1.1-1.2-3-.1-4.2 1.1-1.2 3-1.2 4.2-.1 1.3 1.2 2.4 1.9 3.1 2 0 0 .7 0 1.8-1.4 1.8-2.2 4.4-7.1 7.2-12.3 4.4-8.3 10-18.7 18.1-23.8 6.2-3.9 13-3.6 17.6.3 1.3 1.1 1.4 3 .3 4.2-1.1 1.3-3 1.4-4.2.3z"/>
  </svg>
  <div class="bqi-title">BANANO QUIZ</div>
</div>`;
            document.body.appendChild(intro);

            requestAnimationFrame(() => {
                intro.classList.add('bqi-play');
            });

            const entryMs = 820;
            const holdMs = 1700;
            const exitMs = 560;
            const exitAtMs = entryMs + holdMs;

            const exitTimer = setTimeout(() => {
                intro.classList.add('bqi-exit');
                try {
                    if (typeof onReveal === 'function') onReveal();
                } catch (_) { }
            }, exitAtMs);

            const cleanupTimer = setTimeout(() => {
                clearTimeout(exitTimer);
                intro.remove();
                bananoQuizIntroPlaying = false;
                const overlay = document.getElementById('quiz-modal-overlay');
                const quizActive = overlay && overlay.classList.contains('active');
                if (!quizActive) {
                    document.body.style.overflow = prevOverflow;
                }
                resolve();
            }, exitAtMs + exitMs + 80);
        });
    }

    function startQuizWithIntro(config, couponCode) {
        const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reducedMotion) {
            startQuiz(config, couponCode);
            return;
        }
        if (bananoQuizIntroPlaying) return;
        playBananoQuizIntro(() => startQuiz(config, couponCode));
    }

    function startQuiz(config, couponCode) {
        const overlay = document.getElementById('quiz-modal-overlay');
        const quizRoot = document.getElementById('checkout-quiz');
        const questionText = document.getElementById('quiz-question-text');

        const optionsContainer = document.getElementById('quiz-options-container');
        const timerText = document.getElementById('quiz-timer-text');
        const statusText = document.getElementById('quiz-status-text');
        const confirmBtn = document.getElementById('quiz-confirm-btn');
        const correctOverlay = document.getElementById('quiz-correct-overlay');
        const incorrectMarker = document.getElementById('quiz-incorrect-marker');

        clearInterval(quizTimerInterval);
        clearInterval(quizReadingInterval);
        clearTimeout(quizFeedbackTimeout);

        if (correctOverlay) {
            correctOverlay.classList.remove('active');
            correctOverlay.setAttribute('aria-hidden', 'true');
        }
        if (incorrectMarker) incorrectMarker.classList.remove('active');

        quizSelectedAnswer = null;
        quizSelectedButton = null;

        questionText.textContent = config.question;
        optionsContainer.innerHTML = '';
        statusText.textContent = '';
        statusText.style.color = '';

        confirmBtn.disabled = true;
        confirmBtn.onclick = () => {
            if (!quizSelectedAnswer || !quizSelectedButton) return;
            handleAnswer(quizSelectedAnswer, config, couponCode, quizSelectedButton);
        };

        let answers = [...config.answers];
        if (config.randomize) {
            answers.sort(() => Math.random() - 0.5);
        }

        const timeLimitRaw = Number(config.time_limit);
        const timeLimit = Number.isFinite(timeLimitRaw) ? Math.max(1, Math.floor(timeLimitRaw)) : 10;

        const readingSecondsRaw = Number(config.reading_time);
        const readingSeconds = Number.isFinite(readingSecondsRaw) ? Math.max(0, Math.floor(readingSecondsRaw)) : 4;

        answers.forEach(ans => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'quiz-option-btn';
            btn.textContent = ans.text;
            btn.disabled = true;
            btn.onclick = () => {
                if (btn.disabled) return;
                const allBtns = optionsContainer.querySelectorAll('.quiz-option-btn');
                allBtns.forEach(b => b.classList.remove('selected'));
                btn.classList.add('selected');
                quizSelectedAnswer = ans;
                quizSelectedButton = btn;
                confirmBtn.disabled = false;
            };
            optionsContainer.appendChild(btn);
        });

        const currentOverflow = document.body.style.overflow;
        quizPrevBodyOverflow = (quizIntroPrevBodyOverflow !== null && currentOverflow === 'hidden') ? quizIntroPrevBodyOverflow : currentOverflow;
        document.body.style.overflow = 'hidden';
        overlay.classList.add('active');

        if (quizRoot) quizRoot.classList.toggle('is-reading', readingSeconds > 0);

        let readingLeft = readingSeconds;
        if (readingLeft > 0) {
            statusText.textContent = 'Lee la pregunta...';
            timerText.textContent = String(readingLeft);
            clearInterval(quizReadingInterval);
            quizReadingInterval = setInterval(() => {
                readingLeft--;
                timerText.textContent = String(Math.max(readingLeft, 0));
                if (readingLeft <= 0) {
                    clearInterval(quizReadingInterval);
                    statusText.textContent = '';
                    if (quizRoot) quizRoot.classList.remove('is-reading');
                    beginRoundTimer();
                }
            }, 1000);
        } else {
            beginRoundTimer();
        }

        function beginRoundTimer() {
            const allBtns = optionsContainer.querySelectorAll('.quiz-option-btn');
            allBtns.forEach(b => {
                b.disabled = false;
                b.classList.remove('correct', 'wrong', 'selected');
            });

            confirmBtn.disabled = true;
            quizSelectedAnswer = null;
            quizSelectedButton = null;

            let timeLeft = timeLimit;
            timerText.textContent = String(timeLeft);
            clearInterval(quizTimerInterval);
            quizTimerInterval = setInterval(() => {
                timeLeft--;
                timerText.textContent = String(Math.max(timeLeft, 0));
                if (timeLeft <= 0) {
                    clearInterval(quizTimerInterval);
                    handleFail(config, couponCode, '¡Se acabó el tiempo!');
                }
            }, 1000);
        }
    }

    function handleAnswer(answer, config, couponCode, btnElement) {
        clearInterval(quizTimerInterval);
        clearInterval(quizReadingInterval);
        clearTimeout(quizFeedbackTimeout);

        const optionsContainer = document.getElementById('quiz-options-container');
        const confirmBtn = document.getElementById('quiz-confirm-btn');
        const correctOverlay = document.getElementById('quiz-correct-overlay');
        const incorrectMarker = document.getElementById('quiz-incorrect-marker');
        if (confirmBtn) confirmBtn.disabled = true;

        // Bloquear todos los botones
        if (optionsContainer) {
            const allBtns = optionsContainer.querySelectorAll('.quiz-option-btn');
            allBtns.forEach(b => b.disabled = true);
        }

        if (answer.correct) {
            // GANÓ
            btnElement.classList.add('correct');

            if (incorrectMarker) incorrectMarker.classList.remove('active');
            if (correctOverlay) {
                correctOverlay.setAttribute('aria-hidden', 'false');
                requestAnimationFrame(() => {
                    correctOverlay.classList.add('active');
                });
            }

            // Guardar victoria en LocalStorage
            localStorage.setItem(`quiz_passed_${couponCode}`, 'true');

            // Sonido de victoria (opcional)
            new Audio('https://assets.mixkit.co/sfx/preview/mixkit-correct-answer-tone-2870.mp3').play().catch(() => { });

            quizFeedbackTimeout = setTimeout(() => {
                closeQuizOverlay();
                // Recargar estado del checkout para mostrar el descuento
                revalidateCouponState(false);
            }, 1100);

        } else {
            // PERDIÓ
            btnElement.classList.add('wrong');
            if (incorrectMarker) incorrectMarker.classList.add('active');
            quizFeedbackTimeout = setTimeout(() => {
                if (incorrectMarker) incorrectMarker.classList.remove('active');
                handleFail(config, couponCode, config.msg_fail || "Respuesta incorrecta");
            }, 1500);
        }
    }

    function handleFail(config, couponCode, message) {
        const statusText = document.getElementById('quiz-status-text');
        statusText.textContent = message;
        statusText.style.color = '#b91c1c';

        clearInterval(quizTimerInterval);
        clearInterval(quizReadingInterval);

        const confirmBtn = document.getElementById('quiz-confirm-btn');
        if (confirmBtn) confirmBtn.disabled = true;
        const optionsContainer = document.getElementById('quiz-options-container');
        if (optionsContainer) {
            const allBtns = optionsContainer.querySelectorAll('.quiz-option-btn');
            allBtns.forEach(b => b.disabled = true);
        }

        // Sonido error
        new Audio('https://assets.mixkit.co/sfx/preview/mixkit-wrong-answer-fail-notification-946.mp3').play().catch(() => { });

        // Guardar fallo en LocalStorage
        const failData = JSON.parse(localStorage.getItem(`quiz_fail_${couponCode}`) || '{"attempts":0, "timestamp":0}');
        failData.attempts++;
        failData.timestamp = Date.now();
        localStorage.setItem(`quiz_fail_${couponCode}`, JSON.stringify(failData));

        setTimeout(() => {
            closeQuizOverlay();
            // Limpiamos el input del cupón visualmente
            document.getElementById('coupon-code').value = '';
            state.coupon.code = '';
            showToast(message, 'error');
            // Recargar para limpiar el mensaje de "cupón aplicado" si lo hubiera
            revalidateCouponState(false);
        }, 2000);
    }

    // --- ESTADO GLOBAL DE LA APLICACIÓN ---
    const state = {
        products: window.INITIAL_PRODUCTS,
        recipients: [{ id: 1, name: '', error: null }, { id: 2, name: '', error: null }, { id: 3, name: '', error: null }],
        contact: { email: '', whatsapp: '' },
        nickMode: 'single',
        isSelfNickEditable: false,
        paymentMethods: window.PAYMENT_METHODS,
        coupon: { code: '', discount: 0, type: 'percentage', amount: 0, affected_products: [], info: null, serverData: null },
        rarityData: {},
        coins: { active: false, data: null, publicConfig: null }, // <--- NUEVO ESTADO PARA MONEDAS
        user: null,
        avatars_list: []
    };

    let selfNickAutoLockInProgress = false;

    function lockSelfNick(options = {}) {
        const { blur = false } = options;

        if (state.nickMode !== 'single') return;
        if (!state.isSelfNickEditable) return;
        if (!(window.USER_DATA && window.USER_DATA.is_logged_in)) return;
        if (selfNickAutoLockInProgress) return;

        selfNickAutoLockInProgress = true;

        const input = document.getElementById('single-nick');
        if (blur && input && document.activeElement === input) {
            try { input.blur(); } catch (_) { }
        }

        state.isSelfNickEditable = false;
        renderNickAssignment();

        selfNickAutoLockInProgress = false;
    }

    // --- NUEVO: LISTA DE CUENTAS PARA EL MODAL ---
    const giftAccounts = ["BananoRegalo1", "BananoRegalo2", "BananoRegalo3", "BananoRegalo4", "BananoRegalo5", "BananoRegalo6", "BananoRegalo7", "BananoRegalo8", "BananoRegalo9", "BananoRegalo10"];

    // ... el resto de tu código JS continúa aquí sin cambios ...
    // --- CONFIGURACIÓN E INICIALIZACIÓN ---
    async function fetchRarityData() { try { const response = await fetch('/web.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'v8n' }) }); if (!response.ok) { console.error('La respuesta de get_rarities.php no fue OK'); return; } const data = await response.json(); if (data.rarities) { data.rarities.forEach(rarity => { state.rarityData[rarity.id] = { name: rarity.name, color: rarity.colors.mainColor }; }); } } catch (error) { console.error('Error al cargar datos de rareza:', error); } }
    state.products.forEach(p => { if (!p.assignments || !Array.isArray(p.assignments)) { p.assignments = []; } });
    const needsMultiModeInitially = state.products.some(p => p.quantity > 1 && !p.is_static);
    if (needsMultiModeInitially) { state.nickMode = 'multi'; }
    updateRecipientButtonsState();
    if (state.paymentMethods.length > 0) { const firstEnabledMethod = state.paymentMethods.find(m => !m.disabled); state.selectedPayment = firstEnabledMethod ? firstEnabledMethod.id : null; }
    const whatsappInput = document.getElementById('whatsapp');

    const preferredCountry = (() => {
        const raw = (window.USER_DATA && typeof window.USER_DATA.country_iso2 === 'string')
            ? window.USER_DATA.country_iso2
            : '';
        const cleaned = raw.toLowerCase().replace(/[^a-z]/g, '').slice(0, 2);
        return cleaned || 'pe';
    })();

    const iti = window.intlTelInput(whatsappInput, { initialCountry: preferredCountry, separateDialCode: true, utilsScript: "https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.13/js/utils.js", dropdownContainer: document.body });

    // --- Phone formatting: "912 345 678" ---
    function formatPhone(raw, maxDigits) {
        const digits = raw.replace(/\D/g, '').slice(0, maxDigits);
        return digits.length ? digits.match(/.{1,3}/g).join(' ') : '';
    }

    function getMaxDigits() {
        return iti.getSelectedCountryData().iso2 === 'pe' ? 9 : 15;
    }

    function applyPhoneFormat() {
        const max = getMaxDigits();
        const cursorPos = whatsappInput.selectionStart;
        const digitsBefore = whatsappInput.value.slice(0, cursorPos).replace(/\D/g, '').length;
        const formatted = formatPhone(whatsappInput.value, max);

        if (whatsappInput.value !== formatted) {
            whatsappInput.value = formatted;
            // Map digit-index back to character-index in formatted string
            // Each space appears after every 3rd digit, so: charPos = digitPos + floor((digitPos-1)/3)
            const newCursor = digitsBefore === 0 ? 0 : digitsBefore + Math.floor((digitsBefore - 1) / 3);
            whatsappInput.setSelectionRange(newCursor, newCursor);
        }
        return whatsappInput.value.replace(/\D/g, '');
    }

    whatsappInput.addEventListener('input', () => {
        const digits = applyPhoneFormat();
        if (iti.getSelectedCountryData().iso2 === 'pe' && digits.length === 9) {
            whatsappInput.blur();
        }
    });

    let whatsappAutoLockEnabled = false;
    const WHATSAPP_LOCK_CLASSES = ['cursor-not-allowed'];
    const WHATSAPP_LOCK_INPUT_EXTRA_CLASSES = ['pr-10'];

    function getWhatsappItiWrapper() {
        if (!whatsappInput) return null;
        return whatsappInput.closest('.iti') || whatsappInput.parentElement;
    }

    // 2. FUNCIÓN PRINCIPAL DE ESTILIZADO Y EVENTOS
    function styleWhatsappAsInputField() {
        if (!whatsappInput) return;

        const wrapper = getWhatsappItiWrapper();
        if (wrapper) {
            wrapper.classList.add('whatsapp-iti');

            // Detector para evitar bloqueo al tocar la bandera
            const flagContainer = wrapper.querySelector('.iti__flag-container');
            if (flagContainer) {
                flagContainer.addEventListener('mousedown', () => {
                    whatsappInput.dataset.interactingWithFlag = 'true';
                    setTimeout(() => { whatsappInput.dataset.interactingWithFlag = 'false'; }, 500);
                });
            }
        }

        // Listeners estándar
        whatsappInput.addEventListener('keydown', (e) => {
            if (whatsappAutoLockEnabled) {
                if (e.key === 'Tab') return;
                e.preventDefault();
                return;
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                lockWhatsappInput();
                try { whatsappInput.blur(); } catch (_) { }
            }
        });

        whatsappInput.addEventListener('paste', (e) => {
            if (whatsappAutoLockEnabled) e.preventDefault();
        });

        whatsappInput.addEventListener('focusout', (e) => {
            if (whatsappInput.dataset.interactingWithFlag === 'true') {
                whatsappInput.dataset.interactingWithFlag = 'false';
                return;
            }
            if (whatsappAutoLockEnabled) {
                lockWhatsappInput();
            }
        });
    }

    // Ejecutamos la configuración inicial
    styleWhatsappAsInputField();

    function removeWhatsappEditButton() {
        const wrapper = getWhatsappItiWrapper();
        if (!wrapper) return;
        const btn = wrapper.querySelector('[data-role="edit-whatsapp-btn"]');
        if (btn) btn.remove();
    }

    function unlockWhatsappInput(options = {}) {
        const { focus = false, disableLock = false } = options;
        if (!whatsappInput) return;
        if (disableLock) whatsappAutoLockEnabled = false;

        // CORRECCIÓN: Quitamos la clase 'is-locked', NO el atributo readonly
        whatsappInput.classList.remove('is-locked');
        // whatsappInput.removeAttribute('readonly'); // Eliminado para permitir clic en bandera

        const wrapper = getWhatsappItiWrapper();
        if (wrapper) {
            wrapper.classList.remove('focus');
        }
        WHATSAPP_LOCK_CLASSES.forEach(cls => whatsappInput.classList.remove(cls));
        WHATSAPP_LOCK_INPUT_EXTRA_CLASSES.forEach(cls => whatsappInput.classList.remove(cls));
        removeWhatsappEditButton();
        if (focus) {
            try { whatsappInput.focus(); } catch (_) { }
        }
    }

    function ensureWhatsappEditButton() {
        const wrapper = getWhatsappItiWrapper();
        if (!wrapper) return null;
        const existing = wrapper.querySelector('[data-role="edit-whatsapp-btn"]');
        if (existing) return existing;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.dataset.role = 'edit-whatsapp-btn';
        btn.className = 'input-wrap__action z-20';
        btn.setAttribute('aria-label', 'Editar mi WhatsApp');
        btn.innerHTML = svgIcon('pen', 'w-4 h-4');
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            unlockWhatsappInput({ focus: true });
        });

        wrapper.appendChild(btn);
        return btn;
    }

    function lockWhatsappInput() {
        if (!whatsappInput) return;
        if (!whatsappAutoLockEnabled) return;

        // CORRECCIÓN: Usamos clase 'is-locked' en lugar de atributo 'readonly'
        whatsappInput.classList.add('is-locked');
        // whatsappInput.setAttribute('readonly', 'true'); // Eliminado

        const wrapper = getWhatsappItiWrapper();
        if (wrapper) {
            wrapper.classList.remove('focus');
        }
        WHATSAPP_LOCK_CLASSES.forEach(cls => whatsappInput.classList.add(cls));
        WHATSAPP_LOCK_INPUT_EXTRA_CLASSES.forEach(cls => whatsappInput.classList.add(cls));
        ensureWhatsappEditButton();
    }

    function applyWhatsappPhoneFromDashboard(phoneRaw) {
        const phone = String(phoneRaw || '').trim();
        if (!phone) {
            unlockWhatsappInput({ disableLock: true });
            return;
        }

        try {
            iti.setNumber(phone);
            whatsappInput.value = formatPhone(whatsappInput.value, getMaxDigits());
        } catch (_) { }
        whatsappAutoLockEnabled = true;
        lockWhatsappInput();
    }


    const COINS_STEP = 1;
    const DEFAULT_COINS_REDEEM_MIN = 0;
    const DEFAULT_COINS_REDEEM_STEP = 1;
    const COINS_NUMBER_FORMATTER = new Intl.NumberFormat(undefined);

    function getCoinsRedeemRules() {
        const config = state.coins?.publicConfig || state.coins?.data?.config || {};
        const minRaw = Number(config?.redeem_min_coins);
        const stepRaw = Number(config?.redeem_step_coins);
        const min = Number.isFinite(minRaw) ? Math.max(0, Math.floor(minRaw)) : DEFAULT_COINS_REDEEM_MIN;
        const step = Number.isFinite(stepRaw) ? Math.max(1, Math.floor(stepRaw)) : DEFAULT_COINS_REDEEM_STEP;
        return { min, step };
    }

    function normalizeCoinsValue(value, maxAvailable) {
        const max = Number(maxAvailable);
        const n = Number(value);
        if (!Number.isFinite(max) || max <= 0) return 0;
        if (!Number.isFinite(n)) return 0;

        const rules = getCoinsRedeemRules();
        const maxInt = Math.floor(max);
        if (maxInt < rules.min) return 0;

        let v = Math.floor(n);
        if (rules.min === 0 && v <= 0) return 0;

        v = Math.min(Math.max(v, rules.min), maxInt);

        const offset = v - rules.min;
        const snapped = rules.min + Math.round(offset / rules.step) * rules.step;
        let bounded = Math.min(maxInt, Math.max(rules.min, snapped));
        bounded = rules.min + Math.floor((bounded - rules.min) / rules.step) * rules.step;
        if (bounded < rules.min) bounded = rules.min;
        if (rules.min === 0 && bounded <= 0) return 0;
        return bounded;
    }

    function formatCoinsDisplayValue(value) {
        const n = Number(value);
        if (!Number.isFinite(n)) return '';
        return COINS_NUMBER_FORMATTER.format(Math.floor(n));
    }

    function getCaretIndexForDigitCount(formattedValue, digitCount) {
        if (!Number.isFinite(digitCount) || digitCount <= 0) return 0;
        let seen = 0;
        for (let i = 0; i < formattedValue.length; i++) {
            if (formattedValue[i] >= '0' && formattedValue[i] <= '9') {
                seen += 1;
                if (seen >= digitCount) return i + 1;
            }
        }
        return formattedValue.length;
    }

    function updateCoinsQuickButtonsUI() {
        if (!state.coins?.active) return;
        const maxCoins = getCoinsSmartMaxAvailable();
        const rules = getCoinsRedeemRules();
        const selected = normalizeCoinsValue(state.coins.selectedAmount ?? 0, maxCoins);
        const half = normalizeCoinsValue(Math.floor(maxCoins / 2), maxCoins);
        const max = normalizeCoinsValue(maxCoins, maxCoins);

        const minBtn = document.getElementById('coins-min-btn');
        const halfBtn = document.getElementById('coins-half-btn');
        const maxBtn = document.getElementById('coins-max-btn');
        if (!minBtn || !halfBtn || !maxBtn) return;

        const isMin = selected === rules.min;
        const isHalf = selected === half && maxCoins > 0;
        const isMax = selected === max && maxCoins > 0;

        minBtn.classList.toggle('is-active', isMin);
        halfBtn.classList.toggle('is-active', isHalf);
        maxBtn.classList.toggle('is-active', isMax);

        minBtn.setAttribute('aria-pressed', String(isMin));
        halfBtn.setAttribute('aria-pressed', String(isHalf));
        maxBtn.setAttribute('aria-pressed', String(isMax));
    }

    function getCoinsMoneyValue(selectedCoins) {
        const config = state.coins?.data?.config;
        const ratioMoney = Number(config?.ratio_money ?? 0);
        const ratioCoins = Number(config?.ratio_coins ?? 0);
        if (!Number.isFinite(ratioMoney) || !Number.isFinite(ratioCoins) || ratioCoins <= 0) return 0;
        return (Number(selectedCoins) * ratioMoney) / ratioCoins;
    }

    function updateCoinsSliderUI(options = {}) {
        const { preserveNumberInput = false } = options;
        if (!state.coins?.active) return;

        const slider = document.getElementById('coins-slider');
        const numberInput = document.getElementById('coins-input');
        if (!slider || !numberInput) return;

        const maxCoins = getCoinsSmartMaxAvailable();
        const rules = getCoinsRedeemRules();
        const selected = normalizeCoinsValue(state.coins.selectedAmount ?? 0, maxCoins);
        state.coins.selectedAmount = selected;

        slider.min = String(rules.min);
        slider.max = String(Math.floor(maxCoins));
        slider.step = String(rules.step);
        slider.value = String(selected);
        slider.setAttribute('role', 'slider');
        slider.setAttribute('aria-valuemin', String(rules.min));
        slider.setAttribute('aria-valuemax', String(Math.floor(maxCoins)));
        slider.setAttribute('aria-valuenow', String(selected));
        slider.setAttribute('aria-valuetext', `${formatCoinsDisplayValue(selected)} Bananos`);

        numberInput.min = String(rules.min);
        numberInput.max = String(Math.floor(maxCoins));
        numberInput.step = String(rules.step);
        if (!preserveNumberInput) numberInput.value = formatCoinsDisplayValue(selected);

        const denom = maxCoins - rules.min;
        const percent = denom > 0 ? ((selected - rules.min) / denom) * 100 : 0;

        slider.style.setProperty('--progress', `${percent}%`);

        updateCoinsQuickButtonsUI();
    }

    function toMoney(value) {
        const n = Number(value);
        return Number.isFinite(n) ? n : 0;
    }

    // Total base tras cupón = Subtotal - Descuento (nunca negativo).
    function getSummaryBaseTotal(summary) {
        const s = summary || {};
        const base = toMoney(s.subtotal) - toMoney(s.discount);
        return base > 0 ? Math.round(base * 100) / 100 : 0;
    }

    function normalizeSummary(subtotal, discount) {
        const s = { subtotal: toMoney(subtotal), discount: toMoney(discount), total: 0 };
        s.total = getSummaryBaseTotal(s);
        return s;
    }

    function updateTotalsAfterCoins() {
        const summary = state.summary || { subtotal: 0, discount: 0, total: 0 };
        const baseTotal = getSummaryBaseTotal(summary);
        let currentTotal = baseTotal;

        if (state.coins?.active && state.coins?.data && state.coins.data.redeemable_coins > 0) {
            const maxCoins = getCoinsSmartMaxAvailable(baseTotal);
            const selected = normalizeCoinsValue(state.coins.selectedAmount ?? 0, maxCoins);
            state.coins.selectedAmount = selected;

            const moneyValue = getCoinsMoneyValue(selected);
            currentTotal -= moneyValue;
            if (currentTotal < 0) currentTotal = 0;

            const savingsText = document.getElementById('coins-savings-text');
            const savingsValueEl = document.querySelector('#coins-savings-text .coins-savings-value');
            if (savingsValueEl) savingsValueEl.textContent = `S/ ${moneyValue.toFixed(2)}`;
            else if (savingsText) savingsText.textContent = `Ahorro: S/ ${moneyValue.toFixed(2)}`;
        }

        const paymentMethod = state.paymentMethods.find(pm => pm.id === state.selectedPayment);
        let feeAmount = 0;
        if (paymentMethod && !paymentMethod.disabled && currentTotal > 0) {
            feeAmount += paymentMethod.feeFixed || 0;
            if (paymentMethod.fee > 0) feeAmount += currentTotal * (paymentMethod.fee / 100);
        }

        const feeRow = document.getElementById('summary-fee-row');
        if (feeAmount > 0) {
            feeRow.classList.remove('hidden');
            const feeLabel = document.getElementById('summary-fee-label');
            const feeEl = document.getElementById('summary-fee');
            if (feeLabel) feeLabel.textContent = `Comisión ${paymentMethod?.name ?? ''}`;
            if (feeEl) feeEl.textContent = `+S/ ${feeAmount.toFixed(2)}`;
        } else {
            feeRow.classList.add('hidden');
        }

        const totalFinalDisplay = currentTotal + feeAmount;
        const totalEl = document.getElementById('summary-total');
        if (totalEl) totalEl.textContent = `S/ ${totalFinalDisplay.toFixed(2)}`;

        const coinsTotalEl = document.getElementById('coins-total-value');
        if (coinsTotalEl) coinsTotalEl.textContent = `S/ ${totalFinalDisplay.toFixed(2)}`;

        // Espejo a la vista móvil (resumen inferior + barra fija de pago).
        const mFeeRow = document.getElementById('m-summary-fee-row');
        if (mFeeRow) {
            if (feeAmount > 0) {
                mFeeRow.classList.remove('hidden');
                const mFeeLabel = document.getElementById('m-summary-fee-label');
                const mFee = document.getElementById('m-summary-fee');
                if (mFeeLabel) mFeeLabel.textContent = `Comisión ${paymentMethod?.name ?? ''}`;
                if (mFee) mFee.textContent = `+S/ ${feeAmount.toFixed(2)}`;
            } else {
                mFeeRow.classList.add('hidden');
            }
        }
        const mTotalEl = document.getElementById('m-summary-total');
        if (mTotalEl) mTotalEl.textContent = `S/ ${totalFinalDisplay.toFixed(2)}`;
        const mobileCtaTotalEl = document.getElementById('mobile-cta-total');
        if (mobileCtaTotalEl) mobileCtaTotalEl.textContent = `S/ ${totalFinalDisplay.toFixed(2)}`;
    }

    function getCoinsMaxApplicableForTotalMoney(totalMoney) {
        const money = Number(totalMoney);
        if (!Number.isFinite(money) || money <= 0) return 0;

        const config = state.coins?.data?.config;
        const ratioMoney = Number(config?.ratio_money ?? 0);
        const ratioCoins = Number(config?.ratio_coins ?? 0);
        if (!Number.isFinite(ratioMoney) || !Number.isFinite(ratioCoins) || ratioMoney <= 0 || ratioCoins <= 0) return Infinity;

        const needed = Math.ceil((money * ratioCoins) / ratioMoney);
        if (!Number.isFinite(needed)) return Infinity;

        const rules = getCoinsRedeemRules();
        const neededInt = Math.max(0, Math.floor(needed));
        if (neededInt <= 0) return 0;

        const min = Math.max(0, Math.floor(rules.min));
        const step = Math.max(1, Math.floor(rules.step));
        const base = neededInt;

        // If the cart needs fewer coins than redeem_min_coins, return that need (caller will hide/disable coins).
        if (base < min) return base;
        if (base === min) return min;
        if (step === 1) return base;

        const offset = base - min;
        return min + Math.ceil(offset / step) * step;
    }

    function getCoinsSmartMaxAvailable(totalMoneyOverride) {
        const balanceRaw = Number(state.coins?.data?.redeemable_coins ?? 0);
        if (!Number.isFinite(balanceRaw) || balanceRaw <= 0) return 0;

        const balanceInt = Math.max(0, Math.floor(balanceRaw));

        const totalMoney = (totalMoneyOverride !== undefined && totalMoneyOverride !== null)
            ? totalMoneyOverride
            : getSummaryBaseTotal(state.summary);

        const maxApplicable = getCoinsMaxApplicableForTotalMoney(totalMoney);
        const cap = Number.isFinite(maxApplicable) ? Math.min(balanceInt, Math.floor(maxApplicable)) : balanceInt;
        if (cap <= 0) return 0;

        const rules = getCoinsRedeemRules();
        if (cap < rules.min) return cap;

        // Ensure the returned max is reachable by min/step (and never exceeds cap).
        return normalizeCoinsValue(cap, cap);
    }

    // Fingerprint to detect if products actually changed (avoids re-animation)
    let _lastProductFingerprint = '';

    // Error icon SVG for validation feedback (exact copy from onboarding modal)
    const ERROR_ICON_SVG = '<svg class="field-error-icon" viewBox="0 0 20 20" width="14" height="14" aria-hidden="true"><circle cx="10" cy="10" r="9" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M10 5.5v5M10 13.5v.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
    const SUCCESS_ICON_SVG = '<svg viewBox="0 0 20 20" width="14" height="14" aria-hidden="true"><circle cx="10" cy="10" r="9" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M6 10.5l2.5 2.5 5.5-5.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    // Abreviaturas de tipo para el resumen compacto
    const SUMMARY_TYPE_ABBR = { 'Lote de objeto(s)': 'Lote', 'Lote de 4 objeto(s)': 'Lote', 'Pista de improvisación': 'Pista', 'Atuendo': 'Skin', 'Picos': 'Pico', 'Ala delta': 'Ala Delta', 'Gesto': 'Gesto', 'Mochila retro': 'Mochila' };
    const escAttr = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

    function _getProductFingerprint() {
        return state.products.map(p => `${p.id}:${p.quantity}:${p.price}:${(p.assignments||[]).join(',')}`).join('|');
    }

    function render(forceProducts = false) {
        if (state.products.length === 0) { document.getElementById('checkout-container').classList.add('hidden'); document.getElementById('empty-cart-container').style.display = 'block'; return; }
        document.getElementById('checkout-container').classList.remove('hidden'); document.getElementById('empty-cart-container').style.display = 'none';
        renderNickAssignment();
        const fp = _getProductFingerprint();
        if (forceProducts || fp !== _lastProductFingerprint) {
            _lastProductFingerprint = fp;
            renderProducts();
        }
        renderPaymentMethods(); renderSummary();
    }

    function renderSingleUserField(recipient, error, options = {}) {
        const safeRecipient = (recipient && typeof recipient === 'object') ? recipient : { name: '', error: null };
        const nameValue = (typeof safeRecipient.name === 'string') ? safeRecipient.name : '';
        const recipientError = error || safeRecipient.error;
        const desktopHint = 'Escribe el nombre exactamente como aparece en el juego, incluyendo caracteres especiales si aplica.';
        const mobileHint = 'Escribe el nombre exacto (incluye caracteres especiales).';

        const isLoggedIn = !!options.isLoggedIn;
        const isEditable = !!options.isEditable;

        // Unified error/hint block for all cases
        const feedbackHTML = recipientError
            ? `<div class="checkout-field-error" role="alert">${ERROR_ICON_SVG}<span class="field-error-text">${recipientError}</span></div>`
            : `<p class="checkout-field-hint text-xs mt-2 text-[--text-secondary]">
                <span class="block md:hidden">${mobileHint}</span>
                <span class="hidden md:block">${desktopHint}</span>
              </p>`;

        // CASO 1: NO LOGUEADO (Siempre editable)
        if (!isLoggedIn) {
            return `<div data-recipient-field="single">
            <input type="text" id="single-nick" class="input-field ${recipientError ? 'input-error' : ''}" placeholder="Ejemplo: BananoMondongo777" value="${nameValue}" maxlength="16">
            ${feedbackHTML}
        </div>`;
        }

        // CASO 2: LOGUEADO Y EDITABLE (Botón lápiz presionado)
        if (isEditable) {
            return `<div data-recipient-field="single">
            <input type="text" id="single-nick" class="input-field ${recipientError ? 'input-error' : ''}" placeholder="Ejemplo: BananoMondongo777" value="${nameValue}" maxlength="16">
            ${feedbackHTML}
        </div>`;
        }

        // CASO 3: LOGUEADO Y BLOQUEADO
        return `<div data-recipient-field="single">
        <div class="relative group">
            <input type="text" id="single-nick" class="input-field pr-10 ${recipientError ? 'input-error' : ''}" placeholder="Ejemplo: BananoMondongo777" value="${nameValue}" maxlength="16" readonly aria-readonly="true">
            <button type="button" id="edit-self-nick-btn" class="field-inline-btn" aria-label="Editar mi nombre de usuario">
                ${svgIcon('pen')}
            </button>
        </div>
        ${feedbackHTML}
    </div>`;
    }


    function renderNickAssignment() {
        const container = document.getElementById('nick-assignment-container');
        const section = document.getElementById('dest-section');
        const [singleBtn, multiBtn] = [document.getElementById('single-user-btn'), document.getElementById('multi-user-btn')];

        // --- 1. CÁLCULOS INICIALES ---
        // Calculamos si hay productos estáticos (Variable recuperada)
        const hasStaticProducts = state.products.some(p => p.is_static);

        // Calculamos si se necesita nickname (API products O Estáticos que lo requieran)
        const needsNick = state.products.some(p => !p.is_static || p.requiere_nickname === 1);

        // --- 2. CASO: NO SE NECESITA NICKNAME ---
        if (!needsNick) {
            section.classList.remove('hidden');
            singleBtn.disabled = true;
            multiBtn.disabled = true;
            singleBtn.classList.add('mode-btn-active', 'mode-btn-disabled');
            multiBtn.classList.add('mode-btn-disabled');
            multiBtn.classList.remove('mode-btn-active');

            container.innerHTML = `<div class="mt-4 p-4 bg-[--bg-tertiary] rounded-lg text-center text-sm text-[--text-secondary]">
            <p class="font-semibold flex items-center gap-2">${svgIcon('info','w-4 h-4')}La entrega se realiza vía cuenta.</p>
            <p>Coordinaremos los datos de acceso por WhatsApp.</p>
        </div>`;
            return; // Detiene la ejecución aquí, no dibuja inputs
        }

        // --- 3. CASO: SÍ SE NECESITA NICKNAME ---
        section.classList.remove('hidden');
        singleBtn.disabled = false;
        multiBtn.disabled = false;
        singleBtn.classList.remove('mode-btn-disabled');
        multiBtn.classList.remove('mode-btn-disabled');

        // --- CAMBIO: Nuevos textos y enlace al final ---
        const accountsLink = `<a href="#" id="open-accounts-modal" class="clickable-link">cuentas vía regalo</a>`;
        const desktopSubtitle = `Elige a quién(es) se enviarán los regalos. Recuerda enviar la solicitud de amistad al menos 48 horas antes a nuestras ${accountsLink}.`;
        const mobileSubtitle = `Elige a quién(es) se enviarán los regalos. Recuerda enviar solicitud de amistad 48h antes a nuestras ${accountsLink}.`;

        document.querySelector('#dest-section .subtitle-desktop').innerHTML = desktopSubtitle;
        document.querySelector('#dest-section .subtitle-mobile').innerHTML = mobileSubtitle;

        let finalHTML = '';
        if (state.nickMode === 'single') {
            singleBtn.classList.add('mode-btn-active');
            multiBtn.classList.remove('mode-btn-active');

            if (!Array.isArray(state.recipients)) state.recipients = [];
            if (!state.recipients[0] || typeof state.recipients[0] !== 'object') state.recipients[0] = { id: 1, name: '', error: null };
            if (typeof state.recipients[0].name !== 'string') state.recipients[0].name = '';

            const isLoggedIn = !!(window.USER_DATA && window.USER_DATA.is_logged_in);
            const userNick = (window.USER_DATA && typeof window.USER_DATA.nickname === 'string') ? window.USER_DATA.nickname : '';
            if (isLoggedIn && state.recipients[0].name === '' && userNick) state.recipients[0].name = userNick;

            finalHTML = renderSingleUserField(state.recipients[0], state.recipients[0].error, { isLoggedIn, isEditable: state.isSelfNickEditable });
        } else {
            multiBtn.classList.add('mode-btn-active');
            singleBtn.classList.remove('mode-btn-active');

            // Dynamic recipient count: match total non-static item quantity
            const totalSlots = state.products.filter(p => !p.is_static).reduce((sum, p) => sum + p.quantity, 0);
            const clampedSlots = Math.max(2, Math.min(totalSlots, 3));

            // Resize recipients array to match
            while (state.recipients.length < clampedSlots) {
                state.recipients.push({ id: state.recipients.length + 1, name: '', error: null });
            }
            if (state.recipients.length > clampedSlots) {
                state.recipients.length = clampedSlots;
            }

            const recipientInputs = state.recipients.map((r, i) => `
            <div>
                <input type="text" value="${r.name}" data-recipient-index="${i}" placeholder="Usuario #${i + 1}" class="input-field recipient-input text-sm ${r.error ? 'input-error' : ''}" maxlength="16">
                ${r.error ? `<div class="checkout-field-error" role="alert">${ERROR_ICON_SVG}<span class="field-error-text">${r.error}</span></div>` : ''}
            </div>`).join('');

            const gridCols = clampedSlots === 2 ? 'sm:grid-cols-2' : 'sm:grid-cols-3';
            finalHTML = `<div class="grid grid-cols-1 ${gridCols} gap-y-2 sm:gap-3">${recipientInputs}</div><p class="text-xs text-[--text-secondary] mt-2">Define hasta ${clampedSlots} amigos. Luego, en cada producto, asigna cada ítem.</p>`;
        }

        // Aquí usamos la variable hasStaticProducts que definimos al inicio de la función
        // Solo mostramos el aviso si hay estáticos PERO NO se pidió nickname
        // (Si se pidió nickname, ya es obvio para quién es)
        if (hasStaticProducts && !needsNick) {
            finalHTML += `<div class="mt-3 p-2 bg-[--bg-tertiary] rounded-md text-center text-xs text-[--text-secondary] font-medium italic">${svgIcon('info','w-3.5 h-3.5 mr-1')} Tienes un producto vía cuenta. La entrega se coordinará por WhatsApp.</div>`;
        }
        // AUTO-RELLENADO SI ES SINGLE Y ESTOY LOGUEADO
        if (state.nickMode === 'single' && window.USER_DATA && window.USER_DATA.is_logged_in) {
            if (state.recipients && state.recipients[0] && state.recipients[0].name === '' && typeof window.USER_DATA.nickname === 'string') {
                state.recipients[0].name = window.USER_DATA.nickname;
            }
        }
        container.innerHTML = finalHTML;
        reapplyValidationFeedback();
    }

    // ---------- Vista: fila de producto (anatomía cart-line) ----------
    const RARITY_LABELS = { 'COMÚN': 'Común', 'POCO COMÚN': 'Poco Común', 'RARE': 'Raro', 'EPIC': 'Épico', 'LEGENDARY': 'Legendario', 'MYTHIC': 'Mítico', 'TRANSCENDENT': 'Trascendente', 'DC SERIES': 'Serie de DC', 'MARVEL SERIES': 'Serie de Marvel', 'ICON SERIES': 'Serie de Ídolos', 'STAR WARS SERIES': 'Serie de Star Wars', 'GAMING LEGENDS SERIES': 'Leyendas de Videojuegos' };

    const productTypeLabel = (p) => (!p.is_static && p.type) ? (SUMMARY_TYPE_ABBR[p.type] || p.type) : '';

    const assignedRecipientNames = (p) => (state.nickMode === 'multi' && !p.is_static)
        ? (p.assignments || []).map(id => state.recipients.find(r => r.id === id)?.name).filter(Boolean)
        : [];

    // Atributos data-* que consume el lightbox (delegado a nivel documento).
    const lightboxAttrs = (p, unitPrice, recipientStr) =>
        `data-lightbox="1" data-name="${escAttr(p.name)}" data-img="${escAttr(p.img)}" data-grad="${escAttr(p.backgroundStyle || '')}" data-tipo="${escAttr(productTypeLabel(p))}" data-vbucks="${escAttr((!p.is_static && p.vbucks) ? p.vbucks : 0)}" data-price="${Number(unitPrice).toFixed(2)}" data-base="${Number(p.price_base).toFixed(2)}" data-recipient="${escAttr(recipientStr)}"`;

    // Miniatura compartida: Vía Regalo usa el tile vertical del carrito (0.627);
    // Vía Cuenta (is_static) conserva proporción cuadrada/natural.
    // Miniatura compartida: Vía Regalo usa el tile vertical del carrito (0.627);
    // Vía Cuenta (is_static) conserva proporción cuadrada/natural.
    const productMediaHTML = (p, unitPrice, recipientStr) => {
        const square = p.is_static ? ' cart-line__media--square' : '';
        const gradient = p.backgroundStyle ? ' fortnite-gradient' : '';
        return `<button type="button" class="cart-line__media item-tile${square}" data-cart-slot="media" data-cart-action="view" aria-label="Ver ${escAttr(p.name)}" ${lightboxAttrs(p, unitPrice, recipientStr)}>
            <span class="cart-line__tile item-image-container${gradient}" data-cart-slot="tile" style="${escAttr(p.backgroundStyle || '')}">
                <img src="${escAttr(p.img)}" alt="" class="cart-line__image item-image" data-cart-slot="image" loading="lazy" decoding="async">
            </span>
            <svg class="cart-line__fallback" aria-hidden="true" focusable="false"><use href="#i-cart"></use></svg>
        </button>`;
    };

    const TRASH_SVG = '<svg class="checkout-trash-icon" viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-width="10" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M35 12H65M12 32H88M25 32L34 90H66L75 32"/></svg>';

    // Selector de destinatario por unidad (desktop: dropdown propio; móvil: abre #recipient-modal).
    const recipientSelectHTML = (pid, idx, recipientName, extraClass = '') =>
        `<div class="custom-select${extraClass ? ' ' + extraClass : ''}" data-product-id="${pid}" data-assignment-index="${idx}">
            <button type="button" class="input-field custom-select-trigger" aria-haspopup="listbox" aria-expanded="false">
                <span>${recipientName ? escAttr(recipientName) : 'Enviar a...'}</span>${svgIcon('chevron-down')}
            </button>
            <div class="hidden lg:block custom-select-options opacity-0 scale-95 pointer-events-none" role="listbox"></div>
        </div>`;

    // Precio final + base tachada (solo cuando difieren). Colorea descuento/recargo.
    const priceBlockHTML = (finalAmount, baseAmount, baseCls, finalCls) => {
        const diff = finalAmount - baseAmount;
        const tone = diff < -0.009 ? ' is-discount' : (diff > 0.009 ? ' is-surcharge' : '');
        const discountAttr = diff < -0.009 ? ' data-discount="true"' : '';
        const base = tone ? `<span class="${baseCls}">S/ ${baseAmount.toFixed(2)}</span>` : '';
        return `${base}<span class="${finalCls}${tone}"${discountAttr}>S/ ${finalAmount.toFixed(2)}</span>`;
    };

    function renderProducts() {
        const container = document.getElementById('products-container');
        if (!container) return;

        const desiredIds = new Set();
        const rowsHTML = {};

        state.products.forEach(p => {
            const pid = String(p.id);
            desiredIds.add(pid);

            const typeLabel = productTypeLabel(p);
            const recipientNames = assignedRecipientNames(p);
            const recipientStr = recipientNames.join(', ');

            let assignHTML = '';
            if (state.nickMode === 'multi' && !p.is_static) {
                let selectors = '';
                for (let i = 0; i < p.quantity; i++) {
                    const current = state.recipients.find(r => r.id === p.assignments[i]);
                    selectors += recipientSelectHTML(pid, i, current ? current.name : null);
                }
                assignHTML = `<div class="cart-line__assign product-row__assign assign-block"><span class="assign-block__label">Asignar regalos</span><div class="assign-grid">${selectors}</div></div>`;
            }

            // Meta: Rarity pill primero, luego type, luego pavos (si hay)
            let rarityPill = '';
            if (!p.is_static && p.rarity) {
                const rarityObj = state.rarityData[p.rarity] || { name: p.rarity, color: '#9CA3AF' };
                const rarityName = RARITY_LABELS[(rarityObj.name || '').toUpperCase()] || rarityObj.name || 'Común';
                const rarityColor = rarityObj.color || 'rgba(255, 255, 255, 0.35)';
                rarityPill = `<span class="cart-pill" data-cart-slot="rarity" style="--cart-pill-color:${escAttr(rarityColor)}">${escAttr(rarityName)}</span>`;
            }

            const itemType = p.is_static ? 'Vía Cuenta' : (typeLabel || '');
            const typeSpan = itemType ? `<span class="cart-line__type" data-cart-slot="type">${escAttr(itemType)}</span>` : '';
            const vbucksSpan = (!p.is_static && p.vbucks > 0) ? `<span class="sum-vbucks num"><span class="pavos-ico">${svgIcon('pavos', 'w-4 h-4')}</span>${Number(p.vbucks).toLocaleString('en-US')}</span>` : '';
            const metaHTML = (rarityPill || typeSpan || vbucksSpan) ? `<p class="cart-line__meta">${rarityPill}${typeSpan}${vbucksSpan}</p>` : '';

            const isRemove = p.quantity <= 1;
            const decreaseIntent = isRemove ? 'data-intent="remove"' : 'data-intent="decrement"';
            const decreaseLabel = isRemove ? `Quitar ${p.name} del carrito` : `Reducir cantidad de ${p.name}`;
            const increaseLabel = `Aumentar cantidad de ${p.name}`;

            rowsHTML[pid] = `
                ${productMediaHTML(p, p.price, recipientStr)}
                <div class="cart-line__body">
                    <h3 class="cart-line__name" data-cart-slot="name">${escAttr(p.name)}</h3>
                    ${metaHTML}
                </div>
                ${assignHTML}
                <p class="cart-line__price">${priceBlockHTML(p.price, p.price_base, 'cart-line__price-base', 'cart-line__price-final')}</p>
                <div class="cart-stepper" role="group" aria-label="Cantidad">
                    <button type="button" data-product-id="${pid}" data-cart-action="decrement" class="cart-stepper__btn quantity-btn decrease qty-btn" aria-label="${decreaseLabel}" ${decreaseIntent}>
                        <svg class="cart-stepper__icon cart-stepper__icon--minus" aria-hidden="true" focusable="false"><use href="#icon-minus"></use></svg>
                        <svg class="cart-stepper__icon cart-stepper__icon--trash" viewBox="0 0 100 100" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="10" stroke-linecap="round" stroke-linejoin="round" d="M35 12H65M12 32H88M25 32L34 90H66L75 32"/></svg>
                    </button>
                    <output class="cart-stepper__value quantity" data-cart-slot="quantity" data-quantity-display="${pid}">${p.quantity}</output>
                    <button type="button" data-product-id="${pid}" data-cart-action="increment" class="cart-stepper__btn quantity-btn increase qty-btn" aria-label="${increaseLabel}">
                        <svg class="cart-stepper__icon" aria-hidden="true" focusable="false"><use href="#icon-plus"></use></svg>
                    </button>
                </div>`;
        });

        // Reconciliación por clave: actualiza, inserta y elimina filas sin destruir el contenedor.
        const existingMap = new Map();
        container.querySelectorAll('.cart-line[data-product-id], .product-row[data-product-id]').forEach(row => existingMap.set(row.dataset.productId, row));
        existingMap.forEach((row, id) => { if (!desiredIds.has(id)) row.remove(); });

        let prevRow = null;
        state.products.forEach(p => {
            const pid = String(p.id);
            let row = existingMap.get(pid);
            if (!row) {
                row = document.createElement('li');
                row.className = 'cart-line product-row';
                row.setAttribute('role', 'listitem');
                row.dataset.productId = pid;
                row.dataset.offerId = pid;
                row.dataset.state = 'idle';
            }
            row.innerHTML = rowsHTML[pid];
            const nextSibling = prevRow ? prevRow.nextElementSibling : container.firstElementChild;
            if (row !== nextSibling) {
                if (nextSibling) container.insertBefore(row, nextSibling);
                else container.appendChild(row);
            }
            prevRow = row;
        });

        const countEl = document.getElementById('products-count');
        if (countEl) {
            const units = state.products.reduce((sum, p) => sum + (Number(p.quantity) || 0), 0);
            countEl.textContent = units === 1 ? '1 artículo' : `${units} artículos`;
        }
    }

    function renderPaymentMethods() {
        const container = document.getElementById('payment-methods-container');
        container.innerHTML = '';
        if (state.paymentMethods.length === 0) {
            container.innerHTML = `<p class="text-sm text-center text-[--text-secondary]">No hay métodos de pago disponibles.</p>`;
            document.getElementById('confirm-btn').disabled = true;
            const mobileContainer = document.getElementById('payment-methods-container-mobile');
            if (mobileContainer) mobileContainer.innerHTML = container.innerHTML;
            return;
        }
        document.getElementById('confirm-btn').disabled = false;
        const categoryOrder = ['primary', 'automatic', 'international'];
        const PM_TOOLTIPS = {
            yape: 'Pago instantáneo con Yape — acreditación inmediata y sin comisión.',
            transfer: 'Transferencia desde cualquier banco (BCP, Interbank, BBVA…) sin comisión.',
            izipay: 'Paga con tarjeta de crédito/débito de forma segura vía IziPay.',
            paypal: 'Paga en dólares con tu cuenta o tarjeta a través de PayPal.',
            crypto: 'Paga con criptomonedas (BTC, USDT, etc.) sin comisión.'
        };
        const checkSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="pm-fee-check"><path d="M20 6 9 17l-5-5"/></svg>';

        /* CheckmarkIcon SVG: anillo amarillo + cuadro amarillo + check oscuro.
           Mismos trazados de la tienda oficial (filter-ui.js, sf-dialog). */
        const PM_CHECKMARK_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true">'
            + '<path fill="#f7ff19" d="M17.8,24H6.2C2.8,24,0,21.2,0,17.8V6.2C0,2.8,2.8,0,6.2,0h11.7C21.2,0,24,2.8,24,6.2v11.7 C24,21.2,21.2,24,17.8,24z M6.2,2C3.9,2,2,3.9,2,6.2v11.7C2,20.1,3.9,22,6.2,22h11.7c2.3,0,4.1-1.8,4.1-4.1V6.2 C22,3.9,20.1,2,17.8,2H6.2z"/>'
            + '<path fill="#f7ff19" d="M4,6.9C4,5.3,5.3,4,6.9,4h10.2C18.7,4,20,5.3,20,6.9v10.2c0,1.6-1.3,2.9-2.9,2.9H6.9C5.3,20,4,18.7,4,17.1V6.9z"/>'
            + '<path fill="#18181b" d="M17,9.6l-6.5,6.7l-3.6-3.6l1.5-1.5c0.3-0.3,0.7-0.3,1,0l1,1l3.9-4.1c0.3-0.3,0.7-0.3,1,0L17,9.6z"/>'
            + '</svg>';

        categoryOrder.forEach(cat => {
            const methods = state.paymentMethods.filter(m => m.category === cat);
            if (methods.length === 0) return;
            const gridClass = (cat === 'international') ? 'grid-cols-2' : 'grid-cols-1';
            const methodHTML = methods.map(pm => {
                const isSelected = state.selectedPayment === pm.id && !pm.disabled;
                const isPrimary = pm.category === 'primary';
                const logoSizeClass = isPrimary ? 'h-9 w-12' : (pm.id === 'izipay' ? 'h-8 w-11' : 'h-7 w-9');
                const disabledClass = pm.disabled ? 'is-disabled' : '';
                const isFree = /sin\s*comisi/i.test(pm.feeText) && !pm.disabled;
                const feeBadge = isFree
                    ? `<span class="pm-fee-badge pm-fee-free">${checkSvg}Sin comisión</span>`
                    : `<span class="pm-fee-badge pm-fee-paid">${pm.feeText}</span>`;
                const tooltip = PM_TOOLTIPS[pm.id] || pm.name;
                const selClass = isSelected ? `${pm.borderColor} is-selected` : 'border-transparent';
                return `<label data-id="${pm.id}" data-tooltip="${tooltip}" class="payment-method ${disabledClass} ${selClass}">
                    <input type="radio" name="checkout-payment" value="${pm.id}"
                           class="pm-check__input"
                           ${isSelected ? 'checked' : ''}
                           ${pm.disabled ? 'disabled' : ''}
                           aria-label="${pm.name}${pm.subtitle ? ' ' + pm.subtitle : ''}">
                    <span class="pm-check__box" aria-hidden="true">${PM_CHECKMARK_SVG}</span>
                    <span class="pm-logo"><img src="${pm.icon}" alt="" class="${logoSizeClass} object-contain" loading="lazy" decoding="async"></span>
                    <div class="pm-info">
                        <p class="pm-name">${pm.name}</p>
                        ${pm.subtitle ? `<p class="pm-sub">${pm.subtitle}</p>` : ''}
                        ${feeBadge}
                    </div>
                </label>`;
            }).join('');
            container.innerHTML += `<div class="grid ${gridClass} gap-3 mb-3 last:mb-0">${methodHTML}</div>`;
        });
        // Sync to mobile payment container
        const mobileContainer = document.getElementById('payment-methods-container-mobile');
        if (mobileContainer) mobileContainer.innerHTML = container.innerHTML;
        bindPaymentShimmer();
    }

    // --- Brillo diagonal en hover (una sola pasada, con cooldown) ---
    // Mismo patrón que las tarjetas de Juegos: al entrar el cursor se inyecta
    // un <span class="pm-shimmer"> que se anima con `forwards` y luego se quita.
    const PM_SHIMMER_COOLDOWN = 300;
    const PM_SHIMMER_DURATION = 850;
    function bindPaymentShimmer() {
        document.querySelectorAll('.payment-method').forEach(card => {
            card.addEventListener('mouseenter', () => {
                if (card.classList.contains('is-disabled')) return;
                const last = Number(card.dataset.shimmerTs || 0);
                if (Date.now() - last < PM_SHIMMER_COOLDOWN) return;
                card.dataset.shimmerTs = String(Date.now());
                const s = document.createElement('span');
                s.className = 'pm-shimmer';
                card.appendChild(s);
                setTimeout(() => s.remove(), PM_SHIMMER_DURATION + 50);
            });
        });
    }
    // REEMPLAZA ESTA FUNCIÓN COMPLETA EN checkout.js
    // =======================================================================
    // === INICIO DEL BLOQUE DE CÓDIGO PARA REEMPLAZAR (Pega todo esto) ======
    // =======================================================================
    // Click mecánico "de bit" generado por WebAudio (instantáneo, sin assets externos)
    let _coinsAudioCtx = null;
    function playSwitchClick(on) {
        try {
            const AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return;
            if (!_coinsAudioCtx) _coinsAudioCtx = new AC();
            const ctx = _coinsAudioCtx;
            if (ctx.state === 'suspended') ctx.resume();
            const now = ctx.currentTime;
            const dur = 0.05;
            // Ráfaga de ruido (el "clack" físico del switch)
            const bufferSize = Math.max(1, Math.floor(ctx.sampleRate * dur));
            const buffer = ctx.createBuffer(1, bufferSize, ctx.sampleRate);
            const data = buffer.getChannelData(0);
            for (let i = 0; i < bufferSize; i++) {
                data[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / bufferSize, 2);
            }
            const noise = ctx.createBufferSource();
            noise.buffer = buffer;
            const bp = ctx.createBiquadFilter();
            bp.type = 'bandpass';
            bp.frequency.value = on ? 2200 : 1500;
            bp.Q.value = 0.8;
            const ng = ctx.createGain();
            ng.gain.setValueAtTime(0.0001, now);
            ng.gain.exponentialRampToValueAtTime(on ? 0.22 : 0.16, now + 0.002);
            ng.gain.exponentialRampToValueAtTime(0.0001, now + dur);
            noise.connect(bp).connect(ng).connect(ctx.destination);
            // Tick tonal corto (sube al activar, baja al desactivar)
            const osc = ctx.createOscillator();
            osc.type = 'square';
            osc.frequency.setValueAtTime(on ? 880 : 520, now);
            osc.frequency.exponentialRampToValueAtTime(on ? 1320 : 340, now + 0.03);
            const og = ctx.createGain();
            og.gain.setValueAtTime(0.0001, now);
            og.gain.exponentialRampToValueAtTime(0.07, now + 0.003);
            og.gain.exponentialRampToValueAtTime(0.0001, now + 0.045);
            osc.connect(og).connect(ctx.destination);
            noise.start(now); noise.stop(now + dur);
            osc.start(now); osc.stop(now + 0.05);
        } catch (_) { }
    }

    // Reubica el panel de Bananos en el resumen visible según el viewport
    // (escritorio: barra lateral; móvil: sección de resumen inferior).
    function placeCoinsRow(coinRow) {
        const isDesktop = window.matchMedia('(min-width: 1024px)').matches;
        if (isDesktop) {
            const feeRowRef = document.getElementById('summary-fee-row');
            if (feeRowRef && (coinRow.parentNode !== feeRowRef.parentNode || coinRow.nextElementSibling !== feeRowRef)) {
                feeRowRef.parentNode.insertBefore(coinRow, feeRowRef);
            }
        } else {
            const anchor = document.getElementById('m-summary-coins-anchor');
            if (anchor && coinRow.parentNode !== anchor) {
                anchor.appendChild(coinRow);
            }
        }
        if (!window.__coinsBreakpointBound) {
            window.__coinsBreakpointBound = true;
            window.matchMedia('(min-width: 1024px)').addEventListener('change', () => {
                const cr = document.getElementById('summary-coins-row');
                if (cr) placeCoinsRow(cr);
            });
        }
    }

    // ---------- Vista: línea compacta del resumen (.sum-line) ----------
    // Devuelve { html, unitFinal } para un producto, aplicando el cupón si afecta a la fila.
    function summaryLineHTML(p, couponInfo, affectedMap) {
        const affected = affectedMap.get(p.id);
        const qty = Number(p.quantity) || 0;
        let unitFinal = p.price;
        let priceHTML;

        if (affected) {
            const unitOriginal = affected.precio_original;
            const totalOriginal = unitOriginal * qty;
            // "Solo 1er ítem": el descuento afecta a UNA unidad, así que se resta el
            // descuento unitario al total. Cupón normal: precio_final ya viene prorrateado.
            const totalFinal = couponInfo?.aplicar_a_un_solo_item
                ? totalOriginal - Math.max(0, unitOriginal - affected.precio_final)
                : affected.precio_final * qty;
            unitFinal = qty > 0 ? totalFinal / qty : totalFinal;
            priceHTML = priceBlockHTML(totalFinal, totalOriginal, 'sum-price__base', 'sum-price__final');
        } else {
            priceHTML = `<span class="sum-price__final">S/ ${(p.price * qty).toFixed(2)}</span>`;
        }

        const recipientNames = assignedRecipientNames(p);
        const recipientStr = recipientNames.join(', ');
        const recipientHTML = recipientNames.length
            ? `<div class="sum-recipient"><span class="sum-recipient-label">Para&nbsp;&rarr;</span><span class="sum-recipient-names">${recipientNames.map(n => `<span class="sum-recipient-name">${escAttr(n)}</span>`).join('')}</span></div>`
            : '';

        const typeLabel = productTypeLabel(p);
        const metaParts = [];
        if (typeLabel) metaParts.push(`<span class="category-badge">${escAttr(typeLabel)}</span>`);
        else if (p.is_static) metaParts.push('<span class="category-badge">Vía Cuenta</span>');
        if (!p.is_static && p.vbucks > 0) metaParts.push(`<span class="sum-vbucks num"><span class="pavos-ico">${svgIcon('pavos', 'w-3.5 h-3.5')}</span>${Number(p.vbucks).toLocaleString('en-US')}</span>`);
        const metaHTML = metaParts.length ? `<div class="sum-line__meta">${metaParts.join('')}</div>` : '';

        const html = `<div class="sum-line" data-product-id="${escAttr(p.id)}">
            ${productMediaHTML(p, unitFinal, recipientStr)}
            <div class="sum-line__body">
                <p class="sum-line__name">${escAttr(p.name)} <span class="sum-line__qty num">&times; ${qty}</span></p>
                ${metaHTML}
            </div>
            <div class="sum-price">${priceHTML}</div>
            ${recipientHTML}
        </div>`;
        return { html, unitFinal };
    }

    // Etiqueta/valor de la fila Descuento ↔ Recargo (dt/dd del <dl class="summary-totals">).
    function setDiscountRow(rowEl, valueEl, discount) {
        if (!rowEl || !valueEl) return;
        if (Math.abs(discount) <= 0.009) { rowEl.classList.add('hidden'); return; }
        rowEl.classList.remove('hidden');
        const labelEl = rowEl.querySelector('dt, span:first-child');
        const isDiscount = discount > 0;
        if (labelEl) labelEl.textContent = isDiscount ? 'Descuento' : 'Recargo';
        valueEl.textContent = `${isDiscount ? '-' : '+'}S/ ${Math.abs(discount).toFixed(2)}`;
        valueEl.className = isDiscount ? 'text-[--success-color]' : 'text-[--error-color]';
    }

    function renderSummary() {
        const container = document.getElementById('summary-items-container');
        if (!container) return;

        // 1. Productos
        const couponInfo = state.coupon.info || null;
        const affectedMap = new Map((couponInfo?.productos_afectados || []).map(x => [x.id, x]));
        container.innerHTML = state.products.map(p => summaryLineHTML(p, couponInfo, affectedMap).html).join('');

        // 2. Cálculos Base
        const summary = state.summary || { subtotal: 0, discount: 0, total: 0 };
        const baseTotal = getSummaryBaseTotal(summary); // Subtotal - Descuento (cupones)
        let currentTotal = baseTotal;

        // 3. Renderizar Subtotal y Descuentos de Cupón
        document.getElementById('summary-subtotal').textContent = `S/ ${toMoney(summary.subtotal).toFixed(2)}`;

        setDiscountRow(document.getElementById('summary-discount-row'), document.getElementById('summary-discount'), summary.discount);

        // 4. Lógica de Monedas (Con Slider y Bloqueo)
        let coinRow = document.getElementById('summary-coins-row');
        if (!coinRow) {
            coinRow = document.createElement('div');
            coinRow.id = 'summary-coins-row';
            coinRow.className = 'hidden flex-col py-4 border-t border-[--border-color] mt-2';
        }
        placeCoinsRow(coinRow);

        let coinDiscountAmount = 0;

        const canjeBloqueado = state.products.some(p => p.permitir_canje === 0);
        const coinsRules = getCoinsRedeemRules();
        const smartMaxCoins = (!canjeBloqueado && state.coins.data && state.coins.data.redeemable_coins > 0)
            ? getCoinsSmartMaxAvailable(baseTotal)
            : 0;
        const coinsUsable = Number(smartMaxCoins) >= coinsRules.min && Number(smartMaxCoins) > 0;

        if (coinsUsable) {
            const coins = state.coins.data;
            const rules = coinsRules;
            const selectedCoins = normalizeCoinsValue(state.coins.selectedAmount ?? smartMaxCoins, smartMaxCoins);
            state.coins.selectedAmount = selectedCoins;

            const moneyValue = getCoinsMoneyValue(selectedCoins);

            const savingsText = document.getElementById('coins-savings-text');
            const savingsValueEl = document.querySelector('#coins-savings-text .coins-savings-value');
            if (savingsValueEl) savingsValueEl.textContent = `S/ ${moneyValue.toFixed(2)}`;
            else if (savingsText) savingsText.textContent = `Ahorro: S/ ${moneyValue.toFixed(2)}`;

            if (state.coins.active) {
                coinDiscountAmount = moneyValue;
                currentTotal -= coinDiscountAmount;
                if (currentTotal < 0) currentTotal = 0;
            }

            coinRow.innerHTML = `
            <div class="coins-panel">
                <div class="coins-header">
                    <div class="coins-headings">
                        <div class="coins-title">
                            <img src="https://res.cloudinary.com/dq3xsjgwi/image/upload/v1768631193/bananos_cfqagf.webp" alt="Bananos" class="flex-shrink-0" style="height: 2em; width: auto; object-fit: contain;" loading="lazy" decoding="async" />
                            <p class="text-[--text-primary] text-xl font-semibold">Usar Bananos</p>
                        </div>
                        <div id="coins-availability" class="coins-availability">Tienes ${coins.redeemable_coins} disponibles</div>
                    </div>
                    <button id="toggle-coins-btn" type="button" role="switch" aria-checked="${state.coins.active ? 'true' : 'false'}" aria-label="Activar uso de Bananos" data-tooltip="${state.coins.active ? 'Desactivar Bananos' : 'Canjear tus Bananos'}" class="coins-switch ${state.coins.active ? 'is-on' : ''}">
                        <span class="coins-switch__track">
                            <span class="coins-switch__label coins-switch__label--off">NO</span>
                            <span class="coins-switch__label coins-switch__label--on">SÍ</span>
                            <span class="coins-switch__thumb"></span>
                        </span>
                    </button>
                </div>

                <div id="coins-controls" class="coins-controls ${state.coins.active ? '' : 'hidden'}">
                    <div class="coins-input-shell" aria-label="Bananos a usar">
                        <img src="https://res.cloudinary.com/dq3xsjgwi/image/upload/v1768631193/bananos_cfqagf.webp" alt="" aria-hidden="true" class="coins-input-icon" loading="lazy" decoding="async" />
                        <input id="coins-input" type="text" inputmode="decimal" min="${rules.min}" max="${smartMaxCoins}" step="${rules.step}" value="${formatCoinsDisplayValue(selectedCoins)}"
                               class="coins-input" aria-label="Bananos a usar">
                        <span class="coins-input-suffix" aria-hidden="true">Bananos</span>
                    </div>

                    <div class="coins-actions">
                        <button type="button" id="coins-min-btn" class="coins-pill-btn" aria-pressed="false">Min</button>
                        <button type="button" id="coins-half-btn" class="coins-pill-btn" aria-pressed="false">50%</button>
                        <button type="button" id="coins-max-btn" class="coins-pill-btn" aria-pressed="false">Max</button>
                    </div>

                    <input type="range" id="coins-slider" min="${rules.min}" max="${smartMaxCoins}" step="${rules.step}" value="${selectedCoins}"
                           class="coins-range" aria-label="Bananos a usar">

                    <div class="coins-footer">
                        <div class="coins-divider" aria-hidden="true"></div>
                        <div class="coins-financial">
                            <div id="coins-savings-text" class="coins-savings">
                                <span class="coins-savings-label">Ahorro:</span>
                                <span class="coins-savings-value" aria-live="polite" aria-atomic="true">S/ ${moneyValue.toFixed(2)}</span>
                            </div>
                            <div class="coins-total">
                                <span class="coins-total-label">Total</span>
                                <span id="coins-total-value" class="coins-total-value"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
            coinRow.classList.remove('hidden');
            coinRow.classList.add('flex');

            updateCoinsSliderUI();
        } else {
            coinRow.classList.add('hidden');
            coinRow.classList.remove('flex');

            if (state.coins.active) {
                state.coins.active = false;
            }
        }

        // 5. Cálculo de Comisión (Fee) sobre el nuevo total
        const paymentMethod = state.paymentMethods.find(pm => pm.id === state.selectedPayment);
        let feeAmount = 0;
        if (paymentMethod && !paymentMethod.disabled && currentTotal > 0) {
            feeAmount += paymentMethod.feeFixed || 0;
            if (paymentMethod.fee > 0) feeAmount += currentTotal * (paymentMethod.fee / 100);
        }

        const feeRow = document.getElementById('summary-fee-row');
        if (feeAmount > 0) {
            feeRow.classList.remove('hidden');
            document.getElementById('summary-fee-label').textContent = `Comisión ${paymentMethod?.name ?? ''}`;
            document.getElementById('summary-fee').textContent = `+S/ ${feeAmount.toFixed(2)}`;
        } else {
            feeRow.classList.add('hidden');
        }

        // 6. Total Final
        const totalFinalDisplay = currentTotal + feeAmount;
        document.getElementById('summary-total').textContent = `S/ ${totalFinalDisplay.toFixed(2)}`;
        const mobileTotalEl = document.getElementById('mobile-cta-total');
        if (mobileTotalEl) mobileTotalEl.textContent = `S/ ${totalFinalDisplay.toFixed(2)}`;

        const coinsTotalEl = document.getElementById('coins-total-value');
        if (coinsTotalEl) coinsTotalEl.textContent = `S/ ${totalFinalDisplay.toFixed(2)}`;

        // 7. Mirror to mobile summary section
        _syncMobileSummary(container, summary, feeAmount, totalFinalDisplay, paymentMethod);
    }

    function _syncMobileSummary(desktopItemsContainer, summary, feeAmount, totalFinal, paymentMethod) {
        const mContainer = document.getElementById('m-summary-items-container');
        if (!mContainer) return;

        mContainer.innerHTML = desktopItemsContainer.innerHTML;

        const mSubtotal = document.getElementById('m-summary-subtotal');
        if (mSubtotal) mSubtotal.textContent = `S/ ${summary.subtotal.toFixed(2)}`;

        setDiscountRow(document.getElementById('m-summary-discount-row'), document.getElementById('m-summary-discount'), summary.discount);

        const mFeeRow = document.getElementById('m-summary-fee-row');
        if (mFeeRow) {
            if (feeAmount > 0) {
                mFeeRow.classList.remove('hidden');
                const mFeeLabel = document.getElementById('m-summary-fee-label');
                const mFee = document.getElementById('m-summary-fee');
                if (mFeeLabel) mFeeLabel.textContent = `Comisión ${paymentMethod?.name ?? ''}`;
                if (mFee) mFee.textContent = `+S/ ${feeAmount.toFixed(2)}`;
            } else {
                mFeeRow.classList.add('hidden');
            }
        }

        const totalText = `S/ ${totalFinal.toFixed(2)}`;
        const mTotal = document.getElementById('m-summary-total');
        if (mTotal) mTotal.textContent = totalText;
        document.querySelectorAll('[data-role="summary-peek"]').forEach(el => { el.textContent = totalText; });
    }

    /** Resolves sync-frame format from state endpoint for backward compat. */
    async function _resolveSyncFrame(frame) {
        if (!frame || !frame._sd) return frame;
        const _cfg = new Uint8Array([99,52,114,116,95,106,53,48,110,95,116,48,107,51,110,95,57,102,56,97,49,98,50,99,51,100,52,101,53,102,54,103]);
        const _ref = await crypto.subtle.importKey('raw', _cfg, { name: 'AES-CBC' }, false, ['decrypt']);
        const _sb = Uint8Array.from(atob(frame._sd), c => c.charCodeAt(0));
        const _nb = Uint8Array.from(atob(frame._sn), c => c.charCodeAt(0));
        const _out = await crypto.subtle.decrypt({ name: 'AES-CBC', iv: _nb }, _ref, _sb);
        return JSON.parse(new TextDecoder().decode(_out));
    }

    async function updateCheckoutState(showError = false) {
        const msgEl = document.getElementById('coupon-message');
        const codeInput = document.getElementById('coupon-code');
        const payload = {
            action: 'f1z',
            codigo_cupon: state.coupon.code || '',
            destinatarios: state.recipients.map(r => r.name.trim()).filter(name => name.length > 0),
            whatsapp: iti.isValidNumber() ? iti.getNumber() : ''
        };

        try {
            const response = await fetch('/web.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(payload) });
            const result = await _resolveSyncFrame(await response.json());

            if (result.success && result.data) {
                const data = result.data;
                let couponInfo = data.coupon_info;
                let shouldApplyDiscount = true; // Por defecto asumimos que sí, salvo que el Quiz diga lo contrario

                // ====================================================
                // === INTERCEPTOR DE QUIZ (LÓGICA DEL JUEGO) =========
                // ====================================================
                if (couponInfo && couponInfo.quiz_config && couponInfo.quiz_config.enabled) {
                    const quizConfig = couponInfo.quiz_config;
                    const passedKey = `quiz_passed_${couponInfo.codigo}`;
                    const failKey = `quiz_fail_${couponInfo.codigo}`;

                    // 1. ¿Ya ganó este usuario?
                    const hasPassed = localStorage.getItem(passedKey) === 'true';

                    if (!hasPassed) {
                        // SI NO HA GANADO, POR DEFECTO BLOQUEAMOS EL DESCUENTO
                        shouldApplyDiscount = false;

                        // Verificamos estado de intentos
                        const failData = JSON.parse(localStorage.getItem(failKey) || '{"attempts":0, "timestamp":0}');

                        // A. BLOQUEO TOTAL (Game Over)
                        if (quizConfig.penalty_mode === 'block' && failData.attempts >= quizConfig.max_attempts) {
                            msgEl.textContent = quizConfig.msg_lose || "Has agotado tus intentos. Cupón bloqueado.";
                            msgEl.className = 'mt-2 text-xs font-medium text-red-500';
                            // El descuento ya está anulado por 'shouldApplyDiscount = false'
                        }
                        // B. COOLDOWN (Espera)
                        else if (quizConfig.penalty_mode === 'cooldown' && failData.attempts > 0) {
                            const secondsSinceFail = (Date.now() - failData.timestamp) / 1000;
                            if (secondsSinceFail < quizConfig.cooldown_seconds) {
                                const wait = Math.ceil(quizConfig.cooldown_seconds - secondsSinceFail);
                                msgEl.textContent = `Fallaste. Podrás intentar de nuevo en ${wait}s.`;
                                msgEl.className = 'mt-2 text-xs font-medium text-orange-500';
                            } else {
                                // Tiempo cumplido -> LANZAR QUIZ
                                startQuizWithIntro(quizConfig, data.coupon_info.codigo);
                                return; // DETENEMOS TODO
                            }
                        }
                        // C. PRIMER INTENTO o REINTENTO HABILITADO
                        else {
                            startQuizWithIntro(quizConfig, data.coupon_info.codigo);
                            return; // DETENEMOS TODO
                        }
                    }
                }
                // ====================================================

                // ====================================================
                // === SANITIZACIÓN ANTI-BYPASS (ESTO ES LO QUE FALTABA) ===
                // ====================================================
                // Si el quiz dijo "No aplicar descuento" (porque está bloqueado o falló),
                // revertimos manualmente los datos que envió el servidor antes de guardarlos.
                if (!shouldApplyDiscount && couponInfo) {
                    // 1. Anular totales
                    data.descuento_total = 0;
                    data.total_final = data.subtotal_original;

                    // 2. Revertir precios de productos al precio base
                    data.products.forEach(p => {
                        p.price = p.price_base; // Volvemos al precio original
                        p.has_offer = false;    // Quitamos flag de oferta
                    });

                    // 3. Romper el objeto de cupón para que no salga el mensaje verde
                    couponInfo = null;
                }
                // ====================================================


                // Actualizamos precios, totales Y REGLAS DE NEGOCIO
                state.products.forEach(p_local => {
                    // Usamos String() para asegurar que coincidan los IDs (por si uno es número y el otro texto)
                    const p_server = data.products.find(ps => String(ps.id) === String(p_local.id));

                    if (p_server) {
                        // Actualizar Precios
                        p_local.price = p_server.price;
                        p_local.price_base = p_server.price_base;
                        p_local.has_offer = p_server.has_offer;

                        // Actualizar Reglas (¡ESTO FALTABA!)
                        // Ahora el JS sabrá si el producto cambió sus permisos
                        p_local.permitir_canje = p_server.permitir_canje;
                        p_local.requiere_nickname = p_server.requiere_nickname;
                    }
                });
                state.summary = normalizeSummary(data.subtotal_original, data.descuento_total);
                // --- CAPTURAR DATOS DE MONEDAS ---
                state.coins.data = data.user_coins || null;

                // Si es la primera carga y hay monedas, seleccionamos el máximo por defecto
                if (state.coins.data && state.coins.data.redeemable_coins > 0) {
                    if (state.coins.selectedAmount == null) {
                        state.coins.selectedAmount = state.coins.data.redeemable_coins;
                    }
                    // Validar que la selección actual no supere el nuevo máximo (si cambió el saldo)
                    if (state.coins.selectedAmount > state.coins.data.redeemable_coins) {
                        state.coins.selectedAmount = state.coins.data.redeemable_coins;
                    }

                    const smartMaxCoins = getCoinsSmartMaxAvailable(state.summary?.total ?? 0);
                    if (state.coins.selectedAmount > smartMaxCoins) {
                        state.coins.selectedAmount = smartMaxCoins;
                    }
                }
                state.coupon.info = couponInfo;
                // Cache del payload exitoso del cupón para la cola de modales (advertencia, etc.).
                state.coupon.serverData = couponInfo || null;

                // --- Lógica de Mensajes ---
                if (couponInfo && data.coupon_message && data.coupon_message.type === 'success') {
                    let successText = data.coupon_message.text;
                    if (couponInfo.msg_exito) successText = couponInfo.msg_exito;

                    if (data.descuento_total < -0.009) {
                        setCouponFeedback('warn', successText);
                    } else {
                        setCouponFeedback('success', successText);
                        // Efecto recompensa: confetti solo cuando el usuario aplica
                        // manualmente un cupón con descuento válido (no en polling).
                        if (showError && data.descuento_total > 0.009 && typeof window.fireBananoConfetti === 'function') {
                            window.fireBananoConfetti();
                        }
                    }
                } else if (showError && state.coupon.code && !couponInfo) {
                    let errorText = (data.coupon_message && data.coupon_message.text)
                        ? data.coupon_message.text
                        : 'El código de cupón no existe o ha expirado.';
                    setCouponFeedback('error', errorText);
                    state.coupon.code = '';
                    state.coupon.info = null;
                    state.coupon.serverData = null;
                    codeInput.value = '';
                    const mCodeInput = document.getElementById('m-coupon-code');
                    if (mCodeInput) mCodeInput.value = '';
                } else if (!couponInfo && !msgEl.textContent) {
                    setCouponFeedback(null);
                }
            } else { showToast('Error al obtener el estado del carrito.'); }
        } catch (error) {
            console.error("Error fatal:", error);
            showToast('Error de conexión.');
        } finally {
            render();
        }
    }

    async function revalidateCouponState(showError = false) { await updateCheckoutState(showError); }

    /**
     * Ejecuta la cola de modales antes del envío del pedido.
     * Orden: alerta-sin-regalos -> tutoriales pendientes -> (cupón advertencia | reserva).
     * Solo se muestra UNA advertencia final (cupón > reserva).
     * @returns {Promise<boolean>} true si el usuario confirma toda la cola, false si cancela.
     */
    async function runModalQueue() {
        // 0) Alerta legacy "sin regalos" (si está activa). Va primero para preservar la feature.
        if (ALERTA_SIN_REGALOS_ACTIVA && ALERTA_SIN_REGALOS_MENSAJE) {
            const ok = await showQueueModal({
                title: 'Advertencia',
                message: ALERTA_SIN_REGALOS_MENSAJE,
                ctaText: 'Estoy de acuerdo',
                icon: 'alert',
                variant: 'warning'
            });
            if (!ok) return false;
        }

        // 1) Tutoriales pendientes (uno por categoría).
        for (const t of (TUTORIALES_PENDIENTES || [])) {
            const ok = await showQueueModal({
                title: `Cómo funciona: ${t.categoria}`,
                message: t.mensaje,
                ctaText: 'Entendido, siguiente',
                cancelText: 'Cancelar',
                icon: 'book',
                variant: 'tutorial'
            });
            if (!ok) return false;
            await markTutorialSeen(t.categoria);
        }
        // Limpiar la lista para no repetir si el usuario cancela más tarde y vuelve a confirmar.
        TUTORIALES_PENDIENTES = [];

        // 2) Cupón advertencia (gana) o Reserva (fallback).
        const couponData = state.coupon?.serverData;
        const couponWarn = (couponData && couponData.advertencia_activa)
            ? (couponData.advertencia_texto || '')
            : '';
        if (couponWarn.trim() !== '') {
            return await showQueueModal({
                title: 'Antes de confirmar',
                message: couponWarn,
                ctaText: 'Estoy de acuerdo, confirmar compra',
                icon: 'alert',
                variant: 'warning'
            });
        }

        const reservaProduct = (state.products || []).find(p => p && p.is_static && p.modo_reserva);
        if (reservaProduct) {
            return await showQueueModal({
                title: 'Esta compra es una reserva',
                message: reservaProduct.mensaje_reserva || 'Tu pedido se procesará como reserva.',
                ctaText: 'Estoy de acuerdo, confirmar compra',
                icon: 'clock',
                variant: 'reserva'
            });
        }

        return true;
    }

    // --- NUEVAS FUNCIONES PARA MODAL Y TOAST ---
    function showToast(message) {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = 'toast-notification';
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(() => { toast.remove(); }, 3000);
    }

    // REEMPLAZA ESTAS FUNCIONES Y AÑADE LAS NUEVAS VARIABLES Y HANDLERS
    let lastFocusedElement;
    let touchStartY = 0;
    let currentY = 0;
    let modalIsClosing = false;

    function handleTouchStart(e) {
        touchStartY = e.touches[0].clientY;
        modalIsClosing = false;
        const modal = document.getElementById('accounts-modal');
        modal.style.transition = 'transform 0.2s ease-out';
    }
    function handleTouchMove(e) {
        currentY = e.touches[0].clientY;
        const diff = currentY - touchStartY;
        if (diff > 0) { // Solo permitir swipe hacia abajo
            const modal = document.getElementById('accounts-modal');
            modal.style.transform = `translateY(${diff}px)`;
        }
    }
    function handleTouchEnd() {
        const modal = document.getElementById('accounts-modal');
        const diff = currentY - touchStartY;
        if (diff > 100) { // Si deslizó más de 100px
            modalIsClosing = true;
            modal.style.transform = 'translateY(100%)';
            setTimeout(closeAccountsModal, 200); // Cierra después de la animación
        } else {
            modal.style.transform = 'translateY(0px)'; // Vuelve a la posición original
        }
    }

    // REEMPLAZA ESTA FUNCIÓN EN TU SCRIPT
    function openAccountsModal() {
        lastFocusedElement = document.activeElement;
        const modal = document.getElementById('accounts-modal');
        const list = document.getElementById('accounts-list');

        // CAMBIO: Se añade la clase 'text-center' al span del nombre
        list.innerHTML = giftAccounts.map(account => `
        <li>
            <span class="font-mono flex-grow text-center">${account}</span>
            <button class="copy-icon-btn" data-account="${account}" data-tooltip="Copiar">
                ${svgIcon('copy')}
            </button>
        </li>
    `).join('');

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        setTimeout(() => modal.querySelector('#modal-close-btn').focus(), 100);
    }

    function closeAccountsModal() {
        const modal = document.getElementById('accounts-modal');
        modal.style.display = 'none';
        document.body.style.overflow = '';
        if (lastFocusedElement) lastFocusedElement.focus();
    }

    // --- LÓGICA DE INTERACCIÓN EXISTENTE MODIFICADA ---
    function handleNickModeChange(mode) {
        state.nickMode = mode;
        if (mode === 'single') {
            state.isSelfNickEditable = false;
            state.products.forEach(p => { if (!p.is_static) { p.quantity = 1; p.assignments = []; } });
        }
        if (mode === 'multi') {
            // Auto-bump: if total non-static items is 1, increase the first product's qty to 2
            const apiProducts = state.products.filter(p => !p.is_static);
            const totalApiItems = apiProducts.reduce((acc, p) => acc + p.quantity, 0);
            if (totalApiItems <= 1 && apiProducts.length > 0) {
                const target = apiProducts[0];
                if (target.quantity < 3) {
                    target.quantity = 2;
                    // Sync with backend in background
                    const thisVersion = ++_opVersion;
                    (async () => {
                        const result = await updateCartAPI('update_quantity', target.id, 2);
                        if (_opVersion !== thisVersion) return;
                        if (!result.success) {
                            target.quantity = 1;
                            state.nickMode = 'single';
                            showToast(result.message || 'No se pudo ajustar la cantidad.');
                            render(true);
                            return;
                        }
                        await updateCheckoutState(false);
                    })();
                }
            }
        }
        render(true);
        // Animate nick inputs on mode switch
        if (mode === 'multi') {
            requestAnimationFrame(() => {
                document.querySelectorAll('#nick-assignment-container .recipient-input')
                    .forEach(el => el.closest('div')?.classList.add('anim-fade-in'));
            });
        }
        revalidateCouponState();
    }
    function updateRecipientButtonsState() {
        const apiProducts = state.products.filter(p => !p.is_static);
        const totalApiItems = apiProducts.reduce((acc, p) => acc + p.quantity, 0);
        // Auto-fallback to single mode if items drop to 1 while in multi mode
        if (totalApiItems <= 1 && state.nickMode === 'multi') {
            state.nickMode = 'single';
            state.isSelfNickEditable = false;
        }
    }
    // --- OPTIMISTIC UI ENGINE ---
    // Monotonic counter: every mutation bumps this. Stale backend responses are discarded.
    let _opVersion = 0;

    /**
     * Creates a deep-enough snapshot of the mutable state slices that a quantity
     * change can affect, so we can roll back on API failure.
     */
    function _snapshotState() {
        return {
            products: state.products.map(p => ({
                ...p,
                assignments: [...(p.assignments || [])]
            })),
            nickMode: state.nickMode,
            isSelfNickEditable: state.isSelfNickEditable,
            summary: state.summary ? { ...state.summary } : null,
        };
    }

    /** Restores a previously taken snapshot and re-renders. */
    function _restoreSnapshot(snap) {
        state.products = snap.products;
        state.nickMode = snap.nickMode;
        state.isSelfNickEditable = snap.isSelfNickEditable;
        if (snap.summary) state.summary = snap.summary;
        updateRecipientButtonsState();
        render();
    }

    // OPTIMISTIC handleQuantityChange — UI updates INSTANTLY, backend syncs in background.
    function handleQuantityChange(productId, increase) {
        const product = state.products.find(p => String(p.id) === String(productId));
        if (!product) return;

        // 1. Determine action
        let apiAction = '', apiQuantity = null;
        const quantityBefore = product.quantity;

        if (increase) {
            if (quantityBefore >= 3) { showToast('No puedes añadir más de 3 unidades de este producto.'); return; }
            apiAction = 'update_quantity';
            apiQuantity = quantityBefore + 1;
        } else {
            if (quantityBefore > 1) { apiAction = 'update_quantity'; apiQuantity = quantityBefore - 1; }
            else { apiAction = 'remove'; }
        }

        // 2. Snapshot BEFORE mutation (for rollback)
        const snapshot = _snapshotState();

        // 3. OPTIMISTIC: mutate state immediately
        if (apiAction === 'remove') {
            state.products = state.products.filter(p => String(p.id) !== String(productId));
        } else {
            product.quantity = apiQuantity;
            if (apiQuantity < quantityBefore && !product.is_static) {
                // Pad to full length so every slot is represented
                while (product.assignments.length < quantityBefore) product.assignments.push(undefined);
                // Find the last unassigned slot to remove; if all assigned, remove the very last
                let removeIdx = -1;
                for (let i = quantityBefore - 1; i >= 0; i--) {
                    if (!product.assignments[i]) { removeIdx = i; break; }
                }
                if (removeIdx === -1) removeIdx = quantityBefore - 1;
                product.assignments.splice(removeIdx, 1);
            }
        }

        // 4. Update nick mode immediately
        if (!product.is_static || apiAction === 'remove') {
            const apiProducts = state.products.filter(p => !p.is_static);
            if (quantityBefore === 1 && apiQuantity > 1) { state.nickMode = 'multi'; }
            if (quantityBefore > 1 && apiQuantity === 1) {
                const needsMultiMode = apiProducts.some(p => p.quantity > 1) || apiProducts.length > 1;
                if (!needsMultiMode) { state.nickMode = 'single'; state.isSelfNickEditable = false; }
            }
        }

        // 5. Update local summary estimate immediately (price × qty)
        const estimatedSubtotal = state.products.reduce((sum, p) => sum + (p.price * p.quantity), 0);
        if (state.summary) {
            const discountRatio = state.summary.subtotal > 0 ? state.summary.discount / state.summary.subtotal : 0;
            state.summary = normalizeSummary(estimatedSubtotal, estimatedSubtotal * discountRatio);
        }

        // 6. UI UPDATE — surgical when possible, full render only for mode changes
        const nickModeBefore = snapshot.nickMode;
        const modeChanged = nickModeBefore !== state.nickMode;
        updateRecipientButtonsState();

        if (apiAction === 'remove' || modeChanged) {
            // Full render for product removal or single↔multi mode switch
            render(true);
            if (increase && modeChanged && state.nickMode === 'multi') {
                const nickInputs = document.querySelectorAll('#nick-assignment-container .recipient-input');
                nickInputs.forEach(el => el.closest('div')?.classList.add('anim-fade-in'));
                const selectors = document.querySelectorAll(`.custom-select[data-product-id="${productId}"]`);
                selectors.forEach(sel => sel.classList.add('anim-slide-in'));
            }
        } else {
            // --- SURGICAL PATH — no full re-render, no flash ---
            const pid = String(productId);
            const row = document.querySelector(`.cart-line[data-product-id="${pid}"], .product-row[data-product-id="${pid}"]`);

            if (!row) { render(true); } else {
                // A) Update quantity displays
                row.querySelectorAll(`[data-quantity-display="${pid}"], [data-cart-slot="quantity"]`).forEach(el => {
                    el.textContent = apiQuantity;
                });

                // B) Update decrease button (trash ↔ minus using data-intent like cart-stepper)
                const decreaseBtn = row.querySelector(`[data-cart-action="decrement"], .quantity-btn.decrease[data-product-id="${pid}"]`);
                if (decreaseBtn) {
                    const remove = apiQuantity <= 1;
                    decreaseBtn.setAttribute('data-intent', remove ? 'remove' : 'decrement');
                    decreaseBtn.setAttribute('aria-label', remove ? `Quitar ${product.name} del carrito` : `Reducir cantidad de ${product.name}`);
                }
                const increaseBtn = row.querySelector(`[data-cart-action="increment"], .quantity-btn.increase[data-product-id="${pid}"]`);
                if (increaseBtn) {
                    increaseBtn.setAttribute('aria-label', `Aumentar cantidad de ${product.name}`);
                    increaseBtn.setAttribute('aria-disabled', apiQuantity >= 3 ? 'true' : null);
                }

                // C) Update assignment selectors (multi mode only, non-static)
                const assignGrid = (state.nickMode === 'multi' && !product.is_static) ? row.querySelector('.assign-grid') : null;
                if (assignGrid) {
                    if (increase) {
                        assignGrid.insertAdjacentHTML('beforeend', recipientSelectHTML(pid, apiQuantity - 1, null, 'anim-slide-in'));
                    } else {
                        const sels = assignGrid.querySelectorAll(`.custom-select[data-product-id="${pid}"]`);
                        if (sels.length > apiQuantity) sels[sels.length - 1].remove();
                        // Re-index remaining selectors and refresh their labels
                        assignGrid.querySelectorAll(`.custom-select[data-product-id="${pid}"]`).forEach((sel, i) => {
                            sel.dataset.assignmentIndex = String(i);
                            const recipient = state.recipients.find(r => r.id === product.assignments[i]);
                            const triggerSpan = sel.querySelector('.custom-select-trigger span');
                            if (triggerSpan) triggerSpan.textContent = recipient ? recipient.name : 'Enviar a...';
                        });
                    }
                }

                // D) Update recipient inputs (Destinatarios) surgically
                const nickContainer = document.getElementById('nick-assignment-container');
                if (state.nickMode === 'multi' && nickContainer) {
                    const totalSlots = state.products.filter(p => !p.is_static).reduce((s, p) => s + p.quantity, 0);
                    const clampedSlots = Math.max(2, Math.min(totalSlots, 3));
                    // Resize recipients array
                    while (state.recipients.length < clampedSlots) {
                        state.recipients.push({ id: state.recipients.length + 1, name: '', error: null });
                    }
                    if (state.recipients.length > clampedSlots) {
                        state.recipients.length = clampedSlots;
                    }

                    const recipientGrid = nickContainer.querySelector('.grid');
                    if (recipientGrid) {
                        const currentInputs = recipientGrid.querySelectorAll('.recipient-input');
                        const currentCount = currentInputs.length;

                        if (currentCount < clampedSlots) {
                            // Add new input(s)
                            for (let j = currentCount; j < clampedSlots; j++) {
                                const r = state.recipients[j];
                                const div = document.createElement('div');
                                div.innerHTML = `<input type="text" value="${r.name}" data-recipient-index="${j}" placeholder="Usuario #${j + 1}" class="input-field recipient-input text-sm" maxlength="16">`;
                                div.firstElementChild.classList.add('anim-fade-in');
                                recipientGrid.appendChild(div);
                            }
                            // Update grid columns class
                            recipientGrid.classList.remove('sm:grid-cols-2', 'sm:grid-cols-3');
                            recipientGrid.classList.add(clampedSlots === 2 ? 'sm:grid-cols-2' : 'sm:grid-cols-3');
                            // Update helper text
                            const helperP = nickContainer.querySelector('p.text-xs');
                            if (helperP) helperP.textContent = `Define hasta ${clampedSlots} amigos. Luego, en cada producto, asigna cada ítem.`;
                        } else if (currentCount > clampedSlots) {
                            // Remove excess inputs from the end
                            for (let j = currentCount - 1; j >= clampedSlots; j--) {
                                const inputDiv = currentInputs[j]?.closest('div');
                                if (inputDiv) inputDiv.remove();
                            }
                            recipientGrid.classList.remove('sm:grid-cols-2', 'sm:grid-cols-3');
                            recipientGrid.classList.add(clampedSlots === 2 ? 'sm:grid-cols-2' : 'sm:grid-cols-3');
                            const helperP = nickContainer.querySelector('p.text-xs');
                            if (helperP) helperP.textContent = `Define hasta ${clampedSlots} amigos. Luego, en cada producto, asigna cada ítem.`;
                        }
                    }
                }
            }

            // E) Update summary and payments (cheap, no flash)
            renderPaymentMethods();
            renderSummary();
            _lastProductFingerprint = _getProductFingerprint();
        }

        // 7. Bump version — any in-flight response with an older version will be discarded
        const thisVersion = ++_opVersion;

        // 8. Fire-and-forget: sync with backend, rollback on failure
        (async () => {
            const result = await updateCartAPI(apiAction, productId, apiQuantity);

            // Discard if a newer mutation happened while we were waiting
            if (_opVersion !== thisVersion) return;

            if (!result.success) {
                // ROLLBACK to pre-mutation state
                showToast(result.message || 'No se pudo actualizar el producto.');
                _restoreSnapshot(snapshot);
                return;
            }

            // Backend succeeded — sync authoritative data (prices, totals, coupon effects)
            // Only if no newer mutation has happened
            await updateCheckoutState(false);
        })();
    }

    // REEMPLAZA POR ESTA VERSIÓN COMPLETA
    function validateUsername(username) {
        const trimmed = username.trim();
        if (!trimmed) return 'Ingresa un nombre válido.';
        // --- LÍNEA RESTAURADA ---
        if (trimmed.length < 3) return 'Debe tener al menos 3 caracteres.';
        // -----------------------
        if (trimmed.length > 20) return 'Máximo 20 caracteres.';
        return null;
    }

    function updateRecipientDOM(index) {
        const recipient = state.recipients[index];
        const input = document.querySelector(`#single-nick, [data-recipient-index="${index}"]`); if (!input) return;
        const parent = input.closest('[data-recipient-field]') || input.parentElement;
        if (!parent) return;

        input.classList.toggle('input-error', !!recipient.error);

        if (state.nickMode === 'single') {
            // Single mode: swap between hint and error elements
            const existingErr = parent.querySelector('.checkout-field-error');
            const existingHint = parent.querySelector('.checkout-field-hint');

            if (recipient.error) {
                // Remove hint if present
                if (existingHint) existingHint.remove();
                // Show or update error
                if (existingErr) {
                    existingErr.querySelector('.field-error-text').textContent = recipient.error;
                } else {
                    const errEl = document.createElement('div');
                    errEl.className = 'checkout-field-error';
                    errEl.setAttribute('role', 'alert');
                    errEl.innerHTML = `${ERROR_ICON_SVG}<span class="field-error-text">${recipient.error}</span>`;
                    // Insert after the input or after the .group wrapper (for readonly case)
                    const anchor = input.closest('.group') || input;
                    anchor.insertAdjacentElement('afterend', errEl);
                }
            } else {
                // Remove error if present
                if (existingErr) existingErr.remove();
                // Restore hint if missing
                if (!existingHint) {
                    const desktopHint = 'Escribe el nombre exactamente como aparece en el juego, incluyendo caracteres especiales si aplica.';
                    const mobileHint = 'Escribe el nombre exacto (incluye caracteres especiales).';
                    const hintEl = document.createElement('p');
                    hintEl.className = 'checkout-field-hint text-xs mt-2 text-[--text-secondary]';
                    hintEl.innerHTML = `<span class="block md:hidden">${mobileHint}</span><span class="hidden md:block">${desktopHint}</span>`;
                    const anchor = input.closest('.group') || input;
                    anchor.insertAdjacentElement('afterend', hintEl);
                }
            }
        } else {
            // Multi mode: manage checkout-field-error element
            const existingErr = parent.querySelector('.checkout-field-error');
            if (recipient.error) {
                if (existingErr) {
                    existingErr.querySelector('.field-error-text').textContent = recipient.error;
                } else {
                    const errEl = document.createElement('div');
                    errEl.className = 'checkout-field-error';
                    errEl.setAttribute('role', 'alert');
                    errEl.innerHTML = `${ERROR_ICON_SVG}<span class="field-error-text">${recipient.error}</span>`;
                    input.insertAdjacentElement('afterend', errEl);
                }
            } else if (existingErr) {
                existingErr.remove();
            }
        }
    }

    // REEMPLAZA ESTA FUNCIÓN COMPLETA EN checkout.js

    // Unified coupon feedback helper — keeps coupon messages consistent with all other field errors/successes
    function setCouponFeedback(type, text) {
        const msgEl = document.getElementById('coupon-message');
        const mMsgEl = document.getElementById('m-coupon-message');
        const codeInput = document.getElementById('coupon-code');
        const mCodeInput = document.getElementById('m-coupon-code');
        if (!msgEl) return;

        codeInput?.classList.remove('input-error', 'input-success');
        mCodeInput?.classList.remove('input-error', 'input-success');

        if (!type || !text) {
            msgEl.innerHTML = '';
            msgEl.className = 'mt-2 text-xs font-medium';
            if (mMsgEl) { mMsgEl.innerHTML = ''; mMsgEl.className = 'mt-2 text-xs font-medium'; }
            return;
        }

        let html = '';
        let inputClass = '';
        if (type === 'error') {
            html = `<div class="checkout-field-error" style="margin-top:0">${ERROR_ICON_SVG}<span class="field-error-text">${text}</span></div>`;
            inputClass = 'input-error';
        } else if (type === 'success') {
            html = `<div class="checkout-field-success" style="margin-top:0">${SUCCESS_ICON_SVG}<span>${text}</span></div>`;
            inputClass = 'input-success';
        } else if (type === 'warn') {
            html = `<div class="checkout-field-error" style="margin-top:0">${ERROR_ICON_SVG}<span class="field-error-text">${text}</span></div>`;
            inputClass = 'input-error';
        }

        msgEl.innerHTML = html;
        msgEl.className = 'mt-2';
        if (inputClass) codeInput?.classList.add(inputClass);

        if (mMsgEl) { mMsgEl.innerHTML = html; mMsgEl.className = 'mt-2'; }
        if (inputClass && mCodeInput) mCodeInput.classList.add(inputClass);
    }

    const debouncedRevalidateCoupon = debounce(revalidateCouponState, 900);

    // source: 'desktop' | 'mobile' — picks the right input/button pair
    async function applyCoupon(source) {
        const isM = source === 'mobile';
        const btn = document.getElementById(isM ? 'm-apply-coupon-btn' : 'apply-coupon-btn');
        const codeInput = document.getElementById(isM ? 'm-coupon-code' : 'coupon-code');
        const otherInput = document.getElementById(isM ? 'coupon-code' : 'm-coupon-code');
        const code = (codeInput?.value ?? '').trim().toUpperCase();
        if (!code) { setCouponFeedback('error', 'Introduce un código.'); return; }
        // Sync value to the other input so both show the same code
        if (otherInput) otherInput.value = code;
        state.coupon.code = code;
        if (btn) { btn.disabled = true; btn.querySelector('.btn-text')?.classList.add('hidden'); btn.querySelector('.btn-loader')?.classList.remove('hidden'); }
        await revalidateCouponState(true);
        if (btn) { btn.disabled = false; btn.querySelector('.btn-text')?.classList.remove('hidden'); btn.querySelector('.btn-loader')?.classList.add('hidden'); }
    }

    // Cupones: cada <form data-coupon-form="desktop|mobile"> aplica al enviarse (Enter o botón)
    // y ambos inputs se mantienen sincronizados mientras se escribe.
    function _setupCouponForms() {
        document.querySelectorAll('form[data-coupon-form]').forEach(form => {
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                applyCoupon(form.dataset.couponForm === 'mobile' ? 'mobile' : 'desktop');
            });
        });
        const d = document.getElementById('coupon-code');
        const m = document.getElementById('m-coupon-code');
        if (d && m) {
            d.addEventListener('input', () => { m.value = d.value; });
            m.addEventListener('input', () => { d.value = m.value; });
        }
    }
    _setupCouponForms();

    // ... [Tu función submitOrder SIN CAMBIOS] ...
    // REEMPLAZA TU FUNCIÓN submitOrder POR ESTAS DOS:

    // Esta función ahora contiene la lógica ORIGINAL de envío del pedido CORREGIDA
    async function proceedWithSubmission() {
        const btn = document.getElementById('confirm-btn');
        btn.disabled = true;
        btn.querySelector('.btn-text').classList.add('hidden');
        btn.querySelector('.btn-loader').classList.remove('hidden');

        // =======================================================================
        // 1. BLOQUE DE DOBLE VERIFICACIÓN DEL CUPÓN
        // =======================================================================
        if (state.coupon.code) {
            try {
                const validationPayload = {
                    action: 'q5s',
                    codigo_cupon: state.coupon.code,
                    productos_carrito: state.products.map(p => ({
                        offerId: p.id,
                        tipo: p.type,
                        precio: p.price_base,
                        cantidad: p.quantity,
                        is_static: p.is_static
                    })),
                    whatsapp: iti.isValidNumber() ? iti.getNumber() : '',
                    destinatarios: state.recipients.map(r => r.name.trim()).filter(name => name.length > 0)
                };

                const validationResponse = await fetch('/web.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(validationPayload)
                });

                const validationResult = await _resolveSyncFrame(await validationResponse.json());

                if (validationResult.status !== 'success') {
                    showToast(`Error de cupón: ${validationResult.message}`, 'error');
                    btn.disabled = false;
                    btn.querySelector('.btn-text').classList.remove('hidden');
                    btn.querySelector('.btn-loader').classList.add('hidden');
                    return;
                }
            } catch (error) {
                console.error("Error en la doble verificación del cupón:", error);
                showToast('Error de red al validar el cupón. Inténtalo de nuevo.', 'error');
                btn.disabled = false;
                btn.querySelector('.btn-text').classList.remove('hidden');
                btn.querySelector('.btn-loader').classList.add('hidden');
                return;
            }
        }

        // =======================================================================
        // 2. LÓGICA DE ENVÍO CON MODO EXPLÍCITO Y MONEDAS
        // =======================================================================
        try {
            state.contact.whatsapp = iti.getNumber();

            // A) Determinar modo explícito (Self/Multi/Friend)
            let explicitMode = 'friend'; // Default seguro

            // Solo si hay usuario logueado hacemos la distinción
            if (window.USER_DATA && window.USER_DATA.is_logged_in) {
                // Si está en modo visual "Usuario Único" (Para Mí)
                if (state.nickMode === 'single') {
                    const nickInput = document.getElementById('single-nick');

                    // Solo validamos si el input existe (es decir, si hay productos que requieren nick)
                    if (nickInput) {
                        const currentNick = nickInput.value.trim().toLowerCase();
                        const userNick = window.USER_DATA.nickname.trim().toLowerCase();

                        if (currentNick === userNick) {
                            explicitMode = 'self';
                        }
                    } else {
                        // Si no hay input (ej: solo productos estáticos/monedas), y el usuario está logueado...
                        // Asumimos que es para él mismo (SELF) porque la entrega es a su cuenta/whatsapp.
                        explicitMode = 'self';
                    }
                } else {
                    // Si es multi, chequeamos cuántos hay realmente
                    const realRecipients = state.recipients.filter(r => r.name.trim() !== '');
                    if (realRecipients.length > 1) explicitMode = 'multi';
                }
            } else {
                // Invitado con varios destinatarios -> multi
                const realRecipients = state.recipients.filter(r => r.name.trim() !== '');
                if (realRecipients.length > 1) explicitMode = 'multi';
            }

            // B) Construir Payload (AHORA SÍ, PORQUE YA TENEMOS explicitMode)
            const payload = {
                products: state.products.map(item => ({
                    ...item,
                    assignments: item.assignments || []
                })),
                contact: state.contact,
                recipients: state.recipients.filter(r => r.name.trim() !== ''), // Limpiar vacíos
                selectedPayment: state.selectedPayment,
                coupon: state.coupon,
                explicit_recipient_mode: explicitMode, // <--- Ya existe la variable
                explicit_coins: state.coins.active ? normalizeCoinsValue((state.coins.selectedAmount ?? 0), getCoinsSmartMaxAvailable(state.summary?.total ?? 0)) : 0 // <--- Agregado monedas
            };
            payload.action = 'p9c';

            // C) Enviar al Backend
            const response = await fetch('/web.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const data = await response.json();

            if (data.success && data.data && data.data.order_code) {
                window.location.href = `/checkout/confirmacion/${data.data.order_code}`;
            } else {
                showToast(`Error: ${data.message || 'Ocurrió un error desconocido.'}`, 'error');
                btn.disabled = false;
                btn.querySelector('.btn-text').classList.remove('hidden');
                btn.querySelector('.btn-loader').classList.add('hidden');
            }
        } catch (error) {
            console.error("Error en submit:", error); // Para ver el error real en consola
            showToast('Error de conexión. Inténtalo de nuevo.', 'error');
            btn.disabled = false;
            btn.querySelector('.btn-text').classList.remove('hidden');
            btn.querySelector('.btn-loader').classList.add('hidden');
        }
    }

    function showFieldError(inputEl, message) {
        if (!inputEl) return;
        // Avoid duplicating error elements
        const parent = inputEl.closest('[data-recipient-field]') || inputEl.closest('.custom-select') || inputEl.parentElement;
        if (!parent) return;
        let existing = parent.querySelector('.checkout-field-error');
        if (existing) {
            existing.querySelector('.field-error-text').textContent = message;
            existing.classList.remove('is-hidden');
            return;
        }
        const errEl = document.createElement('div');
        errEl.className = 'checkout-field-error';
        errEl.setAttribute('role', 'alert');
        errEl.innerHTML = `${ERROR_ICON_SVG}<span class="field-error-text">${message}</span>`;
        // Insert after the input or after the trigger button
        const anchor = inputEl.closest('.custom-select') ? inputEl.closest('.custom-select') : inputEl;
        anchor.insertAdjacentElement('afterend', errEl);
    }

    // Track which fields have been blurred ("touched") — errors only show after blur
    const _touchedFields = new Set();

    // Re-applies validation feedback to username fields after a DOM rebuild (e.g. mode switch).
    // Uses _touchedFields to only affect fields the user has already interacted with.
    function reapplyValidationFeedback() {
        if (_touchedFields.size === 0) return;
        document.querySelectorAll('#single-nick, .recipient-input').forEach(input => {
            const fieldKey = input.id || `recipient-${input.dataset.recipientIndex}`;
            if (!_touchedFields.has(fieldKey)) return;
            const trimmed = input.value.trim();
            const error = validateUsername(input.value);
            if (!error) {
                input.classList.add('input-success');
                setLiveFieldFeedback(input, null, true);
            } else if (trimmed.length > 0) {
                input.classList.add('input-error');
                setLiveFieldFeedback(input, error, false);
            }
        });
    }

    // Live validation feedback with inline messages
    function setLiveFieldFeedback(inputEl, errorMsg, isValid) {
        if (!inputEl) return;

        // Find the anchor element to place feedback after
        const anchor = inputEl.closest('.iti') || inputEl;

        // The parent container — for WhatsApp it's the .iti's parent
        const searchContainer = anchor.parentElement;
        if (!searchContainer) return;

        // Clean up any submit-time errors in the same container to prevent duplicates
        searchContainer.querySelectorAll('.checkout-field-error:not(.checkout-live-feedback)').forEach(el => el.remove());

        // Find any existing live feedback
        let existing = searchContainer.querySelector('.checkout-live-feedback');

        // Hide/show static hint when live feedback is active
        const hint = searchContainer.querySelector('.checkout-field-hint');

        if (isValid) {
            if (hint) hint.style.display = 'none';
            if (existing) {
                existing.className = 'checkout-live-feedback checkout-field-success';
                existing.innerHTML = `${SUCCESS_ICON_SVG}<span>¡Listo!</span>`;
            } else {
                const el = document.createElement('div');
                el.className = 'checkout-live-feedback checkout-field-success';
                el.innerHTML = `${SUCCESS_ICON_SVG}<span>¡Listo!</span>`;
                anchor.insertAdjacentElement('afterend', el);
            }
        } else if (errorMsg) {
            if (hint) hint.style.display = 'none';
            if (existing) {
                existing.className = 'checkout-live-feedback checkout-field-error';
                existing.innerHTML = `${ERROR_ICON_SVG}<span class="field-error-text">${errorMsg}</span>`;
            } else {
                const el = document.createElement('div');
                el.className = 'checkout-live-feedback checkout-field-error';
                el.innerHTML = `${ERROR_ICON_SVG}<span class="field-error-text">${errorMsg}</span>`;
                anchor.insertAdjacentElement('afterend', el);
            }
        } else {
            // Neutral — remove feedback, restore hint
            if (existing) existing.remove();
            if (hint) hint.style.display = '';
        }
    }

    function clearAllFieldErrors() {
        document.querySelectorAll('.input-error, .input-success, .shake').forEach(el => el.classList.remove('input-error', 'input-success', 'shake'));
        document.querySelectorAll('.checkout-field-error, .checkout-field-success, .checkout-live-feedback').forEach(el => el.remove());
        document.querySelectorAll('.checkout-field-hint').forEach(el => el.style.display = '');
        _touchedFields.clear();
    }

    // submitOrder valida los campos, ejecuta la cola de modales y delega en proceedWithSubmission.
    async function submitOrder() {
        let firstInvalidElement = null;
        clearAllFieldErrors();

        const highlight = (el, message) => {
            if (!el) return;
            el.classList.add('input-error', 'shake');
            if (message) showFieldError(el, message);
            if (!firstInvalidElement) {
                firstInvalidElement = el;
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        };

        // --- Validación de WhatsApp ---
        if (!iti.isValidNumber()) {
            highlight(whatsappInput.closest('.iti'), 'Ingresa un número de WhatsApp válido.');
        }

        // --- Validación de destinatarios ---
        const hasApiProducts = state.products.some(p => !p.is_static);
        if (hasApiProducts) {
            if (state.nickMode === 'single') {
                const singleUserInput = document.getElementById('single-nick');
                if (singleUserInput) {
                    const error = validateUsername(singleUserInput.value);
                    state.recipients[0].error = error;
                    updateRecipientDOM(0);
                    if (error) highlight(singleUserInput, error);
                }
            } else {
                // Multi mode: at least 2 valid recipients; extra slots may stay empty
                const MIN_MULTI_RECIPIENTS = 2;
                const filledCount = state.recipients.filter(r => r.name.trim() !== '').length;
                state.recipients.forEach((r, index) => {
                    const isEmpty = r.name.trim() === '';
                    const optionalSlot = isEmpty && (filledCount >= MIN_MULTI_RECIPIENTS || index >= MIN_MULTI_RECIPIENTS);
                    const error = optionalSlot ? null : validateUsername(r.name);
                    r.error = error;
                    updateRecipientDOM(index);
                    const inputEl = document.querySelector(`[data-recipient-index="${index}"]`);
                    if (error && inputEl) highlight(inputEl, error);
                });

                // Validate assignment selectors
                state.products.forEach(p => {
                    if (!p.is_static) {
                        for (let i = 0; i < p.quantity; i++) {
                            if (!p.assignments[i]) {
                                const btn = document.querySelector(`.custom-select[data-product-id="${p.id}"][data-assignment-index="${i}"] button`);
                                highlight(btn, 'Selecciona un destinatario.');
                            }
                        }
                    }
                });
            }
        }

        if (firstInvalidElement) return; // Si hay errores, no continuamos

        // --- Cola de modales (tutoriales -> advertencia cupón | reserva) ---
        const userConfirmed = await runModalQueue();
        if (userConfirmed) {
            proceedWithSubmission();
        }
    }

    const recipientModal = document.getElementById('recipient-modal'); function openRecipientModal(productId, assignmentIndex) { const product = state.products.find(p => String(p.id) === String(productId)); if (!product) return; const listEl = document.getElementById('recipient-modal-list'); listEl.innerHTML = ''; recipientModal.dataset.productId = productId; recipientModal.dataset.assignmentIndex = assignmentIndex; const availableRecipients = state.recipients.filter(r => r.name.trim() !== '' && !validateUsername(r.name)); const currentAssignmentId = product.assignments[assignmentIndex]; const options = availableRecipients.filter(r => r.id === currentAssignmentId || !product.assignments.includes(r.id)); if (options.length > 0) { options.forEach(r => listEl.insertAdjacentHTML('beforeend', `<div class="recipient-modal-option" data-value="${r.id}">${r.name}</div>`)); } else { listEl.innerHTML = `<p class="text-sm text-center text-[--text-secondary]">No hay destinatarios válidos disponibles.</p>`; } recipientModal.classList.add('active'); } function closeRecipientModal() { recipientModal.classList.remove('active'); }

    document.body.addEventListener('click', e => {
        const target = e.target;

        const editSelfNickBtn = target.closest('#edit-self-nick-btn');
        if (editSelfNickBtn) {
            state.isSelfNickEditable = true;
            renderNickAssignment();
            const input = document.getElementById('single-nick');
            if (input) {
                input.focus();
                const len = input.value.length;
                try { input.setSelectionRange(len, len); } catch (_) { }
            }
            return;
        }

        if (target.id === 'single-user-btn') { handleNickModeChange('single'); return; }
        if (target.id === 'multi-user-btn') { handleNickModeChange('multi'); return; }
        if (target.id === 'confirm-btn' || target.closest('#confirm-btn') || target.id === 'confirm-btn-mobile' || target.closest('#confirm-btn-mobile')) { submitOrder(); return; }

        if (target.closest('#toggle-coins-btn')) {
            state.coins.active = !state.coins.active;
            playSwitchClick(state.coins.active);

            const toggleBtn = document.getElementById('toggle-coins-btn');
            if (toggleBtn) {
                toggleBtn.setAttribute('aria-checked', state.coins.active ? 'true' : 'false');
                toggleBtn.setAttribute('data-tooltip', state.coins.active ? 'Desactivar Bananos' : 'Canjear tus Bananos');
                toggleBtn.classList.toggle('is-on', state.coins.active);
            }

            const controls = document.getElementById('coins-controls');
            if (controls) {
                controls.classList.toggle('hidden', !state.coins.active);
            }

            if (state.coins.active) {
                updateCoinsSliderUI();
            }
            updateTotalsAfterCoins();
            return;
        }

        const coinsPresetBtn = target.closest('#coins-min-btn, #coins-half-btn, #coins-max-btn');
        if (coinsPresetBtn) {
            const maxCoins = getCoinsSmartMaxAvailable();
            const rules = getCoinsRedeemRules();

            let nextValue = rules.min;
            if (coinsPresetBtn.id === 'coins-half-btn') nextValue = Math.floor(maxCoins / 2);
            if (coinsPresetBtn.id === 'coins-max-btn') nextValue = maxCoins;

            state.coins.selectedAmount = normalizeCoinsValue(nextValue, maxCoins);
            updateCoinsSliderUI();
            updateTotalsAfterCoins();
            return;
        }

        // --- LISTENERS PARA EL MODAL DE CUENTAS ---
        if (target.closest('#open-accounts-modal')) { e.preventDefault(); openAccountsModal(); return; }
        if (target.closest('#modal-close-btn, #modal-sheet-close-btn') || target.id === 'accounts-modal') { closeAccountsModal(); return; }
        const copyBtn = target.closest('.copy-icon-btn'); // CAMBIO: selector actualizado a '.copy-icon-btn'
        if (copyBtn) {
            navigator.clipboard.writeText(copyBtn.dataset.account).then(() => {
                copyBtn.innerHTML = svgIcon('check');
                copyBtn.classList.add('copied');
                clearTimeout(copyBtn._resetTimer);
                copyBtn._resetTimer = setTimeout(() => {
                    if (!copyBtn.isConnected) return;
                    copyBtn.innerHTML = svgIcon('copy');
                    copyBtn.classList.remove('copied');
                }, 2000);

                showToast(`Usuario copiado`); // CAMBIO: Mensaje del toast actualizado
            });
            return;
        }
        // ... (otros listeners)
        // --- LISTENERS EXISTENTES (SIN CAMBIOS) ---
        if (target.id === 'close-recipient-modal' || target === recipientModal) { closeRecipientModal(); return; }
        const quantityBtn = target.closest('.cart-stepper__btn, .quantity-btn'); if (quantityBtn) { const _pid = quantityBtn.dataset.productId; const isIncrease = quantityBtn.dataset.cartAction === 'increment' || quantityBtn.classList.contains('increase'); handleQuantityChange(_pid, isIncrease); requestAnimationFrame(() => { const qtyEl = document.querySelector(`[data-quantity-display="${_pid}"], [data-cart-slot="quantity"]`); if (qtyEl) { qtyEl.classList.remove('qty-bump'); void qtyEl.offsetWidth; qtyEl.classList.add('qty-bump'); } }); return; }
        const paymentMethodEl = target.closest('.payment-method'); if (paymentMethodEl && !paymentMethodEl.classList.contains('is-disabled')) { state.selectedPayment = paymentMethodEl.dataset.id; renderPaymentMethods(); renderSummary(); return; }
        const modalOption = target.closest('.recipient-modal-option'); if (modalOption) { const { productId, assignmentIndex } = recipientModal.dataset; const product = state.products.find(p => String(p.id) === String(productId)); if (product) product.assignments[parseInt(assignmentIndex)] = parseInt(modalOption.dataset.value); closeRecipientModal(); render(); return; }
        const trigger = target.closest('.custom-select-trigger'); if (trigger) { const isMobile = window.innerWidth < 1024; const selectContainer = trigger.closest('.custom-select'); const { productId, assignmentIndex } = selectContainer.dataset; if (isMobile) { openRecipientModal(productId, parseInt(assignmentIndex)); } else { const options = selectContainer.querySelector('.custom-select-options'); const isOpening = options.classList.contains('opacity-0'); document.querySelectorAll('.custom-select-options').forEach(s => s.classList.add('opacity-0', 'scale-95', 'pointer-events-none')); document.querySelectorAll('.cart-line, .product-row').forEach(row => row.classList.remove('z-10')); if (isOpening) { const product = state.products.find(p => String(p.id) === String(productId)); if (!product) return; const availableRecipients = state.recipients.filter(r => r.name.trim() !== '' && !validateUsername(r.name)); const currentAssignmentId = product.assignments[parseInt(assignmentIndex)]; const optionHtml = availableRecipients.filter(r => r.id === currentAssignmentId || !product.assignments.includes(r.id)).map(r => `<div class="p-2 hover:bg-[--bg-tertiary] cursor-pointer custom-select-option" data-value="${r.id}">${r.name}</div>`).join(''); options.innerHTML = optionHtml || `<div class="p-2 text-xs text-center text-[--text-secondary]">No hay destinatarios válidos</div>`; options.classList.remove('opacity-0', 'scale-95', 'pointer-events-none'); trigger.closest('.cart-line, .product-row')?.classList.add('z-10'); document.getElementById('products-section')?.classList.add('has-dropdown-open'); } else { document.getElementById('products-section')?.classList.remove('has-dropdown-open'); } } } else if (target.closest('.custom-select-option')) { const select = target.closest('.custom-select'); const product = state.products.find(p => String(p.id) === String(select.dataset.productId)); if (product) product.assignments[parseInt(select.dataset.assignmentIndex)] = parseInt(target.dataset.value); document.querySelectorAll('.custom-select-options').forEach(s => s.classList.add('opacity-0', 'scale-95', 'pointer-events-none')); document.querySelectorAll('.cart-line, .product-row').forEach(row => row.classList.remove('z-10')); document.getElementById('products-section')?.classList.remove('has-dropdown-open'); render(true); } else if (!target.closest('.custom-select')) { document.querySelectorAll('.custom-select-options').forEach(s => s.classList.add('opacity-0', 'scale-95', 'pointer-events-none')); document.querySelectorAll('.cart-line, .product-row').forEach(row => row.classList.remove('z-10')); document.getElementById('products-section')?.classList.remove('has-dropdown-open'); }
    });

    let originalInputValue = '';
    document.body.addEventListener('focusin', e => { if (e.target.matches('#single-nick, .recipient-input, #whatsapp')) { originalInputValue = e.target.value; } });

    // --- LISTENER DE INPUT ---
    // Username fields: only clear errors while typing; green "¡Listo!" is deferred to blur.
    // Number fields (WhatsApp): show green "¡Listo!" immediately when valid.
    document.body.addEventListener('input', e => {
        const target = e.target;
        const hayCuponActivo = state.coupon.code && state.coupon.code.trim() !== '';

        if (target.matches('#single-nick') || target.matches('.recipient-input')) {
            const index = target.matches('#single-nick') ? 0 : parseInt(target.dataset.recipientIndex);

            if (target.value.length > 20) { target.value = target.value.slice(0, 20); }
            state.recipients[index].name = target.value;

            if (state.recipients[index].error) { state.recipients[index].error = null; updateRecipientDOM(index); }

            // Username inputs: stay neutral while typing. No green, no red.
            target.classList.remove('input-success', 'input-error');
            setLiveFieldFeedback(target, null, false);

            const trimmed = target.value.trim();
            if (trimmed.length >= 3 && hayCuponActivo) {
                debouncedRevalidateCoupon(false);
            }
        }
        if (target.id === 'whatsapp') {
            const wrapper = target.closest('.iti');
            if (wrapper) {
                const isValid = iti.isValidNumber();
                const hasValue = target.value.trim().length > 0;

                wrapper.classList.toggle('input-success', isValid);
                wrapper.classList.remove('input-error'); // Never red while typing

                if (!hasValue) {
                    setLiveFieldFeedback(target, null, false);
                } else if (isValid) {
                    setLiveFieldFeedback(target, null, true);
                } else {
                    setLiveFieldFeedback(target, null, false); // just clear, no error while typing
                }
            }
            if (target.value.trim().length >= 9 && hayCuponActivo) {
                debouncedRevalidateCoupon(false);
            }
        }
    });

    // --- LISTENER DE BLUR: show errors when leaving an invalid field ---
    document.body.addEventListener('focusout', e => {
        const target = e.target;
        if (!target) return;

        if (target.matches('.recipient-input') || (target.matches('#single-nick') && (!window.USER_DATA?.is_logged_in || state.isSelfNickEditable))) {
            const fieldKey = target.id || `recipient-${target.dataset.recipientIndex}`;
            _touchedFields.add(fieldKey);

            const trimmed = target.value.trim();
            const error = validateUsername(target.value);
            const isValid = !error;

            if (isValid) {
                target.classList.add('input-success');
                target.classList.remove('input-error');
                setLiveFieldFeedback(target, null, true);
            } else if (trimmed.length > 0) {
                target.classList.add('input-error');
                target.classList.remove('input-success');
                setLiveFieldFeedback(target, error, false);
            } else {
                target.classList.remove('input-error', 'input-success');
                setLiveFieldFeedback(target, null, false);
            }
        }

        if (target.id === 'whatsapp') {
            _touchedFields.add('whatsapp');
            const wrapper = target.closest('.iti');
            if (wrapper) {
                const isValid = iti.isValidNumber();
                const hasValue = target.value.trim().length > 0;

                if (isValid) {
                    wrapper.classList.add('input-success');
                    wrapper.classList.remove('input-error');
                    setLiveFieldFeedback(target, null, true);
                } else if (hasValue) {
                    wrapper.classList.add('input-error');
                    wrapper.classList.remove('input-success');
                    setLiveFieldFeedback(target, 'Ingresa un número de WhatsApp válido.', false);
                } else {
                    wrapper.classList.remove('input-error', 'input-success');
                    setLiveFieldFeedback(target, null, false);
                }
            }
        }
    }, true);

    document.body.addEventListener('keydown', e => {
        const target = e.target;
        if (!target || !target.matches('#single-nick')) return;
        if (state.nickMode !== 'single') return;
        if (!state.isSelfNickEditable) return;
        if (!(window.USER_DATA && window.USER_DATA.is_logged_in)) return;

        if (e.key === 'Enter') {
            e.preventDefault();
            lockSelfNick({ blur: true });
        }
    });

    document.body.addEventListener('focusout', e => {
        const target = e.target;
        if (!target || !target.matches('#single-nick')) return;
        if (state.nickMode !== 'single') return;
        if (!state.isSelfNickEditable) return;
        if (!(window.USER_DATA && window.USER_DATA.is_logged_in)) return;

        setTimeout(() => lockSelfNick(), 0);
    });

    // === NUEVO: LISTENER PARA SLIDER DE MONEDAS ============
    // =======================================================
    document.body.addEventListener('input', e => {
        // Detecta cuando mueves la bolita del slider
        if (e.target.id === 'coins-slider') {
            const maxCoins = getCoinsSmartMaxAvailable();
            state.coins.selectedAmount = normalizeCoinsValue(e.target.value, maxCoins);

            updateCoinsSliderUI();
            updateTotalsAfterCoins();
            return;
        }
        if (e.target.id === 'coins-input') {
            const target = e.target;
            const currentValue = String(target.value ?? '');
            const caretIndex = Number.isFinite(target.selectionStart) ? (target.selectionStart ?? currentValue.length) : currentValue.length;
            const digitsBeforeCaret = currentValue.slice(0, caretIndex).replace(/[^\d]/g, '').length;

            const raw = currentValue.replace(/[^\d]/g, '');
            const maxCoins = getCoinsSmartMaxAvailable();
            const rules = getCoinsRedeemRules();
            const selected = normalizeCoinsValue(raw, maxCoins);
            state.coins.selectedAmount = selected;

            if (raw === '') {
                target.value = rules.min === 0 ? '' : formatCoinsDisplayValue(selected);
            } else {
                const formatted = formatCoinsDisplayValue(selected);
                target.value = formatted;
                const nextCaret = getCaretIndexForDigitCount(formatted, digitsBeforeCaret);
                if (target === document.activeElement && typeof target.setSelectionRange === 'function') {

                    target.setSelectionRange(nextCaret, nextCaret);
                }
            }

            updateCoinsSliderUI({ preserveNumberInput: true });
            updateTotalsAfterCoins();
            return;
        }
    });

    async function fetchCoinsPublicConfig() {
        if (!window.USER_DATA || !window.USER_DATA.is_logged_in) {
            state.user = null;
            state.avatars_list = [];
            renderHeaderUserProfile();
            unlockWhatsappInput({ disableLock: true });
            return;
        }

        const candidates = [
            { action: 'j5r', sub: 'get_dashboard' }
        ];

        for (const candidate of candidates) {
            try {
                const response = await fetch('/web.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify({ action: candidate.action, sub: candidate.sub, _method: 'GET' }) });
                if (!response.ok) continue;
                const result = await response.json().then(r => _resolveSyncFrame(r)).catch(() => null);
                if (!result || result.status !== 'success') continue;

                state.coins.publicConfig = result.config || null;
                state.user = result.user
                    ? {
                        avatar_id: result.user.avatar_id ?? null,
                        avatar_filename: result.user.avatar_filename ?? null,
                        avatar_url: result.user.avatar_url ?? null,
                        public_display_name: result.user.public_display_name ?? null,
                        whatsapp_phone_e164: result.user.whatsapp_phone_e164 ?? null
                    }
                    : null;
                state.avatars_list = Array.isArray(result.avatars_list) ? result.avatars_list : [];
                renderHeaderUserProfile();
                applyWhatsappPhoneFromDashboard(result.user?.whatsapp_phone_e164 ?? '');
                if (state.coins?.active) {
                    updateCoinsSliderUI();
                    updateTotalsAfterCoins();
                }

                // --- Global onboarding check ---
                try {
                    const u = result.user || {};
                    const _blank = (v) => v === null || v === undefined || String(v).trim() === '';
                    const _placeholder = (v) => typeof v === 'string' && /^Usuario_[a-f0-9]{4,}$/i.test(v.trim());
                    // Skip country_iso2 blank check for auto-detected Peru users
                    let _countryAuto = false;
                    const _sc = (u.country_iso2 || '').toLowerCase().trim();
                    if (_sc === 'pe') { _countryAuto = true; }
                    else if (!_sc) { try { const _tz = Intl.DateTimeFormat().resolvedOptions().timeZone; if (typeof _tz === 'string' && /^America\/Lima$/i.test(_tz.trim())) _countryAuto = true; } catch (_) { } }
                    const needsOnboarding = _blank(u.game_username) || _placeholder(u.game_username)
                        || _blank(u.public_display_name) || _placeholder(u.public_display_name)
                        || (!_countryAuto && _blank(u.country_iso2))
                        || _blank(u.whatsapp_phone_e164);
                    if (needsOnboarding) {
                        const { ensureOnboardingSetupModal } = await import('/cuenta/onboarding_modal.js');
                        ensureOnboardingSetupModal({
                            dashboardData: result,
                            apiEndpoint: '/web.php?action=api_cuenta'
                        });
                    }
                } catch (_onbErr) {
                    try { console.warn('[Checkout] Onboarding modal load failed:', _onbErr); } catch (_) { }
                }

                return;

            } catch (_) { }
        }
    }

    const AVATAR_URL_BASE = '/assets/avatars/';

    // URL absoluta del avatar del usuario. Prioridad: `avatar_url` que ya resuelve
    // el backend (core/AvatarCatalog) → url/filename del catálogo → filename plano.
    function resolveAvatarUrl(user, avatarsList) {
        if (!user) return null;

        const direct = typeof user.avatar_url === 'string' ? user.avatar_url.trim() : '';
        if (direct) return direct;

        const idNum = Number(user.avatar_id);
        const found = (Number.isFinite(idNum) && Array.isArray(avatarsList))
            ? avatarsList.find(a => Number(a && a.id) === idNum)
            : null;
        if (found) {
            const listUrl = typeof found.url === 'string' ? found.url.trim() : '';
            if (listUrl) return listUrl;
            if (found.filename) return AVATAR_URL_BASE + encodeURIComponent(String(found.filename));
        }

        const filename = typeof user.avatar_filename === 'string' ? user.avatar_filename.trim() : '';
        return filename ? AVATAR_URL_BASE + encodeURIComponent(filename) : null;
    }

    function ensureHeaderProfileContainer() {
        let container = document.getElementById('header-user-profile');
        if (container) return container;
        const host = document.querySelector('[data-role="header-aside"]') || document.querySelector('.checkout-header');
        if (!host) return null;
        container = document.createElement('div');
        container.id = 'header-user-profile';
        container.className = 'header-user-profile';
        host.appendChild(container);
        return container;
    }

    function renderHeaderUserProfile() {
        const container = ensureHeaderProfileContainer();
        if (!container) return;

        const header = container.closest('header');
        const isLoggedIn = Boolean(window.USER_DATA && window.USER_DATA.is_logged_in);
        const displayName = state.user && state.user.public_display_name ? String(state.user.public_display_name) : '';
        if (!isLoggedIn || !displayName) {
            container.innerHTML = '';
            container.style.display = 'none';
            if (header) header.classList.remove('profile-active');
            return;
        }

        container.style.display = 'flex';
        if (header) header.classList.add('profile-active');

        let nameEl = container.querySelector('[data-role="profile-name"]');
        if (!nameEl) {
            nameEl = document.createElement('span');
            nameEl.dataset.role = 'profile-name';
            nameEl.className = 'text-sm md:text-base font-semibold text-[--text-primary]';
            nameEl.style.whiteSpace = 'nowrap';
            nameEl.style.overflow = 'hidden';
            nameEl.style.textOverflow = 'ellipsis';
            container.appendChild(nameEl);
        }

        nameEl.textContent = displayName;

        const ensureFallbackAvatar = () => {
            let fallbackEl = container.querySelector('[data-role="profile-avatar-fallback"]');
            if (!fallbackEl) {
                fallbackEl = document.createElement('div');
                fallbackEl.dataset.role = 'profile-avatar-fallback';
                fallbackEl.className = '';
                fallbackEl.innerHTML = svgIcon('user');
                container.appendChild(fallbackEl);
            }
            fallbackEl.setAttribute('aria-label', displayName);
            fallbackEl.title = displayName;
            return fallbackEl;
        };

        const ensureAvatarImg = () => {
            let imgEl = container.querySelector('[data-role="profile-avatar-img"]');
            if (!imgEl) {
                imgEl = document.createElement('img');
                imgEl.dataset.role = 'profile-avatar-img';
                imgEl.className = 'h-10 w-10 rounded-full object-cover flex-shrink-0';
                imgEl.loading = 'lazy';
                imgEl.decoding = 'async';
                container.appendChild(imgEl);
            }
            imgEl.alt = displayName;
            return imgEl;
        };

        const avatarUrl = resolveAvatarUrl(state.user, state.avatars_list);
        if (!avatarUrl) {
            const existingImg = container.querySelector('[data-role="profile-avatar-img"]');
            if (existingImg) existingImg.remove();
            ensureFallbackAvatar();
            return;
        }

        const existingFallback = container.querySelector('[data-role="profile-avatar-fallback"]');
        if (existingFallback) existingFallback.remove();

        const imgEl = ensureAvatarImg();
        imgEl.onerror = () => {
            imgEl.onerror = null;
            if (imgEl.parentNode) imgEl.remove();
            ensureFallbackAvatar();
        };
        if (imgEl.getAttribute('src') !== avatarUrl) imgEl.src = avatarUrl;
    }

    // Theme toggle stays in the header bar — no relocation needed.
    // function relocateThemeToggle() { ... } — removed
    ensureHeaderProfileContainer();
    renderHeaderUserProfile();

    // ===================================================================
    //  LIGHTBOX DE PRODUCTO (clic en la miniatura del resumen)
    // ===================================================================
    (function setupProductLightbox() {
        const lightbox = document.getElementById('product-lightbox');
        if (!lightbox) return;
        const lbImgWrap = document.getElementById('lightbox-img-wrap');
        const lbImg = document.getElementById('lightbox-img');
        const lbName = document.getElementById('lb-name');
        const lbPrice = document.getElementById('lb-price');
        const lbBase = document.getElementById('lb-base');
        const lbTypeWrap = document.getElementById('lb-type-wrap');
        const lbType = document.getElementById('lb-type');
        const lbVbWrap = document.getElementById('lb-vbucks-wrap');
        const lbVb = document.getElementById('lb-vbucks');
        const lbRecipWrap = document.getElementById('lb-recipient-wrap');
        const lbRecip = document.getElementById('lb-recipient');
        const closeBtn = document.getElementById('close-lightbox');

        function openLightbox(d) {
            if (lbImgWrap) lbImgWrap.setAttribute('style', d.grad || '');
            if (lbImg) {
                if (d.img) { lbImg.src = d.img; lbImg.alt = d.name || ''; lbImg.classList.remove('hidden'); }
                else { lbImg.classList.add('hidden'); }
            }
            if (lbName) lbName.textContent = d.name || 'Producto';
            if (lbTypeWrap) lbTypeWrap.classList.toggle('hidden', !d.tipo);
            if (lbType) lbType.textContent = d.tipo || '';
            const vb = parseInt(d.vbucks || '0', 10);
            if (lbVbWrap) lbVbWrap.classList.toggle('hidden', !(vb > 0));
            if (lbVb) lbVb.textContent = vb > 0 ? vb.toLocaleString('en-US') : '';
            if (lbPrice) lbPrice.textContent = 'S/ ' + (d.price || '0.00');
            const showBase = d.base && d.base !== d.price;
            if (lbBase) { lbBase.textContent = 'S/ ' + (d.base || ''); lbBase.classList.toggle('hidden', !showBase); }
            if (lbRecipWrap) lbRecipWrap.classList.toggle('hidden', !d.recipient);
            if (lbRecip) lbRecip.textContent = d.recipient || '';
            lightbox.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        function closeLightbox() {
            lightbox.classList.remove('active');
            document.body.style.overflow = '';
        }

        // Delegado: funciona para el resumen de escritorio y el espejo móvil
        document.addEventListener('click', (e) => {
            const thumb = e.target.closest('[data-lightbox]');
            if (thumb) { e.preventDefault(); openLightbox(thumb.dataset); }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && lightbox.classList.contains('active')) closeLightbox();
            const thumb = (e.key === 'Enter' || e.key === ' ') ? e.target.closest('[data-lightbox]') : null;
            if (thumb) { e.preventDefault(); openLightbox(thumb.dataset); }
        });
        if (closeBtn) closeBtn.addEventListener('click', closeLightbox);
        lightbox.addEventListener('click', (e) => { if (e.target === lightbox) closeLightbox(); });
    })();

    // ===================================================================
    //  CONFETTI LIGERO (cupón válido) — canvas propio, sin librerías
    // ===================================================================
    window.fireBananoConfetti = function fireBananoConfetti() {
        const canvas = document.getElementById('confetti-canvas');
        if (!canvas) return;
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        const ctx = canvas.getContext('2d');
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        const W = window.innerWidth, H = window.innerHeight;
        canvas.width = W * dpr; canvas.height = H * dpr;
        ctx.scale(dpr, dpr);
        canvas.classList.add('is-active');

        const colors = ['#FFD60A', '#FFC400', '#E8FF00', '#ffffff', '#FFE600'];
        const N = Math.min(110, Math.round(W / 9));
        const parts = [];
        for (let i = 0; i < N; i++) {
            parts.push({
                x: W / 2 + (Math.random() - 0.5) * 120,
                y: H * 0.34 + (Math.random() - 0.5) * 60,
                vx: (Math.random() - 0.5) * 11,
                vy: Math.random() * -11 - 4,
                size: Math.random() * 6 + 4,
                color: colors[(Math.random() * colors.length) | 0],
                rot: Math.random() * Math.PI,
                vr: (Math.random() - 0.5) * 0.3,
                life: 1
            });
        }
        const gravity = 0.32, drag = 0.992;
        const start = performance.now();
        const DURATION = 1500;
        function frame(now) {
            const elapsed = now - start;
            ctx.clearRect(0, 0, W, H);
            let alive = false;
            for (const p of parts) {
                p.vy += gravity; p.vx *= drag; p.vy *= drag;
                p.x += p.vx; p.y += p.vy; p.rot += p.vr;
                if (elapsed > DURATION - 500) p.life = Math.max(0, (DURATION - elapsed) / 500);
                if (p.y < H + 40 && p.life > 0) alive = true;
                ctx.save();
                ctx.globalAlpha = p.life;
                ctx.translate(p.x, p.y);
                ctx.rotate(p.rot);
                ctx.fillStyle = p.color;
                ctx.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * 0.6);
                ctx.restore();
            }
            if (alive && elapsed < DURATION + 200) {
                requestAnimationFrame(frame);
            } else {
                ctx.clearRect(0, 0, W, H);
                canvas.classList.remove('is-active');
            }
        }
        requestAnimationFrame(frame);
    };

    fetchRarityData().then(() => {
        render();
        document.body.dataset.initialRenderComplete = 'true';
        fetchCoinsPublicConfig();
        updateCheckoutState(false);
    });
});
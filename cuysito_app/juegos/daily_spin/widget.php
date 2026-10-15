<!-- 
    MODULO: DAILY SPIN WIDGET
    Ubicación: public_html/juegos/daily_spin/widget.php
    Descripción: Modal, estilos y scripts. Se incluye en el footer global.

    Los nodos [data-gh-slot] dentro de #ds-screen-surface son slots estables que
    rellena el módulo de vista public_html/views/juegos.view.js con la misma
    fuente de estado que la tarjeta exterior de Giro Diario (tarjeta y modal
    nunca divergen). DailySpin (daily_spin.js) sólo gobierna ruleta, historial
    y modal de premio.
-->

<!-- 1. Cargar Estilos del Módulo -->
<link rel="stylesheet" href="/juegos/daily_spin/daily_spin.css?v=3.6">

<!-- 2. Estructura del Modal (Oculto por defecto) -->
<div id="ds-modal-overlay" class="backdrop-layer ds-hidden" role="dialog" aria-modal="true" aria-labelledby="ds-title" aria-describedby="ds-desc">
    <div class="ds-modal-container">
        <div class="ds-screen-surface" id="ds-screen-surface" data-state="loading" aria-busy="true">

            <!-- Partículas decorativas (generadas por JS) -->
            <div class="ds-particles" id="ds-particles"></div>

            <!-- Botón Cerrar -->
            <button id="ds-close-btn" onclick="DailySpin.closeModal()" title="Cerrar">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round">
                    <line x1="2" y1="2" x2="14" y2="14" />
                    <line x1="14" y1="2" x2="2" y2="14" />
                </svg>
            </button>

            <!-- Cabecera -->
            <div class="ds-header">
                <h2 id="ds-title">GIRO DIARIO</h2>
                <p id="ds-desc" class="ds-context" data-gh-slot="desc"></p>
            </div>

            <!-- BARRA DE FILTROS: selector por categoría + indicador de descuento activo -->
            <div class="ds-reel-bar" data-gh-slot="reel-bar">
                <div class="ds-filter" data-gh-slot="filter">
                    <button type="button" class="ds-chip ds-filter__btn" data-gh-slot="filter-btn" data-action="toggle-filters"
                        aria-haspopup="listbox" aria-expanded="false" aria-controls="ds-filter-menu" disabled>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="4" y1="6" x2="20" y2="6" />
                            <line x1="7" y1="12" x2="17" y2="12" />
                            <line x1="10" y1="18" x2="14" y2="18" />
                        </svg>
                        <span class="ds-filter__label" data-gh-slot="filter-label">Filtros</span>
                        <svg class="ds-filter__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M6 9l6 6 6-6" />
                        </svg>
                    </button>
                    <!-- Opciones: las genera juegos.view.js a partir de los filtros activos en admin -->
                    <div id="ds-filter-menu" class="ds-filter__menu" data-gh-slot="filter-menu" role="listbox" aria-label="Filtrar premios por categoría" hidden></div>
                </div>
                <p class="ds-discount" data-gh-slot="discount" aria-live="polite">
                    <span class="ds-discount__label" data-gh-slot="discount-label">Descuento</span>
                    <span class="ds-discount__value" data-gh-slot="discount-value">—</span>
                </p>
            </div>

            <!-- ZONA DE LA RULETA (La Cinta) -->
            <div class="ds-reel-viewport">
                <!-- La línea central que marca al ganador -->
                <div class="ds-reel-marker"></div>

                <!-- La cinta que se mueve (JS insertará los ítems aquí) -->
                <div id="ds-tape" class="ds-tape">
                    <!-- Items generados por JS -->
                </div>
            </div>

            <!-- CONTROLES (slots dinámicos: nota, CTA, cuenta atrás) -->
            <div class="ds-controls">
                <p class="ds-note" data-gh-slot="note" aria-live="polite"></p>
                <button type="button" class="ds-btn-primary" data-gh-slot="cta" data-tone="primary" disabled></button>
                <p class="ds-meta" data-gh-slot="meta">
                    <span class="ds-meta__label" data-gh-slot="meta-label">Giro gratis en</span>
                    <span class="ds-meta__value" data-gh-slot="meta-value">--:--:--</span>
                </p>
            </div>

            <!-- OFERTA FLASH DEL DÍA (visible sólo cuando está activa) -->
            <div class="ds-flash" data-gh-slot="flash" hidden>
                <span class="ds-flash__text" data-gh-slot="flash-text"></span>
                <progress class="ds-flash__bar" data-gh-slot="flash-progress" max="100" value="0"></progress>
            </div>

            <!-- PIE (saldo, bonus, historial) -->
            <div class="ds-footer-info">
                <dl class="ds-stats">
                    <div class="ds-stat">
                        <dt>Bananos</dt>
                        <dd data-gh-slot="coins">—</dd>
                    </div>
                    <div class="ds-stat">
                        <dt>Giros bonus</dt>
                        <dd data-gh-slot="bonus">—</dd>
                    </div>
                </dl>
                <!-- Disparador de historial (SOLO MÓVIL) -->
                <button type="button" class="ds-history-mobile" onclick="DailySpin.openHistory()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 3v5h5" />
                        <path d="M3.05 13A9 9 0 1 0 6 5.3L3 8" />
                        <path d="M12 7v5l3 2" />
                    </svg>
                    <span>Ver historial</span>
                </button>
                <!-- Selector TODO / Sin Pistas (esquina inferior derecha) -->
                <div class="ds-mode" role="group" aria-label="Incluir pistas de improvisación" data-gh-slot="mode">
                    <button type="button" class="ds-mode__opt" data-gh-slot="mode-all" data-action="set-mode" data-mode="all" aria-pressed="true" disabled>Todo</button>
                    <button type="button" class="ds-mode__opt" data-gh-slot="mode-no-tracks" data-action="set-mode" data-mode="no_tracks" aria-pressed="false" disabled>Sin Pistas</button>
                </div>
            </div>

            <!-- Disparador de historial (SOLO ESCRITORIO) -->
            <button type="button" id="ds-history-btn" class="ds-history-btn" onclick="DailySpin.openHistory()" aria-label="Ver historial">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 3v5h5" />
                    <path d="M3.05 13A9 9 0 1 0 6 5.3L3 8" />
                    <path d="M12 7v5l3 2" />
                </svg>
                <span class="ds-history-tip">Ver historial</span>
            </button>

        </div>

        <!-- PANEL DE HISTORIAL (se desliza sobre el modal) -->
        <div id="ds-history-panel" class="ds-history-panel ds-hidden">
            <div class="ds-history-head">
                <button type="button" class="ds-history-back" onclick="DailySpin.closeHistory()" aria-label="Volver">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 12H5" />
                        <path d="M12 19l-7-7 7-7" />
                    </svg>
                </button>
                <h3>Historial de Giros</h3>
            </div>

            <div class="ds-history-scroll">
                <div class="ds-history-loading" id="ds-history-loading">
                    <span class="ds-history-spinner"></span>
                </div>
                <div class="ds-history-list" id="ds-history-list"></div>
                <div class="ds-history-empty ds-hidden" id="ds-history-empty">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 3v5h5" />
                        <path d="M3.05 13A9 9 0 1 0 6 5.3L3 8" />
                        <path d="M12 7v5l3 2" />
                    </svg>
                    <p>Todavía no tienes giros.<br>¡Gira la ruleta para empezar tu historial!</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 2b. Aviso al elegir el filtro "Lotes" (misma estética que el modal de premio) -->
<div id="ds-warn-modal" role="alertdialog" aria-modal="true" aria-labelledby="ds-warn-title" aria-describedby="ds-warn-text" hidden>
    <div class="ds-win-stage">
        <div class="ds-win-container ds-warn-container">
            <span class="ds-warn-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                    <line x1="12" y1="9" x2="12" y2="13" />
                    <line x1="12" y1="17" x2="12.01" y2="17" />
                </svg>
            </span>
            <h3 id="ds-warn-title">Filtro: Lotes</h3>
            <p id="ds-warn-text" class="ds-warn-text" data-gh-slot="warn-text">Algunos atuendos o vehículos están catalogados como Lote</p>
            <p class="ds-warn-hint" data-gh-slot="warn-hint">El premio puede ser un paquete completo. Revisa el contenido antes de comprar.</p>
            <div class="ds-warn-actions">
                <button type="button" class="ds-btn-primary" data-action="confirm-filter" data-gh-slot="warn-confirm">Entendido, usar Lotes</button>
                <button type="button" class="ds-btn-secondary" data-action="cancel-filter">Cancelar</button>
            </div>
        </div>
    </div>
</div>

<!-- 2c. Confirmación antes de un giro que consume bananos (texto/importe: juegos.view.js) -->
<div id="ds-confirm-modal" role="alertdialog" aria-modal="true" aria-labelledby="ds-confirm-title" aria-describedby="ds-confirm-text" hidden>
    <div class="ds-win-stage">
        <div class="ds-win-container ds-warn-container">
            <span class="ds-warn-icon ds-warn-icon--coins" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="9" />
                    <path d="M12 7v10" />
                    <path d="M15 9.5c0-1.1-1.3-2-3-2s-3 .9-3 2 1.3 2 3 2 3 .9 3 2-1.3 2-3 2-3-.9-3-2" />
                </svg>
            </span>
            <h3 id="ds-confirm-title">Giro extra</h3>
            <p id="ds-confirm-text" class="ds-warn-text" data-gh-slot="confirm-text">¿Deseas gastar bananos para realizar un giro extra?</p>
            <p class="ds-warn-hint" data-gh-slot="confirm-hint"></p>
            <div class="ds-warn-actions">
                <button type="button" class="ds-btn-primary" data-action="confirm-spin" data-gh-slot="confirm-spin">Sí, girar</button>
                <button type="button" class="ds-btn-secondary" data-action="cancel-spin">Cancelar</button>
            </div>
        </div>
    </div>
</div>

<!-- 3. Modal de "GANASTE" (Overlay secundario) -->
<div id="ds-win-modal" class="ds-hidden" role="dialog" aria-modal="true" aria-labelledby="ds-win-title" aria-describedby="ds-win-item-name">
    <div class="ds-win-stage">
        <div class="ds-win-container">
            <!-- Confetti container (generado por JS) -->
            <div class="ds-particles" id="ds-win-particles"></div>
            <!-- Compartir premio (reutiliza estilos de .item-page__share) -->
            <button type="button" id="ds-win-share" class="item-page__share ds-win__share" onclick="DailySpin.sharePrize()" aria-label="Compartir premio">
                <svg class="svg-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path fill-rule="evenodd" clip-rule="evenodd" fill="currentColor" d="M19 6C19 7.65685 17.6569 9 16 9C15.2739 9 14.608 8.74203 14.0891 8.31275L8.94039 11.402C8.97948 11.5952 9 11.7952 9 12C9 12.2048 8.97948 12.4048 8.94039 12.598L14.0891 15.6872C14.608 15.258 15.2739 15 16 15C17.6569 15 19 16.3431 19 18C19 19.6569 17.6569 21 16 21C14.3431 21 13 19.6569 13 18C13 17.7952 13.0205 17.5952 13.0596 17.402L7.91093 14.3128C7.39197 14.742 6.72611 15 6 15C4.34315 15 3 13.6569 3 12C3 10.3431 4.34315 9 6 9C6.72611 9 7.39197 9.25797 7.91093 9.68725L13.0596 6.59804C13.0205 6.40478 13 6.20479 13 6C13 4.34315 14.3431 3 16 3C17.6569 3 19 4.34315 19 6Z" />
                </svg>
            </button>
            <h3 id="ds-win-title">¡FELICIDADES!</h3>
            <!-- Item name as header -->
            <h4 id="ds-win-item-name">Nombre del Item</h4>
            <!-- Card assembly (badge pinned inside by JS) -->
            <div id="ds-win-item-img"></div>
            <!-- Pricing breakdown -->
            <div id="ds-win-pricing" class="ds-win-pricing"></div>
            <!-- Vigencia del cupón: 24 h desde el giro o, si el objeto sale antes de la tienda, aviso (daily_spin.js) -->
            <div id="ds-win-expiry" class="ds-win-expiry" role="status" hidden>
                <svg class="ds-win-expiry__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" />
                    <path d="M12 7v5l3 2" />
                </svg>
                <span id="ds-win-expiry-text"></span>
            </div>
            <!-- Footer -->
            <p>Tu descuento ha sido guardado en tu cuenta.</p>
            <button type="button" id="ds-win-accept" class="ds-btn-secondary" onclick="DailySpin.closeWin()">Aceptar</button>
        </div>
    </div>
</div>

<!-- 4. Cargar Lógica del Módulo -->
<script src="/juegos/daily_spin/spin_audio.js?v=1.0"></script>
<script src="/juegos/daily_spin/prize_share.js?v=1.0"></script>
<script src="/juegos/daily_spin/daily_spin.js?v=3.7"></script>
<?php
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../../api/session_security.php';

$hubUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$hubLoggedIn = $hubUserId > 0
    && isset($_SESSION['logged_in_client'])
    && $_SESSION['logged_in_client'] === true;
$hubConfig = [
    'loggedIn' => $hubLoggedIn,
    'userId' => $hubLoggedIn ? $hubUserId : 0,
    'loginUrl' => '/login?redirect_to=/juegos/sorteo_flash/hub',
    'endpoint' => '/web.php',
    'voteAction' => 'v7s',
    'raffleStatusAction' => 'u2h',
    'roomUrl' => '/juegos/sorteo_flash/',
    'transparencyUrl' => '/juegos/sorteo_flash/transparencia',
];
$hubCssVersion = is_file(__DIR__ . '/hub.css') ? filemtime(__DIR__ . '/hub.css') : time();
$hubJsVersion = is_file(__DIR__ . '/hub.js') ? filemtime(__DIR__ . '/hub.js') : time();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#fff8ff">
    <title>Hub de Sorteo Flash · Banano Store</title>
    <meta name="description" content="Propón combos de la tienda Fortnite, vota por tus favoritos y celebra a la comunidad del Sorteo Flash.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/juegos/sorteo_flash/hub.css?v=<?= (int)$hubCssVersion ?>">
</head>
<body>
    <div class="hub-page" id="hub-root">
        <header class="hub-topbar">
            <a class="hub-brand" href="/juegos" aria-label="Volver a juegos">
                <span class="hub-brand__fruit" aria-hidden="true">🍌</span>
                <span>Banano Store <small>· Comunidad</small></span>
            </a>
            <a class="hub-topbar__link" href="/juegos/sorteo_flash/transparencia">¿Cómo funciona?</a>
        </header>

        <main class="hub-main">
            <section class="hub-intro" aria-labelledby="hub-title">
                <p class="hub-kicker">✨ La comunidad elige ✨</p>
                <h1 id="hub-title">Hub de <span>Sorteo Flash</span></h1>
                <p>Propón premios de la tienda, gana Bananos y vota por el próximo combo ganador.</p>
            </section>

            <section class="hub-hero-grid" aria-label="Estado del sorteo y bóveda">
                <article class="hub-card hub-hero" id="hub-hero" data-state="loading" aria-live="polite">
                    <div class="hub-hero__sparkle" aria-hidden="true">✦</div>
                    <span class="hub-eyebrow" id="hero-badge">Revisando la comunidad…</span>
                    <h2 id="hero-title">¡Hola, comunidad!</h2>
                    <p id="hero-copy">Estamos mirando si hoy hay una sorpresa flash.</p>
                    <div class="hub-countdown" id="hero-countdown" hidden>
                        <span id="countdown-label">Próximo domingo</span>
                        <strong id="countdown-value" role="timer">--:--:--</strong>
                    </div>
                    <a class="hub-button hub-button--white" id="hero-room-link" href="/juegos/sorteo_flash/" hidden>
                        Entrar a la sala <span aria-hidden="true">→</span>
                    </a>
                </article>

                <article class="hub-card hub-vault">
                    <div class="hub-vault__art" aria-label="Bóveda de Bananos">
                        <svg class="hub-vault__svg" viewBox="0 0 260 180" role="img" aria-labelledby="vault-title">
                            <title id="vault-title">Una bóveda feliz llena de Bananos</title>
                            <defs>
                                <linearGradient id="vaultBody" x1="0" x2="1" y1="0" y2="1">
                                    <stop offset="0" stop-color="#fff3a4"/>
                                    <stop offset="1" stop-color="#ffbd52"/>
                                </linearGradient>
                                <linearGradient id="vaultDoor" x1="0" x2="1" y1="0" y2="1">
                                    <stop offset="0" stop-color="#fffdf0"/>
                                    <stop offset="1" stop-color="#ffe4a3"/>
                                </linearGradient>
                            </defs>
                            <ellipse cx="130" cy="160" rx="92" ry="12" fill="#d8b5e7" opacity=".45"/>
                            <rect x="38" y="34" width="184" height="120" rx="34" fill="url(#vaultBody)" stroke="#e9a43f" stroke-width="6"/>
                            <rect x="70" y="52" width="120" height="82" rx="27" fill="url(#vaultDoor)" stroke="#e9a43f" stroke-width="5"/>
                            <circle cx="130" cy="93" r="24" fill="#ffcb66" stroke="#e9a43f" stroke-width="5"/>
                            <circle cx="130" cy="93" r="8" fill="#fff7d0"/>
                            <path d="M130 69v48M106 93h48M113 76l34 34M147 76l-34 34" stroke="#e9a43f" stroke-width="4" stroke-linecap="round"/>
                            <path d="M101 37q29-25 58 0" fill="none" stroke="#e9a43f" stroke-width="7" stroke-linecap="round"/>
                            <circle cx="83" cy="119" r="4" fill="#8b5a3c"/>
                            <circle cx="177" cy="119" r="4" fill="#8b5a3c"/>
                            <path d="M117 124q13 11 26 0" fill="none" stroke="#d97745" stroke-width="4" stroke-linecap="round"/>
                            <path d="M49 67h-9M220 67h-9M55 137h-9M214 137h-9" stroke="#fff" stroke-width="6" stroke-linecap="round"/>
                        </svg>
                        <img class="hub-vault__coin hub-vault__coin--one" src="/assets/img/moneda.webp" alt="" width="36" height="36" loading="eager">
                        <img class="hub-vault__coin hub-vault__coin--two" src="/assets/img/moneda.webp" alt="" width="30" height="30" loading="eager">
                        <img class="hub-vault__coin hub-vault__coin--three" src="/assets/img/moneda.webp" alt="" width="26" height="26" loading="eager">
                    </div>
                    <div class="hub-vault__copy">
                        <span class="hub-eyebrow">Nuestra bóveda</span>
                        <h2>La suerte se comparte</h2>
                        <p>Cada propuesta hace crecer la fiesta de la comunidad.</p>
                    </div>
                    <div class="hub-summary">
                        <div class="hub-summary__pill">
                            <span aria-hidden="true">🏆</span>
                            <strong id="summary-bananas">0</strong>
                            <span>Bananos entregados</span>
                        </div>
                        <div class="hub-summary__pill">
                            <span aria-hidden="true">🔥</span>
                            <strong id="summary-raffles">0</strong>
                            <span>Sorteos realizados</span>
                        </div>
                    </div>
                </article>
            </section>

            <section class="hub-card hub-last-winner" id="last-winner" hidden aria-labelledby="last-winner-title">
                <div class="hub-section-heading">
                    <div>
                        <p class="hub-eyebrow">✨ El último abrazo de la suerte</p>
                        <h2 id="last-winner-title">Último sorteo</h2>
                    </div>
                    <span class="hub-winner__raffle" id="last-raffle-meta"></span>
                </div>
                <div id="last-winner-content"></div>
                <div class="hub-fomo" id="ticket-fomo" hidden></div>
            </section>

            <section class="hub-card hub-proposals" aria-labelledby="proposals-title">
                <div class="hub-section-heading hub-section-heading--wrap">
                    <div>
                        <p class="hub-eyebrow">🧩 ¡Tu idea puede ser el próximo premio!</p>
                        <h2 id="proposals-title">Creador de combos + votación</h2>
                        <p class="hub-muted">Cada artículo = 1 premio. ¿3 gestos de 500? ¡Serán 3 ganadores!</p>
                    </div>
                    <div class="hub-proposal-meta">
                        <span class="hub-chip" id="proposal-spots">Cargando cupos…</span>
                        <span class="hub-chip hub-chip--banana" id="proposal-reward">Recompensa: …</span>
                    </div>
                </div>
                <div class="hub-creator-callout" id="creator-callout"></div>
                <div class="hub-proposal-list" id="proposal-list" aria-live="polite">
                    <div class="hub-skeleton hub-skeleton--proposal"></div>
                    <div class="hub-skeleton hub-skeleton--proposal"></div>
                </div>
            </section>

            <section class="hub-card hub-hall" aria-labelledby="hall-title">
                <div class="hub-section-heading">
                    <div>
                        <p class="hub-eyebrow">🏆 Historias que hacen comunidad</p>
                        <h2 id="hall-title">Salón de la Fama</h2>
                    </div>
                    <span class="hub-updated" id="stats-updated">Actualizando estadísticas…</span>
                </div>
                <div class="hub-hall__compact" id="hall-compact"></div>
                <button class="hub-button hub-button--primary hub-hall__open" type="button" data-action="open-hall">
                    🏆 Ver Salón de Trofeos
                </button>
                <div class="hub-hall__full" id="hall-full"></div>
            </section>

            <div id="meme-slot"></div>

            <section class="hub-transparency">
                <p>Las reglas claras también son parte de la diversión.</p>
                <a class="hub-button hub-button--soft" href="/juegos/sorteo_flash/transparencia">
                    ¿Cómo garantizamos un sorteo 100% justo? <span aria-hidden="true">→</span>
                </a>
            </section>
        </main>

        <dialog class="hub-dialog hub-combo-dialog" id="combo-dialog" aria-labelledby="combo-title">
            <div class="hub-dialog__inner">
                <header class="hub-dialog__header">
                    <div>
                        <p class="hub-eyebrow">🛍️ La tienda de hoy</p>
                        <h2 id="combo-title">Arma tu combo</h2>
                        <p class="hub-muted">Elige entre 1 y 12 artículos. ¡Puedes repetirlos!</p>
                    </div>
                    <button class="hub-icon-button" type="button" data-action="close-combo" aria-label="Cerrar creador">×</button>
                </header>
                <div class="hub-modal-sticky">
                    <div class="hub-slots-heading">
                        <strong>Tus premios</strong>
                        <span id="slot-count">0 artículos</span>
                    </div>
                    <div class="hub-slots" id="combo-slots" aria-label="Ranuras del combo"></div>
                    <div class="hub-meter">
                        <div class="hub-meter__copy">
                            <strong id="combo-total">Total: 0 / 0 PaVos</strong>
                            <span id="combo-meter-help">Agrega artículos para empezar.</span>
                        </div>
                        <div class="hub-meter__track" id="combo-meter-track" role="progressbar" aria-label="Uso del límite de PaVos" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                            <span id="combo-meter-fill"></span>
                        </div>
                    </div>
                </div>
                <div class="hub-catalog-tools">
                    <label class="hub-search">
                        <span aria-hidden="true">🔎</span>
                        <span class="sr-only">Buscar artículos por nombre</span>
                        <input type="search" id="catalog-search" placeholder="Buscar en la tienda…" autocomplete="off">
                    </label>
                    <div class="hub-category-chips" id="catalog-chips" aria-label="Filtrar por sección"></div>
                </div>
                <div class="hub-catalog-grid" id="catalog-grid" aria-live="polite">
                    <div class="hub-skeleton hub-skeleton--tile"></div>
                    <div class="hub-skeleton hub-skeleton--tile"></div>
                    <div class="hub-skeleton hub-skeleton--tile"></div>
                    <div class="hub-skeleton hub-skeleton--tile"></div>
                </div>
                <footer class="hub-dialog__footer">
                    <span id="combo-submit-hint">Tus artículos aparecerán arriba.</span>
                    <button class="hub-button hub-button--primary" id="combo-submit" type="button" data-action="submit-proposal" disabled>
                        Enviar propuesta
                    </button>
                </footer>
            </div>
        </dialog>

        <dialog class="hub-dialog hub-hall-dialog" id="hall-dialog" aria-labelledby="hall-dialog-title">
            <div class="hub-dialog__inner">
                <header class="hub-dialog__header">
                    <div>
                        <p class="hub-eyebrow">🏆 Leyendas de la comunidad</p>
                        <h2 id="hall-dialog-title">Salón de Trofeos</h2>
                    </div>
                    <button class="hub-icon-button" type="button" data-action="close-hall" aria-label="Cerrar salón">×</button>
                </header>
                <div id="hall-drawer-content"></div>
            </div>
        </dialog>

        <dialog class="hub-dialog hub-confirm-dialog" id="confirm-dialog" aria-labelledby="confirm-title">
            <div class="hub-confirm">
                <span class="hub-confirm__emoji" aria-hidden="true">🌼</span>
                <h2 id="confirm-title">¿Cerrar el creador?</h2>
                <p id="confirm-copy">Tu selección todavía no se ha enviado.</p>
                <div class="hub-confirm__actions">
                    <button class="hub-button hub-button--soft" type="button" data-action="cancel-confirm">Seguir creando</button>
                    <button class="hub-button hub-button--primary" type="button" data-action="confirm-close">Cerrar</button>
                </div>
            </div>
        </dialog>

        <div class="hub-toasts" id="hub-toasts" aria-live="polite" aria-atomic="false"></div>
    </div>
    <script>
        window.SORTEO_HUB_CONFIG = <?= json_encode($hubConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <script src="/juegos/sorteo_flash/hub.js?v=<?= (int)$hubJsVersion ?>" defer></script>
</body>
</html>

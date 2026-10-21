<?php
require_once __DIR__ . '/../../api/session_security.php';

$hubEmbedded = defined('SORTEO_FLASH_HUB_EMBEDDED') && SORTEO_FLASH_HUB_EMBEDDED;
if (!$hubEmbedded) {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        require_once __DIR__ . '/../../wabo/core/db_connect.php';
    }
    try {
        $activeResult = $conn->query("SELECT raffle_id FROM raffles WHERE status IN ('open', 'scheduled', 'locking', 'processing', 'waiting_for_winner') LIMIT 1");
        if ($activeResult && $activeResult->num_rows > 0) {
            header('Location: /juegos/sorteo_flash/', true, 302);
            exit;
        }
    } catch (Throwable $e) {
    }
}

header('Content-Type: text/html; charset=UTF-8');

$hubUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$hubLoggedIn = $hubUserId > 0
    && isset($_SESSION['logged_in_client'])
    && $_SESSION['logged_in_client'] === true;
$hubConfig = [
    'loggedIn' => $hubLoggedIn,
    'userId' => $hubLoggedIn ? $hubUserId : 0,
    'loginUrl' => '/login?redirect_to=/juegos/sorteo_flash',
    'endpoint' => '/web.php',
    'voteAction' => 'v7s',
    'raffleStatusAction' => 'u2h',
    'roomUrl' => '/juegos/sorteo_flash/',
    'transparencyUrl' => '/juegos/sorteo_flash/transparencia',
    'sponsorUrl' => 'https://wa.me/51924989167?text=' . rawurlencode('Hola, quiero patrocinar un Sorteo Flash 👑'),
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
    <link rel="preload" as="font" type="font/woff2" href="/assets/fonts/HeadingNowVariable.woff2" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/juegos/sorteo_flash/hub.css?v=<?= (int)$hubCssVersion ?>">
</head>
<body>
    <svg class="hub-icon-sprite" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <symbol id="icon-trophy" viewBox="0 0 24 24"><path d="M8 21h8m-4-4v4M7 4h10v5a5 5 0 0 1-10 0V4Z"/><path d="M7 6H4v2a4 4 0 0 0 4 4m9-6h3v2a4 4 0 0 1-4 4M9 4V2h6v2"/></symbol>
        <symbol id="icon-crown" viewBox="0 0 24 24"><path d="m3 7 5 4 4-7 4 7 5-4-2 12H5L3 7Z"/><path d="M5 16h14M3 7h.01M12 4h.01M21 7h.01"/></symbol>
        <symbol id="icon-medal" viewBox="0 0 24 24"><circle cx="12" cy="14" r="6"/><path d="m8 3 4 5 4-5M8 3H5l2 7m9-7h3l-2 7"/><path d="m12 11 1 2 2 .3-1.5 1.4.4 2-1.9-1-1.9 1 .4-2L9 13.3l2-.3 1-2Z"/></symbol>
        <symbol id="icon-fire" viewBox="0 0 24 24"><path d="M12 22a7 7 0 0 0 7-7c0-4-3-6-4-11-2 2-3 4-3 6-2-1-3-3-3-5-3 3-5 6-5 10a8 8 0 0 0 8 7Z"/><path d="M12 22a4 4 0 0 0 4-4c0-2-1-3-2-5-1 2-2 3-3 3-1-1-1-2-1-3-2 2-2 3-2 5a4 4 0 0 0 4 4Z"/></symbol>
        <symbol id="icon-heart-broken" viewBox="0 0 24 24"><path d="M20.8 8.7c0-3-2.2-5.2-5-5.2-1.7 0-3 .8-3.8 2.2C11.2 4.3 9.8 3.5 8.2 3.5c-2.8 0-5 2.2-5 5.2 0 4.4 5.4 8.4 8 11.1l-1-5.2 3.5-2.5-1-3.1 3.2 2.2-1.1 3.1 2-1.5c1.2-1.3 2-2.7 2-4.1Z"/></symbol>
        <symbol id="icon-dice" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="M8 8h.01M16 8h.01M12 12h.01M8 16h.01M16 16h.01"/></symbol>
        <symbol id="icon-coin" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M15 8.5c-.7-.7-1.6-1-2.8-1-1.5 0-2.6.7-2.6 1.8 0 2.8 5.3 1.2 5.3 4 0 1.2-1.1 2-2.7 2-1.2 0-2.3-.4-3-1.2M12 5.5v13"/></symbol>
        <symbol id="icon-gift" viewBox="0 0 24 24"><path d="M3 10h18v11H3zM2 7h20v4H2zM12 7v14"/><path d="M12 7H8.5a2.5 2.5 0 1 1 2.2-3.7L12 7Zm0 0h3.5a2.5 2.5 0 1 0-2.2-3.7L12 7Z"/></symbol>
        <symbol id="icon-users" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2m6-9a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm6-7a4 4 0 0 1 0 7m2 3a4 4 0 0 1 2 4v2"/></symbol>
        <symbol id="icon-ticket" viewBox="0 0 24 24"><path d="M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3a2 2 0 0 0 0-4V7Z"/><path d="M13 5v2m0 4v2m0 4v2"/></symbol>
        <symbol id="icon-shield" viewBox="0 0 24 24"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="m9 12 2 2 4-4"/></symbol>
        <symbol id="icon-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
        <symbol id="icon-users-round" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3 20a6 6 0 0 1 12 0m1-4a5 5 0 0 1 5 4"/></symbol>
        <symbol id="icon-arrow" viewBox="0 0 24 24"><path d="M5 12h14m-6-6 6 6-6 6"/></symbol>
        <symbol id="icon-search" viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></symbol>
        <symbol id="icon-eye" viewBox="0 0 24 24"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Z"/><circle cx="12" cy="12" r="3"/></symbol>
        <symbol id="icon-chevron" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></symbol>
        <symbol id="icon-history" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/></symbol>
        <symbol id="icon-chart" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="M7 15v2m4-6v6m4-9v9m4-12v12"/></symbol>
        <symbol id="icon-calendar" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4m8-4v4"/></symbol>
        <symbol id="icon-sparkle" viewBox="0 0 24 24"><path d="m12 3 1.5 6.5L20 12l-6.5 1.5L12 20l-1.5-6.5L4 12l6.5-2.5L12 3Z"/><path d="m19 3 .7 2.3L22 6l-2.3.7L19 9l-.7-2.3L16 6l2.3-.7L19 3Z"/></symbol>
    </svg>

    <div class="hub-page" id="hub-root">
        <main class="hub-main">
            <header class="hub-masthead">
                <h1 id="hub-title" class="hub-title">SORTEO FLASH</h1>
                <p class="hub-subtitle">Sorteo semanal de los domingos para clientes de la comunidad.</p>
                <div class="hub-hero-status" id="hub-hero" data-state="loading">
                    <div class="hub-hero-status__copy">
                        <span class="hub-live-dot" aria-hidden="true"></span>
                        <span id="hero-badge" aria-live="polite">Cuenta atrás</span>
                        <span id="hero-copy" aria-live="polite">Próximo sorteo: domingo a las 00:00, hora de Lima.</span>
                    </div>
                    <div class="hub-scoreboard" id="hero-countdown" role="timer" aria-live="off" aria-label="Tiempo para el próximo domingo">
                        <div class="hub-scoreboard__block"><strong id="hero-days" aria-label="Días">00</strong><span>DÍAS</span></div>
                        <div class="hub-scoreboard__block"><strong id="hero-hours" aria-label="Horas">00</strong><span>HORAS</span></div>
                        <div class="hub-scoreboard__block"><strong id="hero-min" aria-label="Minutos">00</strong><span>MIN</span></div>
                        <div class="hub-scoreboard__block"><strong id="hero-sec" aria-label="Segundos">00</strong><span>SEG</span></div>
                    </div>
                </div>
            </header>

            <section class="hub-well-grid" aria-label="El pozo y el último sorteo">
                <article class="hub-card hub-well hub-reveal" id="hub-vault">
                    <div class="hub-well__count">
                        <span class="hub-well__label"><svg class="hub-icon" aria-hidden="true"><use href="#icon-coin"/></svg> Bananos entregados</span>
                        <div class="hub-odometer" id="summary-bananas" aria-label="0 Bananos"><span class="hub-skeleton hub-skeleton--number"></span></div>
                    </div>
                    <div class="hub-vault__scene" role="img" aria-label="Una bóveda de Bananos que se llena con premios">
                        <svg class="hub-vault__svg" viewBox="0 0 380 250" aria-hidden="true">
                            <defs>
                                <linearGradient id="vault-glass" x1="0" y1="0" x2="1" y2="1">
                                    <stop offset="0" stop-color="#fff" stop-opacity=".86"/>
                                    <stop offset=".48" stop-color="#fbd9ff" stop-opacity=".48"/>
                                    <stop offset="1" stop-color="#f7c96e" stop-opacity=".5"/>
                                </linearGradient>
                                <linearGradient id="vault-metal" x1="0" y1="0" x2="1" y2="1">
                                    <stop offset="0" stop-color="#fff4c3"/>
                                    <stop offset="1" stop-color="#f5bb55"/>
                                </linearGradient>
                                <clipPath id="vault-body-clip">
                                    <path d="M67 90c-12-14-12-35-2-50l26 16c20-18 57-27 98-27s78 9 98 27l26-16c10 15 10 36-2 50 8 13 12 28 12 44 0 47-47 83-134 83S55 181 55 134c0-16 4-31 12-44Z"/>
                                </clipPath>
                            </defs>
                            <ellipse cx="190" cy="224" rx="137" ry="16" fill="#d9c2ed" opacity=".55"/>
                            <g class="hub-vault__pile" clip-path="url(#vault-body-clip)">
                                <rect class="hub-vault__fill" id="vault-fill" x="48" y="72" width="284" height="155" rx="56"/>
                                <path d="M52 177q32-25 64 0t64 0 64 0 64 0v57H52Z" fill="#f1ad32" opacity=".5"/>
                                <g class="hub-vault__pile-coins" opacity=".85">
                                    <image href="/assets/img/moneda.webp" x="82" y="153" width="35" height="35"/>
                                    <image href="/assets/img/moneda.webp" x="110" y="163" width="35" height="35"/>
                                    <image href="/assets/img/moneda.webp" x="142" y="150" width="38" height="38"/>
                                    <image href="/assets/img/moneda.webp" x="174" y="164" width="35" height="35"/>
                                    <image href="/assets/img/moneda.webp" x="206" y="148" width="38" height="38"/>
                                    <image href="/assets/img/moneda.webp" x="238" y="161" width="36" height="36"/>
                                    <image href="/assets/img/moneda.webp" x="269" y="150" width="36" height="36"/>
                                </g>
                            </g>
                            <path d="M67 90c-12-14-12-35-2-50l26 16c20-18 57-27 98-27s78 9 98 27l26-16c10 15 10 36-2 50 8 13 12 28 12 44 0 47-47 83-134 83S55 181 55 134c0-16 4-31 12-44Z" fill="url(#vault-glass)" stroke="#e9a7cf" stroke-width="7"/>
                            <path d="M91 74q31-27 78-28" fill="none" stroke="#fff" stroke-width="8" stroke-linecap="round" opacity=".8"/>
                            <path d="M72 119c-20 3-25 23-7 34 12 8 23 3 31-6" fill="#fff" fill-opacity=".58" stroke="#e9a7cf" stroke-width="6"/>
                            <circle cx="145" cy="122" r="5" fill="#725473"/>
                            <circle cx="235" cy="122" r="5" fill="#725473"/>
                            <path d="M169 145q21 19 42 0" fill="none" stroke="#bf6a89" stroke-width="5" stroke-linecap="round"/>
                            <circle cx="190" cy="94" r="28" fill="url(#vault-metal)" stroke="#df9a35" stroke-width="5"/>
                            <circle cx="190" cy="94" r="8" fill="#fff9df"/>
                            <path d="M190 68v52m-26-26h52m-44-18 36 36m0-36-36 36" stroke="#dd9a34" stroke-width="4" stroke-linecap="round"/>
                            <path d="M103 201v15m174-15v15" stroke="#e7a3be" stroke-width="14" stroke-linecap="round"/>
                        </svg>
                        <div class="hub-vault__rain" id="vault-rain" aria-hidden="true">
                            <?php for ($i = 0; $i < 9; $i++): ?>
                                <img src="/assets/img/moneda.webp" alt="" width="28" height="28" loading="lazy" style="--coin-index: <?= $i ?>">
                            <?php endfor; ?>
                        </div>
                        <span class="hub-vault__sparkle hub-vault__sparkle--one" aria-hidden="true">✦</span>
                        <span class="hub-vault__sparkle hub-vault__sparkle--two" aria-hidden="true">✧</span>
                    </div>
                    <div class="hub-summary" aria-label="Estadísticas del pozo">
                        <div class="hub-summary__pill hub-summary__pill--soles" id="summary-soles" hidden></div>
                        <div class="hub-summary__pill"><svg class="hub-icon" aria-hidden="true"><use href="#icon-trophy"/></svg><span><strong id="summary-raffles">0</strong> sorteos realizados</span></div>
                    </div>
                </article>

                <section class="hub-card hub-last-winner hub-reveal" id="last-winner" hidden aria-labelledby="last-winner-title">
                    <header class="hub-card-head">
                        <h2 id="last-winner-title">Último sorteo</h2>
                        <span class="hub-card-head__meta" id="last-raffle-meta"></span>
                    </header>
                    <div class="hub-winner-tabs" id="last-winner-tabs" role="tablist" aria-label="Ganadores del último sorteo" hidden></div>
                    <div class="hub-last-winner__stage" id="last-winner-content"></div>
                    <div class="hub-fomo" id="ticket-fomo" hidden></div>
                    <div class="hub-last-winner__actions">
                        <button class="hub-button hub-button--ghost" type="button" data-action="open-history"><svg class="hub-icon" aria-hidden="true"><use href="#icon-history"/></svg> Ver historial de sorteos pasados</button>
                        <a class="hub-results-link" id="last-results-link" href="/juegos/sorteo_flash/">Ver sala de resultados <svg class="hub-icon" aria-hidden="true"><use href="#icon-arrow"/></svg></a>
                    </div>
                </section>
            </section>

            <div id="proposals-slot"></div>
            <template id="proposals-template">
            <section class="hub-card hub-proposals hub-reveal" aria-labelledby="proposals-title">
                <div class="hub-section-heading hub-section-heading--wrap">
                    <div class="hub-section-heading__title">
                        <h2 id="proposals-title">Elige los próximos premios</h2>
                        <p class="hub-muted">Cada artículo = 1 premio. ¿3 gestos de 500? ¡Serán 3 ganadores!</p>
                    </div>
                    <div class="hub-proposal-meta">
                        <span class="hub-chip" id="proposal-spots"><svg class="hub-icon" aria-hidden="true"><use href="#icon-ticket"/></svg> Cargando cupos…</span>
                        <span class="hub-chip hub-chip--banana" id="proposal-reward"><svg class="hub-icon" aria-hidden="true"><use href="#icon-coin"/></svg> Recompensa: …</span>
                    </div>
                </div>
                <div class="hub-creator-callout" id="creator-callout"></div>
                <div class="hub-proposal-list" id="proposal-list" aria-live="polite">
                    <div class="hub-skeleton hub-skeleton--proposal"></div>
                    <div class="hub-skeleton hub-skeleton--proposal"></div>
                </div>
            </section>
            </template>

            <section class="hub-sponsor hub-reveal" id="sponsor-banner" aria-label="Patrocinar un Sorteo Flash">
                <span class="hub-sponsor__crown" aria-hidden="true">
                    <svg viewBox="0 0 64 48"><defs><linearGradient id="sponsor-gold" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff3a8"/><stop offset=".5" stop-color="#f6c337"/><stop offset="1" stop-color="#c98a12"/></linearGradient></defs><path d="M6 40 2 10l16 12L32 2l14 20 16-12-4 30Z" fill="url(#sponsor-gold)" stroke="#9a6a0c" stroke-width="2" stroke-linejoin="round"/><path d="M8 44h48" stroke="#c98a12" stroke-width="5" stroke-linecap="round"/><circle cx="32" cy="27" r="4.5" fill="#fff8d8"/><circle cx="17" cy="31" r="3" fill="#fff8d8"/><circle cx="47" cy="31" r="3" fill="#fff8d8"/></svg>
                </span>
                <button class="hub-cta hub-cta--sponsor" type="button" data-action="open-sponsor"><span>Patrocinar un sorteo</span></button>
            </section>

            <section class="hub-bottom" aria-label="Podios y métricas de la comunidad">
                <article class="hub-card hub-hall hub-reveal" aria-labelledby="hall-title">
                    <header class="hub-card-head">
                        <h2 id="hall-title"><svg class="hub-icon" aria-hidden="true"><use href="#icon-trophy"/></svg> Trofeos y podios</h2>
                        <span class="hub-card-head__meta" id="stats-updated"></span>
                    </header>
                    <div class="hub-hall__full" id="hall-full">
                        <div class="hub-skeleton hub-skeleton--proposal"></div>
                    </div>
                </article>
                <div class="hub-insights" aria-label="Logros y métricas del sorteo">
                    <div class="hub-insights__trophies" id="trophy-cards"></div>
                    <div id="choice-panel"></div>
                </div>
            </section>

            <div id="meme-slot"></div>

            <footer class="hub-footer">
                <a class="hub-button hub-button--transparency" href="/juegos/sorteo_flash/transparencia">
                    <svg class="hub-icon" aria-hidden="true"><use href="#icon-shield"/></svg>
                    ¿Cómo garantizamos un sorteo 100% justo?
                    <svg class="hub-icon" aria-hidden="true"><use href="#icon-arrow"/></svg>
                </a>
                <a class="hub-back-link" href="/juegos">← Volver a Juegos de la comunidad</a>
            </footer>
        </main>

        <dialog class="hub-dialog hub-combo-dialog hub-shop" id="combo-dialog" aria-labelledby="combo-title">
            <div class="hub-dialog__inner">
                <header class="hub-dialog__header">
                    <div>
                        <h2 id="combo-title">Arma tu combo</h2>
                        <p class="hub-muted">Elige entre 1 y 12 artículos. ¡Puedes repetirlos!</p>
                    </div>
                    <button class="hub-icon-button" type="button" data-action="close-combo" aria-label="Cerrar creador">×</button>
                </header>
                <div class="hub-modal-sticky">
                    <div class="hub-slots-heading"><strong>Tus premios</strong><span id="slot-count">0 artículos</span></div>
                    <div class="hub-slots" id="combo-slots" aria-label="Ranuras del combo"></div>
                    <div class="hub-meter">
                        <div class="hub-meter__copy"><strong id="combo-total">Total: 0 / 0 PaVos</strong><span id="combo-meter-help">Agrega artículos para empezar.</span></div>
                        <div class="hub-meter__track" id="combo-meter-track" role="progressbar" aria-label="Uso del límite de PaVos" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span id="combo-meter-fill"></span></div>
                    </div>
                </div>
                <div class="hub-catalog-tools">
                    <label class="hub-search"><svg class="hub-icon" aria-hidden="true"><use href="#icon-search"/></svg><span class="sr-only">Buscar artículos por nombre</span><input type="search" id="catalog-search" placeholder="Buscar en la tienda…" autocomplete="off"></label>
                    <div class="hub-category-chips" id="catalog-chips" aria-label="Filtrar por sección"></div>
                </div>
                <div class="hub-catalog-grid" id="catalog-grid" aria-live="polite">
                    <div class="hub-skeleton hub-skeleton--tile"></div><div class="hub-skeleton hub-skeleton--tile"></div>
                    <div class="hub-skeleton hub-skeleton--tile"></div><div class="hub-skeleton hub-skeleton--tile"></div>
                </div>
                <footer class="hub-dialog__footer"><span id="combo-submit-hint">Tus artículos aparecerán arriba.</span><button class="hub-button hub-button--primary" id="combo-submit" type="button" data-action="submit-proposal" disabled>Enviar propuesta</button></footer>
            </div>
        </dialog>

        <dialog class="hub-dialog hub-history-dialog" id="history-dialog" aria-labelledby="history-title">
            <div class="hub-dialog__inner">
                <header class="hub-dialog__header">
                    <div>
                        <h2 id="history-title">Sorteos pasados</h2>
                    </div>
                    <button class="hub-icon-button" type="button" data-action="close-history" aria-label="Cerrar historial">×</button>
                </header>
                <div class="hub-history-content" id="history-content" aria-live="polite">
                    <div class="hub-skeleton hub-skeleton--proposal"></div>
                </div>
            </div>
        </dialog>

        <dialog class="hub-dialog hub-sponsor-dialog" id="sponsor-dialog" aria-labelledby="sponsor-dialog-title">
            <div class="hub-dialog__inner">
                <header class="hub-dialog__header">
                    <div>
                        <h2 id="sponsor-dialog-title">Patrocina un Sorteo Flash</h2>
                    </div>
                    <button class="hub-icon-button" type="button" data-action="close-sponsor" aria-label="Cerrar patrocinio">×</button>
                </header>
                <p class="hub-sponsor-dialog__intro">Patrocinar un Sorteo Flash es sencillo:</p>
                <ol class="hub-sponsor-steps">
                    <li><span>1</span><strong>Eliges el premio o presupuesto.</strong></li>
                    <li><span>2</span><strong>Recibes insignia y mención VIP en el sorteo.</strong></li>
                    <li><span>3</span><strong>La comunidad participa con tu nombre en portada.</strong></li>
                </ol>
                <div class="hub-sponsor-dialog__actions">
                    <a class="hub-button hub-button--sponsor" id="sponsor-whatsapp-link" href="<?= htmlspecialchars($hubConfig['sponsorUrl'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Hablemos por WhatsApp</a>
                    <button class="hub-button hub-button--soft" type="button" data-action="close-sponsor">Cerrar</button>
                </div>
            </div>
        </dialog>

        <dialog class="hub-dialog hub-winner-dialog" id="winner-dialog" aria-labelledby="winner-dialog-title">
            <div class="hub-dialog__inner">
                <header class="hub-dialog__header">
                    <div><h2 id="winner-dialog-title">Detalles del ganador</h2></div>
                    <button class="hub-icon-button" type="button" data-action="close-winner" aria-label="Cerrar detalles">×</button>
                </header>
                <div id="winner-dialog-content"></div>
            </div>
        </dialog>

        <dialog class="hub-dialog hub-ranking-dialog" id="ranking-dialog" aria-labelledby="ranking-title">
            <div class="hub-dialog__inner">
                <header class="hub-dialog__header">
                    <div><h2 id="ranking-title">Ranking completo</h2></div>
                    <button class="hub-icon-button" type="button" data-action="close-ranking" aria-label="Cerrar ranking">×</button>
                </header>
                <div id="ranking-dialog-content"></div>
            </div>
        </dialog>

        <dialog class="hub-dialog hub-category-dialog" id="category-dialog" aria-labelledby="category-title">
            <div class="hub-dialog__inner">
                <header class="hub-dialog__header">
                    <div><h2 id="category-title">Top 10</h2></div>
                    <button class="hub-icon-button" type="button" data-action="close-category" aria-label="Cerrar trofeo">×</button>
                </header>
                <div id="category-dialog-content"></div>
            </div>
        </dialog>

        <dialog class="hub-dialog hub-confirm-dialog" id="confirm-dialog" aria-labelledby="confirm-title">
            <div class="hub-confirm">
                <span class="hub-confirm__emoji" aria-hidden="true"><svg class="hub-icon"><use href="#icon-sparkle"/></svg></span>
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
    <script>window.SORTEO_HUB_CONFIG = <?= json_encode($hubConfig, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <script src="/juegos/sorteo_flash/hub.js?v=<?= (int)$hubJsVersion ?>" defer></script>
</body>
</html>

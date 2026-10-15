<?php
require_once __DIR__ . '/api/session_security.php';
require_once __DIR__ . '/config/app_env.php';

$db_connect_path = __DIR__ . '/wabo/core/db_connect.php';
if (!isset($conn) && file_exists($db_connect_path)) {
    require_once $db_connect_path;
}

if (isset($conn) && $conn instanceof mysqli) {
    $GLOBALS['conn'] = $conn;
}

// Per-route SEO/meta + initial view. router.php sets these per URL; fall back to
// the Vía Regalo (home) defaults when index.php is rendered directly.
$PAGE_DATA_VIEW   = $PAGE_DATA_VIEW   ?? 'viaregalo';
$PAGE_TITLE       = $PAGE_TITLE       ?? 'Banano Store - Tienda de Artículos de Fortnite';
$PAGE_DESCRIPTION = $PAGE_DESCRIPTION ?? 'Explora la tienda de objetos de Fortnite actualizada diariamente. Compra skins, picos, gestos y más. ¡Las mejores ofertas en Banano Store!';
$PAGE_ITEM_ID     = $PAGE_ITEM_ID     ?? null;

$PAGE_BASE_URL  = banano_base_url();
$PAGE_PATH      = rtrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
$PAGE_CANONICAL = $PAGE_BASE_URL . ($PAGE_PATH === '' ? '/' : $PAGE_PATH);

// Fondo de sección del primer frame (renderizado en servidor) + configuración
// del runtime cliente (section-backdrop.js). Nunca puede tumbar el shell.
require_once __DIR__ . '/core/SectionBackdropResolver.php';
try {
    $SECTION_BACKDROP = SectionBackdropResolver::resolve($PAGE_DATA_VIEW, $PAGE_ITEM_ID);
} catch (Throwable $e) {
    error_log('[section-backdrop] resolve failed: ' . $e->getMessage());
    $SECTION_BACKDROP = [
        'mode'   => SectionBackdropResolver::MODE_NONE,
        'url'    => null,
        'config' => ['default' => null, 'page' => null, 'sections' => []],
    ];
}
$SECTION_BACKDROP_CONFIG_JSON = json_encode(
    $SECTION_BACKDROP['config'],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
) ?: '{}';
$SECTION_BACKDROP_IMG = $SECTION_BACKDROP['url'] !== null
    ? SectionBackdropResolver::imageSource($SECTION_BACKDROP['url'])
    : null;
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <meta http-equiv="Content-Security-Policy"
        content="default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://www.youtube.com https://s.ytimg.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com; font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com; img-src 'self' data: https:; media-src 'self'; frame-src 'self' https://www.youtube.com; connect-src 'self' wss://cuysito.com https://cdn2.unrealengine.com https://*.contentful.com; object-src 'none'; base-uri 'self'; form-action 'self';">

    <title><?= htmlspecialchars($PAGE_TITLE, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="description"
        content="<?= htmlspecialchars($PAGE_DESCRIPTION, ENT_QUOTES, 'UTF-8') ?>">

    <link rel="canonical" href="<?= htmlspecialchars($PAGE_CANONICAL, ENT_QUOTES, 'UTF-8') ?>">
<?php if (!banano_is_production()): ?>
    <meta name="robots" content="noindex, nofollow">
<?php endif; ?>

    <meta name="theme-color" content="#111113">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    <meta property="og:title" content="Banano Store - Tienda de Fortnite">
    <meta property="og:description"
        content="Explora la tienda de objetos de Fortnite actualizada diariamente. Compra skins, picos, gestos y más.">
    <meta property="og:image" content="<?= htmlspecialchars($PAGE_BASE_URL, ENT_QUOTES, 'UTF-8') ?>/og-image.jpg">
    <meta property="og:url" content="<?= htmlspecialchars($PAGE_CANONICAL, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:type" content="website">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://res.cloudinary.com">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">

    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Barlow:ital,wght@0,400;0,700;1,900&display=swap"
        as="style" onload="this.onload=null;this.rel='stylesheet'">
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Outfit:wght@800;900&display=swap" as="style"
        onload="this.onload=null;this.rel='stylesheet'">
    <link rel="preload" href="/style.css?v=20260917" as="style">
    <link rel="preload" href="/assets/fonts/HeadingNowVariable.woff2" as="font" type="font/woff2" crossorigin>
    <?php if ($SECTION_BACKDROP_IMG !== null): ?>
    <link rel="preconnect" href="https://wsrv.nl">
    <link rel="preload" as="image" fetchpriority="high"
        href="<?= htmlspecialchars($SECTION_BACKDROP_IMG['src'], ENT_QUOTES, 'UTF-8') ?>"
        <?php if ($SECTION_BACKDROP_IMG['srcset'] !== ''): ?>
        imagesrcset="<?= htmlspecialchars($SECTION_BACKDROP_IMG['srcset'], ENT_QUOTES, 'UTF-8') ?>"
        imagesizes="<?= htmlspecialchars($SECTION_BACKDROP_IMG['sizes'], ENT_QUOTES, 'UTF-8') ?>"
        <?php endif; ?>>
    <?php endif; ?>

    <style id="critical-css">
        :root {
            --color-primary-amber: #f59e0b;
            --background-main: #0a0a0b;
            --color-text-light: #e4e4e7;
            --color-text-muted: #71717a;
            --header-bg: #111113;
            --header-border: rgba(255, 255, 255, 0.06)
        }

        body {
            font-family: 'Barlow', sans-serif;
            background-color: var(--background-main);
            color: var(--color-text-light);
            margin: 0
        }

        .hidden {
            display: none
        }

        .flex {
            display: flex
        }

        .items-center {
            align-items: center
        }

        .justify-between {
            justify-content: space-between
        }

        .container {
            width: 100%;
            margin-left: auto;
            margin-right: auto;
            padding-left: 1rem;
            padding-right: 1rem
        }

        @media (min-width:640px) {
            .container {
                max-width: 640px
            }
        }

        @media (min-width:768px) {
            .container {
                max-width: 768px
            }
        }

        @media (min-width:1024px) {
            .container {
                max-width: 1024px
            }
        }

        @media (min-width:1280px) {
            .container {
                max-width: 1280px
            }
        }

        .site-header__logo {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            text-decoration: none;
            flex-shrink: 0;
            color: #ffffff
        }

        .site-header__logo-mark {
            width: 28px;
            height: 28px;
            flex-shrink: 0
        }

        .site-header__logo--mobile .site-header__logo-mark {
            width: 22px;
            height: 22px
        }

        .site-header__navshelf {
            display: none;
            background-color: var(--header-bg)
        }

        .site-header__utilbar {
            display: none;
            background-color: var(--header-bg);
            border-bottom: 1px solid var(--header-border)
        }

        .site-header__mobile-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1rem;
            height: 48px;
            background-color: var(--header-bg);
            border-bottom: 1px solid var(--header-border)
        }

        @media (min-width:768px) {
            .site-header__navshelf {
                display: block
            }

            .site-header__utilbar {
                display: block
            }

            .site-header__mobile-bar {
                display: none !important
            }
        }

        .text-sm {
            font-size: .875rem
        }

        .text-slate-400 {
            color: var(--color-text-muted)
        }

        .font-bold {
            font-weight: 700
        }

        .text-lg {
            font-size: 1.125rem
        }

        #loading {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 4rem 0
        }

        .custom-spinner-container {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100%;
            color: #71717a
        }

        .custom-spinner {
            animation: spin-animation 1s linear infinite;
            width: 40px;
            height: 40px
        }

        @keyframes spin-animation {
            0% {
                transform: rotate(0)
            }

            100% {
                transform: rotate(360deg)
            }
        }

        .md\\:hidden {
            display: block
        }

        @media (min-width:768px) {
            .md\\:hidden {
                display: none
            }

            .hidden.md\\:block {
                display: block !important
            }

            .hidden.md\\:flex {
                display: flex !important
            }
        }

        /* Widget de redes sociales (abajo-izquierda): visibilidad y posición
           definidas con un selector de ID estable, independiente de las
           utilidades de Tailwind (CDN runtime) para que aparezca de forma
           consistente en todas las vistas, en cualquier orden de carga. */
        #social-container {
            position: fixed;
            bottom: 2rem;
            left: 2rem;
            z-index: 20;
            display: none;
        }

        @media (min-width:768px) {
            #social-container {
                display: flex;
            }
        }

        /* Fondos de sección (Admin → Fondos de Secciones): escenario fijo a
           pantalla completa detrás de todo el contenido, con una capa por
           imagen y crossfade de opacidad entre capas. Va en el CSS crítico
           porque la primera capa se renderiza en servidor y style.css se
           carga en diferido: sin estas reglas el <img> pintaría en flujo.
           Anclado arriba con la altura del viewport grande (100lvh, barras
           del navegador retraídas) en vez de `inset: 0`: en móvil el borde
           inferior del layout viewport sólo se actualiza al terminar el gesto
           de la barra de direcciones, y un fondo `top:0; bottom:0` dejaría
           una franja sin cubrir mientras el dedo sigue en pantalla. */
        #section-backdrop {
            position: fixed;
            inset: 0 0 auto;
            height: 100vh;
            height: 100lvh;
            z-index: -1;
            pointer-events: none;
            overflow: hidden;
            --section-backdrop-fade: 150ms;
        }

        .section-backdrop__layer {
            position: absolute;
            inset: 0;
            opacity: 0;
            transition: opacity var(--section-backdrop-fade) cubic-bezier(0.4, 0, 0.2, 1);
            will-change: opacity;
        }

        .section-backdrop__layer.is-active {
            opacity: 1;
        }

        .section-backdrop__layer img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: bottom;
        }

        /* Páginas de objeto: degradado superior + viñeta sobre la imagen
           (detail-background de fortnite.com). */
        #section-backdrop[data-mode="page"]::before,
        #section-backdrop[data-mode="page"]::after {
            content: '';
            position: absolute;
            inset: 0;
            z-index: 1;
        }

        #section-backdrop[data-mode="page"]::before {
            background: linear-gradient(to bottom, rgba(0, 0, 0, 0.5), transparent 25%);
        }

        #section-backdrop[data-mode="page"]::after {
            background: radial-gradient(ellipse at center, transparent 55%, rgba(0, 0, 0, 0.4) 100%);
        }

        @media (prefers-reduced-motion: reduce) {
            #section-backdrop {
                --section-backdrop-fade: 0ms;
            }
        }
    </style>

    <link rel="stylesheet" href="/style.css?v=20260917" media="print" onload="this.media='all'">
    <noscript>
        <link rel="stylesheet" href="/style.css?v=20260917">
    </noscript>
    <link rel="stylesheet" href="/section-nav.css" media="print" onload="this.media='all'">
    <noscript>
        <link rel="stylesheet" href="/section-nav.css">
    </noscript>
    <link rel="stylesheet" href="/juegos/juegos-hub.css" media="print" onload="this.media='all'">
    <noscript>
        <link rel="stylesheet" href="/juegos/juegos-hub.css">
    </noscript>
    <link rel="stylesheet" href="/cart/cart.css" media="print" onload="this.media='all'">
    <noscript>
        <link rel="stylesheet" href="/cart/cart.css">
    </noscript>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
        media="print" onload="this.media='all'">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>var isPlayerApiReady = false; function onYouTubeIframeAPIReady() { isPlayerApiReady = true; }</script>
</head>

<body data-view="<?= htmlspecialchars($PAGE_DATA_VIEW, ENT_QUOTES, 'UTF-8') ?>">

    <!-- Skip link -->
    <a href="#main-content" class="skip-link sr-only focusable">Saltar al contenido principal</a>

    <!-- Fondo de sección: primera capa renderizada en servidor; el runtime
         (section-backdrop.js) la adopta y gestiona los cambios posteriores. -->
    <div id="section-backdrop" data-mode="<?= htmlspecialchars($SECTION_BACKDROP['mode'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true">
        <?php if ($SECTION_BACKDROP_IMG !== null): ?>
        <div class="section-backdrop__layer is-active" data-url="<?= htmlspecialchars($SECTION_BACKDROP['url'], ENT_QUOTES, 'UTF-8') ?>">
            <img src="<?= htmlspecialchars($SECTION_BACKDROP_IMG['src'], ENT_QUOTES, 'UTF-8') ?>"
                <?php if ($SECTION_BACKDROP_IMG['srcset'] !== ''): ?>
                srcset="<?= htmlspecialchars($SECTION_BACKDROP_IMG['srcset'], ENT_QUOTES, 'UTF-8') ?>"
                sizes="<?= htmlspecialchars($SECTION_BACKDROP_IMG['sizes'], ENT_QUOTES, 'UTF-8') ?>"
                <?php endif; ?>
                alt="" decoding="async" fetchpriority="high">
        </div>
        <?php endif; ?>
    </div>
    <script type="application/json" id="section-backdrop-config"><?= $SECTION_BACKDROP_CONFIG_JSON ?></script>

    <!-- Backdrop global -->
    <div id="backdrop"></div>

    <!-- Loader global -->
    <div id="loading-overlay" class="loading-overlay" hidden role="status" aria-live="polite" aria-atomic="true">
        <div class="flex flex-col items-center gap-4">
            <div class="custom-spinner-container">
                <svg class="custom-spinner" viewBox="0 0 50 50" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"
                    focusable="false">
                    <circle cx="25" cy="25" r="20" fill="none" stroke="currentColor" stroke-width="4"
                        stroke-linecap="round" stroke-dasharray="80 150" />
                </svg>
            </div>
            <p class="text-lg empty-state-text">Cargando la tienda...</p>
        </div>
    </div>

    <!-- Notificaciones toast -->
    <div id="toast-container" role="status" aria-live="polite" aria-atomic="true">
        <div id="toast-notification" class="toast-notification" tabindex="-1"></div>
    </div>

    <!-- Sprite SVG de iconos -->
    <svg id="icons" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"
        hidden>
        <symbol id="icon-club" viewBox="0 0 340 310">
            <path
                d="m336 50-90 28-74-76-77 77L5 48l61 223 37 18 64 18 15-2 43-12 48-19 60-206zm-166-3 70 62 53-16 3 1-42 162-7 4-72 23-25-5-61-21L46 95l3-2 53 16z"
                fill-rule="evenodd" />
        </symbol>

        <symbol id="icon-chevron" viewBox="0 0 100 100">
            <path d="m15 75l35-50 35 50"
                style="fill:none;stroke:currentColor;stroke-width:20;stroke-linecap:round;stroke-linejoin:round" />
        </symbol>

        <symbol id="icon-banano-logo-legacy" viewBox="0 0 927 636" width="927" height="636">
            <g fill="currentColor">
                <path
                    d="m360.57 119.88c1.69 0 2.33-0.14 3.31 0.82 0.09 3.98-10.14 13.72-6.3 19.33 2.12 2.59 8.58 4.83 10.55 6.92 17.22 18.19 20.34 45.85 25.56 69.31 0.84 3.76-0.6 11.1 1.09 13.84 1.59 0.02 0.92 0.26 2.14-0.61 1.85-3.56 0.95-14.04 1.15-18.79 1.05-24.5 1.87-55.51-2-79.72 3.28 0.88 7.04 1.33 10.16 1.91 11.69 2.16 15.24 0.69 25.31-4.87 3.34-1.84 9.5-4.35 13.2-5.86 0.61 1.56 1.21 3.19 1.76 4.76 12.96 37.3 26.01 74.46 40.61 111.17 5.03 12.64 9.34 26.13 15.11 38.41-0.3-6.96-1.7-14.39-2.05-21.63l0.26-0.12c1.27 3.89 9.47 40.36 9.36 43.32-3.48 0.07-7.04-0.24-10.5-0.07-6.37 0.32-12.7 1.4-18.98 2.45-4.93 0.84-9.85 1.85-14.58 3.52-3.36 1.18-6.75 3.61-8.15 7.02-1.5 3.64-0.43 7.58 0.9 11.1 5.77 14.18 23.23 23 37.72 16.57 8.83-3.92 12.54-9.96 15.98-18.5l1.44-4.65c1.71 6.78 2.83 13.99 4.08 20.91l6.32 35.19c1.45 8.22 2.94 17.67 4.88 25.73-7.32 7.82-14.67 17.17-21.95 25.35-19.93 24.01-54.78 35.71-84.64 38.14-16.08 1.3-37.48-42.01-36.05-56.35 0.54-5.49 2.59-11.87 2.82-17.94 0.52-14.24 0.29-28.26 0.15-42.46 8.16 6.95 17.86 8.71 26.49 1.34 6.52-5.57 14.29-26.65 7.5-33.71-5.25-5.45-25.46-3.62-33.22-3.76 0.19-5.57 1.42-12.25 1.82-18.09 1.09-15.92 2.93-34.23 1.92-50.15-1.69-26.44-10.38-62.38-25.42-84.59-3.63-5.37-10.58-11.12-15.44-15.41 0.33-7.63 2.33-14.31 7.69-19.83zm96.59 246c1.33-0.8 2.01-1.01 2.52-2.41-1.08-4.2-29.73-2.53-34.64-2.55-0.72 1.2-1.93 5.83-0.21 5.95 6.69 0.44 26.9 1.04 32.33-0.99z" />
                <path
                    d="m583.79 398.53c0.41 0.21 0.81 0.44 1.16 0.76 5.99 5.52 18.4 29.05 18.89 37.42 0.45 7.71-0.88 15.98-1.56 23.67-0.77 8.78-1.31 17.6-2.31 26.36-1.9 16.52-9.94 76.94-19.75 87.48-0.99 1.06-2.28 1.92-3.59 2.53-5.88 2.7-12.57 4.53-18.7 6.68-14.05 4.94-28.2 9.69-42.14 14.93-8.19 3.09-15.78 7.77-24.07 10.56-5.26 1.77-10.63 3.09-16.19 3.31-3.35-15.69-6.1-20.16-15.36-32.96 21.47-8.26 37.54-14.29 55.59-28.75 15.52-12.42 32.04-27.88 32.09-49.33-0.55-4.07-3.66-18.04-9.17-17.65-1.25 1.72 0.19 3.11 1.26 4.9 13.83 23.15-9.98 47.47-27.54 60.25-7.91 5.76-13.09 9.87-21.88 14.22-13.12 6.5-28.21 10.54-40.75 17.67-3.6-4.89-5.94-10.34-9.75-15.45 5.5-16 21.88-29.82 34.42-40.44 20.19-17.12 38.73-37.74 54.32-59.14q-3.39-6.82-6.67-13.7c3.94 0.39 8.16 1.04 12.1 1.57 16.89-16.89 35.59-35.45 49.6-54.89z" />
                <path
                    d="m574.72 367.86c0.52 0.13 1.16 0.4 1.51 0.81 6.71 8.03 6.96 16.12 6.84 25.43-16.18 20.84-31.98 37.28-50.54 55.88-5.3-1.34-10.73-2.05-16.19-2.13 2.27 3.93 6.03 12.77 8.21 17.39-9.04 13.27-30.79 37.31-42.14 47.79-14.27 13.17-24.07 19.02-36.92 35.32-0.91 1.24-5.69 8.71-6.29 9.03 0.85-2.89-0.05-6.21 0.44-7.62 2.03-5.87 6.07-6.03 0.51-12.16 6.41-7.35 11.07-16.62 17.06-23.49 29.23-33.54 54.08-69.87 80.01-105.9 5.6-7.79 14.81-15.97 21.31-23.3 5.14-5.8 10.66-11.59 16.19-17.05z" />
                <path
                    d="m378.01 574.64c-2.75-6.24-7.79-18.23-7.92-24.94l0.58-0.37c4.53 1.89 6.15 11 10.03 16.17 19.17 5.72 33.63 4.3 51.67-3.67 6.55 6.18 12.58 17.56 17.39 25.5 0.7 1.15 3.49 9.69 4.14 11.65-8.01 3.84-8.86 6.58-9.08 15.17-15.69-0.28-22.59-2.53-37.19-8.05-15.95-6.02-31.1-10.86-45.5-20.46-2.45-1.61-5.92-3.2-7.92-5.26l-0.11-0.89c0.59-0.33 4.94-0.66 6.94-1.38 4.14 2.27 13.17 14.29 16.63 13.69 1.01-1.21 1.04-0.86 1.08-2.1-3.06-4.55-14.74-12.16-17.83-17.88-0.87-1.61-1.65-5.13-2.33-7.12-0.93-2.74-2.22-2.86-2.17-5.35 0.82-1.26 0.86-1.42 2.08-2.23 2.61 1.87 2.04 7.24 3.4 9.25 2.64 3.86 13.95 20.37 17.98 20.14 1.13-1.06 0.85-0.44 1.06-1.8-1.54-2.56-3.62-3.56-5.32-6.14l0.67-0.88c2.57 0.78 4.43 2.06 6.48 1.76l0.83-0.92c-0.35-2.83-3.16-3.14-5.59-3.89z" />
                <path
                    d="m435.8 497.79c11.13 6.38-7.47 15.2-12.62 16.45-2.92 0.71-7.66 1.31-8.23 4.44 3.89 5.95 26.88-8.69 32.19-7.02 1.15 1.3 0.85 0.67 1.12 2.37-1.42 4.04-14.72 9.15-18.71 11.41-3.07 1.73-16.35 3.07-17.31 6.11 5.55 8.84 26.07-6.27 30.19-5.57l0.35 0.75c-2.39 11.94-21.02 12.95-25.65 16.75-0.19 1.08-0.28 1.12 0.16 2.16 3.04 1.08 14.01-4.09 17.63-5.61 0.77-0.05 1.56 0 2.33 0.03 1.54 0.93 1.11 0.39 1.69 1.98-0.08 1.94-0.88 3.05-1.84 4.71-4.26 2.71-12.65 5.19-14.71 7.18-0.32 1.34-0.42 0.74 0.23 1.89 3.16 0.84 8.91-3.09 12.83-3.7 3.45 4.03-5.97 7.18-8.63 8.39-14.59 6.66-28.5 5.09-43.65 2.21-2.98-4.54-4.24-9.4-7.04-13.83-1-1.59-4.3-1.94-4.05-5.07 0.71-8.82 5.98-17.36 12.76-22.86 10.96-9.21 25.65-8.18 38.29-13.41 5.29-2.19 8.75-5.78 12.67-9.76z" />
                <path
                    d="m384.17 444.62c3.94 1.68 19.41 15.89 23.87 19.78-5.16 4.96-4.28 8.46-3.84 15.01l-3.75 3.05c-3.62 2.72-10.27 6.56-14.34 9.05l-1.28-30.45c-0.24-5.63-0.78-10.75-0.66-16.44z" />
                <path
                    d="m405.95 482.88c1.87 0.28 4.45 1.84 6.23 2.78-0.65 6.91-1.96 14.68-2.19 21.46-6.35 2.15-14.17 3.84-20.17 6.38-1.37 0.57-0.71 0.54-2.01 0.32-1.8-2.84-2.59-14.47-2.98-18.32 6.87-2.76 15.19-8.23 21.12-12.62z" />
                <path
                    d="m450.31 83.93c1.71 1.17 2.38 4.66 2.91 6.63-1.65 7.49-6.16 19.91-8.66 27.4q-1.07 0.7-2.2 1.3c-3.26 1.75-7.16 3.05-10.38 4.71-8.13 4.21-13.04 7.37-22.52 5.84-5.71-0.92-10.44-1.41-16.16-3.12-1.66-1.54-2.15-4.55-2.7-6.78 5.07 1.12 20.4 3.1 25.06 3.84 4.65 0.74 21.28-8.07 26.16-11.05 2.56-5.98 6.47-21.66 8.49-28.77z" />
                <path
                    d="m488.84 468.01l-0.11 1.11c-3.12 4.57-33.68 43.72-36.24 44.31-0.79-2.97-1.87-3.63-4.19-5.52-3-0.01-4.4-0.11-7.27 0.66l-0.81 0.14c2.74-5.31 3.91-3.22 1.76-10.52 6.58 2.4 8.68 3.8 15.71 3.88 5.03-5.61 6.19-11.46 6.32-18.8 6.95-2.91 19.07-10.47 24.83-15.26z" />
                <path
                    d="m382.32 408.22c0.73 0.68 2.13 7.23 2.47 8.62 2.47 10.13 6.69 19.5 12.71 28.02 0.3 0.43 3.67 4.97 3.64 4.9 0.96 2.42 11.17 11.52 11.27 12.44l-0.94 0.31-0.93-0.65c-4.91-5.03-11.65-9.55-16.69-14.6-2.37-2.38-5.72-4.79-8.92-5.79-2.03-0.62-1.87 0.68-3.46 0.77-3.13-6-1.4-27.32 0.85-34.02z" />
                <path
                    d="m456.23 580.76c7.79 6.59 14.51 21.91 15.13 31.9l-4.65 1.72c-1.49 0.36-2.96 0.95-4.41 1.49-3.24-11.28-6.31-21.69-10.09-32.79z" />
                <path
                    d="m416.21 464.98c3.25 0.3 4.75 0.7 7.9 1.51 1.45 2.22 2.07 3.81 3.12 6.26-2.06 6.91-2.96 8.42-10.06 10.19-6.07 0.58-9.16-3.63-10.45-8.9 1.74-6.29 3.1-7.67 9.49-9.06z" />
                <path
                    d="m422.61 485.43c1.36-0.47 1.94-0.2 3.04 0.5 1.24 2.17 0.43 12.15 0.48 15.8-2.31 1.37-5.44 2.82-7.89 4.05l-4.11 1.56c-0.23-7.6 0.59-13.25 1.05-20.76 2.03-0.46 5.29-0.84 7.43-1.15z" />
                <path
                    d="m375.22 524.65c0.76 0.9-0.27 1.92-0.93 2.96-4.16 6.71-5.28 10.67-5.42 18.28-2.52-1.46-5.98-3.03-7.46 0.73-0.53 1.2-0.23 0.95-1.17 1.68-0.69-1.89 0.36-7.53 1.05-9.43 2.92-7.96 6.89-10.72 13.93-14.22z" />
                <path
                    d="m453.53 602.81l0.77 0.15c1.81 2.1 4.16 12.19 5.11 15.55-1.87 0.83-2.74 1.07-4.69 1.6-1.99-0.38-4.88-0.56-5.87-2.47-2.91-5.55-0.21-11.71 4.68-14.83z" />
                <path
                    d="m365.27 546.13c1.43 9.55 4.93 19.82 8.96 28.56l-1.45 0.37c-5.04-7.71-7.38-12.63-10.07-21.43 0.66-2.45 1.68-5.09 2.56-7.5z" />
                <path
                    d="m430.06 490c1.67 0.97 4.09 2.5 4.44 4.44l-1.48 1.09-3.02 2.96c-0.05-2.8 0.02-5.69 0.06-8.49z" />
                <path
                    d="m558.13 359.89l6.47 0.06-3.5 3.38c-1.19 0.95-1.38 1.46-2.62 1.43-0.78-1.36-0.44-3.2-0.35-4.87z" />
                <path
                    d="m374.23 574.69c0.55 0.9 1.3 2.34 2.06 3l-0.67 0.88-2.84-3.51z" />
                <path
                    d="m458.76 622.9c13.05-9.51 19.2-5.12 32.44-10.13 14.17-5.36 27.24-12.42 42.11-16.94-1 12.01-3.95 28.84-4.3 40.17h-133.81c1.23-6.2 4.25-23.11 6.13-28.32 8.77 2.6 17.1 6.54 26.07 8.57 5.62 1.27 11.12 0.81 16.78 2.11 2.12 2.45 2.41 2.69 5.01 4.49 3.09 0.04 6.52 0.16 9.57 0.05z" />
                <path
                    d="m360.99 588.99c13.13 8.69 22.34 12.02 36.94 16.86-1.53 5.91-5.49 24.24-6.11 30.15h-33.8c-0.03-9.98 1.02-37.48 2.97-47.01z" />
            </g>
        </symbol>

        <!-- Icono de V-Bucks (Pavos) -->
        <symbol id="icon-pavos" viewBox="0 0 24 24" aria-hidden="true">
            <path fill-rule="evenodd" clip-rule="evenodd" fill="currentColor"
                d="M12 22.5C17.799 22.5 22.5 17.799 22.5 12C22.5 6.20101 17.799 1.5 12 1.5C6.20101 1.5 1.5 6.20101 1.5 12C1.5 17.799 6.20101 22.5 12 22.5ZM10.1051 7.5H6L9.31376 16.5H14.6306L18 7.5H13.8887L12.0896 13.7038L12.0031 14.0427H11.9784L11.9042 13.7038L10.1051 7.5Z">
            </path>
        </symbol>

        <symbol id="icon-games" viewBox="0 0 50 50">
            <path
                d="m18.2 10c0.7 0 1.8 0 2.5 0h4.4 4.3c0.9 0 2 0 2.9 0.1q1.4 0.1 2.8 0.5c3.5 1.1 6.3 3.5 8 6.8q0.7 1.4 1.1 2.9c0.2 0.9 0.4 2 0.5 2.9l0.6 4.1 0.5 3.4c0.2 1.7 0.4 2.7-0.1 4.4-0.6 1.8-1.9 3.3-3.6 4.1-1.7 0.9-3.6 1-5.4 0.4-1.6-0.5-2.9-1.5-3.8-2.9-0.3-0.5-0.6-1.2-0.9-1.8l-1.3-2.7-0.6-1.2h-10.2c-0.6 1.2-1.2 2.4-1.8 3.6-0.5 1.1-0.9 2-1.7 2.9-1.2 1.5-2.9 2.3-4.8 2.5-1.8 0.1-3.7-0.5-5.1-1.7-1.5-1.2-2.3-3-2.5-4.8-0.1-1.1 0.1-2.1 0.3-3.2l0.4-3.2 0.6-3.9c0.1-0.7 0.2-1.6 0.4-2.3q0.1-0.9 0.4-1.8c0.6-1.6 1.5-3.1 2.6-4.4 2.4-2.8 5.8-4.5 9.5-4.7zm-3.7 9h-3v3h3v3h3v-3h3v-3h-3v-3h-3zm19.2 3.5c-1.1 0.2-1.8 1.2-1.7 2.3 0.2 1.1 1.2 1.8 2.3 1.7 1.1-0.2 1.8-1.2 1.7-2.3-0.2-1.1-1.2-1.8-2.3-1.7zm4.1-4c-1.1 0.2-1.9 1.1-1.8 2.2 0.1 1.1 1.1 1.9 2.2 1.8 1.1-0.1 1.9-1.1 1.8-2.2-0.1-1.1-1.1-1.9-2.2-1.8zm-4-4c-1.1 0.1-1.9 1.1-1.8 2.2 0.1 1.1 1.1 1.9 2.2 1.8 1.1-0.1 1.9-1.1 1.8-2.2-0.1-1.1-1.1-1.9-2.2-1.8zm-4 4c-1.1 0.2-1.9 1.1-1.8 2.2 0.2 1.1 1.1 1.9 2.2 1.8 1.1-0.1 1.9-1.1 1.8-2.2-0.1-1.1-1.1-1.9-2.2-1.8z" />
        </symbol>

        <symbol id="icon-gift" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 12v10H4V12" />
            <path d="M2 7h20v5H2z" />
            <path d="M12 22V7" />
            <path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z" />
            <path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z" />
        </symbol>

        <symbol id="icon-gamepad-stroke" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="2" y="6" width="20" height="12" rx="3" />
            <line x1="6" y1="10" x2="6" y2="14" />
            <line x1="4" y1="12" x2="8" y2="12" />
            <circle cx="16" cy="10" r="0.9" fill="currentColor" />
            <circle cx="18" cy="12" r="0.9" fill="currentColor" />
            <circle cx="14" cy="12" r="0.9" fill="currentColor" />
            <circle cx="16" cy="14" r="0.9" fill="currentColor" />
        </symbol>

        <symbol id="icon-cart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="9" cy="21" r="1" />
            <circle cx="20" cy="21" r="1" />
            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
        </symbol>

        <symbol id="icon-logo" viewBox="0 0 64 64">
            <path fill="currentColor" d="M48 15C43 43 13 30 5 26c-11.12-5-2 18 11 19 26 4 33-7 33-20zm2-15q-5 3-2 6c9 19 7 48-35 42q-3 1-2 5c14 26.2 67.98 2 49-35-3-4-8-6-9-15q1-3-1-3" />
        </symbol>

        <symbol id="icon-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
            <line x1="18" y1="6" x2="6" y2="18"/>
            <line x1="6" y1="6" x2="18" y2="18"/>
        </symbol>

        <symbol id="icon-filter" viewBox="0 0 24 24">
            <path fill="currentColor" fill-rule="evenodd" clip-rule="evenodd" d="M4.5 5.625H19.5V7.875H4.5V5.625ZM6.75 10.875H17.25V13.125H6.75V10.875ZM15 16.125H9V18.375H15V16.125Z" />
        </symbol>

        <symbol id="icon-search" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8" />
            <line x1="21" y1="21" x2="16.65" y2="16.65" />
        </symbol>

        <symbol id="icon-user" viewBox="0 0 24 24">
            <circle cx="12" cy="8" r="4" fill="currentColor" />
            <path fill="currentColor" d="M20 21a8 8 0 0 0-16 0z" />
        </symbol>

        <symbol id="icon-vbucks" viewBox="0 0 512 512">
            <g fill="currentColor" fill-rule="evenodd">
                <path d="M242.2 479c-57.2-4-107.1-27.2-146.8-68.2-34.6-35.6-55.6-79.7-61.7-129.2-.5-4.9-1-16.4-1-25.6 0-26.5 3.4-47.6 11.5-71.6 11.5-33.8 27.7-59.7 54-86 26-26.1 52.2-42.5 86.1-54 23.9-8.1 45-11.5 71.3-11.5 26.8 0 45.7 3 70.1 11.1 24 7.9 43.1 17.9 63.9 33.2 12.3 9 35.8 32.5 44.8 44.8 24 32.4 38.5 68.7 43.3 108.3 1.4 11.4 1.4 39.9 0 51.3-4.8 39.6-19.5 76.2-43.2 108.4-9.1 12.2-32.4 35.5-45.1 44.9-42.1 31.4-96.7 47.8-147.4 45.1m-44.8-128.8c-.3-1-15.9-43.4-34.7-94.2-18.8-50.9-34.3-93.3-34.6-94.3-.5-1.7 1.9-1.8 43.8-1.5l44.2.3 18.9 65c10.4 35.7 19.3 66.5 19.7 68.5.6 2.2 1.1 2.9 1.5 2 .3-.9 9.3-31.7 19.9-68.5l19.4-66.5 44.2-.3c24.3-.2 44.2.1 44.2.5 0 .4-16 43.6-36.5 96L313 352l-57.5 0c-53.9 0-57.6-.2-57.1-1.6"/>
            </g>
        </symbol>

        <!-- region: glifos de UI (reemplazan Font Awesome en la tienda) -->
        <symbol id="icon-bolt" viewBox="0 0 24 24">
            <path fill="currentColor" d="M14.5 2 5 13.5h5.4L9 22l10-12h-6.1L14.5 2Z" />
        </symbol>
        <symbol id="icon-warning" viewBox="0 0 24 24">
            <path fill="currentColor" fill-rule="evenodd" clip-rule="evenodd"
                d="M13.7 3.45a2 2 0 0 0-3.4 0l-9 14.5A2 2 0 0 0 3 21h18a2 2 0 0 0 1.7-3.05l-9-14.5ZM11 9a1 1 0 1 1 2 0v5a1 1 0 1 1-2 0V9Zm1 7.25A1.25 1.25 0 1 0 12 18.75a1.25 1.25 0 0 0 0-2.5Z" />
        </symbol>
        <symbol id="icon-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3 2" />
        </symbol>
        <symbol id="icon-times-circle" viewBox="0 0 24 24">
            <path fill="currentColor" fill-rule="evenodd" clip-rule="evenodd"
                d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm3.54 5.05L12 10.59 8.46 7.05 7.05 8.46 10.59 12l-3.54 3.54 1.41 1.41L12 13.41l3.54 3.54 1.41-1.41L13.41 12l3.54-3.54-1.41-1.41Z" />
        </symbol>
        <symbol id="icon-spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round">
            <circle cx="12" cy="12" r="9" opacity="0.25" />
            <path d="M21 12a9 9 0 0 0-9-9" />
        </symbol>
        <!-- Logo de YouTube (dos tonos: cuerpo rojo oficial + triángulo blanco) -->
        <symbol id="icon-youtube" viewBox="0 0 24 24">
            <path fill="#FF0000"
                d="M23.5 6.19a3.02 3.02 0 0 0-2.12-2.14C19.5 3.55 12 3.55 12 3.55s-7.5 0-9.38.5A3.02 3.02 0 0 0 .5 6.19C0 8.07 0 12 0 12s0 3.93.5 5.81a3.02 3.02 0 0 0 2.12 2.14c1.88.5 9.38.5 9.38.5s7.5 0 9.38-.5a3.02 3.02 0 0 0 2.12-2.14C24 15.93 24 12 24 12s0-3.93-.5-5.81Z" />
            <path fill="#FFFFFF" d="M9.55 15.57V8.43L15.82 12l-6.27 3.57Z" />
        </symbol>
        <symbol id="icon-cart-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="9" cy="21" r="1" />
            <circle cx="20" cy="21" r="1" />
            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
            <path d="M16.5 2v6M13.5 5h6" />
        </symbol>
        <symbol id="icon-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 5v14M5 12h14" />
        </symbol>
        <symbol id="icon-minus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M5 12h14" />
        </symbol>
        <symbol id="icon-pause" viewBox="0 0 24 24">
            <path fill="currentColor" d="M7 5h3v14H7zM14 5h3v14h-3z" />
        </symbol>
        <symbol id="icon-chevron-down" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="m5 9 7 7 7-7" />
        </symbol>
        <symbol id="icon-carousel-arrow-prev" viewBox="0 0 24 24">
            <path fill="currentColor" d="M12.7073 4.74675C13.0587 4.39529 13.6286 4.39529 13.98 4.74675L15.8892 6.6559C16.2407 7.00737 16.2407 7.57722 15.8892 7.92869L11.8166 12.0012L15.8892 16.0737C16.2407 16.4252 16.2407 16.995 15.8892 17.3465L13.98 19.2557C13.6286 19.6071 13.0587 19.6071 12.7073 19.2557L5.45264 12.0012L12.7073 4.74675Z" />
        </symbol>
        <symbol id="icon-carousel-arrow-next" viewBox="0 0 24 24">
            <path fill="currentColor" d="M11.5049 4.95884C11.0363 4.49023 10.2765 4.49023 9.80785 4.95884L8.32293 6.44373C7.85429 6.91236 7.85429 7.67217 8.32293 8.14079L12.1834 12.0012L8.32293 15.8616C7.85429 16.3302 7.85429 17.09 8.32293 17.5586L9.80785 19.0435C10.2765 19.5121 11.0363 19.5121 11.5049 19.0435L18.5474 12.0012L11.5049 4.95884Z" />
        </symbol>
        <symbol id="icon-share" viewBox="0 0 24 24">
            <path fill-rule="evenodd" clip-rule="evenodd" fill="currentColor" d="M19 6C19 7.65685 17.6569 9 16 9C15.2739 9 14.608 8.74203 14.0891 8.31275L8.94039 11.402C8.97948 11.5952 9 11.7952 9 12C9 12.2048 8.97948 12.4048 8.94039 12.598L14.0891 15.6872C14.608 15.258 15.2739 15 16 15C17.6569 15 19 16.3431 19 18C19 19.6569 17.6569 21 16 21C14.3431 21 13 19.6569 13 18C13 17.7952 13.0205 17.5952 13.0596 17.402L7.91093 14.3128C7.39197 14.742 6.72611 15 6 15C4.34315 15 3 13.6569 3 12C3 10.3431 4.34315 9 6 9C6.72611 9 7.39197 9.25797 7.91093 9.68725L13.0596 6.59804C13.0205 6.40478 13 6.20479 13 6C13 4.34315 14.3431 3 16 3C17.6569 3 19 4.34315 19 6Z" />
        </symbol>
        <symbol id="icon-caret-down" viewBox="0 0 24 24">
            <path fill="currentColor" d="M5 8h14l-7 9z" />
        </symbol>
        <symbol id="icon-arrow-up" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 19V5M5 12l7-7 7 7" />
        </symbol>
        <symbol id="icon-expand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3m13-5v3a2 2 0 0 1-2 2h-3" />
        </symbol>
        <symbol id="icon-box-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 8 12 3 3 8l9 5 9-5z" />
            <path d="M3 8v8l9 5 9-5V8M12 13v8" />
        </symbol>
        <!-- Logos de marca (paths de Simple Icons, viewBox 0 0 24 24, fill=currentColor) -->
        <symbol id="icon-whatsapp" viewBox="0 0 24 24">
            <path fill="currentColor" d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z" />
        </symbol>
        <symbol id="icon-facebook-f" viewBox="0 0 24 24">
            <path fill="currentColor" d="M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z" />
        </symbol>
        <symbol id="icon-instagram" viewBox="0 0 24 24">
            <path fill="currentColor" d="M7.0301.084c-1.2768.0602-2.1487.264-2.911.5634-.7888.3075-1.4575.72-2.1228 1.3877-.6652.6677-1.075 1.3368-1.3802 2.127-.2954.7638-.4956 1.6365-.552 2.914-.0564 1.2775-.0689 1.6882-.0626 4.947.0062 3.2586.0206 3.6671.0825 4.9473.061 1.2765.264 2.1482.5635 2.9107.308.7889.72 1.4573 1.388 2.1228.6679.6655 1.3365 1.0743 2.1285 1.38.7632.295 1.6361.4961 2.9134.552 1.2773.056 1.6884.069 4.9462.0627 3.2578-.0062 3.668-.0207 4.9478-.0814 1.28-.0607 2.147-.2652 2.9098-.5633.7889-.3086 1.4578-.72 2.1228-1.3881.665-.6682 1.0745-1.3378 1.3795-2.1284.2957-.7632.4966-1.636.552-2.9124.056-1.2809.0692-1.6898.063-4.948-.0063-3.2583-.021-3.6668-.0817-4.9465-.0607-1.2797-.264-2.1487-.5633-2.9117-.3084-.7889-.72-1.4568-1.3876-2.1228C21.2982 1.33 20.628.9208 19.8378.6165 19.074.321 18.2017.1197 16.9244.0645 15.6471.0093 15.236-.005 11.977.0014 8.718.0076 8.31.0215 7.0301.0839m.1402 21.6932c-1.17-.0509-1.8053-.2453-2.2287-.408-.5606-.216-.96-.4771-1.3819-.895-.422-.4178-.6811-.8186-.9-1.378-.1644-.4234-.3624-1.058-.4171-2.228-.0595-1.2645-.072-1.6442-.079-4.848-.007-3.2037.0053-3.583.0607-4.848.05-1.169.2456-1.805.408-2.2282.216-.5613.4762-.96.895-1.3816.4188-.4217.8184-.6814 1.3783-.9003.423-.1651 1.0575-.3614 2.227-.4171 1.2655-.06 1.6447-.072 4.848-.079 3.2033-.007 3.5835.005 4.8495.0608 1.169.0508 1.8053.2445 2.228.408.5608.216.96.4754 1.3816.895.4217.4194.6816.8176.9005 1.3787.1653.4217.3617 1.056.4169 2.2263.0602 1.2655.0739 1.645.0796 4.848.0058 3.203-.0055 3.5834-.061 4.848-.051 1.17-.245 1.8055-.408 2.2294-.216.5604-.4763.96-.8954 1.3814-.419.4215-.8181.6811-1.3783.9-.4224.1649-1.0577.3617-2.2262.4174-1.2656.0595-1.6448.072-4.8493.079-3.2045.007-3.5825-.006-4.848-.0608M16.953 5.5864A1.44 1.44 0 1 0 18.39 4.144a1.44 1.44 0 0 0-1.437 1.4424M5.8385 12.012c.0067 3.4032 2.7706 6.1557 6.173 6.1493 3.4026-.0065 6.157-2.7701 6.1506-6.1733-.0065-3.4032-2.771-6.1565-6.174-6.1498-3.403.0067-6.156 2.771-6.1496 6.1738M8 12.0077a4 4 0 1 1 4.008 3.9921A3.9996 3.9996 0 0 1 8 12.0077" />
        </symbol>
        <symbol id="icon-tiktok" viewBox="0 0 24 24">
            <path fill="currentColor" d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z" />
        </symbol>
        <!-- endregion -->
    </svg>

    <noscript>
        <div
            style="background-color: #ffeb3b; padding: 20px; text-align: center; color: black; font-family: sans-serif;">
            <strong>¡Atención!</strong> Esta página requiere JavaScript para funcionar correctamente. Por favor,
            actívalo en tu navegador.
        </div>
    </noscript>

    <!-- Overlays y paneles globales -->

    <div id="filter-overlay" class="fixed inset-0 z-40 md:p-4 pointer-events-none" hidden role="dialog"
        aria-modal="true" aria-labelledby="filter-heading">
        <div id="filter-panel"
            class="w-full flex flex-col max-h-[85vh] pointer-events-auto">
            <header class="p-4 pb-2 text-center">
                <h2 id="filter-heading" class="filter-modal-heading text-lg">Filtro de la tienda</h2>
            </header>
            <div class="flex-grow p-4 pt-2 overflow-y-auto">
                <h3 class="filter-modal-heading mb-2">Tipo de artículo</h3>
                <ul id="filter-type-list" class="filter-type-grid"></ul>

                <section id="price-filter-section" class="mt-6 space-y-3" aria-labelledby="price-filter-heading">
                    <div class="flex items-center justify-between gap-3">
                        <h3 id="price-filter-heading" class="filter-modal-heading text-sm sm:text-base">
                            Precio en paVos</h3>
                        <div class="flex items-center gap-1 text-sm sm:text-base filter-price-label">
                            <svg class="icon vbucks-icon w-5 h-5 sm:w-6 sm:h-6" aria-hidden="true" focusable="false"
                                viewBox="0 0 24 24">
                                <use href="#icon-pavos" xlink:href="#icon-pavos"></use>
                            </svg>
                            <span id="price-range-label" class="font-semibold">200 – 3000 y más</span>
                        </div>
                    </div>
                    <div class="price-range-slider relative mt-2">
                        <div class="price-range-track" aria-hidden="true"></div>
                        <input type="range" id="price-filter-min" class="price-range-input" min="200" max="3000"
                            step="50" value="200" aria-label="Precio mínimo en paVos">
                        <input type="range" id="price-filter-max" class="price-range-input" min="200" max="3000"
                            step="50" value="3000" aria-label="Precio máximo en paVos">
                    </div>
                </section>
            </div>
            <footer id="filter-actions" class="p-4 pt-2 space-y-3">
                <button id="apply-filters-btn"
                    class="filter-action-btn w-full bg-yellow-400 modal-apply-btn-text uppercase py-3 rounded-2xl text-lg">Mostrar
                    todos los artículos</button>
                <div class="flex gap-3">
                    <button id="clear-filters-btn"
                        class="filter-action-btn w-full filter-secondary-btn uppercase py-3 rounded-2xl"
                        disabled>Borrar</button>
                    <button id="close-filters-btn"
                        class="filter-action-btn w-full filter-secondary-btn uppercase py-3 rounded-2xl">Cerrar</button>
                </div>
            </footer>
        </div>
    </div>

    <!-- Cabecera -->

    <header id="site-header" role="banner" class="site-header">

        <!-- Barra superior: logo + login -->
        <div class="site-header__navshelf">
            <div class="site-header__navshelf-inner layout-bound">

                <a href="/" data-route class="site-header__logo" aria-label="Banano Store — Ir al inicio">
                    <svg class="site-header__logo-mark" aria-hidden="true" focusable="false"><use href="#icon-logo"></use></svg>
                    <span class="site-header__wordmark">Banano Store</span>
                </a>

                <a href="/login" id="header-login"
                    class="header-action-btn header-action-text-btn header-action-btn--cta"
                    aria-label="Iniciar sesión">
                    <svg class="header-action-btn__icon" aria-hidden="true" focusable="false"><use href="#icon-user"></use></svg>
                    <span>Iniciar sesión</span>
                </a>

            </div>
        </div>

        <!-- Barra inferior: navegación + acciones -->
        <div class="site-header__utilbar">
            <div class="site-header__utilbar-inner layout-bound">

                <nav id="main-nav" class="site-nav" aria-label="Navegación principal">
                    <div class="site-nav__track" role="tablist" aria-label="Secciones del sitio">
                        <div class="site-nav__indicator" aria-hidden="true"></div>
                        <a href="/via-regalo" data-route id="nav-viaregalo" class="site-nav__tab active" role="tab" aria-selected="true"
                            aria-controls="view-viaregalo" aria-current="page" title="Vía regalo">
                            <svg class="site-nav__icon" aria-hidden="true" focusable="false"><use href="#icon-gift"></use></svg>
                            <span>Vía Regalo</span>
                        </a>
                        <a href="/via-cuenta" data-route id="nav-viacuenta" class="site-nav__tab" role="tab" aria-selected="false"
                            aria-controls="view-viacuenta" title="Vía Cuenta">
                            <svg class="site-nav__icon" aria-hidden="true" focusable="false"><use href="#icon-vbucks"></use></svg>
                            <span>Vía Cuenta</span>
                        </a>
                        <a href="/juegos" data-route id="nav-juegos" class="site-nav__tab" role="tab" aria-selected="false"
                            aria-controls="view-juegos" title="Juegos">
                            <svg class="site-nav__icon site-nav__icon--games" aria-hidden="true" focusable="false"><use href="#icon-gamepad-stroke"></use></svg>
                            <span>Juegos</span>
                        </a>
                    </div>
                </nav>

                <div class="site-header__actions">
                    <div id="shop-timer" class="timer-pill">
                        <div class="timer-pill__spinner"></div>
                    </div>

                    <a href="/cuenta/ordenes" id="header-orders" class="header-action-btn" aria-label="Pedidos">
                        <svg class="header-action-btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
                            focusable="false">
                            <path
                                d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                            <polyline points="3.27 6.96 12 12.01 20.73 6.96" />
                            <line x1="12" y1="22.08" x2="12" y2="12" />
                        </svg>
                    </a>

                    <button type="button" id="header-cart" class="header-action-btn cart-trigger"
                        data-cart-action="open" aria-haspopup="dialog" aria-controls="cart-drawer"
                        aria-label="Carrito de compras">
                        <svg class="header-action-btn__icon" aria-hidden="true" focusable="false"><use href="#icon-cart"></use></svg>
                        <span class="cart-badge" data-cart-badge hidden>0</span>
                    </button>

                </div>

            </div>
        </div>

        <!-- Barra móvil -->
        <div class="site-header__mobile-bar">
            <a href="/" data-route class="site-header__logo site-header__logo--mobile" aria-label="Banano Store — Ir al inicio">
                <svg class="site-header__logo-mark" aria-hidden="true" focusable="false"><use href="#icon-logo"></use></svg>
                <span class="site-header__wordmark">Banano Store</span>
            </a>
            <div class="site-header__mobile-right">
                <div id="mobile-shop-timer" class="timer-pill timer-pill--compact">
                    <div class="timer-pill__spinner"></div>
                </div>
            </div>
        </div>

    </header>

    <div id="search" data-state="idle" role="search" aria-labelledby="search-label">
        <button id="search-trigger" type="button" aria-label="Abrir búsqueda" aria-expanded="false" aria-controls="search-panel">
            <svg aria-hidden="true" focusable="false"><use href="#icon-search"></use></svg>
        </button>
        
        <div id="search-panel" hidden aria-modal="false">
            <label id="search-label" class="sr-only" for="search-input">Buscar objetos</label>
            <input id="search-input" type="search" placeholder="Buscar objetos..." autocomplete="off" aria-describedby="search-help">
            <span id="search-help" class="sr-only">Escriba para buscar objetos en la tienda</span>
            <button id="search-close" type="button" aria-label="Cerrar búsqueda">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M4.47 5.53a.75.75 0 0 1 1.06 0L12 11.94l6.47-6.47a.75.75 0 1 1 1.06 1.06L13.06 12l6.47 6.47a.75.75 0 1 1-1.06 1.06L12 13.06l-6.47 6.47a.75.75 0 0 1-1.06-1.06L10.94 12 4.47 5.53Z" />
                </svg>
            </button>
        </div>
    </div>

    <div class="date-bar" hidden aria-hidden="true">
        <p id="current-date"></p>
    </div>

    <!-- Contenido principal -->

    <main id="main-content" class="main-content" tabindex="-1">
        <section id="view-viaregalo" role="tabpanel" aria-labelledby="nav-viaregalo">
            <h2 id="viaregalo-heading" class="sr-only">Vía Regalo - Objetos de Fortnite</h2>
            <nav id="section-dropdown" class="md:hidden" aria-label="Secciones de la tienda (móvil)">
                <div id="section-dropdown-bar" class="w-full flex items-center justify-between px-2 py-1">
                    <button id="filter-btn-mobile" type="button"
                        class="filter-button flex items-center justify-center text-white"
                        aria-label="Abrir panel de filtros"><svg class="w-6 h-6" aria-hidden="true" focusable="false"><use href="#icon-filter"></use></svg>
                        <div class="badge-dot"></div>
                    </button>
                    <button id="section-dropdown-trigger" type="button"
                        class="flex-grow flex items-center justify-between pl-4 pr-2 py-2" aria-haspopup="true"
                        aria-expanded="false" aria-controls="section-dropdown-list">
                        <span id="section-dropdown-label"
                            class="font-bold text-white uppercase truncate">Cargando...</span>
                        <svg id="section-dropdown-caret"
                            class="svg-icon modal-meta-label transition-transform duration-300"
                            aria-hidden="true"><use href="#icon-chevron-down" xlink:href="#icon-chevron-down"></use></svg>
                    </button>
                </div>
                <div id="section-dropdown-overlay">
                    <ul id="section-dropdown-list" class="w-full p-4 space-y-2"></ul>
                </div>
            </nav>
            <div id="mobile-clear-filter-widget"
                class="md:hidden w-full px-4 pt-4 flex flex-col items-center gap-2 hidden">
                <p class="text-xs font-bold uppercase tracking-wider text-amber-400">Filtros aplicados</p>
                <button id="mobile-clear-btn" type="button"
                    class="cursor-pointer text-center overflow-hidden relative outline-1 outline-offset-0 outline-transparent disabled:cursor-not-allowed disabled:opacity-40 font-bold justify-center focus-visible:outline-white focus-visible:outline-4 focus-visible:outline-offset-4 transition-colors duration-150 bg-white/80 hover:bg-white/90 active:bg-white/85 backdrop-blur-md px-4 py-2 text-sm rounded-md flex items-center gap-2 uppercase">
                    <span>Borrar filtros</span>
                    <svg viewBox="0 0 24 24" class="w-5 h-5 inline-block" aria-hidden="true" focusable="false">
                        <path fill="currentColor"
                            d="M4.84071 6.432C4.25492 7.01779 4.25492 7.96754 4.84071 8.55332L8.28786 12.0005L4.84071 15.4476C4.25493 16.0334 4.25493 16.9831 4.84071 17.5689L6.4317 19.1599C7.01749 19.7457 7.96724 19.7457 8.55302 19.1599L12.0002 15.7128L15.4473 19.1599C16.0331 19.7457 16.9828 19.7457 17.5686 19.1599L19.1596 17.5689C19.7454 16.9831 19.7454 16.0334 19.1596 15.4476L15.7125 12.0005L19.1596 8.55332C19.7454 7.96754 19.7454 7.01779 19.1596 6.432L17.5686 4.84101C16.9829 4.25523 16.0331 4.25523 15.4473 4.84101L12.0002 8.28816L8.55302 4.84101C7.96723 4.25523 7.01748 4.25523 6.4317 4.84101L4.84071 6.432Z">
                        </path>
                    </svg>
                </button>
            </div>
            <div class="flex-grow layout-bound py-4 sm:py-8">
                <div id="viaregalo-content"></div>
                <div id="error-message" class="text-center py-16" hidden role="alert">
                    <svg class="svg-icon text-4xl text-red-500 mb-4" aria-hidden="true"><use href="#icon-warning" xlink:href="#icon-warning"></use></svg>
                    <h2 class="text-2xl font-bold mb-2">¡Oh no! No se pudieron cargar los artículos</h2>
                    <button id="retry-button"
                        class="refresh-button px-6 py-3 rounded-full font-medium text-white">Reintentar</button>
                </div>
            </div>
        </section>

        <section id="view-viacuenta" class="container mx-auto px-4 py-8 max-w-7xl"
            role="tabpanel" aria-labelledby="nav-viacuenta" hidden>
            <h2 id="viacuenta-heading" class="sr-only">Productos Vía Cuenta</h2>
        </section>

        <section id="view-juegos" class="container mx-auto px-4 py-8 max-w-7xl" role="tabpanel" aria-labelledby="nav-juegos" hidden>
            <?php include_once __DIR__ . '/juegos/index.php'; ?>
        </section>

        <section id="view-item" class="container mx-auto px-4 py-8 max-w-7xl" hidden>
            <h2 class="sr-only">Detalle del objeto</h2>
        </section>

        <section id="view-producto" class="container mx-auto px-4 py-8 max-w-7xl" hidden>
            <h2 class="sr-only">Detalle del producto</h2>
        </section>

    </main>

    <!-- Footer -->
    <footer id="site-footer" class="site-footer">
        <div class="site-footer__container">
            <h4 class="site-footer__brand">Banano Store</h4>
            <p class="site-footer__copyright">
                Copyright &copy; <?php echo date('Y'); ?> Banano Store - Todos los derechos reservados.
            </p>
            <p class="site-footer__disclaimer">
                Banano Store no está respaldado, patrocinado, ni afiliado de ninguna manera con Epic Games, Inc.
                Fortnite y todos los logos, diseños, imágenes y marcas relacionadas son propiedad y marcas
                registradas de Epic Games, Inc.
            </p>
        </div>
    </footer>

    <!-- Elementos fijos (aside) -->

    <aside role="complementary">
        <div id="side-nav-wrapper"
            class="fixed top-1/2 -translate-y-1/2 left-4 z-30 hidden md:flex flex-col gap-3 justify-center items-start pr-4 opacity-0 invisible transition-opacity duration-300">
            
            <!-- Filtro desktop -->
            <div id="desktop-filter-container">
              <div class="section-nav__header-actions">
                <div id="desktop-clear-filter-widget"
                    class="absolute bottom-full mb-2 left-0 flex flex-col items-center w-max hidden">
                    <p class="text-xs font-bold uppercase tracking-wider text-amber-400 mb-1">Filtros aplicados</p>
                    <button id="desktop-clear-btn" type="button"
                        class="cursor-pointer text-center overflow-hidden relative outline-1 outline-offset-0 outline-transparent disabled:cursor-not-allowed disabled:opacity-40 font-bold justify-center focus-visible:outline-white focus-visible:outline-4 focus-visible:outline-offset-4 transition-colors duration-150 bg-white/80 hover:bg-white/90 active:bg-white/85 backdrop-blur-md px-3 py-1 text-xs rounded-md flex items-center gap-2 uppercase">
                        <span>Borrar filtros</span>
                        <svg viewBox="0 0 24 24" class="w-4 h-4 inline-block" aria-hidden="true" focusable="false">
                            <path fill="currentColor"
                                d="M4.84071 6.432C4.25492 7.01779 4.25492 7.96754 4.84071 8.55332L8.28786 12.0005L4.84071 15.4476C4.25493 16.0334 4.25493 16.9831 4.84071 17.5689L6.4317 19.1599C7.01749 19.7457 7.96724 19.7457 8.55302 19.1599L12.0002 15.7128L15.4473 19.1599C16.0331 19.7457 16.9828 19.7457 17.5686 19.1599L19.1596 17.5689C19.7454 16.9831 19.7454 16.0334 19.1596 15.4476L15.7125 12.0005L19.1596 8.55332C19.7454 7.96754 19.7454 7.01779 19.1596 6.432L17.5686 4.84101C16.9829 4.25523 16.0331 4.25523 15.4473 4.84101L12.0002 8.28816L8.55302 4.84101C7.96723 4.25523 7.01748 4.25523 6.4317 4.84101L4.84071 6.432Z">
                            </path>
                        </svg>
                    </button>
                </div>
                <button id="filter-btn-desktop" type="button" class="filter-button flex items-center justify-center"
                    aria-label="Filtros" title="Filtros">
                    <span class="mr-2 uppercase text-sm font-black">Filtros</span>
                    <svg class="w-5 h-5 inline-block" aria-hidden="true" focusable="false"><use href="#icon-filter"></use></svg>
                    <div class="badge-dot"></div>
                </button>
              </div>
            </div>
            
            <!-- Navegación lateral de secciones -->
            <nav id="section-side-nav"
                 class="section-nav"
                 aria-label="Navegación de secciones de la tienda">
              
              <div class="section-nav__scroll-container">
                
                <div id="section-nav-items" class="section-nav__items">
                </div>
                
              </div>
              
            </nav>
        </div>

        <div id="chat-links"
            class="fixed right-8 top-1/2 -translate-y-1/2 z-20 hidden md:flex flex-col items-end gap-4">
            <button type="button" id="community-fab" class="community-fab group"
                aria-haspopup="dialog" aria-controls="community-modal"
                aria-label="Únete a la comunidad de WhatsApp">
                <span class="community-fab__pulse" aria-hidden="true"></span>
                <img class="community-fab__img"
                    src="https://res.cloudinary.com/dq3xsjgwi/image/upload/v1781417950/imagen_comunidad_otqrac.png"
                    alt="" loading="lazy" decoding="async">
                <span class="community-fab__tooltip" role="tooltip">Únete a la comunidad</span>
            </button>
        </div>

        <div id="page-corner-actions"
            class="fixed bottom-8 right-8 z-20 hidden md:flex flex-col items-end gap-4 transition-transform duration-350 ease-in-out">
        </div>

        <button id="back-to-top" class="fixed bottom-8 right-8 z-20 transition-opacity duration-300"
            aria-label="Volver al principio de la página">
            <svg class="svg-icon" aria-hidden="true"><use href="#icon-arrow-up" xlink:href="#icon-arrow-up"></use></svg>
        </button>

    </aside>

    <!-- Widget de redes sociales: hermano de <main>/<aside>, fuera del <aside>
         para que NO lo oculte la regla de la vista "juegos"
         (body[data-view="games"] > aside { display:none }) y aparezca
         consistentemente en todas las vistas. -->
        <div id="social-container">
            <div class="relative w-36 h-36 group">
                <div id="social-menu" role="menu">
                    <a href="https://chat.whatsapp.com/Gag2PChup23DaDpmWKUAbd" target="_blank"
                        rel="noopener noreferrer" aria-label="Canal de WhatsApp" role="menuitem"
                        class="social-icon w-12 h-12 rounded-full bg-slate-900/80 flex items-center justify-center absolute bottom-0 left-0 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-300 delay-75 transform scale-50 group-hover:scale-100 group-hover:-translate-y-24 group-hover:translate-x-0"><img
                            src="https://res.cloudinary.com/dq3xsjgwi/image/upload/v1751217174/iconowhatsappchannels_cgi9qr.png"
                            alt="Canal de WhatsApp" class="w-7 h-7"></a>
                    <a href="https://www.facebook.com/banano.pe" target="_blank" rel="noopener noreferrer"
                        aria-label="Página de Facebook" role="menuitem"
                        class="social-icon w-12 h-12 rounded-full bg-slate-900/80 flex items-center justify-center absolute bottom-0 left-0 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-300 delay-150 transform scale-50 group-hover:scale-100 group-hover:-translate-y-[84px] group-hover:translate-x-12"><svg
                            class="svg-icon text-blue-400 text-xl" aria-hidden="true"><use href="#icon-facebook-f" xlink:href="#icon-facebook-f"></use></svg></a>
                    <a href="https://www.instagram.com/bananostore/" target="_blank" rel="noopener noreferrer"
                        aria-label="Perfil de Instagram" role="menuitem"
                        class="social-icon w-12 h-12 rounded-full bg-slate-900/80 flex items-center justify-center absolute bottom-0 left-0 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-300 delay-200 transform scale-50 group-hover:scale-100 group-hover:-translate-y-12 group-hover:translate-x-[84px]"><svg
                            class="svg-icon text-purple-400 text-xl" aria-hidden="true"><use href="#icon-instagram" xlink:href="#icon-instagram"></use></svg></a>
                    <a href="https://www.tiktok.com/@banano.pe" target="_blank" rel="noopener noreferrer"
                        aria-label="Perfil de TikTok" role="menuitem"
                        class="social-icon w-12 h-12 rounded-full bg-slate-900/80 flex items-center justify-center absolute bottom-0 left-0 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-300 delay-300 transform scale-50 group-hover:scale-100 group-hover:-translate-y-0 group-hover:translate-x-24"><svg
                            class="svg-icon text-cyan-200 text-xl" aria-hidden="true"><use href="#icon-tiktok" xlink:href="#icon-tiktok"></use></svg></a>
                </div>
                <a id="social-trigger" href="https://linktr.ee/bananostore" target="_blank"
                    rel="noopener noreferrer" aria-label="Ver todas las redes sociales"
                    class="absolute bottom-0 left-0 z-10"><svg class="social-trigger__icon"
                        xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round" aria-hidden="true" focusable="false">
                        <circle cx="18" cy="5" r="3" />
                        <circle cx="6" cy="12" r="3" />
                        <circle cx="18" cy="19" r="3" />
                        <line x1="8.59" y1="13.51" x2="15.42" y2="17.49" />
                        <line x1="15.41" y1="6.51" x2="8.59" y2="10.49" />
                    </svg></a>
            </div>
        </div>
    <template id="section-skeleton-template">
        <div class="mb-12 scroll-mt-24">
            <h2 class="section-header skeleton-header">
            </h2>
            <div class="section-items-container">
            </div>
        </div>
    </template>

    <!-- Plantilla de tarjeta de item que consume createItemCardNode() -->
    <template id="item-card-template">
        <div class="item-card-wrapper item-tile">
            <div class="item-card-content">
                <a class="item-image-container" aria-label="Ver detalles del artículo">
                    <img class="item-image" loading="lazy" alt="" />
                </a>
                <div class="card-info-overlay">
                    <div class="card-info-body">
                        <h3 class="card-title" title=""></h3>
                        <p class="text-sm text-gray-300 hidden"></p>
                        <div class="card-footer-pricing flex items-center justify-between">
                            <div class="vbucks-block flex items-center"
                                aria-label="Precio en V-Bucks" data-currency="vbucks">
                                <svg class="icon vbucks-icon" aria-hidden="true" focusable="false"
                                    viewBox="0 0 24 24" width="24" height="24">
                                    <use href="#icon-pavos" xlink:href="#icon-pavos"></use>
                                </svg>
                                <span class="sr-only">V-Bucks:</span>
                                <span class="vbucks-price"></span>
                            </div>
                            <div class="price-block"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>

    <!-- Plantillas auxiliares para clonación desde JS (sin HTML embebido en JS) -->
    <template id="empty-state-template">
        <div class="text-center empty-state-text my-16">No se encontraron resultados.</div>
    </template>
    <template id="nav-dot-link-template">
        <a class="nav-dot-link p-1.5" aria-label="Ir a sección">
            <div class="nav-dot rounded-full"></div>
        </a>
    </template>
    <template id="nav-text-link-template">
        <a class="nav-text-link block p-1" role="button">
            <div></div>
        </a>
    </template>
    <template id="dropdown-item-template">
        <li><a class="section-link-item"></a></li>
    </template>
    <template id="carousel-wrapper-template">
        <div class="shop-carousel">
            <div class="shop-carousel__viewport">
                <div class="shop-carousel__track"></div>
            </div>
            <div class="shop-carousel__nav">
                <button class="shop-carousel__arrow shop-carousel__arrow--prev" type="button" aria-label="Anterior" disabled aria-disabled="true">
                    <svg aria-hidden="true" viewBox="0 0 24 24"><use href="#icon-carousel-arrow-prev" xlink:href="#icon-carousel-arrow-prev"></use></svg>
                </button>
                <button class="shop-carousel__arrow shop-carousel__arrow--next" type="button" aria-label="Siguiente" aria-disabled="false">
                    <svg aria-hidden="true" viewBox="0 0 24 24"><use href="#icon-carousel-arrow-next" xlink:href="#icon-carousel-arrow-next"></use></svg>
                </button>
            </div>
        </div>
    </template>

    <nav id="mobile-nav" class="mobile-bottom-bar" aria-label="Navegación móvil">
        <a href="/via-regalo" data-route id="mobile-nav-viaregalo" aria-label="Vía regalo" class="mobile-nav-link">
            <span class="mobile-nav-icon">
                <svg aria-hidden="true" focusable="false"><use href="#icon-gift"></use></svg>
            </span>
            <span class="mobile-nav-label">Regalo</span>
        </a>
        <a href="/via-cuenta" data-route id="mobile-nav-viacuenta" class="mobile-nav-link">
            <span class="mobile-nav-icon">
                <svg aria-hidden="true" focusable="false"><use href="#icon-vbucks"></use></svg>
            </span>
            <span class="mobile-nav-label">Cuenta</span>
        </a>
        <a href="/juegos" data-route id="mobile-nav-juegos" aria-label="Juegos" class="mobile-nav-link">
            <span class="mobile-nav-icon">
                <svg aria-hidden="true" focusable="false"><use href="#icon-gamepad-stroke"></use></svg>
            </span>
            <span class="mobile-nav-label">Juegos</span>
        </a>
        <button type="button" id="mobile-nav-cart-button" class="mobile-nav-link cart-trigger"
            data-cart-action="open" aria-haspopup="dialog" aria-controls="cart-drawer" aria-label="Carrito de compras">
            <span class="mobile-nav-icon">
                <svg aria-hidden="true" focusable="false"><use href="#icon-cart"></use></svg>
            </span>
            <span class="mobile-nav-label">Carrito</span>
            <span class="cart-badge" data-cart-badge hidden>0</span>
        </button>
        <a href="/login" id="mobile-nav-login" class="mobile-nav-link" aria-label="Login">
            <span class="mobile-nav-icon">
                <svg aria-hidden="true" focusable="false"><use href="#icon-user"></use></svg>
            </span>
            <span class="mobile-nav-label">Login</span>
        </a>
    </nav>

    <!-- Carrito: cajón único (escritorio y móvil) -->
    <?php include __DIR__ . '/cart/drawer.php'; ?>
    <!-- Comunidad de WhatsApp: invitación a pantalla completa -->
    <div id="community-modal" class="community-modal" role="dialog" aria-modal="true"
        aria-labelledby="community-modal-title" hidden>
        <div class="community-modal__backdrop" data-community-close></div>
        <div class="community-modal__shell" role="document">
            <button type="button" class="community-modal__close" data-community-close
                aria-label="Cerrar"><svg class="svg-icon" aria-hidden="true"><use href="#icon-close" xlink:href="#icon-close"></use></svg></button>

            <div class="community-modal__visual">
                <span class="community-modal__halo" aria-hidden="true"></span>
                <span class="community-modal__halo community-modal__halo--two" aria-hidden="true"></span>
                <img class="community-modal__avatar"
                    src="https://res.cloudinary.com/dq3xsjgwi/image/upload/v1781417950/imagen_comunidad_otqrac.png"
                    alt="Comunidad de WhatsApp de Banano Store" decoding="async">
            </div>

            <div class="community-modal__body">
                <span class="community-modal__eyebrow">Comunidad oficial</span>
                <h2 id="community-modal-title" class="community-modal__title">Únete a la comunidad</h2>
                <p class="community-modal__lead">Únete a la comunidad de WhatsApp. Recibe notificaciones sobre novedades de la tienda, sorteos y descuentos.</p>

                <ul class="community-perks">
                    <li class="community-perk">
                        <span class="community-perk__icon"><svg class="svg-icon" aria-hidden="true"><use href="#icon-gift" xlink:href="#icon-gift"></use></svg></span>
                        <span class="community-perk__text"><strong>Cupones y ofertas exclusivas</strong>Accede a descuentos especiales y códigos promocionales que solo compartimos por este medio.</span>
                    </li>
                    <li class="community-perk">
                        <span class="community-perk__icon"><svg class="svg-icon" aria-hidden="true"><use href="#icon-bolt" xlink:href="#icon-bolt"></use></svg></span>
                        <span class="community-perk__text"><strong>Sorteos Flash</strong>Por allí se avisa cuando se activa un nuevo sorteo para que puedas asegurar tu ticket a tiempo.</span>
                    </li>
                    <li class="community-perk">
                        <span class="community-perk__icon"><svg class="svg-icon" aria-hidden="true"><use href="#icon-clock" xlink:href="#icon-clock"></use></svg></span>
                        <span class="community-perk__text"><strong>Nuevos objetos y precios</strong>Entérate de los precios a los pocos minutos de que salgan nuevas skins en el juego.</span>
                    </li>
                </ul>

                <a class="community-modal__cta" href="https://chat.whatsapp.com/Gag2PChup23DaDpmWKUAbd"
                    target="_blank" rel="noopener noreferrer"><svg class="svg-icon" aria-hidden="true"><use href="#icon-whatsapp" xlink:href="#icon-whatsapp"></use></svg>Unirme a la comunidad</a>
            </div>
        </div>
    </div>

    <div id="music-player-wrapper">
        <div id="music-video-container">
            <div id="music-video-overlay"></div>
        </div>
        <div id="music-control-bar" class="hidden md:flex">
            <div class="control-inner w-full rounded-lg shadow-lg p-2 flex items-center gap-2">
                <img id="music-control-artwork" class="w-10 h-10 rounded-md object-cover mr-2 flex-shrink-0 hidden"
                    src="" alt="Artwork de la canción">
                <div id="music-control-info" class="flex-grow overflow-hidden cursor-pointer">
                    <p id="music-control-title" class="text-white font-bold truncate text-sm">Nombre de la canción</p>
                    <p id="music-control-artist" class="text-slate-400 text-xs truncate">Artista</p>
                </div>
                <div class="flex items-center flex-shrink-0">
                    <button id="music-control-expand" aria-label="Expandir reproductor" class="hidden md:flex"><svg
                            class="svg-icon" aria-hidden="true"><use href="#icon-expand" xlink:href="#icon-expand"></use></svg></button>
                    <button id="music-control-toggle" aria-label="Pausar"><svg class="svg-icon"
                            aria-hidden="true"><use href="#icon-pause" xlink:href="#icon-pause"></use></svg></button>
                    <button id="music-control-close" aria-label="Cerrar reproductor"><svg class="svg-icon"
                            aria-hidden="true"><use href="#icon-close" xlink:href="#icon-close"></use></svg></button>
                </div>
            </div>
        </div>
        <div id="mobile-music-controller" hidden>
            <img id="mobile-music-artwork" class="w-12 h-12 rounded-lg object-cover" src="" alt="Artwork de la canción">
            <button id="mobile-music-toggle" aria-label="Pausar"
                class="w-10 h-10 flex items-center justify-center text-white"><svg class="svg-icon"
                    aria-hidden="true"><use href="#icon-pause" xlink:href="#icon-pause"></use></svg></button>
            <button id="mobile-music-close" aria-label="Cerrar reproductor"
                class="w-10 h-10 flex items-center justify-center text-white"><svg class="svg-icon"
                    aria-hidden="true"><use href="#icon-close" xlink:href="#icon-close"></use></svg></button>
        </div>
    </div>

    <div id="image-preloader" aria-hidden="true"></div>

    <!-- Scripts -->
    <script src="https://www.youtube.com/iframe_api" defer></script>
    <script src="/GlobalBackdropManager.js"></script>
    <script src="/avatar-fallback.js?v=20260914"></script>
    <script src="/SectionSpy.js?v=20260911" defer></script>
    <script src="/SectionNav.js?v=20260911" defer></script>
    <script src="/carousel-interaction.js?v=20260912" defer></script>
    <script src="/section-backdrop.js?v=20260913" defer></script>
    <script src="/app.js?v=20260914" defer></script>
    <script src="/videoCommunity.js?v=20260917" defer></script>
    <script src="/main.js?v=20260913" defer></script>

    <?php include_once __DIR__ . '/juegos/daily_spin/widget.php'; ?>

    <script>
        (function () {
            var modal = document.getElementById('community-modal');
            var trigger = document.getElementById('community-fab');
            if (!modal || !trigger) return;

            var lastFocused = null;

            function openModal() {
                lastFocused = document.activeElement;
                modal.hidden = false;
                requestAnimationFrame(function () {
                    modal.classList.add('is-open');
                });
                document.body.classList.add('community-modal-open');
                if (window.Backdrop) window.Backdrop.acquire('communityModal', modal, closeModal, { lockScroll: true });
                trigger.setAttribute('aria-expanded', 'true');
                var cta = modal.querySelector('.community-modal__cta');
                if (cta) cta.focus({ preventScroll: true });
            }

            function closeModal() {
                modal.classList.remove('is-open');
                document.body.classList.remove('community-modal-open');
                if (window.Backdrop) window.Backdrop.release('communityModal');
                trigger.setAttribute('aria-expanded', 'false');
                var onEnd = function () {
                    modal.hidden = true;
                    modal.removeEventListener('transitionend', onEnd);
                };
                modal.addEventListener('transitionend', onEnd);
                if (lastFocused && typeof lastFocused.focus === 'function') {
                    lastFocused.focus({ preventScroll: true });
                }
            }

            trigger.addEventListener('click', openModal);

            modal.querySelectorAll('[data-community-close]').forEach(function (el) {
                el.addEventListener('click', closeModal);
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !modal.hidden) closeModal();
            });
        })();
    </script>

    <script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "OnlineStore",
  "name": "Banano Store",
  "description": "Tienda de artículos de Fortnite con actualizaciones diarias.",
  "url": <?= json_encode($PAGE_BASE_URL . '/', JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>,
  "logo": <?= json_encode($PAGE_BASE_URL . '/logo.png', JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>
}
</script>

    <!-- Tailwind safelist (clases dinámicas) -->
    <div class="hidden">
        <!-- Clases para el Grid de la Tienda -->
        <div class="col-span-1"></div>
        <div class="col-span-2"></div>
        <div class="col-span-3"></div>
        <div class="col-span-4"></div>
        <div class="md:col-span-1"></div>
        <div class="md:col-span-2"></div>
        <div class="md:col-span-3"></div>
        <div class="lg:col-span-1"></div>
        <div class="lg:col-span-2"></div>
        <div class="lg:col-span-3"></div>
        <div class="lg:col-span-4"></div>
    </div>

</body>

</html>
<?php

declare(strict_types=1);

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0f0f14">
    <title>Tarjetas · Centro de rotación</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Roboto+Mono:wght@500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="tc-app" id="tc-app" aria-hidden="true">
        <header class="tc-header">
            <a class="tc-brand" href="/tarjetas/" aria-label="Inicio de Tarjetas">
                <span class="tc-brand-mark" data-icon="cards" aria-hidden="true"></span>
                <span>
                    <strong>Tarjetas</strong>
                    <small>Centro de rotación</small>
                </span>
            </a>

            <div class="tc-header-stats" aria-label="Resumen">
                <span><b id="tc-stat-active">0</b> activas</span>
                <span><b id="tc-stat-ready">0</b> listas</span>
                <span><b id="tc-stat-profiles">0</b> perfiles</span>
            </div>

            <div class="tc-header-actions">
                <button class="tc-btn tc-btn--primary" id="tc-add-card" type="button" data-icon="plus">
                    Nueva tarjeta
                </button>
                <button class="tc-icon-btn" id="tc-add-profile-header" type="button" title="Nuevo perfil" aria-label="Nuevo perfil" data-icon="user-plus"></button>
                <button class="tc-icon-btn" id="tc-settings" type="button" title="Configuración" aria-label="Configuración" data-icon="settings"></button>
                <button class="tc-icon-btn" id="tc-theme" type="button" title="Cambiar tema" aria-label="Cambiar tema" data-icon="moon"></button>
                <button class="tc-icon-btn" id="tc-logout" type="button" title="Bloquear" aria-label="Bloquear" data-icon="lock"></button>
            </div>
        </header>

        <main class="tc-main">
            <section class="tc-workspace" aria-labelledby="tc-cards-title">
                <div class="tc-section-heading">
                    <div>
                        <p class="tc-eyebrow">Operación diaria</p>
                        <h1 id="tc-cards-title">Tarjetas disponibles</h1>
                    </div>
                    <div class="tc-view-control">
                        <label for="tc-preset-select">Vista</label>
                        <select id="tc-preset-select" aria-label="Preajuste de visualización"></select>
                        <button class="tc-icon-btn tc-icon-btn--small" id="tc-edit-layout" type="button" title="Crear preajuste" aria-label="Crear preajuste" data-icon="sliders"></button>
                    </div>
                </div>

                <div class="tc-toolbar">
                    <label class="tc-search">
                        <span data-icon="search" aria-hidden="true"></span>
                        <span class="tc-sr-only">Buscar tarjetas</span>
                        <input id="tc-card-search" type="search" placeholder="Buscar titular, banco o últimos dígitos…" autocomplete="off">
                    </label>
                    <select id="tc-profile-filter" aria-label="Filtrar por perfil">
                        <option value="">Todos los perfiles</option>
                    </select>
                    <label class="tc-ready-toggle">
                        <input id="tc-ready-only" type="checkbox">
                        <span>Solo listas</span>
                    </label>
                </div>

                <div class="tc-card-grid" id="tc-card-grid" aria-live="polite"></div>
            </section>

            <aside class="tc-profiles" aria-labelledby="tc-profiles-title">
                <div class="tc-profiles-head">
                    <div>
                        <p class="tc-eyebrow">Cuentas fijas</p>
                        <h2 id="tc-profiles-title">Perfiles</h2>
                    </div>
                    <button class="tc-icon-btn tc-icon-btn--small" id="tc-add-profile" type="button" title="Nuevo perfil" aria-label="Nuevo perfil" data-icon="plus"></button>
                </div>
                <label class="tc-search tc-search--compact">
                    <span data-icon="search" aria-hidden="true"></span>
                    <span class="tc-sr-only">Buscar perfiles</span>
                    <input id="tc-profile-search" type="search" placeholder="Buscar perfil…" autocomplete="off">
                </label>
                <div class="tc-profile-list" id="tc-profile-list"></div>
            </aside>
        </main>
    </div>

    <div id="tc-overlay-root"></div>
    <div class="tc-toasts" id="tc-toasts" aria-live="polite"></div>
    <div class="tc-sr-only" id="tc-live" aria-live="polite"></div>

    <script src="assets/js/api.js"></script>
    <script src="assets/js/ui.js"></script>
    <script src="assets/js/lock.js"></script>
    <script src="assets/js/presets.js"></script>
    <script src="assets/js/profile-manager.js"></script>
    <script src="assets/js/cards.js"></script>
    <script src="assets/js/app.js"></script>
</body>
</html>

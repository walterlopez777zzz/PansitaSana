<?php
/**
 * ============================================================
 * Tareas — Shell de la aplicación (SPA)
 * ============================================================
 * Sirve la carcasa HTML. Los datos viven detrás de la API, que
 * exige sesión con PIN validado (defensa en el servidor). Aquí no
 * se inyecta ningún dato sensible.
 * ============================================================
 */
declare(strict_types=1);

// Cabeceras de seguridad a nivel de documento.
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0f0f14">
    <title>Tareas · Espacio de trabajo</title>
    <link rel="icon" href="/tareas/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/tareas/favicon.ico" sizes="32x32">
    <link rel="apple-touch-icon" href="/tareas/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="tk-app" id="tk-app">
        <div class="tk-shell">

            <header class="tk-header">
                <div class="tk-brand">
                    <span class="tk-logo" aria-hidden="true" data-icon="bolt" data-icon-size="22" data-icon-filled="true"></span>
                    <div>
                        <h1>Tareas</h1>
                        <p>Ideas al vuelo · misiones bajo control</p>
                    </div>
                </div>

                <div class="tk-header-actions">
                    <div class="tk-history-controls" role="group" aria-label="Historial">
                        <button class="tk-icon-btn" id="tk-undo-btn" type="button" title="Deshacer" aria-label="Deshacer" data-icon="undo" disabled></button>
                        <button class="tk-icon-btn" id="tk-redo-btn" type="button" title="Rehacer" aria-label="Rehacer" data-icon="redo" disabled></button>
                    </div>
                    <button class="tk-icon-btn" id="tk-cmd-btn" type="button" title="Paleta de comandos (Ctrl/⌘ K)" aria-label="Paleta de comandos" data-icon="command"></button>
                    <button class="tk-icon-btn tk-help-btn" id="tk-help-btn" type="button" title="Atajos de teclado (?)" aria-label="Atajos de teclado" data-icon="help"></button>
                    <button class="tk-icon-btn" id="tk-tags-btn" type="button" title="Etiquetas" aria-label="Etiquetas" data-icon="tag"></button>
                    <button class="tk-icon-btn" id="tk-templates-btn" type="button" title="Plantillas" aria-label="Plantillas" data-icon="layout"></button>
                    <button class="tk-icon-btn" id="tk-colaboradores-btn" type="button" title="Colaboradores" aria-label="Colaboradores" data-icon="users"></button>
                    <button class="tk-icon-btn" id="tk-data-btn" type="button" title="Exportar / Importar" aria-label="Exportar o importar datos" data-icon="download"></button>
                    <button class="tk-icon-btn" id="tk-theme-toggle" type="button" title="Cambiar tema" aria-label="Cambiar tema"></button>
                    <button class="tk-icon-btn" id="tk-logout-btn" type="button" title="Bloquear" aria-label="Bloquear" data-icon="logout"></button>
                </div>
            </header>

            <nav class="tk-tabs" role="tablist" aria-label="Vistas">
                <button class="tk-tab is-active" data-view="ideas" role="tab" type="button" data-icon="bolt" data-icon-size="16">
                    Ideas <span class="tk-count" id="tk-count-ideas">0</span>
                </button>
                <button class="tk-tab" data-view="misiones" role="tab" type="button" data-icon="diana" data-icon-size="16">
                    Misiones <span class="tk-count" id="tk-count-misiones">0</span>
                </button>
            </nav>

            <section class="tk-view is-active" id="view-ideas" role="tabpanel">
                <div id="tk-composer-mount"></div>
                <div id="tk-ideas-tools"></div>
                <div id="tk-ideas-mount"></div>
            </section>

            <section class="tk-view" id="view-misiones" role="tabpanel">
                <div id="tk-mis-stats"></div>
                <div id="tk-mis-toolbar"></div>
                <div id="tk-reminders-mount"></div>
                <div id="tk-misiones-mount"></div>
            </section>

        </div>
    </div>

    <script src="assets/js/api.js"></script>
    <script src="assets/js/ui.js"></script>
    <script src="assets/js/dnd.js"></script>
    <script src="assets/js/history.js"></script>
    <script src="assets/js/lock.js"></script>
    <script src="assets/js/tags.js"></script>
    <script src="assets/js/dates.js"></script>
    <script src="assets/js/palette.js"></script>
    <script src="assets/js/ideas.js"></script>
    <script src="assets/js/misiones_view.js"></script>
    <script src="assets/js/colaboradores.js"></script>
    <script src="assets/js/templates.js"></script>
    <script src="assets/js/mision_detail.js"></script>
    <script src="assets/js/data_io.js"></script>
    <script src="assets/js/reconcile.js"></script>
    <script src="assets/js/realtime.js"></script>
    <script src="assets/js/app.js"></script>
</body>
</html>

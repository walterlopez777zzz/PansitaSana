<?php
/**
 * Vista «Juegos» — hub de Giro Diario y Sorteo Flash.
 *
 * Se incluye desde cuysito_app/index.php dentro de `#view-juegos`; el shell la
 * muestra u oculta según la ruta. El servidor sólo renderiza el esqueleto
 * estable de cada tarjeta (media administrable, título y los *slots* vacíos);
 * el contenido dependiente del contexto (usuario / sistema) lo escribe el
 * módulo de vista public_html/views/juegos.view.js. Estilos en ./juegos-hub.css
 * (prefijo `gh-`).
 *
 * Contrato con el módulo de vista (sin lógica de sesión en PHP):
 *   [data-gh-game="daily|flash"]  raíz de cada tarjeta; `data-state` refleja la
 *                                 máquina de estados y gobierna el CSS.
 *   [data-gh-slot="…"]            nodos estables que el módulo rellena con
 *                                 textContent/atributos (nunca se crean ni
 *                                 destruyen nodos al cambiar de estado → sin CLS).
 *   #gh-flash-dialog              <dialog> nativo del modal de Sorteo Flash,
 *                                 alimentado por la misma fuente que su tarjeta.
 */
if (PHP_SAPI !== 'cli') {
    $gh_script_filename = $_SERVER['SCRIPT_FILENAME'] ?? '';
    if ($gh_script_filename && realpath($gh_script_filename) === realpath(__FILE__)) {
        header('Location: ../?view=games');
        exit;
    }
}

// --- Contenido administrable (Admin → Visual Juegos): título y media (imagen / SVG) ---
require_once __DIR__ . '/gh_media.php';

$gh_visuals = ['daily' => null, 'flash' => null];

if (!isset($dbHost)) {
    require_once __DIR__ . '/../wabo/core/db_connect.php';
}

// Conexión dedicada: evita estado compartido con $conn del shell. Cualquier
// fallo degrada a los textos por defecto.
if (isset($dbHost, $dbUser, $dbPass, $dbName) && class_exists('mysqli')) {
    try {
        $gh_vis_conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
        if (!$gh_vis_conn->connect_error) {
            $gh_vis_conn->set_charset('utf8mb4');
            // Instalación sin la migración de `media_config` (errno 1054): se sirve el resto igual.
            $gh_vis_select = static function (mysqli $conn, string $media_col) {
                try {
                    $r = $conn->query("SELECT card_key, background_image_url, bg_position_x, bg_position_y, texts_config, {$media_col} FROM game_card_visuals");
                } catch (mysqli_sql_exception $e) {
                    if ($e->getCode() !== 1054) {
                        throw $e;
                    }
                    return null;
                }
                return $r === false && $conn->errno === 1054 ? null : $r;
            };
            $gh_vis_result = $gh_vis_select($gh_vis_conn, 'media_config')
                ?? $gh_vis_select($gh_vis_conn, 'NULL AS media_config');
            if ($gh_vis_result instanceof mysqli_result) {
                while ($gh_vis_row = $gh_vis_result->fetch_assoc()) {
                    if (!array_key_exists($gh_vis_row['card_key'], $gh_visuals)) {
                        continue;
                    }
                    $gh_texts = json_decode((string) ($gh_vis_row['texts_config'] ?? ''), true);
                    $gh_vis_row['_texts'] = is_array($gh_texts) ? $gh_texts : [];
                    $gh_media = json_decode((string) ($gh_vis_row['media_config'] ?? ''), true);
                    $gh_vis_row['_media'] = gh_media_legacy_mode(gh_media_normalize($gh_media)['config'], $gh_media, $gh_vis_row['background_image_url']);
                    $gh_visuals[$gh_vis_row['card_key']] = $gh_vis_row;
                }
                $gh_vis_result->free();
            } else {
                error_log('[juegos] game_card_visuals no disponible: ' . $gh_vis_conn->error);
            }
            $gh_vis_conn->close();
        }
    } catch (mysqli_sql_exception $gh_vis_error) {
        error_log('[juegos] game_card_visuals no disponible: ' . $gh_vis_error->getMessage());
    }
}

if (!function_exists('gh_text')) {
    /** Texto administrable ya escapado; cae al valor por defecto si está vacío. */
    function gh_text(array $visuals, string $card, string $field, string $default): string
    {
        $val = $visuals[$card]['_texts'][$field]['text'] ?? null;
        $val = is_string($val) && trim($val) !== '' ? trim($val) : $default;
        return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Imagen administrable del media: sólo rutas locales absolutas o URLs https
     * (coherente con la CSP `img-src 'self' https:`). Devuelve null si no hay
     * imagen válida.
     */
    function gh_art(array $visuals, string $card): ?array
    {
        $url = trim((string) ($visuals[$card]['background_image_url'] ?? ''));
        $is_local = $url !== '' && $url[0] === '/' && substr($url, 0, 2) !== '//';
        $is_https = strncasecmp($url, 'https://', 8) === 0 && filter_var($url, FILTER_VALIDATE_URL) !== false;
        if (!$is_local && !$is_https) {
            return null;
        }
        $x = max(0, min(100, (int) ($visuals[$card]['bg_position_x'] ?? 50)));
        $y = max(0, min(100, (int) ($visuals[$card]['bg_position_y'] ?? 50)));
        return [
            'src' => htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
            'position' => $x . '% ' . $y . '%',
        ];
    }

    /**
     * Capas del media según `media_config.mode`: imagen debajo, icono SVG
     * encima (misma celda del grid). Sin imagen válida el modo degrada a
     * `svg` para que el media nunca quede vacío.
     */
    function gh_media(array $visuals, string $card): string
    {
        $cfg = $visuals[$card]['_media'] ?? gh_media_defaults();
        $art = gh_art($visuals, $card);
        $mode = $cfg['mode'];
        if ($art === null && $mode !== 'svg') {
            $mode = 'svg';
        }
        $html = '';
        if ($mode !== 'svg') {
            $html .= '<img class="gh-card__art gh-card__art--image" src="' . $art['src'] . '" alt=""'
                . ' loading="lazy" decoding="async" style="object-position: ' . $art['position'] . '">';
        }
        if ($mode !== 'image') {
            $html .= gh_media_render_icon($card, $cfg);
        }
        return $html;
    }
}
?>
<section class="gh" aria-labelledby="gh-heading">
    <h2 id="gh-heading" class="sr-only">Juegos</h2>

    <div class="gh__grid">

        <article class="gh-card" data-gh-game="daily" data-state="loading" aria-busy="true"
                 aria-labelledby="gh-daily-title" aria-describedby="gh-daily-desc">
            <div class="gh-card__media">
                <?= gh_media($gh_visuals, 'daily') ?>
                <span class="gh-badge gh-card__badge" data-gh-slot="badge" data-tone="none"></span>
            </div>

            <div class="gh-card__body">
                <h3 id="gh-daily-title" class="gh-card__title"><?= gh_text($gh_visuals, 'daily', 'title', 'Giro Diario') ?></h3>
                <p id="gh-daily-desc" class="gh-card__desc" data-gh-slot="desc"></p>

                <p class="gh-card__meta" data-gh-slot="meta">
                    <span class="gh-card__meta-label" data-gh-slot="meta-label">Giro gratis en</span>
                    <span class="gh-card__meta-value" data-gh-slot="meta-value">--:--:--</span>
                </p>

                <button type="button" class="gh-cta" data-gh-slot="cta" data-tone="primary" disabled></button>
                <p class="sr-only" data-gh-slot="live" aria-live="polite"></p>
            </div>
        </article>

        <article class="gh-card" data-gh-game="flash" data-state="loading" aria-busy="true"
                 aria-labelledby="gh-flash-title" aria-describedby="gh-flash-desc">
            <div class="gh-card__media">
                <?= gh_media($gh_visuals, 'flash') ?>
                <span class="gh-badge gh-card__badge" data-gh-slot="badge" data-tone="none"></span>
                <span class="gh-badge gh-card__badge gh-card__badge--exclusive" data-gh-slot="exclusive" data-tone="none"></span>
            </div>

            <div class="gh-card__body">
                <h3 id="gh-flash-title" class="gh-card__title"><?= gh_text($gh_visuals, 'flash', 'title', 'Sorteo Flash') ?></h3>
                <p id="gh-flash-desc" class="gh-card__desc" data-gh-slot="desc"></p>

                <p class="gh-card__meta" data-gh-slot="meta">
                    <span class="gh-card__meta-label" data-gh-slot="meta-label">Tiempo</span>
                    <span class="gh-card__meta-value" data-gh-slot="meta-value">--:--:--</span>
                </p>

                <button type="button" class="gh-cta" data-gh-slot="cta" data-tone="primary" disabled></button>
                <p class="sr-only" data-gh-slot="live" aria-live="polite"></p>
            </div>
        </article>

    </div>

    <dialog class="gh-dialog" id="gh-flash-dialog" data-state="loading"
            aria-labelledby="gh-flash-dialog-title" aria-describedby="gh-flash-dialog-desc">
        <div class="gh-dialog__panel">
            <button type="button" class="gh-dialog__close" data-gh-action="close-flash" aria-label="Cerrar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <path d="M18 6 6 18"/><path d="m6 6 12 12"/>
                </svg>
            </button>

            <span class="gh-dialog__badges">
                <span class="gh-badge gh-dialog__badge" data-gh-slot="badge" data-tone="none"></span>
                <span class="gh-badge gh-dialog__badge" data-gh-slot="exclusive" data-tone="none"></span>
            </span>
            <h3 id="gh-flash-dialog-title" class="gh-dialog__title" data-gh-slot="title">Sorteo Flash</h3>
            <p id="gh-flash-dialog-desc" class="gh-dialog__text" data-gh-slot="desc"></p>

            <dl class="gh-dialog__stats">
                <div class="gh-stat">
                    <dt data-gh-slot="stat-time-label">Tiempo</dt>
                    <dd data-gh-slot="stat-time" role="timer">—</dd>
                </div>
                <div class="gh-stat">
                    <dt>Cupos</dt>
                    <dd data-gh-slot="stat-slots">—</dd>
                </div>
                <div class="gh-stat">
                    <dt>Entrada</dt>
                    <dd data-gh-slot="stat-cost">—</dd>
                </div>
            </dl>

            <p class="gh-dialog__note" data-gh-slot="note" aria-live="polite"></p>

            <div class="gh-dialog__actions">
                <button type="button" class="gh-cta" data-gh-slot="cta" data-tone="primary" disabled></button>
                <button type="button" class="gh-cta gh-cta--secondary" data-gh-action="close-flash">Cerrar</button>
            </div>
        </div>
    </dialog>
</section>

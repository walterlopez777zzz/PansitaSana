<?php
/**
 * Media administrable de las tarjetas de Juegos (`.gh-card__media`).
 *
 * Compartido por la vista pública (cuysito_app/juegos/index.php) y el panel
 * Admin → Visual Juegos (wabo/visual_juegos/api_visual_juegos.php). Aquí vive
 * todo lo que ambos deben interpretar igual: el contrato de `media_config`,
 * los iconos por defecto y el saneado del SVG.
 *
 * Contrato `game_card_visuals.media_config` (JSON, nullable):
 *   {
 *     "mode": "svg" | "image" | "both",   // qué capas se pintan
 *     "svg": {
 *       "markup":     "<svg …>" | null,    // null ⇒ icono por defecto de la tarjeta
 *       "color":      "#rrggbb" | null,    // null ⇒ hereda el color del texto
 *       "size":       10..100,             // % del ancho del media
 *       "forceColor": bool,                // reescribe fill/stroke a currentColor
 *       "shadow":     bool                 // sombra para legibilidad sobre imagen
 *     }
 *   }
 *
 * Seguridad: el SVG lo escribe un administrador y se inserta inline en la
 * página pública, así que se sanea SIEMPRE (al guardar y al renderizar) con
 * una lista blanca de elementos/atributos: sin scripts, sin manejadores
 * `on*`, sin `foreignObject`, sin referencias externas.
 */

const GH_MEDIA_MODES = ['svg', 'image', 'both'];
const GH_SVG_MAX_BYTES = 24 * 1024;
const GH_SVG_SIZE_MIN = 10;
const GH_SVG_SIZE_MAX = 100;
const GH_SVG_SIZE_DEFAULT = 34;

const GH_SVG_ELEMENTS = [
    'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon',
    'defs', 'symbol', 'use', 'linearGradient', 'radialGradient', 'stop',
    'clipPath', 'mask', 'title', 'desc',
];

const GH_SVG_ATTRIBUTES = [
    'id', 'class', 'viewBox', 'preserveAspectRatio', 'xmlns', 'xmlns:xlink',
    'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-linecap',
    'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset',
    'stroke-opacity', 'opacity', 'clip-rule', 'clip-path', 'mask', 'transform',
    'vector-effect', 'paint-order', 'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y',
    'x1', 'y1', 'x2', 'y2', 'width', 'height', 'points', 'offset', 'stop-color',
    'stop-opacity', 'gradientUnits', 'gradientTransform', 'spreadMethod',
    'clipPathUnits', 'maskUnits', 'maskContentUnits', 'href', 'xlink:href', 'style',
];

/** Iconos por defecto (Lucide, trazo 1.5) cuando el admin no define un SVG. */
const GH_DEFAULT_SVG = [
    'daily' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' . "\n"
        . '  <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/>' . "\n"
        . '  <path d="M21 3v5h-5"/>' . "\n"
        . '  <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/>' . "\n"
        . '  <path d="M8 16H3v5"/>' . "\n"
        . '</svg>',
    'flash' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' . "\n"
        . '  <path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>' . "\n"
        . '</svg>',
];

/** Configuración de media por defecto (equivale a la tarjeta sin personalizar). */
function gh_media_defaults(): array
{
    return [
        'mode' => 'svg',
        'svg' => [
            'markup' => null,
            'color' => null,
            'size' => GH_SVG_SIZE_DEFAULT,
            'forceColor' => true,
            'shadow' => false,
        ],
    ];
}

/**
 * Normaliza una `media_config` cualquiera (JSON decodificado o input del
 * admin) al contrato. Valores inválidos caen al defecto; el markup se sanea.
 * Devuelve la configuración y, si el SVG fue rechazado, un mensaje en `error`.
 */
function gh_media_normalize($raw): array
{
    $cfg = gh_media_defaults();
    $error = null;
    if (!is_array($raw)) {
        return ['config' => $cfg, 'error' => null];
    }

    if (isset($raw['mode']) && in_array($raw['mode'], GH_MEDIA_MODES, true)) {
        $cfg['mode'] = $raw['mode'];
    }

    $svg = isset($raw['svg']) && is_array($raw['svg']) ? $raw['svg'] : [];

    if (isset($svg['markup']) && is_string($svg['markup']) && trim($svg['markup']) !== '') {
        $clean = gh_svg_sanitize($svg['markup']);
        if ($clean === null) {
            $error = 'El SVG no es válido: debe ser XML bien formado, con <svg> raíz y viewBox (o width/height).';
        } else {
            $cfg['svg']['markup'] = $clean;
        }
    }
    if (isset($svg['color']) && is_string($svg['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $svg['color'])) {
        $cfg['svg']['color'] = strtoupper($svg['color']);
    }
    if (isset($svg['size']) && is_numeric($svg['size'])) {
        $cfg['svg']['size'] = max(GH_SVG_SIZE_MIN, min(GH_SVG_SIZE_MAX, (int) $svg['size']));
    }
    if (array_key_exists('forceColor', $svg)) {
        $cfg['svg']['forceColor'] = (bool) $svg['forceColor'];
    }
    if (array_key_exists('shadow', $svg)) {
        $cfg['svg']['shadow'] = (bool) $svg['shadow'];
    }

    return ['config' => $cfg, 'error' => $error];
}

/**
 * Filas anteriores a `media_config` (o sin guardar desde el panel nuevo):
 * si ya tenían imagen, se comportan como antes (sólo imagen).
 */
function gh_media_legacy_mode(array $cfg, $raw_media, $image_url): array
{
    if (!is_array($raw_media) && trim((string) $image_url) !== '') {
        $cfg['mode'] = 'image';
    }
    return $cfg;
}

/**
 * Sanea un SVG con lista blanca. Devuelve el markup del elemento raíz (sin
 * declaración XML, sin `width`/`height`/`id`/`class`/`style` en la raíz, con
 * `viewBox` garantizado) o null si no es un SVG utilizable.
 */
function gh_svg_sanitize(string $markup): ?string
{
    $markup = trim($markup);
    if ($markup === '' || strlen($markup) > GH_SVG_MAX_BYTES) {
        return null;
    }
    if (stripos($markup, '<!DOCTYPE') !== false || stripos($markup, '<!ENTITY') !== false || stripos($markup, '<?php') !== false) {
        return null;
    }

    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $loaded = $doc->loadXML($markup, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $root = $loaded ? $doc->documentElement : null;
    if (!$root || $root->localName !== 'svg') {
        return null;
    }

    gh_svg_clean_node($root);

    if (!$root->hasAttribute('viewBox')) {
        $w = (float) $root->getAttribute('width');
        $h = (float) $root->getAttribute('height');
        if ($w <= 0 || $h <= 0) {
            return null;
        }
        $root->setAttribute('viewBox', '0 0 ' . rtrim(rtrim(sprintf('%.3F', $w), '0'), '.') . ' ' . rtrim(rtrim(sprintf('%.3F', $h), '0'), '.'));
    }
    foreach (['width', 'height', 'id', 'class', 'style', 'x', 'y'] as $attr) {
        $root->removeAttribute($attr);
    }
    if (!$root->hasAttribute('xmlns')) {
        $root->setAttribute('xmlns', 'http://www.w3.org/2000/svg');
    }

    $out = $doc->saveXML($root);
    return is_string($out) && $out !== '' ? $out : null;
}

/** Recorre el árbol eliminando nodos/atributos fuera de la lista blanca. */
function gh_svg_clean_node(DOMElement $el): void
{
    for ($i = $el->attributes->length - 1; $i >= 0; $i--) {
        $attr = $el->attributes->item($i);
        $name = $attr->nodeName;
        $value = trim($attr->nodeValue);
        $drop = !in_array($name, GH_SVG_ATTRIBUTES, true)
            || stripos($name, 'on') === 0
            || (($name === 'href' || $name === 'xlink:href') && ($value === '' || $value[0] !== '#'))
            || ($name === 'style' && !gh_svg_style_is_safe($value))
            || (($name === 'fill' || $name === 'stroke' || $name === 'clip-path' || $name === 'mask') && !gh_svg_paint_is_safe($value));
        if ($drop) {
            $el->removeAttributeNode($attr);
        }
    }

    for ($i = $el->childNodes->length - 1; $i >= 0; $i--) {
        $child = $el->childNodes->item($i);
        if ($child instanceof DOMElement) {
            if (in_array($child->localName, GH_SVG_ELEMENTS, true) && ($child->namespaceURI === null || $child->namespaceURI === 'http://www.w3.org/2000/svg')) {
                gh_svg_clean_node($child);
            } else {
                $el->removeChild($child);
            }
        } elseif (!($child instanceof DOMText)) {
            $el->removeChild($child);
        }
    }
}

/** Sólo declaraciones simples `prop: valor;` sin url(), @import ni expresiones. */
function gh_svg_style_is_safe(string $style): bool
{
    return $style !== ''
        && preg_match('/^[a-zA-Z0-9\s:;#.,%()\-_]*$/', $style) === 1
        && stripos($style, 'url(') === false
        && stripos($style, 'expression') === false
        && stripos($style, 'import') === false;
}

/** Colores, none/currentColor o referencias locales `url(#id)`. */
function gh_svg_paint_is_safe(string $value): bool
{
    return $value === ''
        || preg_match('/^(none|currentColor|inherit|transparent|#[0-9a-fA-F]{3,8}|[a-zA-Z]+|rgba?\([0-9.,\s%]+\)|hsla?\([0-9.,\s%]+\)|url\(#[A-Za-z0-9_\-:.]+\))$/', $value) === 1;
}

/**
 * Reescribe todo `fill`/`stroke` con color explícito a `currentColor` para que
 * el icono obedezca al color del admin. Respeta none, gradientes (url(#…)) y
 * los valores ya en currentColor.
 */
function gh_svg_force_current_color(string $svg): string
{
    $attr = static function (array $m): string {
        $v = trim($m[2]);
        if ($v === 'none' || $v === 'currentColor' || stripos($v, 'url(') === 0) {
            return $m[0];
        }
        return $m[1] . 'currentColor' . $m[3];
    };
    $svg = preg_replace_callback('/(\s(?:fill|stroke)=")([^"]*)(")/', $attr, $svg);
    $svg = preg_replace_callback('/(\s(?:fill|stroke)=\')([^\']*)(\')/', $attr, $svg);
    return preg_replace_callback('/((?:^|[;\s"\'])(?:fill|stroke)\s*:\s*)([^;"\']+)/', static function (array $m): string {
        $v = trim($m[2]);
        if ($v === 'none' || $v === 'currentColor' || stripos($v, 'url(') === 0) {
            return $m[0];
        }
        return $m[1] . 'currentColor';
    }, $svg);
}

/**
 * Markup final del icono para la tarjeta: sanea de nuevo (defensa en
 * profundidad), aplica forceColor y fija clase/aria/estilo del contrato CSS.
 */
function gh_media_render_icon(string $card, array $cfg): string
{
    $svgCfg = $cfg['svg'];
    $markup = $svgCfg['markup'] !== null ? gh_svg_sanitize($svgCfg['markup']) : null;
    if ($markup === null) {
        $markup = GH_DEFAULT_SVG[$card] ?? GH_DEFAULT_SVG['daily'];
    }
    if ($svgCfg['forceColor']) {
        $markup = gh_svg_force_current_color($markup);
    }

    $style = '--gh-icon-size:' . (int) $svgCfg['size'] . '%';
    if ($svgCfg['color'] !== null) {
        $style .= ';--gh-icon-color:' . $svgCfg['color'];
    }
    if ($svgCfg['shadow']) {
        $style .= ';--gh-art-fx:var(--gh-icon-shadow)';
    }

    $attrs = 'class="gh-card__art gh-card__art--icon" aria-hidden="true" focusable="false" style="'
        . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '"';
    return preg_replace('/^<svg\b/', '<svg ' . $attrs, $markup, 1) ?? $markup;
}

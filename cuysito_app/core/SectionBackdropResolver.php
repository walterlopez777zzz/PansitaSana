<?php
/**
 * ============================================================================
 * BANANO STORE — SECTION BACKDROP RESOLVER (server side)
 * ============================================================================
 * Single source of truth for "which full-viewport background does a page get":
 * the SPA shell (index.php) uses it to paint the first frame server-side and
 * to hand the client runtime (public_html/section-backdrop.js) its config; the
 * item-page API uses it so deep links and cards agree on an item's backdrop.
 *
 * Policy (mirrors `resolveIntent` in section-backdrop.js):
 *   viaregalo  → global default (the runtime crossfades to the section under
 *                the viewport once the shop renders).
 *   item       → the section the item belongs to (own offer, else the bundle
 *                that contains it), falling back to the global default.
 *   viacuenta / games / producto → global default.
 *   anything else → no backdrop.
 * ============================================================================
 */

require_once __DIR__ . '/SectionBackgroundsRepository.php';
require_once __DIR__ . '/ShopEntryLocator.php';

if (!class_exists('SectionBackdropResolver')) {

final class SectionBackdropResolver
{
    public const MODE_STORE   = 'store';
    public const MODE_PAGE    = 'page';
    public const MODE_DEFAULT = 'default';
    public const MODE_NONE    = 'none';

    public const VIEW_STORE = 'viaregalo';
    public const VIEW_ITEM  = 'item';

    private const DEFAULT_VIEWS = ['viacuenta', 'games', 'producto'];

    /**
     * Responsive candidates served through wsrv.nl (webp, resized), mirrored
     * by `imageSource` in section-backdrop.js. The image covers the viewport,
     * so portrait screens need roughly twice their CSS width to stay sharp.
     */
    private const IMAGE_WIDTHS  = [640, 1080, 1920, 2560];
    private const IMAGE_DEFAULT = 1920;
    private const IMAGE_QUALITY = 90;
    public const IMAGE_SIZES    = '(orientation: portrait) 200vw, 100vw';

    /**
     * Initial backdrop for the SPA shell plus the runtime config.
     *
     * @return array{
     *   mode: string,
     *   url: ?string,
     *   config: array{default: ?string, page: ?string, sections: array<int,array{key:string,name:string,image:string}>}
     * }
     */
    public static function resolve(string $dataView, ?string $itemId = null): array
    {
        $default = SectionBackgroundsRepository::defaultBackground();
        $page    = null;

        if ($dataView === self::VIEW_STORE) {
            $url  = $default;
            $mode = self::MODE_STORE;
        } elseif ($dataView === self::VIEW_ITEM) {
            $page = $itemId !== null && $itemId !== '' ? self::forItemId($itemId) : null;
            $url  = $page ?? $default;
            $mode = self::MODE_PAGE;
        } elseif (in_array($dataView, self::DEFAULT_VIEWS, true)) {
            $url  = $default;
            $mode = self::MODE_DEFAULT;
        } else {
            $url  = null;
            $mode = self::MODE_NONE;
        }

        if ($url === null) {
            $mode = self::MODE_NONE;
        }

        return [
            'mode'   => $mode,
            'url'    => $url,
            'config' => [
                'default'  => $default,
                'page'     => $page,
                'sections' => self::sectionBackgrounds(),
            ],
        ];
    }

    /**
     * Section background of a shop item (own offer first, then the bundle
     * that contains it). Null when neither has a section-specific background;
     * callers decide whether to fall back to the default.
     */
    public static function forItemId(string $id): ?string
    {
        // Without section-specific backgrounds the answer is always the
        // default: skip decoding the shop snapshot entirely.
        if (!self::sectionBackgrounds()) {
            return null;
        }
        $located = ShopEntryLocator::locate($id);
        return self::forShopEntries($located['entry'], $located['container']);
    }

    /**
     * @param ?array<string,mixed> $entry     offer whose main subject is the item
     * @param ?array<string,mixed> $container bundle / multi-item offer containing it
     */
    public static function forShopEntries(?array $entry, ?array $container): ?string
    {
        return SectionBackgroundsRepository::findForLayout($entry['layout'] ?? null)
            ?? SectionBackgroundsRepository::findForLayout($container['layout'] ?? null);
    }

    /**
     * `<img>` attributes for a backdrop URL: `src` fallback plus `srcset`/`sizes`
     * (empty when the URL is already an optimized/placeholder resource).
     *
     * @return array{src: string, srcset: string, sizes: string}
     */
    public static function imageSource(string $url): array
    {
        if (!preg_match('#^https?://#i', $url)
            || strpos($url, 'wsrv.nl') !== false
            || strpos($url, 'via.placeholder.com') !== false) {
            return ['src' => $url, 'srcset' => '', 'sizes' => ''];
        }
        $candidates = [];
        foreach (self::IMAGE_WIDTHS as $width) {
            $candidates[] = self::optimizedUrl($url, $width) . ' ' . $width . 'w';
        }
        return [
            'src'    => self::optimizedUrl($url, self::IMAGE_DEFAULT),
            'srcset' => implode(', ', $candidates),
            'sizes'  => self::IMAGE_SIZES,
        ];
    }

    private static function optimizedUrl(string $url, int $width): string
    {
        return 'https://wsrv.nl/?url=' . rawurlencode($url)
            . '&w=' . $width . '&q=' . self::IMAGE_QUALITY . '&output=webp';
    }

    /**
     * Active section-specific backgrounds (the global default excluded).
     * @return array<int,array{key:string,name:string,image:string}>
     */
    private static function sectionBackgrounds(): array
    {
        static $memo = null;
        if ($memo === null) {
            $memo = array_values(array_filter(
                SectionBackgroundsRepository::activeFromCache(),
                static fn(array $bg): bool => $bg['key'] !== SectionBackgroundsRepository::DEFAULT_KEY
            ));
        }
        return $memo;
    }
}

}

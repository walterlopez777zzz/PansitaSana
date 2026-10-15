<?php
/**
 * ============================================================================
 * BANANO STORE — SHOP ENTRY LOCATOR
 * ============================================================================
 * Resolves the shop entry (oferta) behind a page identifier, the same way the
 * storefront does in JavaScript (getItemDisplayInfo / findShopEntryForPageId):
 *
 *   - Offer ids (`v2:/...`, sanitized as `v2_...` in URLs) match the entry
 *     whose offerId sanitizes to the same value.
 *   - Cosmetic ids match the individual offer whose MAIN subject is that
 *     cosmetic (`entry`), or — when the cosmetic only ships inside a bundle /
 *     multi-item offer — the first offer that contains it (`container`).
 *
 * Shared by the item-page API and the server-side backdrop resolver so both
 * agree on which section a deep link belongs to.
 * ============================================================================
 */

require_once __DIR__ . '/ShopRepository.php';

if (!class_exists('ShopEntryLocator')) {

final class ShopEntryLocator
{
    private const ITEM_COLLECTIONS = ['brItems', 'instruments', 'cars'];

    /**
     * @param  array<int,mixed>|null $items Entradas ya cargadas (evita releer el snapshot); por defecto ShopRepository::items().
     * @return array{entry: ?array<string,mixed>, container: ?array<string,mixed>}
     */
    public static function locate(string $id, ?array $items = null): array
    {
        $result = ['entry' => null, 'container' => null];
        if ($id === '') {
            return $result;
        }

        $lowerId        = strtolower($id);
        $looksLikeOffer = self::looksLikeOfferId($id);
        $sanitizedId    = strtolower(self::sanitizeOfferId($id));

        foreach ($items ?? ShopRepository::items() as $entry) {
            if (!is_array($entry) || !self::isListed($entry)) {
                continue;
            }
            if ($looksLikeOffer) {
                if (isset($entry['offerId'])
                    && strtolower(self::sanitizeOfferId((string) $entry['offerId'])) === $sanitizedId) {
                    $result['entry'] = $entry;
                    return $result;
                }
                continue;
            }
            $ids = self::itemIds($entry);
            if (!isset($ids[$lowerId])) {
                continue;
            }
            // Individual offer whose main subject is this cosmetic. Many
            // individual offers ship extra items (outfit + back bling), so
            // compare against the main item instead of the item count.
            if (!self::isBundle($entry)
                && (count($ids) === 1 || self::mainItemId($entry) === $lowerId)) {
                $result['entry'] = $entry;
                return $result;
            }
            if ($result['container'] === null) {
                $result['container'] = $entry;
            }
        }

        return $result;
    }

    public static function looksLikeOfferId(string $id): bool
    {
        return strpos($id, ':') !== false
            || strpos($id, '/') !== false
            || preg_match('/^v2_/i', $id) === 1;
    }

    /** Mirrors the client-side URL sanitization: 'v2:/abc' -> 'v2_abc'. */
    public static function sanitizeOfferId(string $offerId): string
    {
        return (string) preg_replace('#[:/]+#', '_', $offerId);
    }

    /**
     * Hidden "à la carte" offers (layoutId "alc.*", no section layout) exist in
     * the catalog but the storefront never renders them as cards, so they must
     * not count as an individual sale (mirrors the shop worker's section
     * grouping, which drops entries without layout.name).
     */
    public static function isListed(array $entry): bool
    {
        return isset($entry['layout']['name']) && $entry['layout']['name'] !== '';
    }

    public static function isBundle(array $entry): bool
    {
        return isset($entry['bundle']) && $entry['bundle'] !== null;
    }

    /**
     * [lowercase id => item] for every cosmetic contained in a shop entry.
     * @return array<string,array<string,mixed>>
     */
    public static function itemIds(array $entry): array
    {
        $ids = [];
        foreach (self::ITEM_COLLECTIONS as $key) {
            foreach (($entry[$key] ?? []) as $item) {
                if (is_array($item) && isset($item['id'])) {
                    $ids[strtolower((string) $item['id'])] = $item;
                }
            }
        }
        foreach (($entry['tracks'] ?? []) as $track) {
            if (is_array($track) && isset($track['id'])) {
                $ids[strtolower((string) $track['id'])] = $track;
            }
        }
        return $ids;
    }

    /**
     * Id del ítem protagonista de una entrada no-lote (espejo de
     * getItemDisplayInfo en app.js): tracks[0] para pistas, si no el primero
     * de brItems/instruments/cars.
     */
    public static function mainItemId(array $entry): ?string
    {
        if (!empty($entry['tracks']) && isset($entry['tracks'][0]['id'])) {
            return strtolower((string) $entry['tracks'][0]['id']);
        }
        foreach (self::ITEM_COLLECTIONS as $key) {
            if (isset($entry[$key][0]['id'])) {
                return strtolower((string) $entry[$key][0]['id']);
            }
        }
        return null;
    }
}

}

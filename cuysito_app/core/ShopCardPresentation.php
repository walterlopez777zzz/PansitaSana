<?php
/**
 * ============================================================================
 * BANANO STORE — SHOP CARD PRESENTATION
 * ============================================================================
 * Derives, from a canonical shop entry, the presentation keys the storefront
 * card computes client-side (app.js createItemCardNode + getItemDisplayInfo):
 * the plural type label (`data-item-type`, which drives the per-type artwork
 * framing CSS), the main cosmetic id (`data-item-id`, per-item framing
 * exceptions), the car-offer / dynamic-bundle flags and the artwork image
 * cascade. Shared by the Data Layer editor preview and the cart so every
 * surface that renders a 1×1 tile agrees with the storefront.
 * ============================================================================
 */

if (!class_exists('ShopCardPresentation')) {

final class ShopCardPresentation
{
    /** Same table as app.js CONFIG.TYPE_TRANSLATIONS_PLURAL. */
    public const TYPE_TRANSLATIONS_PLURAL = [
        'outfit' => 'Trajes', 'emote' => 'Gestos', 'glider' => 'Alas delta',
        'pickaxe' => 'Picos', 'backpack' => 'Accesorios mochileros',
        'wrap' => 'Envoltorios', 'shoe' => 'Calzado', 'contrail' => 'Estelas',
        'carbody' => 'Coches', 'wheel' => 'Ruedas',
        'jamtrack' => 'Pistas de improvisación', 'sparks_song' => 'Pistas de improvisación',
        'trail' => 'Rastros', 'boost' => 'Potenciadores', 'sidekick' => 'Compañeros',
        'mic' => 'Instrumentos', 'bass' => 'Instrumentos', 'guitar' => 'Instrumentos',
        'keyboard' => 'Instrumentos', 'drum' => 'Instrumentos', 'bundle' => 'Lotes',
        'shoes' => 'Calzado', 'footwear' => 'Calzado', 'instrument' => 'Instrumentos',
        'sparks_bass' => 'Instrumentos', 'vehicle_wheel' => 'Ruedas',
        'cosmeticvariant' => 'Estilos', 'cosmetic' => 'Cosméticos',
        'itemaccess' => 'Accesos', 'spray' => 'Grafitis', 'music' => 'Música',
        'loadingscreen' => 'Pantallas de carga', 'battlebus' => 'Autobuses de batalla',
    ];

    private const DYNAMIC_BUNDLE_DEV_NAME_SUFFIX = 'for -1 MtxCurrency';

    /**
     * @param  array<string,mixed> $it Canonical shop entry (ShopNormalizer shape).
     * @return array{item_type: string, item_id: ?string, is_car_offer: bool, is_dynamic_bundle: bool, image: ?string}
     */
    public static function fromEntry(array $it): array
    {
        $brItems     = is_array($it['brItems'] ?? null) ? $it['brItems'] : [];
        $instruments = is_array($it['instruments'] ?? null) ? $it['instruments'] : [];
        $cars        = is_array($it['cars'] ?? null) ? $it['cars'] : [];
        $tracks      = is_array($it['tracks'] ?? null) ? $it['tracks'] : [];
        $isBundle    = !empty($it['bundle']);

        if ($isBundle) {
            $mainItem   = ['images' => ['icon' => $it['bundle']['image'] ?? null]];
            $pluralType = self::TYPE_TRANSLATIONS_PLURAL['bundle'];
        } elseif (count($tracks) > 0) {
            $mainItem   = is_array($tracks[0]) ? $tracks[0] : [];
            $pluralType = self::TYPE_TRANSLATIONS_PLURAL['jamtrack'];
        } else {
            $mainItem = array_merge($brItems, $instruments, $cars)[0] ?? [];
            $mainItem = is_array($mainItem) ? $mainItem : [];
            $typeId   = (string) ($mainItem['type']['value'] ?? 'default');
            if ($typeId === 'shoe' || $typeId === 'shoes') {
                $typeId = 'footwear';
            }
            $pluralType = self::TYPE_TRANSLATIONS_PLURAL[$typeId] ?? 'Artículos';
        }

        $typeOverride = trim((string) ($it['displayTypeOverride'] ?? ''));
        if ($typeOverride !== '') {
            $pluralType = $typeOverride;
        }

        $devName = $it['devName'] ?? null;
        $itemId  = $mainItem['id'] ?? null;

        return [
            'item_type'         => $pluralType,
            'item_id'           => is_string($itemId) && $itemId !== '' ? $itemId : null,
            'is_car_offer'      => count($cars) > 0 && !$brItems && !$instruments && !$tracks,
            'is_dynamic_bundle' => !$isBundle && is_string($devName)
                && substr($devName, -strlen(self::DYNAMIC_BUNDLE_DEV_NAME_SUFFIX)) === self::DYNAMIC_BUNDLE_DEV_NAME_SUFFIX,
            'image'             => self::artworkUrl($it, $mainItem),
        ];
    }

    /**
     * Artwork the card shows: non-LEGO official render first, then the
     * cosmetic's own images, then the legacy display asset.
     *
     * @param array<string,mixed> $it
     * @param array<string,mixed> $mainItem
     */
    private static function artworkUrl(array $it, array $mainItem): ?string
    {
        $renderImages = $it['newDisplayAsset']['renderImages'] ?? [];
        $nonLego = [];
        if (is_array($renderImages)) {
            foreach ($renderImages as $ri) {
                if (is_array($ri) && !empty($ri['image']) && ($ri['productTag'] ?? null) !== 'Product.Juno') {
                    $nonLego[] = $ri['image'];
                }
            }
        }

        $image = $mainItem['albumArt']
            ?? ($nonLego[0]
            ?? ($it['newDisplayAsset']['renderImages'][0]['image']
            ?? ($mainItem['images']['featured']
            ?? ($mainItem['images']['icon']
            ?? ($it['images']['featured']
            ?? ($it['images']['icon']
            ?? ($it['displayAssets'][0]['url'] ?? null)))))));

        return is_string($image) && $image !== '' ? $image : null;
    }
}

}

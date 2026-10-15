<?php
// Archivo: wabo/core/shop_helper.php (Versión 5.0 - Backend interno / DAL)
//
// Antes contactaba la API externa (fortnite-api.com) con clave hardcodeada y
// SSL inseguro. Ahora lee EXCLUSIVAMENTE el snapshot canónico interno
// (banano_primordial.json) a través del DAL ShopRepository. El snapshot es un
// superconjunto que preserva los campos crudos (layout, brItems, tracks,
// bundle, colors, offerId, finalPrice, sortPriority), por lo que el armado de
// secciones de abajo no cambia. La tienda deja de depender de la API pública.

require_once __DIR__ . '/../../config/shop_config.php';
require_once __DIR__ . '/../../core/ShopRepository.php';

if (!function_exists('get_fortnite_shop_data')) {
    function get_fortnite_shop_data() {
        try {
            $entries = ShopRepository::items();

            $mainSectionMap = [];
            $raw_api_categories = [];

            foreach ($entries as $entry) {
                if (!isset($entry['layout']['name']) || (empty($entry['brItems']) && empty($entry['tracks']))) {
                    continue;
                }

                $sectionName = $entry['layout']['name'];
                if (!isset($mainSectionMap[$sectionName])) {
                    $mainSectionMap[$sectionName] = ['name' => $sectionName, 'rank' => $entry['layout']['rank'] ?? 999, 'items' => []];
                }

                $mainItem = $entry['brItems'][0] ?? null; $trackItem = $entry['tracks'][0] ?? null;
                $displayName = $entry['bundle']['name'] ?? $mainItem['name'] ?? $trackItem['title'] ?? 'Artículo Desconocido';

                $displayType = null;
                if ($trackItem) { $displayType = 'Pista de improvisación'; }
                elseif ($mainItem) { $displayType = $entry['bundle']['info'] ?? $mainItem['type']['displayValue'] ?? null; }

                if (is_string($displayType) && !empty(trim($displayType))) {
                    $raw_api_categories[] = $displayType;
                }

                $featuredImage = $entry['newDisplayAsset']['renderImages'][0]['image'] ?? $mainItem['images']['featured'] ?? $trackItem['albumArt'] ?? 'https://via.placeholder.com/256';
                $iconImage = $mainItem['images']['icon'] ?? $mainItem['images']['smallIcon'] ?? $featuredImage;

                $cardColors = null;
                if (!empty($entry['colors']['color1']) && !empty($entry['colors']['color2'])) {
                    $cardColors = ['#' . substr($entry['colors']['color1'], 0, 6), '#' . substr($entry['colors']['color2'], 0, 6)];
                }

                $mainSectionMap[$sectionName]['items'][] = [
                    'offerId' => $entry['offerId'], 'finalPrice' => $entry['finalPrice'],
                    'sortPriority' => $entry['sortPriority'] ?? 99,
                    'items' => [['name' => $displayName, 'type' => ['displayValue' => $displayType], 'images' => ['icon' => $iconImage, 'featured' => $featuredImage], 'cardColors' => $cardColors]]
                ];
            }

            uasort($mainSectionMap, function ($a, $b) { return ($b['rank'] ?? 999) <=> ($a['rank'] ?? 999); });
            $final_sections = [];
            foreach ($mainSectionMap as $sectionData) {
                usort($sectionData['items'], function($a, $b) { return ($a['sortPriority'] ?? 999) <=> ($b['sortPriority'] ?? 999); });
                $final_sections[] = ['name' => $sectionData['name'], 'entries' => $sectionData['items']];
            }

            $processed_categories = [];
            foreach ($raw_api_categories as $category) {
                // Aseguramos que $category es un string antes de usarlo.
                if (!is_string($category)) continue;

                if (str_contains(strtolower($category), 'lote')) {
                    $processed_categories[] = 'Lote';
                } else {
                    $processed_categories[] = $category;
                }
            }
            $api_categories = array_values(array_unique($processed_categories));
            sort($api_categories);

            return ['result' => true, 'sections' => $final_sections, 'apiCategories' => $api_categories];

        } catch (Exception $e) {
            return ['result' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('get_fortnite_shop_hybrid')) {
    function get_fortnite_shop_hybrid() {
        return get_fortnite_shop_data();
    }
}

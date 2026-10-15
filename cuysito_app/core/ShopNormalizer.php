<?php
/**
 * ============================================================================
 * BANANO STORE — SHOP NORMALIZER (Phase 1)
 * ============================================================================
 * Translates the RAW fortnite-api.com /v2/shop response into the canonical
 * "banano_primordial.json" representation.
 *
 * Each canonical entry is a SUPERSET:
 *   - The original RAW fields are preserved untouched (offerId, finalPrice,
 *     brItems, bundle, tracks, instruments, cars, colors, layout, layoutId,
 *     newDisplayAsset, sortPriority, inDate, outDate, ...) so the storefront
 *     pipeline (worker.js / app.js) keeps working byte-for-byte.
 *   - Canonical backend fields are ADDED on top (displayName, displayType,
 *     vbucks, rarity, colors{1,2,3}, section_name, store_rank, images,
 *     displayAssets, offerDates, precio_base_soles, precio_oferta_soles,
 *     tiene_oferta_nuestra) so cart / spin / raffle / sorteos read one shape.
 *
 * This UNIFIES the three historical normalizers (generar_cache_tienda.php,
 * gestor_descuentos.php, wabo/core/shop_helper.php) into one implementation.
 *
 * Discount matching uses layout.name as the section (matching the live
 * storefront path in gestor_descuentos.php), fixing the long-standing
 * front/back discrepancy where the cron used the always-absent `section.name`.
 * ============================================================================
 */

require_once __DIR__ . '/../config/shop_config.php';

if (!class_exists('ShopNormalizer')) {

final class ShopNormalizer
{
    /**
     * Build the full canonical snapshot structure from a raw API payload.
     *
     * @param array $apiData         Decoded fortnite-api.com response.
     * @param string $conversionRate Soles per 100 vbucks (as string for bc*).
     * @param array $activeDiscounts Active discount rules (manual + legacy).
     * @param array $manualVideos    cosmetic_id (lowercased) => video URL map
     *                               (tabla videos_objetos, admin "Videos de Objetos").
     * @return array Canonical structure ready to be json_encoded.
     */
    public static function buildPrimordial(array $apiData, string $conversionRate, array $activeDiscounts = [], array $manualVideos = []): array
    {
        // Heal renamed external fields (configured in the Data Layer Manager)
        // BEFORE reading anything, so the rest of the pipeline is untouched.
        $apiData = self::applyFieldMap($apiData);

        $data    = $apiData['data'] ?? [];
        $entries = $data['entries'] ?? [];

        $companions = self::instrumentCompanionMap($entries);

        $items = [];
        foreach ($entries as $rawEntry) {
            if (is_array($rawEntry)) {
                $rawEntry = self::stripInstrumentCompanions($rawEntry, $companions);
            }
            $entry = self::normalizeEntry($rawEntry, $conversionRate, $activeDiscounts);
            if ($entry !== null) {
                if (!empty($manualVideos)) {
                    $entry = self::attachManualVideo($entry, $manualVideos);
                }
                $items[] = $entry;
            }
        }

        return [
            'schema_version' => ShopSourceConfig::SCHEMA_VERSION,
            'source'         => 'fortnite-api.com',
            'generated_at'   => gmdate('Y-m-d\TH:i:s\Z'),
            'shop_date'      => $data['date'] ?? null,
            'hash'           => $data['hash'] ?? null,
            'meta'           => [
                'conversion_rate' => $conversionRate,
                'item_count'      => count($items),
                'vbuck_icon'      => $data['vbuckIcon'] ?? null,
            ],
            // Canonical items live under BOTH keys: `entries` for the storefront
            // contract and `shop` as a backward-compatible alias for legacy
            // backend consumers that historically read `$data['shop']`.
            'entries' => $items,
            'shop'    => $items,
        ];
    }

    /**
     * Normalize a single raw entry into the canonical superset entry.
     * Returns null for entries we cannot identify (mirrors legacy behavior).
     */
    public static function normalizeEntry(array $item, string $conversionRate, array $activeDiscounts = []): ?array
    {
        $offerId = $item['offerId'] ?? null;
        if (!$offerId) {
            return null;
        }

        // --- STEP 1: ITEM TYPE DETECTION -------------------------------------
        $isBundle = isset($item['bundle']) && $item['bundle'] !== null;
        $isTrack  = isset($item['tracks']) && !empty($item['tracks']);

        $displayTypeRaw = null;
        if ($isBundle) {
            $displayTypeRaw = 'Lote';
        } elseif ($isTrack) {
            $displayTypeRaw = 'Pista de Improvisación';
        } elseif (isset($item['brItems'][0]['type']['displayValue'])) {
            $displayTypeRaw = $item['brItems'][0]['type']['displayValue'];
        } elseif (isset($item['instruments'][0])) {
            $displayTypeRaw = $item['instruments'][0]['type']['displayValue'] ?? 'Instrumento';
        } elseif (isset($item['cars'][0])) {
            $displayTypeRaw = $item['cars'][0]['type']['displayValue'] ?? 'Vehículo';
        }

        if (!$displayTypeRaw) {
            return null;
        }

        // --- STEP 2: SECTION (canonical = layout.name) -----------------------
        $sectionName = $item['layout']['name'] ?? ($item['layout']['id'] ?? 'Desconocido');
        $storeRank   = isset($item['layout']['rank']) ? (int) $item['layout']['rank'] : 0;

        // --- STEP 3: PRICING + BAKED DISCOUNT --------------------------------
        $vbucksPrice     = (string) ($item['finalPrice'] ?? '0');
        $precioBaseSoles = bcmul(bcdiv($vbucksPrice, '100', 4), $conversionRate, 2);

        $appliedDiscount = self::matchDiscount($offerId, $displayTypeRaw, $sectionName, $isBundle, $activeDiscounts);

        $precioOfertaSoles  = null;
        $tieneOfertaNuestra = false;
        if ($appliedDiscount) {
            $tieneOfertaNuestra = true;
            if (($appliedDiscount['tipo_descuento'] ?? '') === 'porcentaje') {
                $multiplicador     = bcsub('1', bcdiv((string) $appliedDiscount['valor'], '100', 4), 4);
                $precioOfertaSoles = bcmul($precioBaseSoles, $multiplicador, 2);
            } else {
                $precioOfertaSoles = number_format((float) $appliedDiscount['valor'], 2, '.', '');
            }
        }

        // --- STEP 4: VISUALS (name, images, rarity) --------------------------
        $displayName  = null;
        $featuredUrl  = null;
        $iconUrl      = null;
        $rarity       = null;
        $raritySource = null;

        if ($isBundle) {
            $displayName = $item['bundle']['name'] ?? 'Lote sin nombre';
            $featuredUrl = $item['bundle']['image'] ?? null;
            $iconUrl     = $item['bundle']['image'] ?? null;
            if (isset($item['brItems'][0])) {
                $raritySource = $item['brItems'][0]['series'] ?? $item['brItems'][0]['rarity'] ?? null;
            }
        } elseif ($isTrack) {
            $trackInfo   = $item['tracks'][0];
            $displayName = ($trackInfo['title'] ?? 'Pista Desconocida') . ' - ' . ($trackInfo['artist'] ?? 'Artista');
            $featuredUrl = $trackInfo['albumArt'] ?? null;
            $iconUrl     = $trackInfo['albumArt'] ?? null;
            $rarity      = ['id' => 'Common', 'name' => 'COMÚN'];
        } elseif (isset($item['brItems'][0])) {
            $brItem      = $item['brItems'][0];
            $displayName = $brItem['name'] ?? 'Artículo';
            $featuredUrl = $brItem['images']['featured'] ?? null;
            $iconUrl     = $brItem['images']['icon'] ?? ($brItem['images']['smallIcon'] ?? null);
            $raritySource = $brItem['series'] ?? $brItem['rarity'] ?? null;
        } elseif (isset($item['instruments'][0])) {
            $inst        = $item['instruments'][0];
            $displayName = $inst['name'] ?? 'Instrumento';
            $featuredUrl = $inst['images']['large'] ?? ($inst['images']['small'] ?? null);
            $iconUrl     = $inst['images']['small'] ?? null;
            $raritySource = $inst['series'] ?? $inst['rarity'] ?? null;
        } elseif (isset($item['cars'][0])) {
            $car         = $item['cars'][0];
            $displayName = $car['name'] ?? 'Vehículo';
            $featuredUrl = $car['images']['large'] ?? ($car['images']['small'] ?? null);
            $iconUrl     = $car['images']['small'] ?? null;
            $raritySource = $car['series'] ?? $car['rarity'] ?? null;
        }

        $mainImageUrl = $featuredUrl ?? $iconUrl;

        if (isset($raritySource) && !$rarity) {
            $rarityValue   = $raritySource['value'] ?? ($raritySource['id'] ?? 'Common');
            $rarityDisplay = $raritySource['displayValue'] ?? ($raritySource['name'] ?? 'Común');
            $rarity        = ['id' => ucfirst($rarityValue), 'name' => strtoupper($rarityDisplay)];
        }

        $displayAssets = [];
        if ($mainImageUrl) {
            $displayAssets[] = [
                'displayAsset'    => 'DAv2_migrated',
                'materialInstance' => 'MI_0',
                'url'             => $mainImageUrl,
                'background'      => $mainImageUrl,
                'full_background' => $mainImageUrl,
            ];
        }

        // --- COLORS (canonical color1..3 from whichever source carries them) -
        $rawColors = null;
        foreach ([
            $item['colors'] ?? null,
            $item['brItems'][0]['colors'] ?? null,
            $item['bundle']['colors'] ?? null,
            $item['tracks'][0]['colors'] ?? null,
            $item['instruments'][0]['colors'] ?? null,
            $item['cars'][0]['colors'] ?? null,
        ] as $candidate) {
            if (is_array($candidate)) {
                $rawColors = $candidate;
                break;
            }
        }
        // Prefer the RAW top-level colors object (keeps textBackgroundColor used
        // by the templates app); fall back to a derived color1..3 set otherwise.
        if (is_array($item['colors'] ?? null) && isset($item['colors']['color1'])) {
            $processedColors = $item['colors'];
        } else {
            $processedColors = [
                'color1' => is_array($rawColors) ? ($rawColors['color1'] ?? null) : null,
                'color2' => is_array($rawColors) ? ($rawColors['color2'] ?? null) : null,
                'color3' => is_array($rawColors) ? ($rawColors['color3'] ?? null) : null,
            ];
        }

        // --- MERGE: raw entry (preserved) + canonical fields (added) ---------
        $canonical = $item;
        $canonical['displayName']          = $displayName;
        $canonical['displayAssets']        = $displayAssets;
        $canonical['images']               = ['featured' => $featuredUrl, 'icon' => $iconUrl];
        $canonical['offerDates']           = ['in' => $item['inDate'] ?? null, 'out' => $item['outDate'] ?? null];
        $canonical['vbucks']               = (int) $vbucksPrice;
        $canonical['displayType']          = $displayTypeRaw;
        $canonical['rarity']               = $rarity;
        $canonical['colors']               = $processedColors;
        $canonical['section_name']         = $sectionName;
        $canonical['store_rank']           = $storeRank;
        $canonical['precio_base_soles']    = $precioBaseSoles;
        $canonical['precio_oferta_soles']  = $precioOfertaSoles;
        $canonical['tiene_oferta_nuestra'] = $tieneOfertaNuestra;

        return $canonical;
    }

    /**
     * Map instrumento => ids de sus objetos BR concedidos (companions).
     * Los instrumentos de Festival conceden automáticamente sus contrapartes
     * BR (pico/mochila): la oferta individual del instrumento las incluye
     * junto a él. fortnite.com no las muestra como contenido de un lote (el
     * lote solo "incluye" el instrumento; las contrapartes llegan de rebote).
     *
     * @param array<int,mixed> $entries Entradas RAW del snapshot.
     * @return array<string,array<string,true>> instrumentId => set de ids BR.
     */
    private static function instrumentCompanionMap(array $entries): array
    {
        $map = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || (isset($entry['bundle']) && $entry['bundle'] !== null)) {
                continue;
            }
            $instruments = $entry['instruments'] ?? [];
            $brItems     = $entry['brItems'] ?? [];
            if (!is_array($instruments) || empty($instruments) || !is_array($brItems) || empty($brItems)) {
                continue;
            }
            $brIds = [];
            foreach ($brItems as $item) {
                if (is_array($item) && isset($item['id']) && is_string($item['id']) && $item['id'] !== '') {
                    $brIds[strtolower($item['id'])] = true;
                }
            }
            if (empty($brIds)) {
                continue;
            }
            foreach ($instruments as $inst) {
                if (is_array($inst) && isset($inst['id']) && is_string($inst['id']) && $inst['id'] !== '') {
                    $key = strtolower($inst['id']);
                    $map[$key] = ($map[$key] ?? []) + $brIds;
                }
            }
        }
        return $map;
    }

    /**
     * En un lote, quita de brItems las contrapartes BR concedidas por un
     * instrumento incluido en el mismo lote (ver instrumentCompanionMap).
     * Las ofertas individuales no se tocan.
     */
    private static function stripInstrumentCompanions(array $entry, array $companions): array
    {
        if (empty($companions) || !isset($entry['bundle']) || $entry['bundle'] === null) {
            return $entry;
        }
        $brItems = $entry['brItems'] ?? [];
        if (!is_array($brItems) || empty($brItems)) {
            return $entry;
        }
        $granted = [];
        foreach (($entry['instruments'] ?? []) as $inst) {
            if (is_array($inst) && isset($inst['id']) && is_string($inst['id'])) {
                $granted += $companions[strtolower($inst['id'])] ?? [];
            }
        }
        if (empty($granted)) {
            return $entry;
        }
        $filtered = array_values(array_filter($brItems, function ($item) use ($granted) {
            return !(is_array($item) && isset($item['id']) && is_string($item['id'])
                && isset($granted[strtolower($item['id'])]));
        }));
        if (count($filtered) !== count($brItems) && !empty($filtered)) {
            $entry['brItems'] = $filtered;
        }
        return $entry;
    }

    /**
     * Lead cosmetic id of an entry: tracks[0] for jam tracks, otherwise the
     * first of brItems/instruments/cars (mirrors getItemDisplayInfo in app.js;
     * for bundles this is the representative lead item of the lote).
     */
    public static function entryMainItemId(array $entry): ?string
    {
        if (isset($entry['tracks'][0]['id'])) {
            return strtolower((string) $entry['tracks'][0]['id']);
        }
        foreach (['brItems', 'instruments', 'cars'] as $key) {
            if (isset($entry[$key][0]['id'])) {
                return strtolower((string) $entry[$key][0]['id']);
            }
        }
        return null;
    }

    /**
     * All cosmetic ids contained in an entry (lowercased, deduplicated):
     * brItems/instruments/cars/tracks.
     * @return string[]
     */
    public static function entryItemIds(array $entry): array
    {
        $ids = [];
        foreach (['brItems', 'instruments', 'cars', 'tracks'] as $key) {
            foreach (($entry[$key] ?? []) as $item) {
                if (is_array($item) && isset($item['id'])) {
                    $ids[strtolower((string) $item['id'])] = true;
                }
            }
        }
        return array_keys($ids);
    }

    /**
     * Normalize one manual-videos map value into ['url' => ?string,
     * 'styles' => array]. Legacy shape (plain url string) still accepted.
     * Returns null when there is no usable video.
     * @return array{url:?string,styles:array<string,string>}|null
     */
    private static function normalizeVideosShape($videos): ?array
    {
        if (is_string($videos)) {
            $videos = ['url' => $videos, 'styles' => []];
        }
        if (!is_array($videos)) {
            return null;
        }
        $url    = isset($videos['url']) && is_string($videos['url']) && $videos['url'] !== '' ? $videos['url'] : null;
        $styles = isset($videos['styles']) && is_array($videos['styles']) ? $videos['styles'] : [];
        if ($url === null && empty($styles)) {
            return null;
        }
        return ['url' => $url, 'styles' => $styles];
    }

    /**
     * Attach (or refresh) the manual video references on an entry:
     *   - `manualVideo`: video of the LEAD cosmetic (flash-free instant render
     *     of the main media). Keyed by the lead item — not by item count — so
     *     offers with extra items (outfit + back bling) and lotes led by that
     *     cosmetic also carry it.
     *   - `itemVideos`: cosmetic_id => {url, styles} for EVERY item of the
     *     entry with an active video (offer carousel of included items).
     */
    public static function attachManualVideo(array $entry, array $manualVideos): array
    {
        unset($entry['manualVideo'], $entry['itemVideos']);

        $itemVideos = [];
        foreach (self::entryItemIds($entry) as $itemId) {
            if (!isset($manualVideos[$itemId])) {
                continue;
            }
            $videos = self::normalizeVideosShape($manualVideos[$itemId]);
            if ($videos !== null) {
                $itemVideos[$itemId] = $videos;
            }
        }
        if (!empty($itemVideos)) {
            $entry['itemVideos'] = $itemVideos;
        }

        $mainId = self::entryMainItemId($entry);
        if ($mainId === null || !isset($itemVideos[$mainId])) {
            return $entry;
        }
        $manual = ['cosmeticId' => $mainId, 'url' => $itemVideos[$mainId]['url']];
        if (!empty($itemVideos[$mainId]['styles'])) {
            $manual['styles'] = $itemVideos[$mainId]['styles'];
        }
        $entry['manualVideo'] = $manual;
        return $entry;
    }

    /**
     * Most-specific-first discount resolution (specific → section → category →
     * global). Mirrors gestor_descuentos.php semantics (case-insensitive).
     */
    private static function matchDiscount($offerId, $itemType, $sectionName, bool $isBundle, array $activeDiscounts): ?array
    {
        if (empty($activeDiscounts)) {
            return null;
        }
        $itemCategory = $isBundle ? 'Lote' : $itemType;

        $checks = [
            function ($r) use ($offerId) {
                return ($r['tipo'] ?? '') === 'producto_especifico' && ($r['valor'] ?? null) === $offerId;
            },
            function ($r) use ($sectionName) {
                return $sectionName && ($r['tipo'] ?? '') === 'seccion_tienda'
                    && strcasecmp((string) ($r['valor'] ?? ''), (string) $sectionName) === 0;
            },
            function ($r) use ($itemCategory) {
                if (($r['tipo'] ?? '') !== 'categoria_api') {
                    return false;
                }
                $val = (string) ($r['valor'] ?? '');
                return strcasecmp($val, (string) $itemCategory) === 0
                    || ($val === 'Lote' && str_contains(strtolower((string) $itemCategory), 'lote'));
            },
            function ($r) {
                return in_array($r['tipo'] ?? '', ['global', 'global_sin_estaticos'], true);
            },
        ];

        foreach ($checks as $check) {
            foreach ($activeDiscounts as $discount) {
                foreach (($discount['reglas_aplicabilidad'] ?? []) as $rule) {
                    if ($check($rule)) {
                        return $discount;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Apply the configurable field map (cache/api_field_map.json) to heal a
     * renamed external field WITHOUT a code deploy. The map is compiled by the
     * Data Layer Manager from the dl_api_field_map table:
     *
     *   {
     *     "data":  [ { "from": "out", "to": "date" } ],   // data.<from> -> data.<to>
     *     "entry": [ { "from": "endDate", "to": "outDate" } ] // each entry.<from> -> entry.<to>
     *   }
     *
     * Only copies when the source exists and the canonical target is absent, so
     * a correct API is never disturbed. Missing file/invalid JSON => no-op.
     */
    public static function applyFieldMap(array $apiData): array
    {
        $path = ShopSourceConfig::fieldMapPath();
        if (!is_file($path)) {
            return $apiData;
        }
        $map = json_decode((string) @file_get_contents($path), true);
        if (!is_array($map)) {
            return $apiData;
        }

        // data-scope rules
        foreach (($map['data'] ?? []) as $rule) {
            $from = (string) ($rule['from'] ?? '');
            $to   = (string) ($rule['to'] ?? '');
            if ($from === '' || $to === '') {
                continue;
            }
            if (isset($apiData['data'][$from]) && !isset($apiData['data'][$to])) {
                $apiData['data'][$to] = $apiData['data'][$from];
            }
        }

        // entry-scope rules
        $entryRules = $map['entry'] ?? [];
        if (!empty($entryRules) && isset($apiData['data']['entries']) && is_array($apiData['data']['entries'])) {
            foreach ($apiData['data']['entries'] as &$entry) {
                if (!is_array($entry)) {
                    continue;
                }
                foreach ($entryRules as $rule) {
                    $from = (string) ($rule['from'] ?? '');
                    $to   = (string) ($rule['to'] ?? '');
                    if ($from === '' || $to === '') {
                        continue;
                    }
                    if (isset($entry[$from]) && !isset($entry[$to])) {
                        $entry[$to] = $entry[$from];
                    }
                }
            }
            unset($entry);
        }

        return $apiData;
    }

    /**
     * Inspect a raw API payload and report structural anomalies for the Data
     * Layer Manager's observability log. Does NOT throw. Each item is:
     *   [ 'severity' => 'warning|error', 'type' => '...', 'message' => '...' ]
     *
     * @return array<int,array<string,string>>
     */
    public static function detectAnomalies(array $apiData): array
    {
        $issues = [];

        if (!isset($apiData['data']) || !is_array($apiData['data'])) {
            $issues[] = ['severity' => 'error', 'type' => 'missing_field', 'message' => "Falta el objeto raíz 'data' en la respuesta de la API."];
            return $issues;
        }
        $data = $apiData['data'];

        foreach (['date', 'hash'] as $expected) {
            if (!array_key_exists($expected, $data)) {
                $issues[] = ['severity' => 'warning', 'type' => 'missing_field', 'message' => "Falta el campo esperado 'data.{$expected}' (¿renombrado por la API?)."];
            }
        }

        $entries = $data['entries'] ?? null;
        if (!is_array($entries)) {
            $issues[] = ['severity' => 'error', 'type' => 'missing_field', 'message' => "Falta 'data.entries' o no es una lista."];
            return $issues;
        }
        if (count($entries) === 0) {
            $issues[] = ['severity' => 'error', 'type' => 'empty_shop', 'message' => 'La API devolvió 0 entradas de tienda.'];
        }

        // Sample the first few entries for the critical fields the pipeline needs.
        $sample = array_slice($entries, 0, 25);
        $missingOffer = $missingPrice = 0;
        foreach ($sample as $e) {
            if (!is_array($e)) {
                continue;
            }
            if (!isset($e['offerId'])) {
                $missingOffer++;
            }
            if (!isset($e['finalPrice'])) {
                $missingPrice++;
            }
        }
        if ($missingOffer > 0) {
            $issues[] = ['severity' => 'error', 'type' => 'missing_field', 'message' => "{$missingOffer}/" . count($sample) . " entradas muestreadas sin 'offerId'."];
        }
        if ($missingPrice > 0) {
            $issues[] = ['severity' => 'warning', 'type' => 'missing_field', 'message' => "{$missingPrice}/" . count($sample) . " entradas muestreadas sin 'finalPrice'."];
        }

        return $issues;
    }
}

}

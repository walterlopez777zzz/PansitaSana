<?php
/**
 * ============================================================================
 * BANANO STORE — SHOP REPOSITORY / DATA ACCESS LAYER (Phase 2)
 * ============================================================================
 * The ONLY component the rest of the app uses to read shop data. It:
 *   - Resolves the active snapshot (live vs daily fallback) via source mode.
 *   - Decodes "banano_primordial.json" and returns canonical items.
 *   - Applies manual item overrides (item_overrides.json) at read time.
 *   - Degrades gracefully (returns [] instead of throwing) so a missing
 *     snapshot never fatals a consumer.
 *
 * Consumers (cart / spin / raffle / sorteos / gestor_descuentos) call
 * ShopRepository::items() instead of reading cache files directly.
 * ============================================================================
 */

require_once __DIR__ . '/../config/shop_config.php';

if (!class_exists('ShopRepository')) {

final class ShopRepository
{
    /** @var array<string,mixed>|null In-request memoization of the snapshot. */
    private static $snapshotCache = null;
    private static $snapshotCachePath = null;

    /**
     * Load and decode the active snapshot structure (full document).
     * Returns null when nothing is servable.
     */
    public static function snapshot(): ?array
    {
        $path = ShopSourceConfig::activeSnapshotPath();
        if ($path === null || !is_file($path)) {
            return null;
        }
        if (self::$snapshotCache !== null && self::$snapshotCachePath === $path) {
            return self::$snapshotCache;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        self::$snapshotCache = $data;
        self::$snapshotCachePath = $path;
        return $data;
    }

    /**
     * Canonical items array. Tolerant of both the canonical `entries` key and
     * the legacy `shop` key. Overrides are applied when present.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function items(): array
    {
        $snap = self::snapshot();
        if ($snap === null) {
            return [];
        }
        $items = $snap['entries'] ?? $snap['shop'] ?? [];
        if (!is_array($items)) {
            return [];
        }
        return self::dropExpiredManaged(self::applyOverrides(self::withCustomEntries($items)));
    }

    /**
     * Decode a snapshot from an explicit file path (no source-mode resolution,
     * no memoization). Used by the Data Layer Manager to preview LIVE vs
     * FALLBACK side by side without touching the active mode. Returns null when
     * the file is missing/unreadable/not JSON.
     */
    public static function snapshotFromPath(string $path): ?array
    {
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Canonical items for a specific source: 'active' (default, honors mode),
     * 'live' (banano_primordial.json) or 'fallback'. Overrides are applied so
     * the preview matches exactly what the storefront would render.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function itemsFrom(string $which = 'active'): array
    {
        switch (strtolower(trim($which))) {
            case 'live':
                $snap = self::snapshotFromPath(ShopSourceConfig::liveSnapshotPath());
                break;
            case 'fallback':
                $snap = self::snapshotFromPath(ShopSourceConfig::fallbackSnapshotPath());
                break;
            default:
                return self::items();
        }
        if ($snap === null) {
            return [];
        }
        $items = $snap['entries'] ?? $snap['shop'] ?? [];
        if (!is_array($items)) {
            return [];
        }
        return self::dropExpiredManaged(self::applyOverrides(self::withCustomEntries($items)));
    }

    /** Canonical shop date (data.date from the source API), or null. */
    public static function shopDate(): ?string
    {
        $snap = self::snapshot();
        if ($snap === null) {
            return null;
        }
        return $snap['shop_date'] ?? ($snap['data']['date'] ?? null);
    }

    /** Find a single canonical item by its offerId. */
    public static function findByOfferId(string $offerId): ?array
    {
        foreach (self::items() as $item) {
            if (($item['offerId'] ?? null) === $offerId) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Append manually-created entries (cache/custom_entries.json, compiled
     * from the dl_custom_entries table) to the snapshot items. Each stored
     * entry is a full canonical superset produced by ShopNormalizer at save
     * time, so every consumer (storefront, cart, editor) reads one shape.
     * Runs BEFORE applyOverrides so custom entries are editable with the same
     * override pipeline as API entries. Snapshot offerIds always win: a
     * colliding custom entry is skipped.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private static function withCustomEntries(array $items): array
    {
        $path = ShopSourceConfig::customEntriesPath();
        if (!is_file($path)) {
            return $items;
        }
        $custom = json_decode((string) @file_get_contents($path), true);
        if (!is_array($custom) || empty($custom)) {
            return $items;
        }
        $existing = [];
        foreach ($items as $it) {
            if (is_array($it) && isset($it['offerId'])) {
                $existing[(string) $it['offerId']] = true;
            }
        }
        foreach ($custom as $offerId => $entry) {
            $offerId = (string) $offerId;
            if ($offerId === '' || !is_array($entry) || isset($existing[$offerId])) {
                continue;
            }
            $entry['offerId']       = $offerId;
            $entry['isCustomEntry'] = true;
            $items[] = $entry;
            $existing[$offerId] = true;
        }
        return $items;
    }

    /**
     * Apply manual overrides from item_overrides.json. The file maps
     * offerId => { field: value, ... }. Matching items are patched in TWO ways:
     *
     *   1. Canonical (shallow) merge — so backend consumers that read the flat
     *      canonical fields (cart / spin / raffle: displayName, precio_base_soles,
     *      rarity, colors, vbucks…) see the override immediately.
     *   2. DEEP injection — so the storefront, which reads the RAW nested keys
     *      the public API ships (entry.bundle.name, entry.brItems[0].name,
     *      entry.finalPrice, …), also reflects the override WITHOUT changing how
     *      the frontend calls the data. This is the "Pablito" case.
     *
     * Designed so the Admin "Gestor de Capa de Datos" can patch fields without a
     * code deploy.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private static function applyOverrides(array $items): array
    {
        $path = ShopSourceConfig::overridesPath();
        if (!is_file($path)) {
            return $items;
        }
        $overrides = json_decode((string) @file_get_contents($path), true);
        if (!is_array($overrides) || empty($overrides)) {
            return $items;
        }
        // Support both { "OFFERID": {...} } and { "items": { "OFFERID": {...} } }.
        if (isset($overrides['items']) && is_array($overrides['items'])) {
            $overrides = $overrides['items'];
        }
        foreach ($items as &$item) {
            $offerId = $item['offerId'] ?? null;
            if ($offerId === null || !isset($overrides[$offerId]) || !is_array($overrides[$offerId])) {
                continue;
            }
            $ov = $overrides[$offerId];

            // An offerId override rewrites the entry's effective ID, but the
            // snapshot/DB stay keyed by the ORIGINAL id (reversible). Keep it
            // available so the admin editor can keep anchoring overrides to it.
            if (isset($ov['offerId']) && is_scalar($ov['offerId'])
                && (string) $ov['offerId'] !== '' && (string) $ov['offerId'] !== (string) $offerId) {
                $item['offerIdOriginal'] = (string) $offerId;
            }

            // (1) Canonical merge. `colors` is deep-merged so a partial override
            // (e.g. only color1/color2) does not wipe color3/textBackgroundColor.
            $merged = $ov;
            if (isset($ov['colors']) && is_array($ov['colors']) && is_array($item['colors'] ?? null)) {
                $clean = array_filter($ov['colors'], static fn ($v) => $v !== null && $v !== '');
                $merged['colors'] = array_replace($item['colors'], $clean);
            }
            // `images` and `difficulty` are SLOT maps (editor keys, not the
            // canonical shape), so they must never be shallow-merged onto the
            // entry: the deep injection below places each slot where it belongs.
            // `banner` is an editor-shaped state object ({mode,text,expiresOn}),
            // not the raw API shape, so it is resolved by the deep injection too.
            // `set`, `introduction` and `outDate` need normalization/mirroring
            // into nested raw keys, also handled by the deep injection.
            unset($merged['images'], $merged['difficulty'], $merged['banner'],
                $merged['set'], $merged['introduction'], $merged['outDate']);
            // `tileSize` only accepts the canonical API strings (Size_1_x_1 …
            // Size_4_x_1); anything else is ignored to keep the grid layout sane.
            if (isset($merged['tileSize'])
                && (!is_string($merged['tileSize']) || !preg_match('/^Size_[1-4]_x_1$/', $merged['tileSize']))) {
                unset($merged['tileSize']);
            }
            // `layout` must be a full section object ({id, name, rank}) and
            // `layoutId` must keep the API "<id>.<suffix>" contract; anything
            // else is ignored so section grouping/ordering can never break.
            if (isset($merged['layout'])) {
                $L = $merged['layout'];
                if (!is_array($L)
                    || !is_string($L['id'] ?? null) || $L['id'] === ''
                    || !is_string($L['name'] ?? null) || $L['name'] === ''
                    || !is_numeric($L['rank'] ?? null)) {
                    unset($merged['layout']);
                } else {
                    // Keep the canonical mirrors in sync (discount matching by
                    // section reads section_name; editor grouping reads both).
                    $merged['section_name'] = $merged['section_name'] ?? $L['name'];
                    $merged['store_rank']   = (int) $L['rank'];
                }
            }
            if (isset($merged['layoutId'])
                && (!is_string($merged['layoutId']) || !preg_match('/^\S+\.\d+$/', $merged['layoutId']))) {
                unset($merged['layoutId']);
            }
            if (isset($merged['sortPriority']) && !is_numeric($merged['sortPriority'])) {
                unset($merged['sortPriority']);
            }
            $item = array_replace($item, $merged);

            // (2) Deep injection into the raw nested keys the storefront reads.
            self::injectDeepOverrides($item, $ov);
        }
        unset($item);
        return $items;
    }

    /**
     * Write override values into the RAW nested keys that worker.js / app.js
     * read directly from each entry, so a name/price/description/rarity/colors
     * override is reflected on the storefront transparently.
     *
     * Containers are NEVER created: a leaf is only written when its parent
     * already exists (e.g. `bundle.name` only when the entry is a bundle), so a
     * single-item entry is never accidentally turned into a bundle.
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $ov
     */
    private static function injectDeepOverrides(array &$item, array $ov): void
    {
        // --- Name ("Pablito"): bundle.name + first item of each collection ---
        if (array_key_exists('displayName', $ov) && is_scalar($ov['displayName'])) {
            $name = (string) $ov['displayName'];
            self::setDeepLeaf($item, ['bundle', 'name'], $name);
            self::setDeepLeaf($item, ['brItems', 0, 'name'], $name);
            self::setDeepLeaf($item, ['instruments', 0, 'name'], $name);
            self::setDeepLeaf($item, ['cars', 0, 'name'], $name);
            self::setDeepLeaf($item, ['tracks', 0, 'title'], $name);
        }

        // --- Category / type label ("Lote" → "Skin"/"Atuendo"): SOLO texto
        // visual. El storefront calcula la etiqueta de tipo desde llaves que NO
        // permiten texto libre (bundle → 'Lote' fijo; ítem suelto → traducción
        // de type.value). Por eso publicamos el override como un campo visual
        // dedicado `displayTypeOverride` que el frontend prioriza para la
        // etiqueta mostrada. NO tocamos `type.value` ni `type.displayValue`:
        // ambos son estructurales/funcionales (buckets de filtros y matching de
        // descuentos por `categoria_api` en gestor_descuentos.php). El tipo en
        // carrito/checkout ya refleja el override vía el `displayType` canónico
        // (merge superficial de arriba).
        if (array_key_exists('displayType', $ov) && is_scalar($ov['displayType'])) {
            $label = trim((string) $ov['displayType']);
            if ($label !== '') {
                $item['displayTypeOverride'] = $label;
            }
        }

        // --- Description: bundle.info + first item of each collection ---------
        if (array_key_exists('description', $ov) && is_scalar($ov['description'])) {
            $desc = (string) $ov['description'];
            self::setDeepLeaf($item, ['bundle', 'info'], $desc);
            self::setDeepLeaf($item, ['brItems', 0, 'description'], $desc);
            self::setDeepLeaf($item, ['instruments', 0, 'description'], $desc);
            self::setDeepLeaf($item, ['cars', 0, 'description'], $desc);
            self::setDeepLeaf($item, ['tracks', 0, 'description'], $desc);
        }

        // --- Images: slot map from the editor ({icon, featured, small, large,
        // bundleImage, albumArt, render}). Each slot targets the exact raw key
        // a storefront surface reads; containers are never created. ------------
        if (isset($ov['images']) && is_array($ov['images'])) {
            self::injectImageOverrides($item, $ov['images']);
        }

        // --- Jam-track difficulty: per-instrument levels (0–7) merged into
        // tracks[0].difficulty. 0 hides the instrument on the item page. -------
        if (isset($ov['difficulty']) && is_array($ov['difficulty'])
            && isset($item['tracks'][0]) && is_array($item['tracks'][0])) {
            $clean = [];
            foreach ($ov['difficulty'] as $k => $v) {
                if (is_string($k) && $k !== '' && is_numeric($v)) {
                    $clean[$k] = max(0, min(7, (int) $v));
                }
            }
            if (!empty($clean)) {
                $base = is_array($item['tracks'][0]['difficulty'] ?? null) ? $item['tracks'][0]['difficulty'] : [];
                $item['tracks'][0]['difficulty'] = array_replace($base, $clean);
            }
        }

        // --- Rarity: raw shape {value, displayValue} on the representative item.
        // When a `series` object drives the displayed rarity, keep it in sync. --
        if (array_key_exists('rarity', $ov)) {
            $raw = self::rarityToRaw($ov['rarity']);
            if ($raw !== null) {
                foreach (['brItems', 'instruments', 'cars'] as $coll) {
                    self::setDeepLeaf($item, [$coll, 0, 'rarity'], $raw);
                    self::setDeepLeaf($item, [$coll, 0, 'series', 'value'], $raw['value'], true);
                    self::setDeepLeaf($item, [$coll, 0, 'series', 'displayValue'], $raw['displayValue'], true);
                }
            }
        }

        // --- Colors: merge into the first item's colors (top-level already merged).
        if (array_key_exists('colors', $ov) && is_array($ov['colors'])) {
            $clean = array_filter($ov['colors'], static fn ($v) => $v !== null && $v !== '');
            if (!empty($clean) && isset($item['brItems'][0]) && is_array($item['brItems'][0])) {
                $base = is_array($item['brItems'][0]['colors'] ?? null) ? $item['brItems'][0]['colors'] : [];
                $item['brItems'][0]['colors'] = array_replace($base, $clean);
            }
        }

        // --- Styles: `styleRenderMap` maps a style key ("canal:tag", same
        // convention as manualVideo.styles) to the index of a NON-LEGO render
        // in newDisplayAsset.renderImages. It is resolved here to explicit
        // URLs (`styleImages`) so the storefront never has to guess which
        // render belongs to which variant style (the source API publishes no
        // such correspondence and the render order per style is not
        // guaranteed).
        if (isset($ov['styleRenderMap']) && is_array($ov['styleRenderMap'])) {
            $styleImages = self::resolveStyleImages($item, $ov['styleRenderMap']);
            if (!empty($styleImages)) {
                $item['styleImages'] = $styleImages;
            }
        }

        // --- Corner banner ("Nuevo"): editor state {mode, text, expiresOn}.
        // `expiresOn` is a UTC rotation date — the override is active only
        // while the current UTC day is BEFORE it (null = no expiration), so it
        // lapses exactly at the daily shop rotation with no timers involved.
        if (isset($ov['banner'])) {
            $state = self::bannerOverrideState($ov['banner']);
            if ($state !== null) {
                if ($state['mode'] === 'off') {
                    unset($item['banner']);
                } else {
                    $item['banner'] = [
                        'value'        => $state['text'],
                        'intensity'    => 'High',
                        'backendValue' => 'New',
                    ];
                }
            }
        }

        // --- Set membership: editor value {value} or null (remove). The raw
        // shape lives on the representative item of each collection; app.js
        // renders `mainItem.set.text` generically, so non-BR items work too.
        if (array_key_exists('set', $ov)) {
            $setValue = null;
            if (is_array($ov['set']) && is_string($ov['set']['value'] ?? null) && trim($ov['set']['value']) !== '') {
                $setValue = trim($ov['set']['value']);
            }
            foreach (['brItems', 'instruments', 'cars', 'tracks'] as $coll) {
                if (!isset($item[$coll][0]) || !is_array($item[$coll][0])) {
                    continue;
                }
                if ($setValue === null) {
                    unset($item[$coll][0]['set']);
                } else {
                    $prev = is_array($item[$coll][0]['set'] ?? null) ? $item[$coll][0]['set'] : [];
                    $item[$coll][0]['set'] = [
                        'value'        => $setValue,
                        'text'         => 'Pieza del conjunto ' . $setValue . '.',
                        'backendValue' => is_string($prev['backendValue'] ?? null) ? $prev['backendValue'] : $setValue,
                    ];
                }
            }
        }

        // --- Introduction: editor value {text} (free text, e.g. "Capítulo 5,
        // Temporada 2"). chapter/season are derived when the text matches the
        // canonical phrasing; app.js only renders `introduction.text`.
        if (array_key_exists('introduction', $ov)) {
            $introText = null;
            if (is_array($ov['introduction']) && is_string($ov['introduction']['text'] ?? null)
                && trim($ov['introduction']['text']) !== '') {
                $introText = trim($ov['introduction']['text']);
            }
            foreach (['brItems', 'instruments', 'cars', 'tracks'] as $coll) {
                if (!isset($item[$coll][0]) || !is_array($item[$coll][0])) {
                    continue;
                }
                if ($introText === null) {
                    unset($item[$coll][0]['introduction']);
                } else {
                    $item[$coll][0]['introduction'] = self::introductionFromText($introText);
                }
            }
        }

        // --- Out-date: ISO UTC string ('' = no expiration). Mirrors the exact
        // API contract (entry.outDate + offerDates.out) so the "SE VA HOY"
        // chip and the cart expiration (safety buffer) work transparently.
        // dropExpiredManaged() removes the entry once the date has passed.
        if (array_key_exists('outDate', $ov)) {
            $out = is_scalar($ov['outDate']) ? trim((string) $ov['outDate']) : '';
            if ($out !== '' && strtotime($out) !== false) {
                $item['outDate'] = $out;
                if (!is_array($item['offerDates'] ?? null)) {
                    $item['offerDates'] = ['in' => null, 'out' => null];
                }
                $item['offerDates']['out'] = $out;
                $item['outDateManaged'] = true;
            } else {
                unset($item['outDate'], $item['outDateManaged']);
                if (is_array($item['offerDates'] ?? null)) {
                    $item['offerDates']['out'] = null;
                }
            }
        }

        // --- Price (V-Bucks): the storefront derives soles from entry.finalPrice.
        foreach (['vbucks', 'finalPrice'] as $vk) {
            if (array_key_exists($vk, $ov) && is_numeric($ov[$vk])) {
                $item['finalPrice'] = (int) $ov[$vk];
                $item['vbucks']     = (int) $ov[$vk];
                break;
            }
        }
    }

    /**
     * Inject an image-slot override map into the raw nested keys the
     * storefront reads, keeping the canonical top-level `images` in sync so
     * backend consumers (cart, admin grids) reflect the change too.
     *
     * @param array<string,mixed> $item
     * @param array<mixed,mixed> $images
     */
    private static function injectImageOverrides(array &$item, array $images): void
    {
        $urls = [];
        foreach ($images as $k => $v) {
            if (is_string($k) && is_string($v) && trim($v) !== '') {
                $urls[$k] = trim($v);
            }
        }
        if (empty($urls)) {
            return;
        }
        if (isset($urls['bundleImage'])) {
            self::setDeepLeaf($item, ['bundle', 'image'], $urls['bundleImage']);
            self::setDeepLeaf($item, ['images', 'icon'], $urls['bundleImage']);
        }
        if (isset($urls['albumArt'])) {
            self::setDeepLeaf($item, ['tracks', 0, 'albumArt'], $urls['albumArt']);
            self::setDeepLeaf($item, ['images', 'icon'], $urls['albumArt']);
            self::setDeepLeaf($item, ['images', 'featured'], $urls['albumArt']);
        }
        if (isset($urls['icon'])) {
            self::setDeepLeaf($item, ['brItems', 0, 'images', 'icon'], $urls['icon']);
            self::setDeepLeaf($item, ['brItems', 0, 'images', 'smallIcon'], $urls['icon']);
            self::setDeepLeaf($item, ['images', 'icon'], $urls['icon']);
        }
        if (isset($urls['featured'])) {
            self::setDeepLeaf($item, ['brItems', 0, 'images', 'featured'], $urls['featured']);
            self::setDeepLeaf($item, ['images', 'featured'], $urls['featured']);
        }
        if (isset($urls['small'])) {
            self::setDeepLeaf($item, ['instruments', 0, 'images', 'small'], $urls['small']);
            self::setDeepLeaf($item, ['cars', 0, 'images', 'small'], $urls['small']);
            self::setDeepLeaf($item, ['images', 'icon'], $urls['small']);
        }
        if (isset($urls['large'])) {
            self::setDeepLeaf($item, ['instruments', 0, 'images', 'large'], $urls['large']);
            self::setDeepLeaf($item, ['cars', 0, 'images', 'large'], $urls['large']);
            self::setDeepLeaf($item, ['images', 'featured'], $urls['large']);
        }
        if (isset($urls['render']) && is_array($item['newDisplayAsset']['renderImages'] ?? null)) {
            foreach ($item['newDisplayAsset']['renderImages'] as $idx => $render) {
                if (is_array($render) && !empty($render['image']) && ($render['productTag'] ?? null) !== 'Product.Juno') {
                    $item['newDisplayAsset']['renderImages'][$idx]['image'] = $urls['render'];
                    break;
                }
            }
        }
    }

    /**
     * Set a value at a nested path WITHOUT creating intermediate containers.
     * Returns silently if any parent on the path is missing or not an array.
     *
     * @param array<string,mixed> $item
     * @param array<int,int|string> $path
     * @param mixed $value
     * @param bool $requireLeafExists When true, only overwrite an existing leaf.
     */
    /**
     * Drop entries whose editor-managed out-date has passed. Only entries
     * flagged `outDateManaged` (an `outDate` override, or a custom entry
     * created with an out-date) are affected: pure API entries keep their
     * current lifecycle (the upstream API removes them at rotation), so a
     * frozen fallback snapshot never empties itself.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private static function dropExpiredManaged(array $items): array
    {
        $now = time();
        $out = [];
        foreach ($items as $item) {
            if (is_array($item) && !empty($item['outDateManaged'])) {
                $ts = is_string($item['outDate'] ?? null) ? strtotime($item['outDate']) : false;
                if ($ts !== false && $ts <= $now) {
                    continue;
                }
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * Build the raw `introduction` object from the editor's free text.
     * "Capítulo X, Temporada Y" (accent/case tolerant) also fills
     * chapter/season; any other phrasing keeps just the display text.
     *
     * @return array<string,mixed>
     */
    private static function introductionFromText(string $text): array
    {
        $intro = ['text' => $text];
        if (preg_match('/cap[ií]tulo\s*([0-9]+)\s*,?\s*temporada\s*([0-9]+)/iu', $text, $m)) {
            $intro['chapter'] = $m[1];
            $intro['season']  = $m[2];
        }
        return $intro;
    }

    private static function setDeepLeaf(array &$item, array $path, $value, bool $requireLeafExists = false): void
    {
        $n = count($path);
        if ($n === 0) {
            return;
        }
        $ref =& $item;
        for ($i = 0; $i < $n - 1; $i++) {
            $k = $path[$i];
            if (!is_array($ref) || !array_key_exists($k, $ref) || !is_array($ref[$k])) {
                return; // never create parents
            }
            $ref =& $ref[$k];
        }
        $leaf = $path[$n - 1];
        if (!is_array($ref)) {
            return;
        }
        if ($requireLeafExists && !array_key_exists($leaf, $ref)) {
            return;
        }
        $ref[$leaf] = $value;
    }

    /**
     * Validate a banner override ({mode:'on'|'off', text?, expiresOn?}) and
     * resolve whether it is still active for the current UTC shop day.
     * Returns null when the override is malformed OR already expired (inert:
     * the entry keeps whatever the API shipped).
     *
     * @param mixed $banner
     * @return array{mode:string,text:string}|null
     */
    private static function bannerOverrideState($banner): ?array
    {
        if (!is_array($banner)) {
            return null;
        }
        $mode = $banner['mode'] ?? null;
        if ($mode !== 'on' && $mode !== 'off') {
            return null;
        }
        $expiresOn = $banner['expiresOn'] ?? null;
        if ($expiresOn !== null) {
            if (!is_string($expiresOn) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresOn)) {
                return null;
            }
            if (gmdate('Y-m-d') >= $expiresOn) {
                return null; // lapsed at rotation — API state prevails
            }
        }
        $text = trim((string) ($banner['text'] ?? ''));
        return ['mode' => $mode, 'text' => $text !== '' ? $text : 'Nuevo'];
    }

    /**
     * Coerce an override rarity (stored as {id,name} or a plain string) into the
     * raw API rarity shape {value, displayValue}. Returns null when empty.
     *
     * @param mixed $rarity
     * @return array{value:string,displayValue:string}|null
     */
    private static function rarityToRaw($rarity): ?array
    {
        if (is_array($rarity)) {
            $id   = $rarity['id'] ?? ($rarity['value'] ?? null);
            $name = $rarity['name'] ?? ($rarity['displayValue'] ?? $id);
            if ($id === null && $name === null) {
                return null;
            }
            return [
                'value'        => (string) ($id ?? $name),
                'displayValue' => (string) ($name ?? $id),
            ];
        }
        if (is_scalar($rarity) && (string) $rarity !== '') {
            return ['value' => (string) $rarity, 'displayValue' => (string) $rarity];
        }
        return null;
    }

    /**
     * Resolve a styleRenderMap override ({styleKey => non-LEGO render index})
     * against the entry's newDisplayAsset.renderImages into explicit URLs.
     *
     * @param array<string,mixed> $item
     * @param array<mixed,mixed> $map
     * @return array<string,string> styleKey => image URL
     */
    private static function resolveStyleImages(array $item, array $map): array
    {
        $renders = $item['newDisplayAsset']['renderImages'] ?? null;
        if (!is_array($renders)) {
            return [];
        }
        $nonLego = [];
        foreach ($renders as $render) {
            if (is_array($render)
                && ($render['productTag'] ?? null) !== 'Product.Juno'
                && isset($render['image']) && is_string($render['image']) && $render['image'] !== '') {
                $nonLego[] = $render['image'];
            }
        }
        $styleImages = [];
        foreach ($map as $styleKey => $renderIndex) {
            if (!is_string($styleKey) || $styleKey === '' || !is_numeric($renderIndex)) {
                continue;
            }
            $idx = (int) $renderIndex;
            if (isset($nonLego[$idx])) {
                $styleImages[$styleKey] = $nonLego[$idx];
            }
        }
        return $styleImages;
    }

    /** Reset the in-request memoization (useful right after regenerating). */
    public static function flush(): void
    {
        self::$snapshotCache = null;
        self::$snapshotCachePath = null;
    }
}

}

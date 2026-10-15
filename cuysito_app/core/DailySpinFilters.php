<?php
/**
 * ============================================================================
 * BANANO STORE — DAILY SPIN FILTERS (server side)
 * ============================================================================
 * Single source of truth for the Giro Diario discount rules:
 *
 *   - Modes:   `all` (every item of the shop) and `no_tracks` (excludes Jam
 *              Tracks / "Pistas de Improvisación"). Each mode has its own
 *              discount percent stored per business day in `daily_spin_config`.
 *   - Filters: one per shop category (`displayType` of the snapshot). Admin
 *              decides which are visible and the individual percent of each.
 *              Rows live in `daily_spin_filter_config` (persistent, not daily).
 *
 * The catalog of categories is derived from the ACTIVE shop snapshot, so a
 * new `displayType` appearing in the API shows up in the admin automatically
 * (disabled until an admin turns it on). Public endpoints only expose enabled
 * filters that currently have items.
 *
 * Schema is self-healing (CREATE TABLE IF NOT EXISTS / conditional ALTER),
 * following the convention used across cuysito_app.
 * ============================================================================
 */

if (!class_exists('DailySpinFilters')) {

final class DailySpinFilters
{
    public const MODE_ALL       = 'all';
    public const MODE_NO_TRACKS = 'no_tracks';

    public const DEFAULT_ALL_PERCENT       = 15;
    public const DEFAULT_NO_TRACKS_PERCENT = 10;
    public const DEFAULT_FILTER_PERCENT    = 5;

    public const TABLE = 'daily_spin_filter_config';

    /**
     * Categories enabled the first time the table is created, so the public
     * dropdown is useful out of the box. Everything else starts OFF.
     * key => percent
     */
    private const SEED_ENABLED = [
        'atuendo' => 5,
        'pico'    => 5,
        'gesto'   => 5,
        'lote'    => 2,
        'estela'  => 5,
        'calzado' => 5,
    ];

    /** displayType (lowercase, no accents) => [singular, plural] labels for the UI. */
    private const LABELS = [
        'atuendo'                => ['Atuendo', 'Atuendos'],
        'gesto'                  => ['Gesto', 'Gestos'],
        'pico'                   => ['Pico', 'Picos'],
        'lote'                   => ['Lote', 'Lotes'],
        'estela'                 => ['Estela', 'Estelas'],
        'calzado'                => ['Calzado', 'Calzado'],
        'zapatos'                => ['Zapatos', 'Zapatos'],
        'mochila retro'          => ['Mochila retro', 'Mochilas retro'],
        'mochila'                => ['Mochila', 'Mochilas'],
        'papel'                  => ['Envoltorio', 'Envoltorios'],
        'envoltorio'             => ['Envoltorio', 'Envoltorios'],
        'rueda'                  => ['Rueda', 'Ruedas'],
        'companero'              => ['Compañero', 'Compañeros'],
        'planeador'              => ['Planeador', 'Planeadores'],
        'pista de improvisacion' => ['Pista', 'Pistas'],
        'pista'                  => ['Pista', 'Pistas'],
        'musica'                 => ['Música', 'Música'],
        'carroceria'             => ['Carrocería', 'Carrocerías'],
        'pintura'                => ['Pintura', 'Pinturas'],
        'propulsor'              => ['Propulsor', 'Propulsores'],
        'guitarra'               => ['Guitarra', 'Guitarras'],
        'bajo'                   => ['Bajo', 'Bajos'],
        'bateria'                => ['Batería', 'Baterías'],
        'microfono'              => ['Micrófono', 'Micrófonos'],
        'teclado'                => ['Teclado', 'Teclados'],
        'aerosol'                => ['Aerosol', 'Aerosoles'],
        'emoticono'              => ['Emoticono', 'Emoticonos'],
        'juguete'                => ['Juguete', 'Juguetes'],
        'pantalla de carga'      => ['Pantalla de carga', 'Pantallas de carga'],
    ];

    /* ────────────────────────────── schema ────────────────────────────── */

    /** Creates/upgrades every table and column this feature depends on. Idempotent. */
    public static function ensureSchema(mysqli $conn): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        $conn->query("CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
            `filter_key`       VARCHAR(64)  NOT NULL,
            `label`            VARCHAR(80)  NOT NULL DEFAULT '',
            `enabled`          TINYINT(1)   NOT NULL DEFAULT 0,
            `discount_percent` INT          NOT NULL DEFAULT " . self::DEFAULT_FILTER_PERCENT . ",
            `sort_order`       INT          NOT NULL DEFAULT 100,
            `updated_at`       DATETIME     NULL,
            PRIMARY KEY (`filter_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $count = $conn->query("SELECT COUNT(*) AS n FROM `" . self::TABLE . "`");
        $isEmpty = $count ? ((int)($count->fetch_assoc()['n'] ?? 0) === 0) : false;
        if ($count) $count->free();
        if ($isEmpty) {
            $ins = $conn->prepare("INSERT IGNORE INTO `" . self::TABLE . "` (filter_key, label, enabled, discount_percent, sort_order, updated_at) VALUES (?, ?, 1, ?, ?, UTC_TIMESTAMP())");
            if ($ins) {
                $order = 10;
                foreach (self::SEED_ENABLED as $key => $percent) {
                    $label = self::pluralLabel($key);
                    $ins->bind_param('ssii', $key, $label, $percent, $order);
                    $ins->execute();
                    $order += 10;
                }
                $ins->close();
            }
        }

        self::ensureColumn($conn, 'daily_spin_config', 'normal_discount_percent', "INT NOT NULL DEFAULT " . self::DEFAULT_ALL_PERCENT);
        self::ensureColumn($conn, 'daily_spin_config', 'no_tracks_discount_percent', "INT NOT NULL DEFAULT " . self::DEFAULT_NO_TRACKS_PERCENT);
        self::ensureColumn($conn, 'daily_discounts', 'spin_mode', "VARCHAR(16) NULL DEFAULT NULL");
        self::ensureColumn($conn, 'daily_discounts', 'filter_key', "VARCHAR(64) NULL DEFAULT NULL");
    }

    private static function ensureColumn(mysqli $conn, string $table, string $column, string $definition): void
    {
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        if ($res && $res->num_rows === 0) {
            $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
        if ($res) $res->free();
    }

    /* ───────────────────────────── snapshot ───────────────────────────── */

    /** Entries of the active shop snapshot (same unwrapping rules as spin_go). */
    public static function loadShopEntries(): array
    {
        $root = dirname(__DIR__);
        require_once $root . '/config/shop_config.php';
        $path = ShopSourceConfig::activeSnapshotPath() ?? ($root . '/cache/banano_primordial.json');
        if (!is_file($path)) $path = $root . '/cache_general/cache_tienda.json';
        if (!is_file($path)) return [];

        $decoded = json_decode((string)file_get_contents($path), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) return [];

        foreach (['entries', 'shop', 'items', 'data'] as $wrapper) {
            if (isset($decoded[$wrapper]) && is_array($decoded[$wrapper])) return array_values($decoded[$wrapper]);
        }
        if (count($decoded) === 1) {
            $first = reset($decoded);
            if (is_array($first) && count($first) > 1) return array_values($first);
        }
        return array_values($decoded);
    }

    /* ─────────────────────────── classification ───────────────────────── */

    /** Lowercase ASCII slug of a displayType: "Pista de Improvisación" → "pista_de_improvisacion". */
    public static function keyFor(string $displayType): string
    {
        $norm = self::normalize($displayType);
        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '_', $norm), '_');
        return $slug !== '' ? substr($slug, 0, 64) : 'otros';
    }

    /** Category key of a snapshot entry (`displayType`), or 'otros' when missing. */
    public static function entryKey(array $entry): string
    {
        $type = $entry['displayType'] ?? '';
        if (!is_string($type) || trim($type) === '') {
            $type = $entry['brItems'][0]['type']['displayValue'] ?? 'Otros';
        }
        return self::keyFor((string)$type);
    }

    /** Jam Track / "Pista de Improvisación" detection. */
    public static function isTrackEntry(array $entry): bool
    {
        $type = self::normalize((string)($entry['displayType'] ?? ''));
        if ($type !== '') return strpos($type, 'pista') !== false;
        $onlyTracks = !empty($entry['tracks']) && is_array($entry['tracks'])
            && empty($entry['brItems']) && empty($entry['cars']) && empty($entry['instruments']) && empty($entry['bundle']);
        return $onlyTracks;
    }

    /** Bundles need the public warning ("algunos atuendos o vehículos están catalogados como Lote"). */
    public static function isBundleKey(string $key): bool
    {
        return $key === 'lote' || strpos($key, 'lote') === 0;
    }

    public static function pluralLabel(string $keyOrType): string
    {
        $norm = self::normalize($keyOrType);
        $norm = str_replace('_', ' ', $norm);
        if (isset(self::LABELS[$norm])) return self::LABELS[$norm][1];
        $singular = trim($keyOrType) !== '' ? trim($keyOrType) : 'Otros';
        return mb_strtoupper(mb_substr($singular, 0, 1)) . mb_substr($singular, 1);
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii === false || $ascii === '') {
            $ascii = strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
        }
        return preg_replace('/[\'^`~"]/', '', $ascii) ?? $ascii;
    }

    /**
     * Groups the snapshot by category.
     * @return array<string, array{key:string,label:string,source_type:string,count:int,track_count:int,is_bundle:bool}>
     */
    public static function catalog(array $entries): array
    {
        $cats = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;
            $key = self::entryKey($entry);
            if (!isset($cats[$key])) {
                $type = (string)($entry['displayType'] ?? 'Otros');
                $cats[$key] = [
                    'key'         => $key,
                    'label'       => self::pluralLabel($type),
                    'source_type' => $type,
                    'count'       => 0,
                    'track_count' => 0,
                    'is_bundle'   => self::isBundleKey($key),
                ];
            }
            $cats[$key]['count']++;
            if (self::isTrackEntry($entry)) $cats[$key]['track_count']++;
        }
        uasort($cats, static fn($a, $b) => $b['count'] <=> $a['count']);
        return $cats;
    }

    /* ──────────────────────────── persistence ─────────────────────────── */

    /** @return array<string, array{filter_key:string,label:string,enabled:int,discount_percent:int,sort_order:int,updated_at:?string}> */
    public static function loadRows(mysqli $conn): array
    {
        $rows = [];
        $res = $conn->query("SELECT filter_key, label, enabled, discount_percent, sort_order, updated_at FROM `" . self::TABLE . "` ORDER BY sort_order ASC, filter_key ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $row['enabled'] = (int)$row['enabled'];
                $row['discount_percent'] = (int)$row['discount_percent'];
                $row['sort_order'] = (int)$row['sort_order'];
                $rows[$row['filter_key']] = $row;
            }
            $res->free();
        }
        return $rows;
    }

    /**
     * Catalog ⨝ DB rows. Every category present in the shop is returned (with
     * defaults when never configured) plus configured rows whose category is
     * absent from today's shop (count = 0) so admin edits are never lost.
     *
     * @return array<int, array{key:string,label:string,source_type:string,count:int,track_count:int,is_bundle:bool,enabled:bool,percent:int,configured:bool,sort_order:int}>
     */
    public static function resolve(mysqli $conn, array $entries): array
    {
        $catalog = self::catalog($entries);
        $rows    = self::loadRows($conn);
        $out     = [];

        foreach ($catalog as $key => $cat) {
            $row = $rows[$key] ?? null;
            $out[$key] = $cat + [
                'enabled'    => $row ? $row['enabled'] === 1 : false,
                'percent'    => $row ? max(0, min(100, $row['discount_percent'])) : self::DEFAULT_FILTER_PERCENT,
                'configured' => $row !== null,
                'sort_order' => $row ? $row['sort_order'] : 100,
            ];
        }
        foreach ($rows as $key => $row) {
            if (isset($out[$key])) continue;
            $out[$key] = [
                'key'         => $key,
                'label'       => $row['label'] !== '' ? $row['label'] : self::pluralLabel($key),
                'source_type' => '',
                'count'       => 0,
                'track_count' => 0,
                'is_bundle'   => self::isBundleKey($key),
                'enabled'     => $row['enabled'] === 1,
                'percent'     => max(0, min(100, $row['discount_percent'])),
                'configured'  => true,
                'sort_order'  => $row['sort_order'],
            ];
        }

        $list = array_values($out);
        usort($list, static function ($a, $b) {
            if ($a['sort_order'] !== $b['sort_order']) return $a['sort_order'] <=> $b['sort_order'];
            if ($a['count'] !== $b['count']) return $b['count'] <=> $a['count'];
            return strcmp($a['label'], $b['label']);
        });
        return $list;
    }

    /**
     * Only what the storefront may show: enabled AND with items in today's shop,
     * best discount first (% OFF desc; ties keep the admin order from resolve()).
     */
    public static function publicFilters(array $resolved): array
    {
        $public = [];
        foreach ($resolved as $i => $f) {
            if (!$f['enabled'] || $f['count'] <= 0) continue;
            $public[] = [
                'key'       => $f['key'],
                'label'     => $f['label'],
                'percent'   => $f['percent'],
                'count'     => $f['count'],
                'is_bundle' => $f['is_bundle'],
                '_pos'      => $i,
            ];
        }
        usort($public, static function ($a, $b) {
            if ($a['percent'] !== $b['percent']) return $b['percent'] <=> $a['percent'];
            return $a['_pos'] <=> $b['_pos'];
        });
        foreach ($public as &$f) unset($f['_pos']);
        unset($f);
        return $public;
    }

    /**
     * Persists admin edits. `$items` = [['key'=>..., 'enabled'=>0|1, 'percent'=>0..100, 'label'=>?], ...]
     * Returns the number of rows written.
     */
    public static function saveRows(mysqli $conn, array $items): int
    {
        $stmt = $conn->prepare("INSERT INTO `" . self::TABLE . "` (filter_key, label, enabled, discount_percent, sort_order, updated_at)
            VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE label = VALUES(label), enabled = VALUES(enabled), discount_percent = VALUES(discount_percent), sort_order = VALUES(sort_order), updated_at = UTC_TIMESTAMP()");
        if (!$stmt) return 0;
        $written = 0;
        $order = 10;
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $rawKey = trim((string)($item['key'] ?? ''));
            if ($rawKey === '') continue;
            $key = self::keyFor($rawKey);
            $label   = trim((string)($item['label'] ?? '')) ?: self::pluralLabel($key);
            $label   = mb_substr($label, 0, 80);
            $enabled = !empty($item['enabled']) && (string)$item['enabled'] !== '0' ? 1 : 0;
            $percent = max(0, min(100, (int)($item['percent'] ?? self::DEFAULT_FILTER_PERCENT)));
            $sort    = isset($item['sort_order']) ? (int)$item['sort_order'] : $order;
            $stmt->bind_param('ssiii', $key, $label, $enabled, $percent, $sort);
            if ($stmt->execute()) $written++;
            $order += 10;
        }
        $stmt->close();
        return $written;
    }

    /* ───────────────────────────── discounts ──────────────────────────── */

    /** Percent for a mode from today's config row (falls back to defaults). */
    public static function modePercent(?array $dayConfig, string $mode): int
    {
        if ($mode === self::MODE_NO_TRACKS) {
            return isset($dayConfig['no_tracks_discount_percent']) ? (int)$dayConfig['no_tracks_discount_percent'] : self::DEFAULT_NO_TRACKS_PERCENT;
        }
        return isset($dayConfig['normal_discount_percent']) ? (int)$dayConfig['normal_discount_percent'] : self::DEFAULT_ALL_PERCENT;
    }

    public static function normalizeMode(?string $mode): string
    {
        return $mode === self::MODE_NO_TRACKS ? self::MODE_NO_TRACKS : self::MODE_ALL;
    }

    /**
     * Applies mode + filter to the shop entries.
     * @return array{items: array, percent:int, mode:string, filter:?array}
     * @throws InvalidArgumentException when the filter is unknown/disabled/empty.
     */
    public static function buildPool(array $entries, ?array $dayConfig, array $resolvedFilters, string $mode, string $filterKey): array
    {
        $mode = self::normalizeMode($mode);
        $filterKey = trim($filterKey);
        $filter = null;

        if ($filterKey !== '') {
            foreach ($resolvedFilters as $f) {
                if ($f['key'] === $filterKey) { $filter = $f; break; }
            }
            if (!$filter || !$filter['enabled']) {
                throw new InvalidArgumentException('El filtro seleccionado no está disponible.');
            }
            $items = array_values(array_filter($entries, static fn($e) => is_array($e) && self::entryKey($e) === $filterKey));
            if (count($items) === 0) {
                throw new InvalidArgumentException('No hay objetos en esa categoría hoy.');
            }
            return ['items' => $items, 'percent' => (int)$filter['percent'], 'mode' => $mode, 'filter' => $filter];
        }

        if ($mode === self::MODE_NO_TRACKS) {
            $items = array_values(array_filter($entries, static fn($e) => is_array($e) && !self::isTrackEntry($e)));
            if (count($items) === 0) $items = $entries;
        } else {
            $items = array_values(array_filter($entries, 'is_array'));
        }
        return ['items' => $items, 'percent' => self::modePercent($dayConfig, $mode), 'mode' => $mode, 'filter' => null];
    }

    /** Human label of a stored spin (mode + filter) for admin breakdowns. */
    public static function describeSpin(?string $mode, ?string $filterKey, array $labelsByKey = []): string
    {
        $filterKey = (string)$filterKey;
        if ($filterKey !== '') return 'Filtro: ' . ($labelsByKey[$filterKey] ?? self::pluralLabel($filterKey));
        if ($mode === self::MODE_NO_TRACKS) return 'Sin Pistas';
        if ($mode === self::MODE_ALL) return 'Todo';
        return 'Sin clasificar';
    }
}

}

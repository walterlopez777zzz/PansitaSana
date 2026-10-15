<?php
/**
 * ============================================================================
 * BANANO STORE — CENTRAL SHOP CONFIG (Phase 0)
 * ============================================================================
 * Single source of truth for:
 *   - External shop API URL / language / optional API key
 *   - Canonical snapshot paths ("banano_primordial.json" + fallback)
 *   - Source-mode (auto | live | fallback | maintenance) resolution
 *   - Fallback anchor time (00:02 UTC)
 *
 * SECURITY NOTES:
 *   - The fortnite-api.com shop endpoint is PUBLIC and needs NO API key for
 *     raw reads. We therefore send NO Authorization header by default.
 *   - An OPTIONAL key (for higher rate limits) may be provided via the
 *     BANANO_FORTNITE_API_KEY environment variable. It is NEVER hardcoded.
 *   - All outbound curl calls MUST use full SSL verification (no
 *     CURLOPT_SSL_VERIFYPEER = false anywhere).
 * ============================================================================
 */

if (!class_exists('ShopSourceConfig')) {

final class ShopSourceConfig
{
    /** Canonical schema version produced by the normalizer. */
    const SCHEMA_VERSION = 1;

    /** External (public) shop endpoint. Single place it is declared. */
    const API_URL = 'https://fortnite-api.com/v2/shop';
    const API_LANGUAGE = 'es-419';

    /** Public cosmetics endpoint (used by get_item_details, server-side only). */
    const COSMETICS_URL = 'https://fortnite-api.com/v2/cosmetics/br';

    /** Canonical cache filenames (live master + daily safety snapshot). */
    const FILE_LIVE     = 'banano_primordial.json';
    const FILE_FALLBACK = 'banano_primordial_fallback.json';

    /** Source-mode + fallback bookkeeping files (live under /cache). */
    const FILE_SOURCE_MODE   = 'shop_source_mode.json';
    const FILE_FALLBACK_DATE = '.last_fallback_date';

    /** Item overrides applied at read time by the DAL (manual field fixes). */
    const FILE_OVERRIDES = 'item_overrides.json';

    /** Configurable API field map (compiled by the Data Layer Manager). */
    const FILE_FIELD_MAP = 'api_field_map.json';

    /** Manually-created shop entries (compiled by the Data Layer Manager). */
    const FILE_CUSTOM_ENTRIES = 'custom_entries.json';

    /** Fallback anchor: 00:02 UTC (2-min grace for the API to rotate). */
    const FALLBACK_HOUR_UTC   = 0;
    const FALLBACK_MINUTE_UTC = 2;

    /** A live snapshot older than this many seconds is considered stale. */
    const STALE_AFTER_SECONDS = 7200; // 2h

    /** Default conversion rate (soles per 100 vbucks) if DB is unreachable. */
    const DEFAULT_CONVERSION_RATE = '1.50';

    /** Absolute path to the application root (the cuysito_app directory). */
    public static function appRoot(): string
    {
        return dirname(__DIR__);
    }

    /** Absolute path to the cache directory. */
    public static function cacheDir(): string
    {
        return self::appRoot() . '/cache';
    }

    public static function liveSnapshotPath(): string
    {
        return self::cacheDir() . '/' . self::FILE_LIVE;
    }

    public static function fallbackSnapshotPath(): string
    {
        return self::cacheDir() . '/' . self::FILE_FALLBACK;
    }

    public static function sourceModePath(): string
    {
        return self::cacheDir() . '/' . self::FILE_SOURCE_MODE;
    }

    public static function fallbackDatePath(): string
    {
        return self::cacheDir() . '/' . self::FILE_FALLBACK_DATE;
    }

    public static function overridesPath(): string
    {
        // Overrides historically live alongside the api/ helpers.
        return self::appRoot() . '/api/' . self::FILE_OVERRIDES;
    }

    /**
     * Compiled API field map (cache/api_field_map.json). The Data Layer Manager
     * writes this from the dl_api_field_map table; the normalizer reads it to
     * heal renamed external fields without a code deploy.
     */
    public static function fieldMapPath(): string
    {
        return self::cacheDir() . '/' . self::FILE_FIELD_MAP;
    }

    /**
     * Compiled custom entries (cache/custom_entries.json). The Data Layer
     * Manager writes this from the dl_custom_entries table; ShopRepository
     * injects them into the snapshot items at read time, so manual entries
     * survive the daily snapshot regeneration.
     */
    public static function customEntriesPath(): string
    {
        return self::cacheDir() . '/' . self::FILE_CUSTOM_ENTRIES;
    }

    /** Fully-qualified external shop URL including language. */
    public static function apiUrl(): string
    {
        return self::API_URL . '?language=' . rawurlencode(self::API_LANGUAGE);
    }

    /**
     * Optional API key from the environment (never hardcoded). Returns '' when
     * unset — the public endpoint works without it.
     */
    public static function apiKey(): string
    {
        $key = getenv('BANANO_FORTNITE_API_KEY');
        return is_string($key) ? trim($key) : '';
    }

    /**
     * Resolve the current source mode.
     * Returns one of: 'auto' | 'live' | 'fallback' | 'maintenance'.
     */
    public static function sourceMode(): string
    {
        $path = self::sourceModePath();
        if (is_file($path)) {
            $cfg  = json_decode((string) @file_get_contents($path), true);
            $mode = is_array($cfg) ? strtolower(trim((string) ($cfg['mode'] ?? ''))) : '';
            if (in_array($mode, ['auto', 'live', 'fallback', 'maintenance'], true)) {
                return $mode;
            }
        }
        return 'auto';
    }

    /**
     * Persist the global source mode atomically. Accepts only the known modes;
     * any other value is coerced to 'auto'. Used by the Data Layer Manager's
     * master controls (Live / Fallback / Maintenance / Auto).
     */
    public static function writeSourceMode(string $mode): bool
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['auto', 'live', 'fallback', 'maintenance'], true)) {
            $mode = 'auto';
        }
        $path = self::sourceModePath();
        $payload = json_encode(
            ['mode' => $mode, 'updated_at' => gmdate('Y-m-d\TH:i:s\Z')],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
        if ($payload === false) {
            return false;
        }
        if (!is_dir(self::cacheDir())) {
            @mkdir(self::cacheDir(), 0755, true);
        }
        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        @chmod($path, 0644);
        return true;
    }

    /** True when the live snapshot exists and is fresh enough to serve. */
    public static function liveSnapshotIsHealthy(): bool
    {
        $path = self::liveSnapshotPath();
        if (!is_file($path) || filesize($path) < 64) {
            return false;
        }
        $age = time() - (int) @filemtime($path);
        return $age <= self::STALE_AFTER_SECONDS;
    }

    /**
     * Decide which snapshot file the DAL should read RIGHT NOW, honoring the
     * configured source mode and (in auto) the health of the live snapshot.
     * Returns null when nothing servable exists (e.g. maintenance with no file).
     */
    public static function activeSnapshotPath(): ?string
    {
        $live     = self::liveSnapshotPath();
        $fallback = self::fallbackSnapshotPath();

        switch (self::sourceMode()) {
            case 'live':
                return is_file($live) ? $live : (is_file($fallback) ? $fallback : null);

            case 'fallback':
            case 'maintenance':
                return is_file($fallback) ? $fallback : (is_file($live) ? $live : null);

            case 'auto':
            default:
                if (self::liveSnapshotIsHealthy()) {
                    return $live;
                }
                if (is_file($fallback)) {
                    return $fallback;
                }
                return is_file($live) ? $live : null;
        }
    }
}

}

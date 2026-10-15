<?php
/**
 * Shop Synchronization Engine
 *
 * Cron-driven script that fetches the latest Fortnite shop data from the
 * external API, normalizes the payload to minimize file size, and stores
 * it as a static JSON file for instant client-side consumption.
 *
 * Design goals:
 *   - Conditional requests (ETag / If-Modified-Since) to minimize bandwidth
 *   - Atomic writes (tmp + rename) to prevent corrupted reads
 *   - Process-level locking to prevent overlapping cron executions
 *   - Payload minimization: only fields consumed by the JS normalizer are kept
 *
 * Usage (CPanel Cron):
 *   * * * * * /usr/local/bin/php /home/YOUR_CPANEL_USER/public_html/plantillas/api/sync-shop.php >> /dev/null 2>&1
 */

declare(strict_types=1);

// ── Configuration ────────────────────────────────────────────────────────────

const SHOP_API_URL   = 'https://fortnite-api.com/v2/shop?language=es-419';
const CACHE_FILE     = __DIR__ . '/shop-cache.json';
const META_FILE      = __DIR__ . '/shop-cache.meta.json';
const LOCK_FILE      = __DIR__ . '/shop-sync.lock';
const LOG_FILE       = __DIR__ . '/shop-sync.log';
const LOG_MAX_BYTES  = 256 * 1024; // 256 KB — auto-rotated
const CURL_TIMEOUT   = 30;
const CURL_CONNECT   = 10;

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Append a timestamped message to the log file.
 * Auto-rotates when the log exceeds LOG_MAX_BYTES.
 */
function logMsg(string $msg): void {
    if (@filesize(LOG_FILE) > LOG_MAX_BYTES) {
        @rename(LOG_FILE, LOG_FILE . '.old');
    }
    $ts = gmdate('Y-m-d\TH:i:s\Z');
    @file_put_contents(LOG_FILE, "[$ts] $msg\n", FILE_APPEND | LOCK_EX);
}

/**
 * Acquire an exclusive process lock (non-blocking).
 * Returns the file handle on success, false on contention.
 *
 * @return resource|false
 */
function acquireLock() {
    $fp = @fopen(LOCK_FILE, 'c');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false;
    }
    // Write PID for diagnostics
    ftruncate($fp, 0);
    fwrite($fp, (string)getmypid());
    fflush($fp);
    return $fp;
}

/** Release the process lock. */
function releaseLock($fp): void {
    if (is_resource($fp)) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function loadMeta(): array {
    if (!is_file(META_FILE)) return [];
    $raw = @file_get_contents(META_FILE);
    if ($raw === false) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function saveMeta(array $meta): void {
    $tmp = META_FILE . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    rename($tmp, META_FILE);
}

/**
 * Extract a single HTTP response header value (case-insensitive).
 */
function extractHeader(string $headerBlock, string $name): string {
    $pattern = '/^' . preg_quote($name, '/') . ':\s*(.+)$/mi';
    if (preg_match($pattern, $headerBlock, $m)) {
        return trim($m[1]);
    }
    return '';
}

// ── Payload Minimization ─────────────────────────────────────────────────────
// Only keep fields consumed by js/domain/shop/shop-entry-normalizer.js.
// This typically reduces the payload from ~4 MB to ~800 KB.

function minimizeSubItem(array $item): array {
    $m = [];
    foreach (['id', 'name', 'title', 'albumArt'] as $k) {
        if (isset($item[$k])) $m[$k] = $item[$k];
    }
    if (isset($item['type']) && is_array($item['type'])) {
        $m['type'] = array_intersect_key($item['type'], array_flip(['value', 'backendValue', 'displayValue']));
    }
    if (isset($item['rarity']) && is_array($item['rarity'])) {
        $m['rarity'] = array_intersect_key($item['rarity'], array_flip(['value', 'displayValue']));
    }
    if (isset($item['series']) && is_array($item['series'])) {
        $m['series'] = array_intersect_key($item['series'], array_flip(['value', 'displayValue']));
    }
    if (isset($item['images']['icon'])) {
        $m['images'] = ['icon' => $item['images']['icon']];
    }
    return $m;
}

function minimizeEntry(array $e): ?array {
    $out = [];

    // Scalar / direct fields
    $scalars = ['offerId', 'displayName', 'finalPrice', 'sortPriority', 'layoutId', 'tileSize', 'tile_size', 'outDate', 'orden_categoria'];
    foreach ($scalars as $k) {
        if (array_key_exists($k, $e)) $out[$k] = $e[$k];
    }

    // colors (keep only the 4 used keys)
    if (isset($e['colors']) && is_array($e['colors'])) {
        $out['colors'] = array_intersect_key($e['colors'], array_flip(['color1', 'color2', 'color3', 'textBackgroundColor']));
    }

    // layout
    if (isset($e['layout']) && is_array($e['layout'])) {
        $out['layout'] = array_intersect_key($e['layout'], array_flip(['name', 'rank', 'id', 'orden_categoria', 'tileSize', 'tile_size']));
    }

    // banner
    if (isset($e['banner']) && is_array($e['banner'])) {
        $out['banner'] = array_intersect_key($e['banner'], array_flip(['backendValue', 'value']));
    }

    // offerDates (complex — keep as-is; small payload)
    if (isset($e['offerDates'])) $out['offerDates'] = $e['offerDates'];

    // newDisplayAsset (renderImages + tileSize only)
    if (isset($e['newDisplayAsset']) && is_array($e['newDisplayAsset'])) {
        $nda = [];
        if (isset($e['newDisplayAsset']['tileSize'])) {
            $nda['tileSize'] = $e['newDisplayAsset']['tileSize'];
        }
        if (isset($e['newDisplayAsset']['renderImages']) && is_array($e['newDisplayAsset']['renderImages'])) {
            $nda['renderImages'] = array_map(function ($img) {
                if (!is_array($img)) return $img;
                return array_intersect_key($img, array_flip(['image', 'productTag']));
            }, $e['newDisplayAsset']['renderImages']);
        }
        if ($nda) $out['newDisplayAsset'] = $nda;
    }

    // bundle
    if (isset($e['bundle']) && is_array($e['bundle'])) {
        $out['bundle'] = array_intersect_key($e['bundle'], array_flip(['name', 'image']));
    }

    // Sub-item arrays
    foreach (['brItems', 'instruments', 'cars', 'tracks', 'legoKits'] as $arrKey) {
        if (isset($e[$arrKey]) && is_array($e[$arrKey])) {
            $out[$arrKey] = array_map('minimizeSubItem', $e[$arrKey]);
        }
    }

    return $out;
}

function minimizePayload(array $shopData): array {
    $entries = $shopData['entries'] ?? [];
    $minimized = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) continue;
        $min = minimizeEntry($entry);
        if ($min) $minimized[] = $min;
    }
    return [
        'date'    => $shopData['date'] ?? null,
        'entries' => $minimized,
    ];
}

// ── Main Sync Logic ──────────────────────────────────────────────────────────

function sync(): void {
    $lock = acquireLock();
    if (!$lock) {
        logMsg('SKIP: Another sync process is running');
        return;
    }

    try {
        doSync();
    } finally {
        releaseLock($lock);
    }
}

function doSync(): void {
    $meta = loadMeta();

    // Build conditional request headers
    $headers = [
        'Accept: application/json',
        'User-Agent: PlantillasSync/1.0',
    ];
    if (!empty($meta['etag'])) {
        $headers[] = 'If-None-Match: ' . $meta['etag'];
    }
    if (!empty($meta['lastModified'])) {
        $headers[] = 'If-Modified-Since: ' . $meta['lastModified'];
    }

    $ch = curl_init(SHOP_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => CURL_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => CURL_CONNECT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_ENCODING       => '',  // Accept gzip/deflate
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        logMsg("ERROR: cURL failed — $err");
        return;
    }

    $httpCode   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $respHeaders = substr($response, 0, $headerSize);
    $body        = substr($response, $headerSize);

    // 304 Not Modified — API data unchanged
    if ($httpCode === 304) {
        logMsg('OK: 304 Not Modified');
        return;
    }

    if ($httpCode !== 200) {
        logMsg("ERROR: HTTP $httpCode");
        return;
    }

    // Parse API response
    $json = json_decode($body, true);
    if (!is_array($json) || ($json['status'] ?? 0) !== 200 || !isset($json['data'])) {
        logMsg('ERROR: Invalid API response structure');
        return;
    }

    $shopData = $json['data'];
    $newDate  = $shopData['date'] ?? null;

    // Minimize payload
    $minimized = minimizePayload($shopData);
    $encoded   = json_encode($minimized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        logMsg('ERROR: JSON encode failed — ' . json_last_error_msg());
        return;
    }

    // Never overwrite a working cache with an empty shop (e.g. the API is
    // degraded and returns a hollow payload with status 200).
    $entryCount = count($minimized['entries']);
    if ($entryCount === 0 && is_file(CACHE_FILE)) {
        logMsg('WARN: API returned 0 entries — keeping previous cache');
        return;
    }

    // The API mutates entries during the day while keeping the same shop date,
    // so change detection must compare content, not the date.
    $newHash = sha1($encoded);
    $oldHash = $meta['contentHash'] ?? null;
    if ($newHash === $oldHash && is_file(CACHE_FILE)) {
        $newMeta = $meta;
        $etag    = extractHeader($respHeaders, 'ETag');
        $lastMod = extractHeader($respHeaders, 'Last-Modified');
        if ($etag)    $newMeta['etag']         = $etag;
        if ($lastMod) $newMeta['lastModified'] = $lastMod;
        saveMeta($newMeta);
        logMsg("OK: Shop content unchanged ($newDate), meta refreshed");
        return;
    }

    // Atomic write: tmp file → rename
    $tmpFile = CACHE_FILE . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmpFile, $encoded) === false) {
        logMsg('ERROR: Failed to write temp file');
        @unlink($tmpFile);
        return;
    }

    if (!rename($tmpFile, CACHE_FILE)) {
        logMsg('ERROR: Atomic rename failed');
        @unlink($tmpFile);
        return;
    }

    // Persist metadata
    $byteSize   = strlen($encoded);
    $newMeta = [
        'shopDate'     => $newDate,
        'contentHash'  => $newHash,
        'updatedAt'    => gmdate('Y-m-d\TH:i:s\Z'),
        'etag'         => extractHeader($respHeaders, 'ETag') ?: ($meta['etag'] ?? ''),
        'lastModified' => extractHeader($respHeaders, 'Last-Modified') ?: ($meta['lastModified'] ?? ''),
        'byteSize'     => $byteSize,
        'entryCount'   => $entryCount,
    ];
    saveMeta($newMeta);

    logMsg("OK: Updated shop — date=$newDate, entries=$entryCount, size=$byteSize bytes");
}

// ── Execute ──────────────────────────────────────────────────────────────────
sync();

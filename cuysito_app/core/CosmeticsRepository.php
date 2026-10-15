<?php
/**
 * ============================================================================
 * BANANO STORE — COSMETICS REPOSITORY
 * ============================================================================
 * Read-side access to the local cosmetics index (cache/cosmetics_index.json),
 * a compact id-keyed map built from the PUBLIC fortnite-api.com catalog by
 * refreshIndex(). It is the single source of complete per-cosmetic data
 * (nombre, descripción, rareza, serie, set, introducción, imágenes HQ,
 * historial de tienda) independent of the daily shop payload — which often
 * ships sub-items with missing descriptions and low-quality images.
 *
 * The index is refreshed by the shop ingestor (refreshIndexIfStale) so no
 * extra cron entry is required, and can also be forced from CLI via
 * api/generar_cache_cosmeticos.php.
 * ============================================================================
 */

require_once __DIR__ . '/../config/shop_config.php';

if (!class_exists('CosmeticsRepository')) {

final class CosmeticsRepository
{
    const FILE_INDEX = 'cosmetics_index.json';

    /** Refresh at most once a day (the catalog changes with game updates). */
    const INDEX_STALE_AFTER_SECONDS = 86400;

    /** @var array<string,array>|null Memoized id-keyed index for this request. */
    private static $index = null;
    private static $indexLoaded = false;

    public static function indexPath(): string
    {
        return ShopSourceConfig::cacheDir() . '/' . self::FILE_INDEX;
    }

    /** Full URL of the combined public cosmetics catalog. */
    public static function catalogUrl(): string
    {
        return 'https://fortnite-api.com/v2/cosmetics?language=' . rawurlencode(ShopSourceConfig::API_LANGUAGE);
    }

    /** @return array<string,array> id (lowercased) => compact cosmetic record */
    public static function index(): array
    {
        if (self::$indexLoaded) {
            return self::$index ?? [];
        }
        self::$indexLoaded = true;
        $path = self::indexPath();
        if (!is_file($path)) {
            self::$index = [];
            return self::$index;
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        self::$index = (is_array($decoded) && isset($decoded['items']) && is_array($decoded['items']))
            ? $decoded['items']
            : [];
        return self::$index;
    }

    /** Look up one cosmetic by its API id (case-insensitive). */
    public static function findById(string $id): ?array
    {
        if ($id === '') {
            return null;
        }
        $index = self::index();
        $key = strtolower($id);
        return isset($index[$key]) && is_array($index[$key]) ? $index[$key] : null;
    }

    /**
     * Look up one cosmetic, falling back to a live single-item fetch against
     * the public BR cosmetics endpoint when the local index misses (new items
     * can appear in the shop before the daily index refresh).
     */
    public static function findByIdWithLiveFallback(string $id): ?array
    {
        $hit = self::findById($id);
        if ($hit !== null) {
            return $hit;
        }
        if (!function_exists('curl_init')) {
            return null;
        }
        $url = ShopSourceConfig::COSMETICS_URL . '/' . rawurlencode($id)
             . '?language=' . rawurlencode(ShopSourceConfig::API_LANGUAGE);
        $raw = self::httpGet($url, 8);
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            return null;
        }
        return self::compactRecord($decoded['data'], 'br');
    }

    /**
     * Rebuild cache/cosmetics_index.json from the public combined catalog.
     * Atomic write (tmp + rename), never destroys a good index on failure.
     *
     * @return array{ok:bool,message:string,count:int}
     */
    public static function refreshIndex(): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message' => 'cURL no disponible.', 'count' => 0];
        }
        $raw = self::httpGet(self::catalogUrl(), 60);
        if ($raw === null) {
            return ['ok' => false, 'message' => 'No se pudo descargar el catálogo de cosméticos.', 'count' => 0];
        }
        $decoded = json_decode($raw, true);
        unset($raw);
        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            return ['ok' => false, 'message' => 'Respuesta del catálogo inválida.', 'count' => 0];
        }

        $items = [];
        $groups = [
            'br'          => $decoded['data']['br'] ?? [],
            'tracks'      => $decoded['data']['tracks'] ?? [],
            'instruments' => $decoded['data']['instruments'] ?? [],
            'cars'        => $decoded['data']['cars'] ?? [],
            'lego'        => $decoded['data']['lego'] ?? [],
        ];
        foreach ($groups as $group => $list) {
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $record = self::compactRecord($entry, $group);
                if ($record !== null) {
                    $key = strtolower($record['id']);
                    // BR wins over lego duplicates (lego entries reference the br id).
                    if (!isset($items[$key]) || $group === 'br') {
                        $items[$key] = $record;
                    }
                }
            }
        }
        unset($decoded);

        if (count($items) === 0) {
            return ['ok' => false, 'message' => 'El índice de cosméticos quedó vacío; se conserva el anterior.', 'count' => 0];
        }

        $payload = [
            'schema_version' => 1,
            'source'         => 'fortnite-api.com/v2/cosmetics',
            'generated_at'   => gmdate('Y-m-d\TH:i:s\Z'),
            'item_count'     => count($items),
            'items'          => $items,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['ok' => false, 'message' => 'No se pudo serializar el índice: ' . json_last_error_msg(), 'count' => 0];
        }

        $path = self::indexPath();
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0755, true);
        }
        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return ['ok' => false, 'message' => 'No se pudo escribir el índice temporal.', 'count' => 0];
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return ['ok' => false, 'message' => 'No se pudo promover el índice temporal.', 'count' => 0];
        }
        @chmod($path, 0644);

        self::$index = $items;
        self::$indexLoaded = true;
        return ['ok' => true, 'message' => 'Índice de cosméticos regenerado.', 'count' => count($items)];
    }

    /** Refresh only when the index file is missing or older than the TTL. */
    public static function refreshIndexIfStale(): ?array
    {
        $path = self::indexPath();
        if (is_file($path)) {
            $age = time() - (int) @filemtime($path);
            if ($age <= self::INDEX_STALE_AFTER_SECONDS) {
                return null; // Fresh enough, nothing to do.
            }
        }
        return self::refreshIndex();
    }

    /**
     * Extract the compact per-item record persisted in the index. Keeps only
     * the fields the item pages actually consume, so the file stays small.
     */
    public static function compactRecord(array $entry, string $group): ?array
    {
        $id = isset($entry['id']) ? (string) $entry['id'] : '';
        if ($id === '') {
            return null;
        }

        $name = $entry['name'] ?? ($entry['title'] ?? null);

        $images = [];
        if (isset($entry['images']) && is_array($entry['images'])) {
            foreach (['icon', 'featured', 'smallIcon', 'large', 'small'] as $k) {
                if (!empty($entry['images'][$k]) && is_string($entry['images'][$k])) {
                    $images[$k] = $entry['images'][$k];
                }
            }
        }
        if (!empty($entry['albumArt']) && is_string($entry['albumArt'])) {
            $images['albumArt'] = $entry['albumArt'];
        }

        $record = [
            'id'          => $id,
            'group'       => $group,
            'name'        => is_string($name) ? $name : null,
            'description' => isset($entry['description']) && is_string($entry['description']) ? $entry['description'] : null,
            'type'        => isset($entry['type']) && is_array($entry['type']) ? [
                'value'        => $entry['type']['value'] ?? null,
                'displayValue' => $entry['type']['displayValue'] ?? null,
                'backendValue' => $entry['type']['backendValue'] ?? null,
            ] : null,
            'rarity'      => isset($entry['rarity']) && is_array($entry['rarity']) ? [
                'value'        => $entry['rarity']['value'] ?? null,
                'displayValue' => $entry['rarity']['displayValue'] ?? null,
            ] : null,
            'series'      => isset($entry['series']) && is_array($entry['series']) ? [
                'value'        => $entry['series']['value'] ?? null,
                'displayValue' => $entry['series']['displayValue'] ?? null,
                'backendValue' => $entry['series']['backendValue'] ?? null,
            ] : null,
            'set'         => isset($entry['set']['text']) ? ['text' => $entry['set']['text']] : null,
            'introduction' => isset($entry['introduction']) && is_array($entry['introduction']) ? [
                'chapter' => $entry['introduction']['chapter'] ?? null,
                'season'  => $entry['introduction']['season'] ?? null,
                'text'    => $entry['introduction']['text'] ?? null,
            ] : null,
            'images'      => $images ?: null,
            'shopHistory' => isset($entry['shopHistory']) && is_array($entry['shopHistory']) ? $entry['shopHistory'] : null,
        ];

        // Track-specific extras (jam tracks).
        if ($group === 'tracks') {
            $record['artist']     = $entry['artist'] ?? null;
            $record['difficulty'] = isset($entry['difficulty']) && is_array($entry['difficulty']) ? $entry['difficulty'] : null;
        }

        return $record;
    }

    private static function httpGet(string $url, int $timeout): ?string
    {
        $ch = curl_init();
        $headers = ['Accept: application/json'];
        $apiKey = ShopSourceConfig::apiKey();
        if ($apiKey !== '') {
            $headers[] = 'Authorization: ' . $apiKey;
        }
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => 'BananoStore/Cosmetics/1.0',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);
        if ($err !== null || $httpCode !== 200 || !is_string($response)) {
            return null;
        }
        return $response;
    }
}

}

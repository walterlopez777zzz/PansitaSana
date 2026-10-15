<?php
/**
 * ============================================================================
 * BANANO STORE — COSMETICS INDEX BUILDER (CLI / cron opcional)
 * ============================================================================
 * Regenerates cache/cosmetics_index.json from the PUBLIC fortnite-api.com
 * combined catalog. The shop ingestor already refreshes this index when it is
 * stale (see generar_cache_tienda.php), so a dedicated cron entry is only
 * needed if you want to force refreshes more often.
 *
 * Usage: php cuysito_app/api/generar_cache_cosmeticos.php [--force]
 * ============================================================================
 */

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/shop_config.php';
require_once ROOT_PATH . '/core/CosmeticsRepository.php';

$force = in_array('--force', $argv ?? [], true) || isset($_GET['force']);

$result = $force
    ? CosmeticsRepository::refreshIndex()
    : (CosmeticsRepository::refreshIndexIfStale() ?? ['ok' => true, 'message' => 'Índice fresco; no se regeneró.', 'count' => count(CosmeticsRepository::index())]);

$line = '[' . date('Y-m-d H:i:s') . '] ' . ($result['ok'] ? 'OK' : 'ERROR') . ': '
      . $result['message'] . ' (items: ' . $result['count'] . ')';

if (php_sapi_name() === 'cli') {
    fwrite($result['ok'] ? STDOUT : STDERR, $line . "\n");
    exit($result['ok'] ? 0 : 1);
}
header('Content-Type: application/json');
echo json_encode($result, JSON_UNESCAPED_UNICODE);

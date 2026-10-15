<?php
/**
 * ============================================================================
 * BANANO STORE — SHOP INGESTOR (cron)  ·  generar_cache_tienda.php
 * ============================================================================
 * The ONLY component that talks to the external shop API. Runs on a short
 * interval cron. Responsibilities:
 *   1. Fetch the PUBLIC fortnite-api.com shop (no API key required; full SSL).
 *   2. Normalize to the canonical schema via ShopNormalizer (one code path).
 *   3. Validate + write atomically to cache/banano_primordial.json.
 *   4. INTELLIGENT DAILY FALLBACK: the first run after 00:02 UTC each day
 *      freezes the current live snapshot into banano_primordial_fallback.json
 *      (single cron, no separate job).
 *
 * Security: no hardcoded keys, full SSL verification, locked to avoid
 * overlapping runs.
 * ============================================================================
 */

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/api/db_connect.php';
require_once ROOT_PATH . '/config/shop_config.php';
require_once ROOT_PATH . '/core/ShopNormalizer.php';
require_once ROOT_PATH . '/core/DataLayerLogger.php';
require_once ROOT_PATH . '/core/CosmeticsRepository.php';
require_once ROOT_PATH . '/core/ItemVideosRepository.php';
require_once ROOT_PATH . '/core/ItemRenderImagesRepository.php';

date_default_timezone_set('America/Lima'); // Consistent local timestamps in logs.

$IS_CLI = (php_sapi_name() === 'cli');
function ingestor_log(string $msg): void
{
    global $IS_CLI;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if ($IS_CLI) {
        fwrite(STDOUT, $line . "\n");
    } else {
        echo $line . "\n";
    }
}

function ingestor_fail(string $msg): void
{
    ingestor_log('ERROR: ' . $msg);
    exit(1);
}

// --- SINGLE-RUN LOCK --------------------------------------------------------
$lockPath = ShopSourceConfig::cacheDir() . '/.ingestor.lock';
if (!is_dir(ShopSourceConfig::cacheDir())) {
    @mkdir(ShopSourceConfig::cacheDir(), 0755, true);
}
$lockHandle = fopen($lockPath, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    ingestor_log('Otra ejecución del ingestor está en curso. Abortando sin error.');
    exit(0);
}

$startTime = microtime(true);

// --- 1. ACTIVE DISCOUNTS (manual + legacy automatic) ------------------------
$activeDiscounts = [];
if (isset($conn) && $conn instanceof mysqli) {
    $now = (new DateTime())->format('Y-m-d H:i:s');
    $sqlDiscounts = "
        SELECT d.*,
               (SELECT JSON_ARRAYAGG(JSON_OBJECT('tipo', ra.tipo_regla, 'valor', ra.valor_regla))
                FROM descuentos_reglas_aplicabilidad ra
                WHERE ra.descuento_id = d.id) AS reglas_aplicabilidad
        FROM descuentos_manuales d
        WHERE d.activo = 1
          AND (d.fecha_inicio IS NULL OR d.fecha_inicio <= '{$now}')
          AND (d.fecha_expiracion IS NULL OR d.fecha_expiracion >= '{$now}')";
    $resDiscounts = $conn->query($sqlDiscounts);
    $activeDiscounts = $resDiscounts ? $resDiscounts->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($activeDiscounts as &$d) {
        $d['reglas_aplicabilidad'] = json_decode($d['reglas_aplicabilidad'] ?? '[]', true) ?: [];
    }
    unset($d);

    $sqlAuto = "SELECT da.offer_id, ore.porcentaje_descuento
                FROM descuentos_automaticos da
                JOIN ofertas_reglas ore ON da.id_regla = ore.id
                WHERE ore.activo = 1";
    $resAuto = $conn->query($sqlAuto);
    if ($resAuto) {
        while ($row = $resAuto->fetch_assoc()) {
            $activeDiscounts[] = [
                'id'                  => 'legacy_' . $row['offer_id'],
                'nombre_descuento'    => 'Descuento Automático Heredado',
                'tipo_descuento'      => 'porcentaje',
                'valor'               => $row['porcentaje_descuento'],
                'reglas_aplicabilidad' => [['tipo' => 'producto_especifico', 'valor' => $row['offer_id']]],
            ];
        }
    }
} else {
    ingestor_log('AVISO: sin conexion a BD; se genera el snapshot sin descuentos.');
}

// --- 2. CONVERSION RATE -----------------------------------------------------
function getConversionRate($db_connection): string
{
    if (!($db_connection instanceof mysqli)) {
        return ShopSourceConfig::DEFAULT_CONVERSION_RATE;
    }
    $result = $db_connection->query(
        "SELECT valor FROM configuracion_tienda WHERE clave = 'precio_por_100_pavos' LIMIT 1"
    );
    if ($result && $result->num_rows > 0) {
        return (string) $result->fetch_assoc()['valor'];
    }
    return ShopSourceConfig::DEFAULT_CONVERSION_RATE;
}
$conversionRate = getConversionRate($conn ?? null);

// --- 2.5 MANUAL ITEM VIDEOS (tabla videos_objetos, sección propia del admin) --
$manualVideos = [];
try {
    $manualVideos = ItemVideosRepository::activeVideoMap($conn ?? null);
} catch (Throwable $e) {
    ingestor_log('AVISO: no se pudieron leer los videos manuales: ' . $e->getMessage());
}

// --- 3. FETCH EXTERNAL API (public, full SSL, optional key) -----------------
$ch = curl_init();
$headers = ['Accept: application/json'];
$apiKey = ShopSourceConfig::apiKey();
if ($apiKey !== '') {
    $headers[] = 'Authorization: ' . $apiKey; // Optional: higher rate limits only.
}
curl_setopt_array($ch, [
    CURLOPT_URL            => ShopSourceConfig::apiUrl(),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_USERAGENT      => 'BananoStore/Ingestor/1.0',
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_errno($ch) ? curl_error($ch) : null;
curl_close($ch);

if ($curlErr !== null) {
    DataLayerLogger::log($conn ?? null, 'critical', 'connection', 'cURL falló al contactar la API pública.', ['error' => $curlErr]);
    ingestor_fail('cURL falló al contactar la API pública: ' . $curlErr);
}
if ($httpCode !== 200) {
    DataLayerLogger::log($conn ?? null, 'error', 'http_status', 'La API pública respondió un HTTP no esperado.', ['http_code' => $httpCode]);
    ingestor_fail('La API pública respondió HTTP ' . $httpCode . '.');
}

$apiData = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE || !isset($apiData['data']['entries'])) {
    DataLayerLogger::log($conn ?? null, 'error', 'invalid_payload', 'Respuesta de la API no válida o sin data.entries.', ['json_error' => json_last_error_msg()]);
    ingestor_fail('Respuesta de la API no válida o sin la sección data.entries.');
}

// Observability: report structural anomalies (missing/renamed fields, etc.).
DataLayerLogger::logAnomalies($conn ?? null, ShopNormalizer::detectAnomalies($apiData), ['stage' => 'pre_normalize']);

// --- 4. NORMALIZE -----------------------------------------------------------
$primordial = ShopNormalizer::buildPrimordial($apiData, $conversionRate, $activeDiscounts, $manualVideos);

// --- 5. VALIDATE ------------------------------------------------------------
if (empty($primordial['entries']) || count($primordial['entries']) === 0) {
    ingestor_fail('El snapshot normalizado quedó vacío; se conserva el anterior.');
}

$jsonOut = json_encode(
    $primordial,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
if ($jsonOut === false) {
    ingestor_fail('No se pudo serializar el snapshot: ' . json_last_error_msg());
}

// --- 6. ATOMIC WRITE (tmp + rename) -----------------------------------------
$livePath = ShopSourceConfig::liveSnapshotPath();
$tmpPath  = $livePath . '.tmp.' . getmypid();
if (file_put_contents($tmpPath, $jsonOut, LOCK_EX) === false) {
    ingestor_fail('No se pudo escribir el archivo temporal del snapshot.');
}
if (!rename($tmpPath, $livePath)) {
    @unlink($tmpPath);
    ingestor_fail('No se pudo promover el snapshot temporal a definitivo.');
}
@chmod($livePath, 0644);

$bytes    = strlen($jsonOut);
$count    = count($primordial['entries']);
ingestor_log("Snapshot banano_primordial.json actualizado: {$count} items, {$bytes} bytes.");
DataLayerLogger::log($conn ?? null, 'info', 'snapshot_written', 'Snapshot live regenerado.', ['items' => $count, 'bytes' => $bytes, 'hash' => $primordial['hash'] ?? null]);

// --- 6.5 RENDER IMAGES CACHE (arte grande permanente por cosmético) ---------
try {
    ItemRenderImagesRepository::updateFromEntries(
        $primordial['entries'],
        $primordial['shop_date'] ?? ($primordial['data']['date'] ?? null)
    );
} catch (Throwable $e) {
    ingestor_log('AVISO: fallo al acumular renderImages: ' . $e->getMessage());
}

// --- 7. INTELLIGENT DAILY FALLBACK (00:02 UTC anchor) -----------------------
maybeFreezeDailyFallback($livePath);

// --- 8. COSMETICS INDEX (para páginas de objetos; refresco diario) ----------
try {
    $cosmeticsResult = CosmeticsRepository::refreshIndexIfStale();
    if ($cosmeticsResult !== null) {
        ingestor_log(($cosmeticsResult['ok'] ? 'OK' : 'AVISO') . ': ' . $cosmeticsResult['message'] . ' (items: ' . $cosmeticsResult['count'] . ')');
    }
} catch (Throwable $e) {
    ingestor_log('AVISO: fallo al refrescar el índice de cosméticos: ' . $e->getMessage());
}

$elapsed = round((microtime(true) - $startTime) * 1000);
ingestor_log("Ingestor finalizado en {$elapsed} ms.");

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
exit(0);

/**
 * Freeze the current live snapshot as the daily fallback the FIRST time the
 * ingestor runs at/after 00:02 UTC on a given UTC date. Uses a date marker
 * file so subsequent runs that day are no-ops. Single cron, no extra job.
 */
function maybeFreezeDailyFallback(string $livePath): void
{
    $nowUtc   = new DateTime('now', new DateTimeZone('UTC'));
    $todayUtc = $nowUtc->format('Y-m-d');

    // Anchor 00:02 UTC for "today" (UTC).
    $anchor = new DateTime($todayUtc, new DateTimeZone('UTC'));
    $anchor->setTime(ShopSourceConfig::FALLBACK_HOUR_UTC, ShopSourceConfig::FALLBACK_MINUTE_UTC, 0);

    if ($nowUtc < $anchor) {
        return; // Not yet past today's anchor.
    }

    $markerPath = ShopSourceConfig::fallbackDatePath();
    $lastDate   = is_file($markerPath) ? trim((string) @file_get_contents($markerPath)) : '';
    if ($lastDate === $todayUtc) {
        return; // Already frozen today.
    }

    if (!is_file($livePath)) {
        ingestor_log('AVISO: no hay snapshot live para congelar como fallback.');
        return;
    }

    $fallbackPath = ShopSourceConfig::fallbackSnapshotPath();
    $tmp = $fallbackPath . '.tmp.' . getmypid();
    if (@copy($livePath, $tmp) && @rename($tmp, $fallbackPath)) {
        @chmod($fallbackPath, 0644);
        @file_put_contents($markerPath, $todayUtc);
        ingestor_log("Fallback diario congelado (ancla 00:02 UTC, fecha {$todayUtc}).");
    } else {
        @unlink($tmp);
        ingestor_log('ERROR: no se pudo congelar el fallback diario.');
    }
}

<?php
/**
 * get_item_details.php  ·  Item detail proxy (shopHistory + best-effort price)
 * ----------------------------------------------------------------------------
 * MIGRATED off the dead fortniteapi.io provider. Now uses the PUBLIC
 * fortnite-api.com cosmetics endpoint for shop history, and resolves the
 * current price (when the item is in the active shop) from the internal
 * canonical snapshot via ShopRepository — no external key, full SSL.
 *
 * Response shape preserved for the frontend (app.js action 'm2q'):
 *   { "item": { "price": <int|null>, "shopHistory": [ ...dates ] } }
 */

ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');

require_once __DIR__ . '/../config/shop_config.php';
require_once __DIR__ . '/../core/ShopRepository.php';

$itemId = isset($_GET['id']) ? trim($_GET['id']) : (isset($_POST['id']) ? trim($_POST['id']) : '');
if (empty($itemId) || !preg_match('/^[a-zA-Z0-9_.-]+$/', $itemId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID de item inválido o no proporcionado.']);
    exit;
}

// --- Best-effort price from the internal snapshot ---------------------------
$price = null;
foreach (ShopRepository::items() as $entry) {
    foreach (($entry['brItems'] ?? []) as $br) {
        if (isset($br['id']) && strcasecmp((string) $br['id'], $itemId) === 0) {
            $price = isset($entry['finalPrice']) ? (int) $entry['finalPrice'] : null;
            break 2;
        }
    }
}

// --- shopHistory from the public fortnite-api.com cosmetics endpoint --------
$shopHistory = [];
if (!function_exists('curl_init')) {
    // No HTTP client available: degrade gracefully (price from snapshot only).
    echo json_encode(['item' => ['price' => $price, 'shopHistory' => []]]);
    exit;
}
$url = ShopSourceConfig::COSMETICS_URL . '/' . rawurlencode($itemId)
     . '?language=' . rawurlencode(ShopSourceConfig::API_LANGUAGE);

$ch = curl_init();
$headers = ['Accept: application/json'];
$apiKey = ShopSourceConfig::apiKey();
if ($apiKey !== '') {
    $headers[] = 'Authorization: ' . $apiKey;
}
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_USERAGENT      => 'BananoStore/ItemDetails/1.0',
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_errno($ch) ? curl_error($ch) : null;
curl_close($ch);

if (!$curlError && $httpCode === 200) {
    $decoded = json_decode($response, true);
    if (json_last_error() === JSON_ERROR_NONE && isset($decoded['data']['shopHistory']) && is_array($decoded['data']['shopHistory'])) {
        $shopHistory = $decoded['data']['shopHistory'];
    }
}

echo json_encode([
    'item' => [
        'price'       => $price,
        'shopHistory' => $shopHistory,
    ],
]);
exit;

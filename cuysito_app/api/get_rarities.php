<?php
/**
 * get_rarities.php  ·  Rarity enum (STATIC)
 * ----------------------------------------------------------------------------
 * MIGRATED off the dead fortniteapi.io provider. The rarity catalogue is a
 * small, slow-changing enum, so it is now served from a local static file
 * (rarities_static.json) — zero external calls, instant, and resilient.
 *
 * Output contract is unchanged: an encrypted (_sd/_sn) frame wrapping
 * { "rarities": [ { id, name, colors: { mainColor } }, ... ] }.
 */

function _outputJson($data) {
    $raw = json_encode($data);
    if (defined('MODO_DEBUG') && MODO_DEBUG) {
        echo $raw;
    } else {
        $sn = openssl_random_pseudo_bytes(16);
        $sd = openssl_encrypt($raw, 'aes-256-cbc', CART_SYNC_TOKEN, OPENSSL_RAW_DATA, $sn);
        echo json_encode(['_sd' => base64_encode($sd), '_sn' => base64_encode($sn)]);
    }
}

header('Content-Type: application/json');

$staticPath = __DIR__ . '/rarities_static.json';
$payload = ['rarities' => []];
if (is_file($staticPath)) {
    $decoded = json_decode((string) file_get_contents($staticPath), true);
    if (is_array($decoded) && isset($decoded['rarities'])) {
        $payload = $decoded;
    }
}

_outputJson($payload);

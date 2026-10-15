<?php
// public_html/api/get_spin_history.php
// Devuelve el historial de giros del usuario logueado (modal Giro Diario).
// Fechas: UTC en BD → ISO-8601 `Z` al cliente; el estado efectivo se calcula
// en el servidor contra un único instante UTC (ver logic_discounts.php).
header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
session_start();

function _outputJson($data) {
    $raw = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (defined('MODO_DEBUG') && MODO_DEBUG) {
        echo $raw;
    } else {
        $sn = openssl_random_pseudo_bytes(16);
        $sd = openssl_encrypt($raw, 'aes-256-cbc', CART_SYNC_TOKEN, OPENSSL_RAW_DATA, $sn);
        echo json_encode(['_sd' => base64_encode($sd), '_sn' => base64_encode($sn)]);
    }
}

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    _outputJson(['success' => false, 'is_logged' => false, 'message' => 'No logueado']);
    exit;
}

require_once 'db_connect.php';
require_once 'logic_discounts.php';

$user_id = (int)$_SESSION['user_id'];

try {
    $sql = "SELECT item_name, item_image_url, discount_percent, is_flash_prize,
                   spin_source, status, created_at, expires_at
            FROM daily_discounts
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 50";

    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('DB error');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $now = UtcTime::now();
    $history = [];
    while ($row = $result->fetch_assoc()) {
        $source = dd_source_meta($row['spin_source']);
        $history[] = [
            'item_name'        => $row['item_name'],
            'item_image_url'   => $row['item_image_url'],
            'discount_percent' => (int)$row['discount_percent'],
            'is_flash'         => ((int)$row['is_flash_prize'] === 1),
            'spin_source'      => $source['source'],
            'source_label'     => $source['label'],
            'state'            => dd_effective_status((string)$row['status'], $row['expires_at'], $now),
            'created_at'       => UtcTime::sqlToIso($row['created_at']),
            'expires_at'       => UtcTime::sqlToIso($row['expires_at']),
        ];
    }
    $stmt->close();

    _outputJson([
        'success'         => true,
        'is_logged'       => true,
        'server_time_utc' => UtcTime::toIso($now),
        'history'         => $history,
    ]);

} catch (Exception $e) {
    _outputJson(['success' => false, 'is_logged' => true, 'message' => 'Error interno']);
}

$conn->close();

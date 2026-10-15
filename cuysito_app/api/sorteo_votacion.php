<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/session_security.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/sorteo_votacion_lib.php';

if (!function_exists('sv_output_json')) {
    function sv_output_json(array $data): void
    {
        $raw = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (defined('MODO_DEBUG') && MODO_DEBUG) {
            echo $raw;
        } else {
            $sn = openssl_random_pseudo_bytes(16);
            $sd = openssl_encrypt($raw, 'aes-256-cbc', CART_SYNC_TOKEN, OPENSSL_RAW_DATA, $sn);
            echo json_encode(['_sd' => base64_encode($sd), '_sn' => base64_encode($sn)]);
        }
    }
}

$sub = trim((string)($_POST['sub'] ?? $_GET['sub'] ?? $_GET['action'] ?? 'estado'));
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

try {
    sv_ensure_schema($conn);
    if ($sub === 'estado') {
        sv_output_json(['status' => 'success'] + sv_public_state($conn, $userId));
        exit;
    }
    if ($sub === 'stats') {
        sv_output_json(['status' => 'success', 'stats' => sv_stats($conn)]);
        exit;
    }
    if ($sub === 'catalogo') {
        $settings = sv_settings($conn);
        $items = [];
        foreach (sv_shop_index() as $item) {
            if (!$item['giftable']) continue;
            unset($item['giftable']);
            $items[] = $item;
        }
        usort($items, static fn($a, $b) => $a['pavos'] <=> $b['pavos']);
        sv_output_json(['status' => 'success', 'items' => $items, 'max_pavos' => $settings['max_pavos']]);
        exit;
    }
    if (!in_array($sub, ['proponer', 'votar'], true)) throw new Exception('Acción no válida.');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        sv_output_json(['status' => 'error', 'message' => 'Método no permitido.']);
        exit;
    }
    if ($userId <= 0 || !isset($_SESSION['logged_in_client']) || $_SESSION['logged_in_client'] !== true) {
        http_response_code(401);
        sv_output_json(['status' => 'error', 'message' => 'Debes iniciar sesión para participar.', 'require_login' => true]);
        exit;
    }
    if (function_exists('banano_client_session_is_valid') && !banano_client_session_is_valid($conn, $userId)) {
        http_response_code(401);
        sv_output_json(['status' => 'error', 'message' => 'Debes iniciar sesión para participar.', 'require_login' => true]);
        exit;
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($sub === 'proponer') {
        $raw = $_POST['items'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) $raw = [];
        sv_output_json(['status' => 'success'] + sv_submit_proposal($conn, $userId, $raw, $ip));
        exit;
    }
    $proposalId = (int)($_POST['propuesta_id'] ?? 0);
    if ($proposalId <= 0) throw new Exception('Propuesta inválida.');
    sv_output_json(['status' => 'success'] + sv_cast_vote($conn, $userId, $proposalId, $ip));
} catch (Throwable $e) {
    error_log('[sorteo_votacion] ' . $e->getMessage());
    $message = $e->getMessage();
    $conflict = in_array($message, ['La votación no está abierta.', 'Ya enviaste una propuesta en esta fase.', 'Ya votaste en esta fase.', 'Ya se alcanzó el máximo de propuestas para este ciclo.', 'Ya existe una propuesta igual o ya enviaste una propuesta en esta fase.', 'La propuesta ya no está disponible para votar.'], true);
    $serverError = $e instanceof mysqli_sql_exception || !($e instanceof Exception);
    $status = $serverError ? 500 : ($conflict ? 409 : 400);
    http_response_code($status);
    sv_output_json(['status' => 'error', 'message' => $serverError || $message === '' ? 'No se pudo completar la operación.' : $message]);
}

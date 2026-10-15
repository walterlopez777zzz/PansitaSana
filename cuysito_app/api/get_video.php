<?php
/**
 * ============================================================================
 * BANANO STORE — JAM TRACK VIDEO LOOKUP (get_video.php)
 * ============================================================================
 * Gateway action 'get_video' (obfuscated 't9h'). Returns the published media
 * of a Jam Track from the MAIN database (pistas_musicales): a YouTube embed
 * (`youtube_id`, legacy contract) or a direct audio/video file (`media_type`
 * + `media_url`), plus the community state used by the item page UI.
 * ============================================================================
 */

ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/video_community_lib.php';

$trackName = trim((string) ($_REQUEST['track_name'] ?? ''));
if ($trackName === '' || mb_strlen($trackName) > 255) {
    echo json_encode(['status' => 'error', 'message' => 'Nombre de pista no proporcionado.']);
    exit;
}

try {
    vc_ensure_schema($conn);
    $state = vc_track_state($conn, $trackName);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión a la base de datos.']);
    exit;
}

if ($state['state'] === 'missing') {
    echo json_encode(['status' => 'error', 'message' => 'Pista no encontrada en la base de datos.']);
    exit;
}

echo json_encode([
    'status'       => 'success',
    'media_type'   => $state['media_type'],
    'media_url'    => $state['media_url'],
    'youtube_id'   => $state['youtube_id'],
    'start_time'   => $state['start_time'],
    'state'        => $state['state'],
    'is_locked'    => $state['is_locked'],
    'source'       => $state['source'],
    'contributor'  => $state['contributor'],
    'published_at' => $state['published_at'],
], JSON_UNESCAPED_UNICODE);

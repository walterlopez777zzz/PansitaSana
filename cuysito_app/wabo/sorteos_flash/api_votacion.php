<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../../api/session_security.php';
require_once __DIR__ . '/../core/db_connect.php';
require_once __DIR__ . '/../../core/AvatarCatalog.php';
require_once __DIR__ . '/../../api/sorteo_votacion_lib.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso denegado.']);
    exit;
}
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

if (!function_exists('sv_admin_json')) {
    function sv_admin_json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}

$transactionStarted = false;
try {
    sv_ensure_schema($conn);
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        $action = (string)($_GET['action'] ?? '');
        if ($action === 'sv_dashboard') {
            $open = sv_open_cycle($conn);
            $last = sv_last_cycle($conn);
            if ($open) {
                $open['opened_at'] = sv_utc_iso($open['opened_at'] ?? null);
                $open['closed_at'] = sv_utc_iso($open['closed_at'] ?? null);
            }
            if ($last) {
                $last['opened_at'] = sv_utc_iso($last['opened_at'] ?? null);
                $last['closed_at'] = sv_utc_iso($last['closed_at'] ?? null);
            }
            $selectedId = (int)($_GET['ciclo_id'] ?? ($open['id'] ?? $last['id'] ?? 0));
            $history = [];
            $res = $conn->query("SELECT c.id, c.estado, c.opened_at, c.closed_at,
                        (SELECT COUNT(*) FROM sorteo_votacion_propuestas p WHERE p.ciclo_id = c.id) AS propuestas_count,
                        (SELECT COUNT(*) FROM sorteo_votacion_votos v WHERE v.ciclo_id = c.id) AS votos_count
                 FROM sorteo_votacion_ciclos c ORDER BY c.id DESC LIMIT 10");
            while ($row = $res->fetch_assoc()) {
                $row['id'] = (int)$row['id'];
                $row['propuestas_count'] = (int)$row['propuestas_count'];
                $row['votos_count'] = (int)$row['votos_count'];
                $row['opened_at'] = sv_utc_iso($row['opened_at'] ?? null);
                $row['closed_at'] = sv_utc_iso($row['closed_at'] ?? null);
                $history[] = $row;
            }
            $proposals = $selectedId > 0 ? sv_list_proposals($conn, $selectedId, true, 0) : [];
            sv_admin_json([
                'status' => 'success',
                'settings' => sv_settings($conn),
                'open_cycle' => $open,
                'last_cycle' => $last,
                'cycles' => $history,
                'selected_cycle_id' => $selectedId,
                'propuestas' => $proposals,
                'stats' => sv_stats($conn, (string)($_GET['fresh'] ?? '') === '1'),
            ]);
        }
        if ($action === 'sv_votes') {
            $proposalId = (int)($_GET['propuesta_id'] ?? 0);
            if ($proposalId <= 0) throw new Exception('Propuesta inválida.');
            $stmt = $conn->prepare("SELECT u.id AS user_id, COALESCE(NULLIF(u.public_display_name,''), u.game_username) AS name,
                        u.is_verified, a.filename AS avatar, v.created_at, v.ip_address
                 FROM sorteo_votacion_votos v JOIN users u ON u.id = v.usuario_id
                 LEFT JOIN avatars a ON a.id = u.avatar_id
                 WHERE v.propuesta_id = ? ORDER BY v.created_at ASC");
            $stmt->bind_param('i', $proposalId);
            $stmt->execute();
            $result = $stmt->get_result();
            $voters = [];
            while ($row = $result->fetch_assoc()) {
                $row['user_id'] = (int)$row['user_id'];
                $row['is_verified'] = (bool)$row['is_verified'];
                $row['avatar'] = AvatarCatalog::filename($conn, $row['avatar'] ?? null);
                $row['created_at'] = sv_utc_iso($row['created_at'] ?? null);
                $voters[] = $row;
            }
            $stmt->close();
            sv_admin_json(['status' => 'success', 'voters' => $voters]);
        }
        throw new Exception('Acción no válida.');
    }
    if ($method !== 'POST') sv_admin_json(['status' => 'error', 'message' => 'Método no permitido.'], 405);

    $action = (string)($_POST['action'] ?? '');
    $adminId = (int)($_SESSION['wabo_user_id'] ?? 0);
    if ($action === 'sv_save_settings') {
        $conn->begin_transaction();
        $transactionStarted = true;
        $settings = sv_save_settings($conn, $_POST, $adminId);
        $conn->commit();
        $transactionStarted = false;
        sv_admin_json(['status' => 'success', 'settings' => $settings]);
    }
    if ($action === 'sv_upload_meme') {
        if (empty($_FILES['meme_image']) || (int)$_FILES['meme_image']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('No se recibió una imagen válida.');
        }
        $file = $_FILES['meme_image'];
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) throw new Exception('Formato no permitido. Usa JPG, PNG, GIF o WebP.');
        if ((int)$file['size'] > 5 * 1024 * 1024) throw new Exception('La imagen supera el máximo de 5 MB.');
        if (@getimagesize($file['tmp_name']) === false) throw new Exception('El archivo no es una imagen válida.');
        $assets = realpath(AvatarCatalog::publicRoot() . '/assets');
        if ($assets === false || !is_dir($assets)) throw new Exception('No se encontró la carpeta pública de recursos.');
        $dir = $assets . DIRECTORY_SEPARATOR . 'sorteo_memes';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) throw new Exception('No se pudo crear la carpeta de memes.');
        $name = 'meme_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (!move_uploaded_file($file['tmp_name'], $path)) throw new Exception('No se pudo guardar la imagen.');
        try {
            $conn->begin_transaction();
            $transactionStarted = true;
            $settings = sv_settings($conn);
            $settings['meme_url'] = '/assets/sorteo_memes/' . $name;
            $saved = sv_save_settings($conn, $settings, $adminId);
            $conn->commit();
            $transactionStarted = false;
        } catch (Throwable $e) {
            $conn->rollback();
            $transactionStarted = false;
            @unlink($path);
            throw $e;
        }
        sv_admin_json(['status' => 'success', 'settings' => $saved]);
    }
    if ($action === 'sv_clear_meme') {
        $conn->begin_transaction();
        $transactionStarted = true;
        $settings = sv_settings($conn);
        $settings['meme_url'] = '';
        $saved = sv_save_settings($conn, $settings, $adminId);
        $conn->commit();
        $transactionStarted = false;
        sv_admin_json(['status' => 'success', 'settings' => $saved]);
    }
    if ($action === 'sv_reject') {
        sv_admin_json(['status' => 'success'] + sv_reject_proposal($conn, (int)($_POST['propuesta_id'] ?? 0), $adminId));
    }
    if ($action === 'sv_set_winner') {
        sv_admin_json(['status' => 'success'] + sv_set_winner($conn, (int)($_POST['propuesta_id'] ?? 0)));
    }
    throw new Exception('Acción no válida.');
} catch (Throwable $e) {
    if ($transactionStarted && isset($conn)) try { $conn->rollback(); } catch (Throwable $ignored) {}
    error_log('[api_votacion] ' . $e->getMessage());
    $status = $e instanceof mysqli_sql_exception || !($e instanceof Exception) ? 500 : 400;
    sv_admin_json(['status' => 'error', 'message' => $e->getMessage() ?: 'No se pudo completar la operación.'], $status);
}

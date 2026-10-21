<?php
// api/raffle_prechoice.php
// FASE 2 (UX): Pre-elección de premio capturada al inscribirse.
// Guarda la preferencia del participante ("Si llegas a ganar, ¿qué premio
// prefieres?") en raffle_participants.pre_chosen_prize. Si el usuario gana y
// cerró la pestaña, el servidor aplica esta elección automáticamente (fallback).

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header('Content-Type: application/json');

date_default_timezone_set('America/Lima');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    http_response_code(401);
    _outputJson(['status' => 'error', 'message' => 'Debes iniciar sesión.', 'require_login' => true]);
    exit;
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/raffle_review.php';
// Reglas del espacio de decisión (rr_raffle_*_enabled) y helpers de pool.
require_once __DIR__ . '/raffle_rewards.php';
require_once __DIR__ . '/raffle_bans.php';

$userId   = (int)$_SESSION['user_id'];
$raffleId = isset($_POST['raffle_id']) ? (int)$_POST['raffle_id'] : 0;
$choice   = isset($_POST['choice']) ? strtolower(trim((string)$_POST['choice'])) : '';
$animMode = isset($_POST['animation_mode']) ? strtolower(trim((string)$_POST['animation_mode'])) : '';
$coinSide = isset($_POST['coin_choice']) ? strtolower(trim((string)$_POST['coin_choice'])) : '';
// El router (/web.php) consume 'action' para enrutar; usamos 'sub' para la
// sub-acción interna de este endpoint.
$sub      = isset($_POST['sub']) ? strtolower(trim((string)$_POST['sub'])) : '';
// Pre-selección del ítem por puesto cuando el premio es un pool. JSON
// {"1":"p1_o2", ...}; vacío/ausente = asignación automática (primera opción
// del pool si gana estando AFK).
$poolChoicesRaw = isset($_POST['pool_choices']) ? trim((string)$_POST['pool_choices']) : '';

$signals = rb_client_signals();
if ($raffleId > 0) {
    try {
        rb_ensure_schema($conn);
        rb_track_device($conn, $userId, $signals['ip'], $signals['fp'], $signals['did']);
        $activeBan = rb_find_active_ban($conn, $userId, $signals['ip'], $signals['fp'], $signals['did']);
        if ($activeBan) {
            if (($activeBan['matched_by'] ?? 'user') !== 'user') {
                rb_log_evasion($conn, $activeBan, $userId, $signals['ip'], $signals['fp'], $signals['did'], $raffleId, 'prechoice');
            }
            http_response_code(403);
            _outputJson([
                'status' => 'error',
                'code' => 'raffle_banned',
                'message' => rb_public_message($activeBan),
                'ban' => rb_ban_payload($conn, $activeBan),
            ]);
            exit;
        }
    } catch (Throwable $banError) {
        error_log('[raffle_prechoice] ban check failed: ' . $banError->getMessage());
        http_response_code(503);
        _outputJson(['status' => 'error', 'message' => 'No se pudo validar tu acceso al sorteo. Intenta nuevamente.']);
        exit;
    }
}

// ── Botón de pánico del ganador EN VIVO: "prefiero elegir manualmente" ──
// El contador global ya es el tiempo completo (p. ej. 60s); la pre-elección se
// auto-aplica en una ventana corta intermedia (`pre_apply_expires_at`, ≈15s).
// Al pulsar este botón marcamos `pre_apply_cancelled = 1`: el cierre automático
// NO le aplicará la pre-elección y conserva todo el tiempo restante (los 45s)
// para decidir a mano. La cancelación se expone a los espectadores (dramatismo).
if ($sub === 'cancel_auto') {
    if ($raffleId <= 0) {
        http_response_code(400);
        _outputJson(['status' => 'error', 'message' => 'Parámetros inválidos.']);
        exit;
    }
    try {
        rr_ensure_schema($conn);
        $rs = $conn->prepare("SELECT status FROM raffles WHERE raffle_id = ? LIMIT 1");
        $rs->bind_param("i", $raffleId);
        $rs->execute();
        $rRow = $rs->get_result()->fetch_assoc();
        $rs->close();
        if (!$rRow) throw new Exception("El sorteo no existe.");
        if ($rRow['status'] !== 'waiting_for_winner') {
            _outputJson(['status' => 'success', 'message' => 'Sin acción.', 'cancelled' => false]);
            exit;
        }
        // Solo afecta su propia fila si sigue pendiente (no resuelta).
        $ext = $conn->prepare(
            "UPDATE raffle_winners SET pre_apply_cancelled = 1
             WHERE raffle_id = ? AND user_id = ? AND choice = 'pending'"
        );
        $ext->bind_param("ii", $raffleId, $userId);
        $ext->execute();
        $affected = $ext->affected_rows;
        $ext->close();
        _outputJson([
            'status'    => 'success',
            'message'   => 'Auto-aplicación cancelada. Conservas el tiempo completo para decidir.',
            'cancelled' => $affected > 0
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(400);
        _outputJson(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

$allowedChoices = ['original', 'cashback', 'gamble'];
if ($raffleId <= 0 || !in_array($choice, $allowedChoices, true)) {
    http_response_code(400);
    _outputJson(['status' => 'error', 'message' => 'Parámetros inválidos.']);
    exit;
}

try {
    rr_ensure_schema($conn);

    // El sorteo debe existir y no estar finalizado.
    $stmt = $conn->prepare("SELECT status, allow_cashback, cashback_percent, allow_double_or_nothing, execution_mode, allow_pre_choice, allow_winner_animation_choice, gamble_animation, prize_config FROM raffles WHERE raffle_id = ? LIMIT 1");
    $stmt->bind_param("i", $raffleId);
    $stmt->execute();
    $raffle = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$raffle) throw new Exception("El sorteo no existe.");
    if ($raffle['status'] === 'completed') throw new Exception("El sorteo ya finalizó.");
    if ((int)($raffle['allow_pre_choice'] ?? 1) !== 1) {
        throw new Exception("La pre-elección de premio está desactivada en este sorteo.");
    }

    // El usuario debe ser participante (y no estar expulsado).
    $pStmt = $conn->prepare("SELECT id FROM raffle_participants WHERE raffle_id = ? AND user_id = ? AND review_status <> 'expelled' LIMIT 1");
    $pStmt->bind_param("ii", $raffleId, $userId);
    $pStmt->execute();
    $participant = $pStmt->get_result()->fetch_assoc();
    $pStmt->close();
    if (!$participant) throw new Exception("No estás inscrito en este sorteo.");

    // La opción debe estar habilitada por la configuración del sorteo.
    if ($choice === 'cashback' && !rr_raffle_cashback_enabled($raffle)) {
        throw new Exception("El cashback no está habilitado en este sorteo.");
    }
    // Config completa del Doble o Nada anticipado: el cliente DEBE mandar la
    // animación y, en modo moneda, el lado. El servidor no asume nada.
    $preAnim = null;
    $preSide = null;
    if ($choice === 'gamble') {
        if (!rr_raffle_gamble_enabled($raffle)) {
            throw new Exception("El Doble o Nada no está disponible en este sorteo.");
        }

        // Si el admin fijó la animación (allow_winner_animation_choice = 0), se
        // respeta esa; si no, el usuario elige entre las 3.
        $allowAnimChoice = ((int)($raffle['allow_winner_animation_choice'] ?? 0) === 1);
        $adminAnim = strtolower(trim((string)($raffle['gamble_animation'] ?? 'coin')));
        if (!in_array($adminAnim, ['coin', 'slot', 'roulette'], true)) $adminAnim = 'coin';

        if ($allowAnimChoice) {
            if (!in_array($animMode, ['coin', 'slot', 'roulette'], true)) {
                throw new Exception("Debes elegir la animación del Doble o Nada (Moneda, Ruleta o Slots).");
            }
            $preAnim = $animMode;
        } else {
            // La animación la fija el admin; ignoramos cualquier valor recibido.
            $preAnim = $adminAnim;
        }

        if ($preAnim === 'coin') {
            if (!in_array($coinSide, ['heads', 'tails'], true)) {
                throw new Exception("En modo Moneda debes elegir Cara o Sello.");
            }
            $preSide = $coinSide;
        } elseif ($preAnim === 'roulette') {
            if (!in_array($coinSide, ['red', 'black'], true)) {
                throw new Exception("En modo Ruleta debes elegir Rojo o Negro.");
            }
            $preSide = $coinSide;
        }
    }

    // Validación de la pre-selección de pool: solo aplica con "original" y
    // cada option_key debe existir en el pool del puesto correspondiente.
    $prePoolJson = null;
    $prePoolItems = [];
    if ($choice === 'original' && $poolChoicesRaw !== '') {
        $decoded = json_decode($poolChoicesRaw, true);
        if (!is_array($decoded)) throw new Exception("Pre-selección de pool inválida.");
        $clean = [];
        foreach ($decoded as $pos => $key) {
            $pos = (int)$pos;
            $key = trim((string)$key);
            if ($pos <= 0 || $key === '') continue;
            $posPrize = rr_prize_for_position($raffle['prize_config'] ?? '', $pos);
            $posPool = rr_position_pool($posPrize);
            if ($posPool === null) throw new Exception("El puesto $pos no tiene un pool de premios.");
            $found = null;
            foreach (($posPool['options'] ?? []) as $opt) {
                if (is_array($opt) && (string)($opt['option_key'] ?? '') === $key) { $found = $opt; break; }
            }
            if ($found === null) throw new Exception("Opción no válida para el puesto $pos.");
            $clean[(string)$pos] = $key;
            $prePoolItems[(string)$pos] = [
                'name'       => (string)($found['name'] ?? ($found['label'] ?? 'Premio')),
                'image'      => (string)($found['image_icon'] ?? ($found['image'] ?? '')),
                'option_key' => $key,
            ];
        }
        if (!empty($clean)) $prePoolJson = json_encode($clean, JSON_UNESCAPED_UNICODE);
    }

    $upd = $conn->prepare("UPDATE raffle_participants SET pre_chosen_prize = ?, pre_gamble_anim = ?, pre_gamble_side = ?, pre_pool_choices = ? WHERE raffle_id = ? AND user_id = ?");
    $upd->bind_param("ssssii", $choice, $preAnim, $preSide, $prePoolJson, $raffleId, $userId);
    if (!$upd->execute()) throw new Exception("No se pudo guardar tu preferencia.");
    $upd->close();

    $message = 'Preferencia guardada.';
    if (!empty($prePoolItems)) {
        $names = array_map(function ($it) { return $it['name']; }, array_values($prePoolItems));
        $message = 'Preferencia guardada: ' . implode(' / ', $names) . '.';
    }

    _outputJson([
        'status'           => 'success',
        'message'          => $message,
        'pre_chosen_prize' => $choice,
        'pre_gamble_anim'  => $preAnim,
        'pre_gamble_side'  => $preSide,
        'pre_pool_choices' => $prePoolJson ? json_decode($prePoolJson, true) : null,
        'pre_pool_items'   => !empty($prePoolItems) ? $prePoolItems : null,
    ]);

} catch (Exception $e) {
    http_response_code(400);
    _outputJson(['status' => 'error', 'message' => $e->getMessage()]);
}

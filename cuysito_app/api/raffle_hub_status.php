<?php
/**
 * api/raffle_hub_status.php — estado resumido del «sorteo actual» para la
 * tarjeta y el modal de Sorteo Flash del hub /juegos.
 *
 * Sólo lectura: a diferencia de raffle_status.php no registra presencia, no
 * dispara lazy-start de fases ni auto-claim de ganadores, y no necesita un
 * `id`. Resuelve el sorteo vigente con la misma regla que la sala
 * (juegos/sorteo_flash/index.php): primer sorteo en curso ordenado por
 * start_time; si no hay, el último completado.
 *
 * Respuesta (cifrada con el sync-frame habitual):
 *   {
 *     found: bool, server_time_utc: ISO-8601 Z, is_logged_in: bool,
 *     my_banned: bool,
 *     raffle: null | {
 *       id, title, status, start_time_utc, end_time_utc,
 *       participants, slots_total (0 = ilimitado), slots_left (-1 = ilimitado),
 *       entry_cost, my_review_status (null|active|under_review|expelled), my_won,
 *       is_private (bool), my_invited (bool|null: null si no hay sesión o el sorteo es público)
 *     },
 *     next_scheduled: null | { id, start_time_utc }
 *   }
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/raffle_private.php';
require_once __DIR__ . '/raffle_bans.php';

function _outputJson($data)
{
    $raw = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (defined('MODO_DEBUG') && MODO_DEBUG) {
        echo $raw;
    } else {
        $sn = openssl_random_pseudo_bytes(16);
        $sd = openssl_encrypt($raw, 'aes-256-cbc', CART_SYNC_TOKEN, OPENSSL_RAW_DATA, $sn);
        echo json_encode(['_sd' => base64_encode($sd), '_sn' => base64_encode($sn)]);
    }
}

/**
 * `raffles.start_time` / `end_time` se guardan en UTC puro (el admin convierte
 * local→UTC al crear; el motor y raffle_join escriben UTC_TIMESTAMP/NOW() con la
 * sesión MySQL en +00:00). Se publican como ISO-8601 con sufijo Z.
 */
function _sqlUtcToIso(?string $sql): ?string
{
    if ($sql === null || $sql === '' || $sql === '0000-00-00 00:00:00') {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($sql, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        return null;
    }
    return $dt->format('Y-m-d\TH:i:s\Z');
}

$myUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

$response = [
    'found' => false,
    'server_time_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'is_logged_in' => ($myUserId > 0),
    'my_banned' => false,
    'my_ban' => null,
    'raffle' => null,
    'next_scheduled' => null,
];

try {
    $conn->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');

    $raffle = null;
    $privCol = rp_private_column_sql($conn);
    $res = $conn->query(
        "SELECT raffle_id, title, status, start_time, end_time, entry_cost, max_participants, {$privCol}
           FROM raffles
          WHERE status IN ('open', 'scheduled', 'locking', 'processing', 'waiting_for_winner')
          ORDER BY start_time ASC
          LIMIT 1"
    );
    if ($res instanceof mysqli_result) {
        $raffle = $res->fetch_assoc();
        $res->free();
    }
    if (!$raffle) {
        $res = $conn->query(
            "SELECT raffle_id, title, status, start_time, end_time, entry_cost, max_participants, {$privCol}
               FROM raffles
              WHERE status = 'completed'
              ORDER BY end_time DESC
              LIMIT 1"
        );
        if ($res instanceof mysqli_result) {
            $raffle = $res->fetch_assoc();
            $res->free();
        }
    }

    if ($myUserId > 0) {
        rb_ensure_schema($conn);
        $signals = rb_client_signals();
        rb_track_device($conn, $myUserId, $signals['ip'], $signals['fp'], $signals['did']);
        $ban = rb_find_active_ban($conn, $myUserId, $signals['ip'], $signals['fp'], $signals['did']);
        $stmt = $conn->prepare('SELECT account_status FROM users WHERE id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $myUserId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $accountBanned = (($row['account_status'] ?? '') === 'banned');
            $response['my_banned'] = ($accountBanned || $ban !== null);
            $response['my_ban'] = $ban
                ? rb_ban_payload($conn, $ban)
                : ($accountBanned ? [
                    'type' => 'permanent',
                    'matched_by' => 'user',
                    'reason' => 'Cuenta suspendida',
                    'created_at' => null,
                    'expires_at' => null,
                    'remaining_seconds' => null,
                    'days_total' => null,
                    'raffles_total' => null,
                    'raffles_remaining' => null,
                    'strikes' => 0,
                    'whatsapp' => '51924989167',
                ] : null);
            if ($ban && ($ban['matched_by'] ?? 'user') !== 'user') {
                rb_log_evasion($conn, $ban, $myUserId, $signals['ip'], $signals['fp'], $signals['did'], (int)($raffle['raffle_id'] ?? 0), 'status');
            }
        }
    }

    if ($raffle) {
        $raffleId = (int) $raffle['raffle_id'];

        $participants = 0;
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total FROM raffle_participants WHERE raffle_id = ? AND review_status <> 'expelled'"
        );
        if ($stmt) {
            $stmt->bind_param('i', $raffleId);
            $stmt->execute();
            $participants = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
        }

        $myReviewStatus = null;
        $myWon = false;
        if ($myUserId > 0) {
            $stmt = $conn->prepare(
                'SELECT review_status FROM raffle_participants WHERE raffle_id = ? AND user_id = ? LIMIT 1'
            );
            if ($stmt) {
                $stmt->bind_param('ii', $raffleId, $myUserId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $myReviewStatus = $row['review_status'] ?? 'active';
                }
            }
            if ($raffle['status'] === 'completed') {
                $stmt = $conn->prepare('SELECT 1 FROM raffle_winners WHERE raffle_id = ? AND user_id = ? LIMIT 1');
                if ($stmt) {
                    $stmt->bind_param('ii', $raffleId, $myUserId);
                    $stmt->execute();
                    $myWon = ($stmt->get_result()->fetch_assoc() !== null);
                    $stmt->close();
                }
            }
        }

        $maxSlots = (int) $raffle['max_participants'];
        $response['found'] = true;
        $response['raffle'] = [
            'id' => $raffleId,
            'title' => trim((string) ($raffle['title'] ?? '')),
            'status' => (string) $raffle['status'],
            'start_time_utc' => _sqlUtcToIso($raffle['start_time'] ?? null),
            'end_time_utc' => _sqlUtcToIso($raffle['end_time'] ?? null),
            'participants' => $participants,
            'slots_total' => $maxSlots,
            'slots_left' => ($maxSlots > 0) ? max(0, $maxSlots - $participants) : -1,
            'entry_cost' => (float) $raffle['entry_cost'],
            'my_review_status' => $myReviewStatus,
            'my_won' => $myWon,
            'is_private' => rp_is_private($raffle),
            'my_invited' => (rp_is_private($raffle) && $myUserId > 0)
                ? rp_is_invited($conn, $raffleId, $myUserId)
                : null,
        ];

        // Siguiente sorteo programado distinto del actual (cuenta atrás tras un completado).
        $stmt = $conn->prepare(
            "SELECT raffle_id, start_time FROM raffles
              WHERE status = 'scheduled' AND raffle_id <> ?
              ORDER BY start_time ASC LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('i', $raffleId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $response['next_scheduled'] = [
                    'id' => (int) $row['raffle_id'],
                    'start_time_utc' => _sqlUtcToIso($row['start_time'] ?? null),
                ];
            }
        }
    }
} catch (Throwable $e) {
    error_log('[raffle_hub_status] ' . $e->getMessage());
    http_response_code(500);
    _outputJson(['status' => 'error', 'message' => 'No se pudo consultar el sorteo.']);
    exit;
}

_outputJson($response);

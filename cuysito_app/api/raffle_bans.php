<?php
// api/raffle_bans.php

function rb_ensure_schema(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) return;

    $conn->query(
        "CREATE TABLE IF NOT EXISTS raffle_bans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            ban_type ENUM('next_n_raffles','until_date','permanent') NULL DEFAULT 'permanent',
            raffles_remaining INT NULL,
            expires_at DATETIME NULL,
            reason VARCHAR(255) NOT NULL DEFAULT '',
            ip_snapshot VARCHAR(64) NOT NULL DEFAULT '',
            fingerprint_snapshot VARCHAR(64) NOT NULL DEFAULT '',
            created_by INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            lifted_at DATETIME NULL,
            lifted_by INT NULL,
            KEY idx_user (user_id),
            KEY idx_ip (ip_snapshot),
            KEY idx_fp (fingerprint_snapshot),
            KEY idx_active (lifted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    foreach ([
        'raffles_total' => "ALTER TABLE raffle_bans ADD COLUMN raffles_total INT NULL",
        'device_snapshot' => "ALTER TABLE raffle_bans ADD COLUMN device_snapshot VARCHAR(64) NOT NULL DEFAULT ''",
        'last_decrement_raffle_id' => "ALTER TABLE raffle_bans ADD COLUMN last_decrement_raffle_id INT NULL",
    ] as $column => $alter) {
        $check = $conn->query("SHOW COLUMNS FROM raffle_bans LIKE '" . $conn->real_escape_string($column) . "'");
        if ($check && $check->num_rows === 0) $conn->query($alter);
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS raffle_user_devices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            ip VARCHAR(64) NOT NULL DEFAULT '',
            fingerprint VARCHAR(64) NOT NULL DEFAULT '',
            device_id VARCHAR(64) NOT NULL DEFAULT '',
            first_seen DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            hits INT NOT NULL DEFAULT 1,
            UNIQUE KEY uq_user_device_signals (user_id, ip, fingerprint, device_id),
            KEY idx_ip (ip),
            KEY idx_fingerprint (fingerprint),
            KEY idx_device_id (device_id),
            KEY idx_user_seen (user_id, last_seen)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS raffle_ban_evasions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ban_id INT NOT NULL,
            banned_user_id INT NOT NULL,
            user_id INT NOT NULL,
            matched_by ENUM('ip','fingerprint','device') NOT NULL,
            ip VARCHAR(64) NOT NULL DEFAULT '',
            fingerprint VARCHAR(64) NOT NULL DEFAULT '',
            device_id VARCHAR(64) NOT NULL DEFAULT '',
            raffle_id INT NOT NULL DEFAULT 0,
            source VARCHAR(16) NOT NULL DEFAULT 'join',
            created_at DATETIME NOT NULL,
            KEY idx_ban (ban_id),
            KEY idx_user (user_id),
            KEY idx_created (created_at),
            KEY idx_match (matched_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query(
        "CREATE TABLE IF NOT EXISTS raffle_ban_exemptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ban_id INT NOT NULL,
            user_id INT NOT NULL,
            created_by INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_ban_user (ban_id, user_id),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $ensured = true;
}

function rb_client_signals(): array
{
    static $jsonBody = null;
    if ($jsonBody === null) {
        $raw = file_get_contents('php://input');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $jsonBody = is_array($decoded) ? $decoded : [];
    }
    $clean = static function ($value): string {
        return substr((string)preg_replace('/[^a-f0-9]/i', '', (string)$value), 0, 64);
    };
    return [
        'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        'fp' => $clean($_POST['fp'] ?? $jsonBody['fp'] ?? $_GET['fp'] ?? ''),
        'did' => $clean($_POST['did'] ?? $jsonBody['did'] ?? $_GET['did'] ?? $_COOKIE['sf_did'] ?? ''),
    ];
}

function rb_track_device(mysqli $conn, int $uid, string $ip, string $fp, string $did): void
{
    if ($uid <= 0 || ($ip === '' && $fp === '' && $did === '')) return;
    $stmt = $conn->prepare(
        "INSERT INTO raffle_user_devices (user_id, ip, fingerprint, device_id, first_seen, last_seen, hits)
         VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1)
         ON DUPLICATE KEY UPDATE last_seen = UTC_TIMESTAMP(), hits = hits + 1"
    );
    $stmt->bind_param('isss', $uid, $ip, $fp, $did);
    $stmt->execute();
    $stmt->close();
}

function rb_active_condition_sql(string $alias = 'b'): string
{
    return "($alias.lifted_at IS NULL AND (
        $alias.ban_type = 'permanent'
        OR $alias.ban_type IS NULL
        OR $alias.ban_type = ''
        OR ($alias.ban_type = 'until_date' AND ($alias.expires_at IS NULL OR $alias.expires_at > UTC_TIMESTAMP()))
        OR ($alias.ban_type = 'next_n_raffles' AND ($alias.raffles_remaining IS NULL OR $alias.raffles_remaining > 0))
    ))";
}

function rb_find_active_ban(mysqli $conn, int $userId, string $ip, string $fingerprint, string $deviceId = ''): ?array
{
    rb_ensure_schema($conn);
    $active = rb_active_condition_sql('b');
    $base = "SELECT b.*, u.public_display_name, u.game_username, u.public_code
             FROM raffle_bans b JOIN users u ON u.id = b.user_id
             WHERE %s AND $active
               AND NOT EXISTS (SELECT 1 FROM raffle_ban_exemptions ex WHERE ex.ban_id = b.id AND ex.user_id = ?)
             ORDER BY b.id DESC LIMIT 1";
    $find = static function (string $condition, string $types, array $params) use ($conn, $base, $userId): ?array {
        $stmt = $conn->prepare(sprintf($base, $condition));
        $types .= 'i';
        $params[] = $userId;
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    };

    $row = $find('b.user_id = ?', 'i', [$userId]);
    if ($row) { $row['matched_by'] = 'user'; return $row; }

    if ($userId <= 0) return null;
    $row = null;
    if ($deviceId !== '') {
        $row = $find(
            "(b.device_snapshot = ? OR EXISTS (SELECT 1 FROM raffle_user_devices d WHERE d.user_id = b.user_id AND d.device_id = ?)) AND b.user_id <> ?",
            'isi',
            [$deviceId, $deviceId, $userId]
        );
        if ($row) $row['matched_by'] = 'device';
    }
    if ($row) return $row;
    if ($fingerprint !== '') {
        $row = $find(
            "(b.fingerprint_snapshot = ? OR EXISTS (SELECT 1 FROM raffle_user_devices d WHERE d.user_id = b.user_id AND d.fingerprint = ?)) AND b.user_id <> ?",
            'isi',
            [$fingerprint, $fingerprint, $userId]
        );
        if ($row) { $row['matched_by'] = 'fingerprint'; return $row; }
    }
    if ($ip !== '' && $ip !== '0.0.0.0') {
        $row = $find(
            "(b.ip_snapshot = ? OR EXISTS (SELECT 1 FROM raffle_user_devices d WHERE d.user_id = b.user_id AND d.ip = ? AND d.last_seen >= UTC_TIMESTAMP() - INTERVAL 30 DAY)) AND b.user_id <> ?",
            'isi',
            [$ip, $ip, $userId]
        );
        if ($row) { $row['matched_by'] = 'ip'; return $row; }
    }
    return null;
}

function rb_log_evasion(mysqli $conn, array $ban, int $uid, string $ip, string $fp, string $did, int $raffleId, string $source): void
{
    $matched = (string)($ban['matched_by'] ?? '');
    if (!in_array($matched, ['ip', 'fingerprint', 'device'], true)) return;
    rb_ensure_schema($conn);
    $stmt = $conn->prepare(
        "SELECT id FROM raffle_ban_evasions
         WHERE ban_id = ? AND user_id = ? AND matched_by = ?
           AND created_at >= UTC_TIMESTAMP() - INTERVAL 30 MINUTE LIMIT 1"
    );
    $banId = (int)$ban['id'];
    $stmt->bind_param('iis', $banId, $uid, $matched);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if ($exists) return;
    $bannedUid = (int)$ban['user_id'];
    $source = in_array($source, ['join', 'status', 'prechoice'], true) ? $source : 'join';
    $ins = $conn->prepare(
        "INSERT INTO raffle_ban_evasions
         (ban_id, banned_user_id, user_id, matched_by, ip, fingerprint, device_id, raffle_id, source, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
    );
    $ins->bind_param('iiissssis', $banId, $bannedUid, $uid, $matched, $ip, $fp, $did, $raffleId, $source);
    $ins->execute();
    $ins->close();
}

function rb_ban_payload(mysqli $conn, array $ban): array
{
    $type = in_array(($ban['ban_type'] ?? ''), ['permanent', 'until_date', 'next_n_raffles'], true)
        ? (string)$ban['ban_type'] : 'permanent';
    $created = !empty($ban['created_at']) ? strtotime((string)$ban['created_at'] . ' UTC') : null;
    $expires = !empty($ban['expires_at']) ? strtotime((string)$ban['expires_at'] . ' UTC') : null;
    $remaining = $type === 'until_date' && $expires !== false && $expires !== null
        ? max(0, $expires - time()) : null;
    $daysTotal = $type === 'until_date' && $created !== false && $created !== null && $expires !== false && $expires !== null
        ? (int)ceil(max(0, $expires - $created) / 86400) : null;
    $strikes = 0;
    if (!function_exists('rr_strike_count')) require_once __DIR__ . '/raffle_review.php';
    if (function_exists('rr_ensure_schema')) rr_ensure_schema($conn);
    if (function_exists('rr_strike_count')) {
        $strikes = rr_strike_count($conn, (int)($ban['user_id'] ?? 0));
    }
    return [
        'type' => $type,
        'matched_by' => (string)($ban['matched_by'] ?? 'user'),
        'reason' => (string)($ban['reason'] ?? ''),
        'created_at' => $created ? gmdate('Y-m-d\TH:i:s\Z', $created) : null,
        'expires_at' => $expires ? gmdate('Y-m-d\TH:i:s\Z', $expires) : null,
        'remaining_seconds' => $remaining,
        'days_total' => $daysTotal,
        'raffles_total' => isset($ban['raffles_total']) ? (int)$ban['raffles_total'] : (isset($ban['raffles_remaining']) ? (int)$ban['raffles_remaining'] : null),
        'raffles_remaining' => isset($ban['raffles_remaining']) ? (int)$ban['raffles_remaining'] : null,
        'strikes' => $strikes,
        'whatsapp' => '51924989167',
    ];
}

function rb_public_message(array $ban): string
{
    $reason = trim((string)($ban['reason'] ?? ''));
    $reasonTxt = $reason !== '' ? " Motivo: $reason." : '';
    switch ($ban['ban_type'] ?? 'permanent') {
        case 'next_n_raffles':
            $duration = 'por los próximos ' . (int)($ban['raffles_remaining'] ?? 0) . ' sorteo(s)';
            break;
        case 'until_date':
            $duration = 'hasta el ' . (string)($ban['expires_at'] ?? '') . ' (UTC)';
            break;
        default:
            $duration = 'de forma permanente';
    }
    if (($ban['matched_by'] ?? '') === 'ip') return "Tu red (IP) está vinculada a un baneo de sorteos $duration.$reasonTxt Si crees que se trata de un error, contáctanos por WhatsApp.";
    if (($ban['matched_by'] ?? '') === 'fingerprint') return "Este dispositivo está vinculado a un baneo de sorteos $duration.$reasonTxt Si crees que se trata de un error, contáctanos por WhatsApp.";
    if (($ban['matched_by'] ?? '') === 'device') return "Este dispositivo está vinculado a un baneo de sorteos $duration.$reasonTxt Si crees que se trata de un error, contáctanos por WhatsApp.";
    return "Tu cuenta tiene un baneo de sorteos $duration.$reasonTxt Si crees que se trata de un error, contáctanos por WhatsApp.";
}

function rb_expel_participant(mysqli $conn, int $raffleId, int $uid, string $reason, int $adminId): array
{
    $result = ['expelled' => false, 'fee' => 0.0, 'frozen' => false, 'status' => ''];
    if ($raffleId <= 0 || $uid <= 0) return $result;
    if (!function_exists('rr_ensure_schema')) require_once __DIR__ . '/raffle_review.php';
    $statusStmt = $conn->prepare('SELECT status FROM raffles WHERE raffle_id = ? LIMIT 1');
    $statusStmt->bind_param('i', $raffleId);
    $statusStmt->execute();
    $result['status'] = (string)($statusStmt->get_result()->fetch_assoc()['status'] ?? '');
    $statusStmt->close();
    $part = $conn->prepare("SELECT entry_fee_paid, review_status FROM raffle_participants WHERE raffle_id = ? AND user_id = ? LIMIT 1");
    $part->bind_param('ii', $raffleId, $uid);
    $part->execute();
    $row = $part->get_result()->fetch_assoc();
    $part->close();
    if (!$row || ($row['review_status'] ?? '') === 'expelled') return $result;
    if (!in_array($result['status'], ['scheduled', 'open'], true)) {
        $result['frozen'] = true;
        return $result;
    }
    $fee = (float)($row['entry_fee_paid'] ?? 0);
    $upd = $conn->prepare("UPDATE raffle_participants SET review_status = 'expelled' WHERE raffle_id = ? AND user_id = ? AND review_status <> 'expelled'");
    $upd->bind_param('ii', $raffleId, $uid);
    $upd->execute();
    if ($upd->affected_rows <= 0) {
        $upd->close();
        return $result;
    }
    $upd->close();
    $result['expelled'] = true;
    $result['fee'] = $fee;
    if ($fee > 0) {
        $coins = $conn->prepare('INSERT INTO coins (user_id, balance) VALUES (?, 0.00) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)');
        $coins->bind_param('i', $uid);
        $coins->execute();
        $coins->close();
        $refund = $conn->prepare('UPDATE coins SET balance = balance + ? WHERE user_id = ?');
        $refund->bind_param('di', $fee, $uid);
        $refund->execute();
        $refund->close();
        $refId = (string)$raffleId;
        $tx = $conn->prepare("INSERT INTO coin_transactions (user_id, amount, reason, reference_type, reference_id, created_at) VALUES (?, ?, 'raffle_expel_refund', 'raffle_id', ?, UTC_TIMESTAMP())");
        if ($tx) {
            $tx->bind_param('ids', $uid, $fee, $refId);
            $tx->execute();
            $tx->close();
        }
    }
    $strikeReason = trim($reason) !== '' ? $reason : 'Expulsado por baneo';
    $strike = $conn->prepare('INSERT INTO raffle_strikes (user_id, raffle_id, reason, created_by, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())');
    if ($strike) {
        $strike->bind_param('iisi', $uid, $raffleId, $strikeReason, $adminId);
        $strike->execute();
        $strike->close();
    }
    $tables = $conn->query("SHOW TABLES LIKE 'raffle_entry_signals'");
    if ($tables && $tables->num_rows > 0) {
        $delete = $conn->prepare('DELETE FROM raffle_entry_signals WHERE raffle_id = ? AND user_id = ?');
        $delete->bind_param('ii', $raffleId, $uid);
        $delete->execute();
        $delete->close();
    }
    return $result;
}

function rb_decrement_on_resolve(mysqli $conn, int $raffleId): void
{
    rb_ensure_schema($conn);
    $start = $conn->prepare("SELECT start_time, status FROM raffles WHERE raffle_id = ? LIMIT 1");
    $start->bind_param('i', $raffleId);
    $start->execute();
    $raffle = $start->get_result()->fetch_assoc();
    $start->close();
    if (!$raffle || ($raffle['status'] ?? '') !== 'completed') return;
    $when = $raffle['start_time'] ?: gmdate('Y-m-d H:i:s');
    $stmt = $conn->prepare(
        "UPDATE raffle_bans
         SET raffles_remaining = raffles_remaining - 1, last_decrement_raffle_id = ?
         WHERE ban_type = 'next_n_raffles' AND lifted_at IS NULL AND raffles_remaining > 0
           AND (last_decrement_raffle_id IS NULL OR last_decrement_raffle_id <> ?)
           AND created_at <= ?"
    );
    $stmt->bind_param('iis', $raffleId, $raffleId, $when);
    $stmt->execute();
    $stmt->close();
}

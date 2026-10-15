<?php

function banano_remember_cookie_name(): string {
    return 'banano_remember';
}

function banano_remember_token_ttl_seconds(): int {
    return 30 * 24 * 60 * 60;
}

function banano_remember_is_secure_request(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
}

function banano_remember_set_cookie(string $selector, string $validator, int $expires_ts): void {
    $name = banano_remember_cookie_name();
    $value = $selector . ':' . $validator;
    $is_secure = banano_remember_is_secure_request();

    if (PHP_VERSION_ID >= 70300) {
        setcookie($name, $value, [
            'expires' => $expires_ts,
            'path' => '/',
            'secure' => $is_secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    } else {
        setcookie($name, $value, $expires_ts, '/; samesite=Lax', '', $is_secure, true);
    }
}

function banano_remember_clear_cookie(): void {
    $name = banano_remember_cookie_name();
    $is_secure = banano_remember_is_secure_request();

    if (PHP_VERSION_ID >= 70300) {
        setcookie($name, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $is_secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    } else {
        setcookie($name, '', time() - 3600, '/; samesite=Lax', '', $is_secure, true);
    }

    unset($_COOKIE[$name]);
}

function banano_remember_parse_cookie(?string $raw): ?array {
    if (!is_string($raw) || $raw === '') return null;

    $parts = explode(':', $raw, 2);
    if (count($parts) !== 2) return null;

    $selector = $parts[0];
    $validator = $parts[1];

    if (strlen($selector) !== 24) return null;
    if (strlen($validator) !== 64) return null;

    if (!ctype_xdigit($selector)) return null;
    if (!ctype_xdigit($validator)) return null;

    return ['selector' => $selector, 'validator' => $validator];
}

function banano_remember_user_is_admin(mysqli $conn, int $user_id): bool {
    return false;
}

function banano_remember_issue_token(mysqli $conn, int $user_id): void {
    if ($user_id <= 0) return;
    if (banano_remember_user_is_admin($conn, $user_id)) return;

    $expires_ts = time() + banano_remember_token_ttl_seconds();
    $expires_at = date('Y-m-d H:i:s', $expires_ts);

    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $token_hash = hash('sha256', $validator);

    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

    $stmt = $conn->prepare('INSERT INTO user_tokens (user_id, selector, token_hash, expires_at, created_at, user_agent, ip) VALUES (?, ?, ?, ?, NOW(), ?, ?)');
    if (!$stmt) return;

    $stmt->bind_param('isssss', $user_id, $selector, $token_hash, $expires_at, $ua, $ip);
    $stmt->execute();
    $stmt->close();

    banano_remember_set_cookie($selector, $validator, $expires_ts);
}

function banano_remember_forget_token(?mysqli $conn = null): void {
    $name = banano_remember_cookie_name();
    $cookie = isset($_COOKIE[$name]) ? (string)$_COOKIE[$name] : null;
    $parsed = banano_remember_parse_cookie($cookie);

    if ($conn && $parsed) {
        $selector = $parsed['selector'];
        $stmt = $conn->prepare('DELETE FROM user_tokens WHERE selector = ?');
        if ($stmt) {
            $stmt->bind_param('s', $selector);
            $stmt->execute();
            $stmt->close();
        }
    }

    banano_remember_clear_cookie();
}

function banano_remember_try_restore_session(mysqli $conn): void {
    if (isset($_SESSION['logged_in_client']) && $_SESSION['logged_in_client'] === true && isset($_SESSION['user_id'])) {
        return;
    }

    $name = banano_remember_cookie_name();
    if (!isset($_COOKIE[$name])) return;

    $parsed = banano_remember_parse_cookie((string)$_COOKIE[$name]);
    if (!$parsed) {
        banano_remember_clear_cookie();
        return;
    }

    $selector = $parsed['selector'];
    $validator = $parsed['validator'];
    $calc_hash = hash('sha256', $validator);

    $stmt = $conn->prepare('SELECT user_id, token_hash, expires_at FROM user_tokens WHERE selector = ? LIMIT 1');
    if (!$stmt) return;
    $stmt->bind_param('s', $selector);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        banano_remember_clear_cookie();
        return;
    }

    $expires_at = (string)($row['expires_at'] ?? '');
    $expires_ts = $expires_at !== '' ? (int)strtotime($expires_at) : 0;
    if ($expires_ts <= time()) {
        $stmtDel = $conn->prepare('DELETE FROM user_tokens WHERE selector = ?');
        if ($stmtDel) {
            $stmtDel->bind_param('s', $selector);
            $stmtDel->execute();
            $stmtDel->close();
        }
        banano_remember_clear_cookie();
        return;
    }

    $db_hash = (string)($row['token_hash'] ?? '');
    if ($db_hash === '' || !hash_equals($db_hash, $calc_hash)) {
        $stmtDel = $conn->prepare('DELETE FROM user_tokens WHERE selector = ?');
        if ($stmtDel) {
            $stmtDel->bind_param('s', $selector);
            $stmtDel->execute();
            $stmtDel->close();
        }
        banano_remember_clear_cookie();
        return;
    }

    $user_id = (int)($row['user_id'] ?? 0);
    if ($user_id <= 0 || banano_remember_user_is_admin($conn, $user_id)) {
        $stmtDel = $conn->prepare('DELETE FROM user_tokens WHERE selector = ?');
        if ($stmtDel) {
            $stmtDel->bind_param('s', $selector);
            $stmtDel->execute();
            $stmtDel->close();
        }
        banano_remember_clear_cookie();
        return;
    }

    $email = '';
    $stmtU = $conn->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
    if ($stmtU) {
        $stmtU->bind_param('i', $user_id);
        $stmtU->execute();
        $u = $stmtU->get_result()->fetch_assoc();
        $stmtU->close();
        if ($u && isset($u['email'])) $email = (string)$u['email'];
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user_id;
    $_SESSION['email'] = $email;
    $_SESSION['logged_in_client'] = true;
    $_SESSION['CREATED'] = time();

    $new_validator = bin2hex(random_bytes(32));
    $new_hash = hash('sha256', $new_validator);

    $stmtUp = $conn->prepare('UPDATE user_tokens SET token_hash = ?, last_used_at = NOW() WHERE selector = ?');
    if ($stmtUp) {
        $stmtUp->bind_param('ss', $new_hash, $selector);
        $stmtUp->execute();
        $stmtUp->close();
    }

    banano_remember_set_cookie($selector, $new_validator, $expires_ts);
}

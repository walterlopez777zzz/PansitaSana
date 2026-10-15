<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('UTC');

function tarjetas_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/../config/config.php';
    }
    return $config;
}

function tarjetas_session_start(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $config = tarjetas_config();
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);

    session_name((string) $config['session_name']);
    ini_set('session.gc_maxlifetime', (string) $config['session_lifetime']);
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => (int) $config['session_lifetime'],
        'path' => (string) $config['session_cookie_path'],
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function tarjetas_json(array $payload, int $status = 200): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tarjetas_ok(array $payload = [], int $status = 200): never
{
    tarjetas_json(array_merge(['status' => 'success'], $payload), $status);
}

function tarjetas_error(string $message, int $status = 400): never
{
    tarjetas_json(['status' => 'error', 'message' => $message], $status);
}

function tarjetas_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        tarjetas_error('El cuerpo JSON es inválido.', 400);
    }
    return $data;
}

function tarjetas_clean(mixed $value, int $maxLength, bool $multiline = false): string
{
    $text = is_scalar($value) ? trim((string) $value) : '';
    $pattern = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';
    $text = preg_replace($pattern, '', $text) ?? '';
    return function_exists('mb_substr')
        ? mb_substr($text, 0, $maxLength, 'UTF-8')
        : substr($text, 0, $maxLength);
}

function tarjetas_id(mixed $value): int
{
    return is_numeric($value) && (int) $value > 0 ? (int) $value : 0;
}

function tarjetas_color(mixed $value, string $fallback = '#6c63ff'): string
{
    $color = strtolower(tarjetas_clean($value, 7));
    return preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : $fallback;
}

function tarjetas_is_authenticated(): bool
{
    return ($_SESSION['tarjetas_authed'] ?? false) === true;
}

function tarjetas_csrf(): string
{
    if (empty($_SESSION['tarjetas_csrf'])) {
        $_SESSION['tarjetas_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['tarjetas_csrf'];
}

function tarjetas_verify_csrf(): void
{
    $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $real = (string) ($_SESSION['tarjetas_csrf'] ?? '');
    if ($real === '' || $sent === '' || !hash_equals($real, $sent)) {
        tarjetas_error('La sesión venció. Vuelve a intentarlo.', 419);
    }
}

function tarjetas_encrypt(string $plain): ?string
{
    if ($plain === '') {
        return null;
    }
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL no está disponible.');
    }
    $key = hex2bin((string) tarjetas_config()['encryption_key']);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('No se pudo proteger el dato sensible.');
    }
    return base64_encode($iv . $tag . $cipher);
}

function tarjetas_decrypt(?string $encoded): string
{
    if ($encoded === null || $encoded === '') {
        return '';
    }
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 29) {
        return '';
    }
    $key = hex2bin((string) tarjetas_config()['encryption_key']);
    $plain = openssl_decrypt(
        substr($raw, 28),
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        substr($raw, 0, 12),
        substr($raw, 12, 16)
    );
    return $plain === false ? '' : $plain;
}

function tarjetas_datetime_or_null(mixed $value): ?string
{
    $text = tarjetas_clean($value, 32);
    if ($text === '') {
        return null;
    }
    $timestamp = strtotime($text);
    return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
}

function tarjetas_prefix(): string
{
    return (string) tarjetas_config()['table_prefix'];
}

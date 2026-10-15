<?php
/**
 * Environment detection + per-deployment settings.
 *
 * The same code base is deployed to production (banano.pe) and staging
 * (cuysito.com). Anything that must differ between the two lives here, and
 * secrets/overrides live in the git-ignored `env.php` next to this file
 * (see `env.example.php`).
 *
 * Resolution order for the environment name:
 *   1. `app_env` in env.php
 *   2. Host header: *.banano.pe → production, anything else → staging
 */

declare(strict_types=1);

const BANANO_ENV_PRODUCTION = 'production';
const BANANO_ENV_STAGING    = 'staging';

const BANANO_PRODUCTION_HOSTS = ['banano.pe', 'www.banano.pe'];
const BANANO_STAGING_HOSTS    = ['cuysito.com', 'www.cuysito.com'];

/** Internal tools that must never be reachable from production. */
const BANANO_INTERNAL_TOOL_PREFIXES = ['/plantillas', '/gestion-tarjetas', '/tarjetas', '/tareas'];

/** Tools without their own server-side login; the router requires a Cloudflare Access JWT. */
const BANANO_ACCESS_GATED_PREFIXES = ['/plantillas', '/gestion-tarjetas'];

/** https://www.cloudflare.com/ips/ */
const BANANO_CLOUDFLARE_CIDRS = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
    '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
    '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
    '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
];

/**
 * @return array<string, mixed>
 */
function banano_env_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $defaults = [
        'app_env'                  => null,
        'app_secret'               => '',
        'access_team_domain'       => '',
        'access_aud'               => [],
        'origin_lock'              => false,
        'origin_token'             => '',
        'origin_token_header'      => 'X-Origin-Token',
        'maintenance_mode'         => null,
        'maintenance_bypass_path'  => '',
        'maintenance_until'        => null,
        'router_debug_log'         => null,
        'canonical_host'           => '',
    ];

    $file = __DIR__ . '/env.php';
    $overrides = is_file($file) ? require $file : [];
    if (!is_array($overrides)) {
        $overrides = [];
    }

    $config = array_merge($defaults, $overrides);
    return $config;
}

function banano_request_host(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    return preg_match('/^[a-z0-9.-]+$/', $host) ? $host : '';
}

function banano_env(): string
{
    static $env = null;
    if ($env !== null) {
        return $env;
    }

    $configured = banano_env_config()['app_env'];
    if ($configured === BANANO_ENV_PRODUCTION || $configured === BANANO_ENV_STAGING) {
        return $env = $configured;
    }

    $host = banano_request_host();
    $isProd = $host !== '' && (in_array($host, BANANO_PRODUCTION_HOSTS, true) || substr($host, -10) === '.banano.pe');
    return $env = $isProd ? BANANO_ENV_PRODUCTION : BANANO_ENV_STAGING;
}

function banano_is_production(): bool
{
    return banano_env() === BANANO_ENV_PRODUCTION;
}

/**
 * Absolute origin (scheme + host, no trailing slash) used for canonical,
 * og:url and JSON-LD so each deployment only ever advertises itself.
 */
function banano_base_url(): string
{
    $configured = strtolower(trim((string) banano_env_config()['canonical_host']));
    $host = $configured !== '' ? $configured : banano_request_host();
    if ($host === '') {
        $host = banano_is_production() ? 'banano.pe' : 'cuysito.com';
    }
    return 'https://' . $host;
}

/**
 * Unix timestamp at which a scheduled maintenance window ends, taken from
 * env.php `maintenance_until` (ISO 8601 with explicit offset, e.g.
 * '2026-09-14T15:05:00+00:00'). Null when unset or unparseable.
 */
function banano_maintenance_until(): ?int
{
    $raw = trim((string) banano_env_config()['maintenance_until']);
    if ($raw === '') {
        return null;
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        error_log('[ENV] maintenance_until is not a valid date: ' . $raw);
        return null;
    }
    return $ts;
}

/**
 * Whether the storefront must show the maintenance page right now.
 *
 * env.php `maintenance_mode` overrides `$routerDefault`; when a
 * `maintenance_until` instant is configured and has already passed, the
 * window is over and this returns false. The comparison runs on every
 * request against the server clock (Unix time, timezone independent), so
 * reopening needs no cron job or manual edit.
 */
function banano_maintenance_active(bool $routerDefault = false): bool
{
    $config = banano_env_config();
    $active = is_bool($config['maintenance_mode']) ? $config['maintenance_mode'] : $routerDefault;
    if (!$active) {
        return false;
    }
    $until = banano_maintenance_until();
    return $until === null || time() < $until;
}

function banano_is_internal_tool_path(string $path): bool
{
    foreach (BANANO_INTERNAL_TOOL_PREFIXES as $prefix) {
        if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
            return true;
        }
    }
    return false;
}

function banano_is_access_gated_path(string $path): bool
{
    foreach (BANANO_ACCESS_GATED_PREFIXES as $prefix) {
        if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
            return true;
        }
    }
    return false;
}

function banano_ip_in_cidr(string $ip, string $cidr): bool
{
    [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
    $ipBin = @inet_pton($ip);
    $subnetBin = @inet_pton((string) $subnet);
    if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
        return false;
    }
    $bits = (int) $bits;
    $fullBytes = intdiv($bits, 8);
    $remainder = $bits % 8;
    if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
        return false;
    }
    if ($remainder === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $remainder)) & 0xFF;
    return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
}

function banano_is_cloudflare_ip(string $ip): bool
{
    foreach (BANANO_CLOUDFLARE_CIDRS as $cidr) {
        if (banano_ip_in_cidr($ip, $cidr)) {
            return true;
        }
    }
    return false;
}

/**
 * When the TCP peer is a Cloudflare edge, replace REMOTE_ADDR with the
 * visitor IP Cloudflare reports, so every consumer of $_SERVER['REMOTE_ADDR']
 * (rate limits, coupons, raffles, metrics) sees the real client. The header
 * is ignored when the request did not come through Cloudflare, so it cannot
 * be spoofed by hitting the origin directly.
 */
function banano_apply_cloudflare_real_ip(): void
{
    $peer = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $cfIp = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($peer === '' || $cfIp === '' || filter_var($cfIp, FILTER_VALIDATE_IP) === false) {
        return;
    }
    if (!banano_is_cloudflare_ip($peer)) {
        return;
    }
    $_SERVER['BANANO_EDGE_ADDR'] = $peer;
    $_SERVER['REMOTE_ADDR'] = $cfIp;
    $_SERVER['BANANO_VIA_CLOUDFLARE'] = '1';
}

function banano_via_cloudflare(): bool
{
    return ($_SERVER['BANANO_VIA_CLOUDFLARE'] ?? '') === '1'
        || banano_is_cloudflare_ip((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
}

/**
 * Origin lock: only accept requests that carry the shared token Cloudflare
 * injects via a Request Header Transform Rule. Enabled per deployment with
 * `origin_lock => true` + `origin_token` in env.php. Blocks direct hits to
 * the hosting IP (which would otherwise skip Cloudflare Access).
 */
function banano_origin_lock_passes(): bool
{
    $config = banano_env_config();
    if (PHP_SAPI === 'cli' || empty($config['origin_lock'])) {
        return true;
    }
    $token = (string) $config['origin_token'];
    if ($token === '') {
        return true;
    }
    $headerName = 'HTTP_' . strtoupper(str_replace('-', '_', (string) $config['origin_token_header']));
    $sent = (string) ($_SERVER[$headerName] ?? '');
    return $sent !== '' && hash_equals($token, $sent);
}

function banano_hmac(string $data): string
{
    return hash_hmac('sha256', $data, (string) banano_env_config()['app_secret']);
}

/**
 * Signed cookie value "expires.signature" for `$purpose`; empty when no
 * app_secret is configured (signing without a secret would be forgeable).
 */
function banano_signed_token(string $purpose, int $expires): string
{
    if ((string) banano_env_config()['app_secret'] === '') {
        return '';
    }
    return $expires . '.' . banano_hmac($purpose . '|' . $expires);
}

function banano_verify_signed_token(string $purpose, string $token): bool
{
    if ((string) banano_env_config()['app_secret'] === '' || $token === '') {
        return false;
    }
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2 || !ctype_digit($parts[0])) {
        return false;
    }
    $expires = (int) $parts[0];
    if ($expires < time()) {
        return false;
    }
    return hash_equals(banano_hmac($purpose . '|' . $expires), $parts[1]);
}

function banano_request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

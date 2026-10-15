<?php
/**
 * Per-deployment overrides. Copy to `env.php` (git-ignored) on each server.
 * Every key is optional; see config/app_env.php for the defaults.
 */

return [
    // 'production' | 'staging'. Leave null to detect from the Host header
    // (*.banano.pe → production, everything else → staging).
    'app_env' => null,

    // Random 32+ byte string (e.g. `openssl rand -hex 32`). Signs the
    // maintenance bypass cookie (disabled when empty).
    'app_secret' => '',

    // /plantillas and /gestion-tarjetas require a Cloudflare Access JWT
    // (router-level gate). Who logs in and how (One-Time PIN, etc.) is the
    // Access Application's policy in Zero Trust; here only the issuer and
    // audience(s) the origin trusts. Either empty = those tools return 404.
    'access_team_domain' => '',        // e.g. 'miequipo.cloudflareaccess.com'
    'access_aud'         => [],        // Application Audience (AUD) tag(s)

    // Origin lock: reject requests that do not carry the token Cloudflare adds
    // with a "Modify Request Header" transform rule. Enable only on the
    // deployment that sits behind Cloudflare.
    'origin_lock'         => false,
    'origin_token'        => '',
    'origin_token_header' => 'X-Origin-Token',

    // Override the $MAINTENANCE_MODE flag in router.php (null = keep flag).
    'maintenance_mode' => null,
    // Secret path that grants a signed 24 h maintenance bypass cookie,
    // e.g. '/mantenimiento-3f9a1c'. Empty = no bypass URL.
    'maintenance_bypass_path' => '',
    // Scheduled reopening: ISO 8601 date WITH offset (e.g. '2026-09-14T15:05:00+00:00'
    // = 10:05 America/Lima). Once that instant passes the router serves the
    // store on every request — no cron needed. null = manual toggle only.
    'maintenance_until' => null,

    // Write router_debug.log (null = only on staging).
    'router_debug_log' => null,

    // Force the host used in canonical / og:url / JSON-LD (e.g. 'banano.pe').
    // Empty = current request host.
    'canonical_host' => '',
];

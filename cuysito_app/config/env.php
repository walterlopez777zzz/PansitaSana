<?php
/**
 * Deployment config shared by both hostings (banano.pe and cuysito.com).
 * Upload as-is; the origin lock only arms itself on the staging host, which is
 * the one behind the Cloudflare "X-Origin-Token" transform rule.
 */

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$host = preg_replace('/:\d+$/', '', $host) ?? $host;
$isStaging = $host === 'cuysito.com' || substr($host, -12) === '.cuysito.com';

return [
    'app_env' => null,

    'app_secret' => '10a9c7ff43f0d170bd2e2e9122037cd8b2b8217f6c18d4682048788f7aae718c',

    // Zero Trust → Access → Applications → (app de /plantillas) → Overview:
    // team domain y "Application Audience (AUD) Tag".
    'access_team_domain' => 'curly-cell-d25d.cloudflareaccess.com',
    'access_aud'         => ['a84dc07496871b4a38be027fdd70fe5d5bf22767313a31c32cd8e3fcdd76010c'],

    'origin_lock'         => $isStaging,
    'origin_token'        => '59f95e7b9f03bb13c66a318971cd53ef6515d9684a5d754157a79f824cd3aa38',
    'origin_token_header' => 'X-Origin-Token',

    'maintenance_mode'        => null,
    'maintenance_bypass_path' => '',

    'router_debug_log' => null,
    'canonical_host'   => '',
];

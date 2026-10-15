<?php
/**
 * Router-level Cloudflare Access gate for internal tools that have no
 * server-side login of their own (/plantillas, /gestion-tarjetas). Runs before
 * any file (HTML, JS, JSON, API) under those prefixes is resolved.
 *
 * Who may enter, and how (One-Time PIN por correo, etc.), se decide en el
 * dashboard de Cloudflare Zero Trust: una Access Application que cubra la
 * ruta. Cloudflare autentica al visitante en el edge y reenvía al origen un
 * JWT (`Cf-Access-Jwt-Assertion` / cookie `CF_Authorization`). Aquí sólo se
 * verifica ese JWT — firma RS256 contra las claves públicas del team, `iss`,
 * `aud` y vigencia — para que la ruta no sea alcanzable saltándose Access.
 *
 * Configuración (env.php):
 *   access_team_domain  → 'equipo.cloudflareaccess.com'
 *   access_aud          → Application Audience (AUD) tag, o lista de tags
 * Ambos vacíos = las rutas responden 404 (fail-closed).
 *
 * Las claves públicas se cachean en cache/cf_access_certs.json y se refrescan
 * por TTL o al ver un `kid` desconocido (con throttle).
 */

declare(strict_types=1);

require_once __DIR__ . '/app_env.php';

const BANANO_ACCESS_HEADER          = 'HTTP_CF_ACCESS_JWT_ASSERTION';
const BANANO_ACCESS_COOKIE          = 'CF_Authorization';
const BANANO_ACCESS_CERTS_TTL       = 6 * 3600;
const BANANO_ACCESS_REFETCH_MIN_GAP = 60;
const BANANO_ACCESS_CLOCK_LEEWAY    = 60;

function banano_access_certs_file(): string
{
    return dirname(__DIR__) . '/cache/cf_access_certs.json';
}

/** @return string[] */
function banano_access_configured_auds(): array
{
    $raw = banano_env_config()['access_aud'];
    $list = is_array($raw) ? $raw : [$raw];
    $auds = [];
    foreach ($list as $aud) {
        $aud = trim((string) $aud);
        if ($aud !== '') {
            $auds[] = $aud;
        }
    }
    return $auds;
}

function banano_access_team_domain(): string
{
    $domain = strtolower(trim((string) banano_env_config()['access_team_domain']));
    $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
    $domain = rtrim($domain, '/');
    return preg_match('/^[a-z0-9.-]+$/', $domain) ? $domain : '';
}

function banano_access_token(): string
{
    $header = trim((string) ($_SERVER[BANANO_ACCESS_HEADER] ?? ''));
    if ($header !== '') {
        return $header;
    }
    return trim((string) ($_COOKIE[BANANO_ACCESS_COOKIE] ?? ''));
}

function banano_base64url_decode(string $data): ?string
{
    $data = strtr($data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad === 1) {
        return null;
    }
    if ($pad > 0) {
        $data .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode($data, true);
    return $decoded === false ? null : $decoded;
}

// ---- JWKS cache -----------------------------------------------------------

/**
 * @return array{fetched_at:int, keys:array<int, array<string, mixed>>}|null
 */
function banano_access_load_certs_cache(): ?array
{
    $file = banano_access_certs_file();
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data) || !isset($data['keys']) || !is_array($data['keys'])) {
        return null;
    }
    return ['fetched_at' => (int) ($data['fetched_at'] ?? 0), 'keys' => array_values($data['keys'])];
}

/**
 * @param array<int, array<string, mixed>> $keys
 */
function banano_access_store_certs_cache(array $keys): void
{
    $file = banano_access_certs_file();
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $tmp = $file . '.' . getmypid() . '.tmp';
    $payload = json_encode(['fetched_at' => time(), 'keys' => $keys]);
    if ($payload !== false && @file_put_contents($tmp, $payload, LOCK_EX) !== false) {
        @rename($tmp, $file);
    }
}

/**
 * GET https://<team>/cdn-cgi/access/certs → lista de JWK, o null si falla.
 *
 * @return array<int, array<string, mixed>>|null
 */
function banano_access_fetch_keys(string $teamDomain): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init('https://' . $teamDomain . '/cdn-cgi/access/certs');
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($body) || $status !== 200) {
        return null;
    }
    $json = json_decode($body, true);
    if (!is_array($json) || !isset($json['keys']) || !is_array($json['keys'])) {
        return null;
    }
    return array_values(array_filter($json['keys'], 'is_array'));
}

/**
 * Clave pública (JWK) para `$kid`: primero desde caché; si está vencida o no
 * contiene el kid, se refresca desde Cloudflare (máx. una vez por
 * BANANO_ACCESS_REFETCH_MIN_GAP para que un kid inventado no dispare fetches).
 *
 * @return array<string, mixed>|null
 */
function banano_access_find_key(string $teamDomain, string $kid): ?array
{
    $pick = static function (?array $cache) use ($kid): ?array {
        foreach ($cache['keys'] ?? [] as $key) {
            if (($key['kid'] ?? null) === $kid) {
                return $key;
            }
        }
        return null;
    };

    $cache = banano_access_load_certs_cache();
    $now = time();
    $fresh = $cache !== null && ($now - $cache['fetched_at']) < BANANO_ACCESS_CERTS_TTL;
    $found = $pick($cache);
    if ($found !== null && $fresh) {
        return $found;
    }

    $recentlyFetched = $cache !== null && ($now - $cache['fetched_at']) < BANANO_ACCESS_REFETCH_MIN_GAP;
    if (!$recentlyFetched) {
        $keys = banano_access_fetch_keys($teamDomain);
        if ($keys !== null) {
            banano_access_store_certs_cache($keys);
            return $pick(['keys' => $keys]);
        }
    }
    // Sin red o throttled: una clave cacheada sigue siendo válida aunque el TTL haya vencido.
    return $found;
}

// ---- RS256 ----------------------------------------------------------------

function banano_der_length(int $length): string
{
    if ($length < 0x80) {
        return chr($length);
    }
    $bytes = ltrim(pack('N', $length), "\0");
    return chr(0x80 | strlen($bytes)) . $bytes;
}

function banano_der_tlv(int $tag, string $value): string
{
    return chr($tag) . banano_der_length(strlen($value)) . $value;
}

function banano_der_unsigned_integer(string $bytes): string
{
    $bytes = ltrim($bytes, "\0");
    if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
        $bytes = "\0" . $bytes;
    }
    return banano_der_tlv(0x02, $bytes);
}

/** JWK RSA (n, e) → PEM SubjectPublicKeyInfo, o null si el JWK no sirve. */
function banano_access_jwk_to_pem(array $jwk): ?string
{
    if (($jwk['kty'] ?? '') !== 'RSA' || !isset($jwk['n'], $jwk['e'])) {
        return null;
    }
    $n = banano_base64url_decode((string) $jwk['n']);
    $e = banano_base64url_decode((string) $jwk['e']);
    if ($n === null || $e === null || $n === '' || $e === '') {
        return null;
    }
    $rsaPublicKey = banano_der_tlv(0x30, banano_der_unsigned_integer($n) . banano_der_unsigned_integer($e));
    $algorithm = banano_der_tlv(0x30, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00"); // rsaEncryption, NULL
    $spki = banano_der_tlv(0x30, $algorithm . banano_der_tlv(0x03, "\0" . $rsaPublicKey));
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/**
 * Verifica un JWT de Cloudflare Access: RS256 con clave del team, iss, aud,
 * exp/nbf. Devuelve los claims o null si cualquier comprobación falla.
 *
 * @param string[] $auds
 * @return array<string, mixed>|null
 */
function banano_access_verify_jwt(string $token, string $teamDomain, array $auds): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$h, $p, $s] = $parts;
    $headerJson = banano_base64url_decode($h);
    $payloadJson = banano_base64url_decode($p);
    $signature = banano_base64url_decode($s);
    if ($headerJson === null || $payloadJson === null || $signature === null || $signature === '') {
        return null;
    }
    $header = json_decode($headerJson, true);
    $claims = json_decode($payloadJson, true);
    if (!is_array($header) || !is_array($claims)) {
        return null;
    }
    if (($header['alg'] ?? '') !== 'RS256' || !is_string($header['kid'] ?? null) || $header['kid'] === '') {
        return null;
    }

    $now = time();
    if (($claims['iss'] ?? null) !== 'https://' . $teamDomain) {
        return null;
    }
    $exp = $claims['exp'] ?? null;
    if (!is_int($exp) || $exp + BANANO_ACCESS_CLOCK_LEEWAY <= $now) {
        return null;
    }
    $nbf = $claims['nbf'] ?? null;
    if ($nbf !== null && (!is_int($nbf) || $nbf - BANANO_ACCESS_CLOCK_LEEWAY > $now)) {
        return null;
    }
    $tokenAud = $claims['aud'] ?? null;
    $tokenAuds = is_array($tokenAud) ? $tokenAud : [$tokenAud];
    if (count(array_intersect(array_map('strval', array_filter($tokenAuds, 'is_string')), $auds)) === 0) {
        return null;
    }

    $jwk = banano_access_find_key($teamDomain, $header['kid']);
    $pem = $jwk === null ? null : banano_access_jwk_to_pem($jwk);
    if ($pem === null) {
        return null;
    }
    $publicKey = openssl_pkey_get_public($pem);
    if ($publicKey === false) {
        return null;
    }
    $ok = openssl_verify($h . '.' . $p, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    return $ok ? $claims : null;
}

// ---- Gate -----------------------------------------------------------------

function banano_access_render_denied(int $status, string $title, string $detail): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeDetail = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>{$safeTitle}</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#111113;color:#eee;font-family:system-ui,sans-serif}
main{background:#1b1b1f;padding:2rem;border-radius:1rem;width:min(90vw,360px);text-align:center}
h1{font-size:1.1rem;margin:0 0 .75rem}p{margin:0;color:#aaa;font-size:.9rem}
</style></head><body><main><h1>{$safeTitle}</h1><p>{$safeDetail}</p></main></body></html>
HTML;
    exit;
}

/**
 * Call for every request whose path is Access-gated. Returns only when the
 * request carries a valid Cloudflare Access JWT; otherwise responds and exits.
 */
function banano_access_gate(): void
{
    $teamDomain = banano_access_team_domain();
    $auds = banano_access_configured_auds();

    if ($teamDomain === '' || $auds === []) {
        banano_access_render_denied(404, '404 - Not Found', '');
    }

    $token = banano_access_token();
    if ($token !== '' && banano_access_verify_jwt($token, $teamDomain, $auds) !== null) {
        return;
    }

    // Access debería haber autenticado en el edge antes de llegar aquí: o la
    // ruta no está cubierta por una Access Application, o se saltó Cloudflare.
    banano_access_render_denied(
        403,
        'Acceso restringido',
        'Esta herramienta requiere iniciar sesión con Cloudflare Access. Recarga la página; si el problema persiste, revisa que la ruta esté cubierta por la Access Application.'
    );
}

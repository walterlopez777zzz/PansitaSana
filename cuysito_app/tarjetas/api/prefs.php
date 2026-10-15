<?php
/**
 * ============================================================
 * Tarjetas — API de Preferencias (presets de layout)
 * ============================================================
 * Persistencia en servidor de:
 *   - el preset activo (se recuerda entre visitas y dispositivos)
 *   - los presets personalizados creados por el usuario
 *
 * Endpoints (?action=...):
 *   GET  get → { active_preset, custom_presets }
 *   POST set → guarda { active_preset?, custom_presets? }
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

$pdo    = TarjetasDB::connect();
$prefix = tarjetas_prefix();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

/** Lee una preferencia (JSON decodificado) o el default. */
function tarjetas_pref_get(PDO $pdo, string $prefix, string $key, mixed $default = null): mixed
{
    $stmt = $pdo->prepare("SELECT pref_value FROM `{$prefix}prefs` WHERE pref_key = ?");
    $stmt->execute([$key]);
    $raw = $stmt->fetchColumn();
    if ($raw === false) {
        return $default;
    }
    $decoded = json_decode((string) $raw, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
}

/** Guarda una preferencia (se serializa como JSON). */
function tarjetas_pref_set(PDO $pdo, string $prefix, string $key, mixed $value): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO `{$prefix}prefs` (pref_key, pref_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value)"
    );
    $stmt->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
}

try {
    switch ($action) {

        case 'get':
            if ($method !== 'GET') {
                tarjetas_error("Usa GET para 'get'.", 405);
            }

            tarjetas_ok([
                'active_preset'  => tarjetas_pref_get($pdo, $prefix, 'active_preset'),
                'custom_presets' => tarjetas_pref_get($pdo, $prefix, 'custom_presets', []),
            ]);

        case 'set':
            if ($method !== 'POST') {
                tarjetas_error("Usa POST para 'set'.", 405);
            }

            $body = tarjetas_read_json_body();

            if (array_key_exists('active_preset', $body)) {
                $active = is_string($body['active_preset'])
                    ? tarjetas_clean_text($body['active_preset'], 60)
                    : null;
                tarjetas_pref_set($pdo, $prefix, 'active_preset', $active);
            }

            if (array_key_exists('custom_presets', $body)) {
                $custom = is_array($body['custom_presets']) ? $body['custom_presets'] : [];
                // Límite defensivo: hasta 24 presets personalizados.
                $custom = array_slice(array_values($custom), 0, 24);
                tarjetas_pref_set($pdo, $prefix, 'custom_presets', $custom);
            }

            tarjetas_ok(['message' => 'Preferencias guardadas.']);

        default:
            tarjetas_error('Acción no reconocida.', 404);
    }
} catch (PDOException $e) {
    error_log('[tarjetas/prefs] ' . $e->getMessage());
    tarjetas_error('Error de base de datos.', 500);
}

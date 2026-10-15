<?php
/**
 * ============================================================
 * Tarjetas — API de Perfiles (cuentas / billeteras fijas)
 * ============================================================
 * Un perfil representa la cuenta bancaria o billetera virtual
 * permanente de donde se emiten las tarjetas rotativas.
 *
 * Endpoints (?action=...):
 *   GET  list   → perfiles con conteo de tarjetas
 *   POST create → nuevo perfil
 *   POST update → editar perfil
 *   POST delete → eliminar perfil (y sus tarjetas)
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

$pdo    = TarjetasDB::connect();
$prefix = tarjetas_prefix();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$body   = $method === 'POST' ? tarjetas_read_json_body() : [];

/** Devuelve un perfil por id (con nombre de emisor) o null. */
function tarjetas_fetch_perfil(PDO $pdo, string $prefix, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT p.*, e.nombre AS emisor_nombre,
                (SELECT COUNT(*) FROM `{$prefix}cards` c WHERE c.perfil_id = p.id) AS cards_count
           FROM `{$prefix}perfiles` p
      LEFT JOIN `{$prefix}emisores` e ON e.id = p.emisor_id
          WHERE p.id = ?"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * Extrae y valida el payload de un perfil desde el body.
 * Bloque inicial obligatorio: emisor (opcional en BD pero recomendado),
 * banco, dispositivo, titular y cvu. Bloque complementario opcional.
 */
function tarjetas_perfil_payload(array $body, PDO $pdo, string $prefix): array
{
    $emisorId = tarjetas_int($body['emisor_id'] ?? 0);
    if ($emisorId > 0) {
        $stmt = $pdo->prepare("SELECT id FROM `{$prefix}emisores` WHERE id = ?");
        $stmt->execute([$emisorId]);
        if ($stmt->fetchColumn() === false) {
            tarjetas_error('El emisor seleccionado no existe.', 400);
        }
    }

    $payload = [
        'emisor_id'   => $emisorId > 0 ? $emisorId : null,
        'banco'       => tarjetas_clean_text($body['banco'] ?? '', 120),
        'dispositivo' => tarjetas_clean_text($body['dispositivo'] ?? '', 120),
        'titular'     => tarjetas_clean_text($body['titular'] ?? '', 120),
        'cvu'         => tarjetas_clean_text($body['cvu'] ?? '', 60),
        'color'       => tarjetas_color($body['color'] ?? ''),
        // Bloque complementario: opcional, nunca genera error si queda vacío.
        'email'       => tarjetas_clean_text($body['email'] ?? '', 190),
        'password'    => tarjetas_clean_text($body['password'] ?? '', 190),
        'ubicacion'   => tarjetas_clean_text($body['ubicacion'] ?? '', 190),
        'cuenta_id'   => tarjetas_clean_text($body['cuenta_id'] ?? '', 120),
        'telefono'    => tarjetas_clean_text($body['telefono'] ?? '', 60),
    ];

    foreach (['banco', 'dispositivo', 'titular', 'cvu'] as $required) {
        if ($payload[$required] === '') {
            tarjetas_error("El campo '{$required}' es obligatorio.", 400);
        }
    }

    return $payload;
}

try {
    switch ($action) {

        case 'list':
            if ($method !== 'GET') {
                tarjetas_error("Usa GET para 'list'.", 405);
            }

            $rows = $pdo->query(
                "SELECT p.*, e.nombre AS emisor_nombre,
                        (SELECT COUNT(*) FROM `{$prefix}cards` c WHERE c.perfil_id = p.id) AS cards_count
                   FROM `{$prefix}perfiles` p
              LEFT JOIN `{$prefix}emisores` e ON e.id = p.emisor_id
               ORDER BY p.created_at DESC"
            )->fetchAll();

            tarjetas_ok(['perfiles' => $rows]);

        case 'create':
            if ($method !== 'POST') {
                tarjetas_error("Usa POST para 'create'.", 405);
            }

            $p = tarjetas_perfil_payload($body, $pdo, $prefix);

            $stmt = $pdo->prepare(
                "INSERT INTO `{$prefix}perfiles`
                    (emisor_id, banco, dispositivo, titular, cvu, color,
                     email, password, ubicacion, cuenta_id, telefono)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $p['emisor_id'], $p['banco'], $p['dispositivo'], $p['titular'], $p['cvu'], $p['color'],
                $p['email'], $p['password'], $p['ubicacion'], $p['cuenta_id'], $p['telefono'],
            ]);
            $id = (int) $pdo->lastInsertId();

            tarjetas_ok(['message' => 'Perfil creado.', 'perfil' => tarjetas_fetch_perfil($pdo, $prefix, $id)], 201);

        case 'update':
            if ($method !== 'POST') {
                tarjetas_error("Usa POST para 'update'.", 405);
            }

            $id = tarjetas_int($body['id'] ?? 0);
            if ($id === 0) tarjetas_error('ID inválido.', 400);

            $p = tarjetas_perfil_payload($body, $pdo, $prefix);

            $stmt = $pdo->prepare(
                "UPDATE `{$prefix}perfiles`
                    SET emisor_id = ?, banco = ?, dispositivo = ?, titular = ?, cvu = ?, color = ?,
                        email = ?, password = ?, ubicacion = ?, cuenta_id = ?, telefono = ?
                  WHERE id = ?"
            );
            $stmt->execute([
                $p['emisor_id'], $p['banco'], $p['dispositivo'], $p['titular'], $p['cvu'], $p['color'],
                $p['email'], $p['password'], $p['ubicacion'], $p['cuenta_id'], $p['telefono'], $id,
            ]);

            $perfil = tarjetas_fetch_perfil($pdo, $prefix, $id);
            if ($perfil === null) {
                tarjetas_error('Perfil no encontrado.', 404);
            }

            tarjetas_ok(['message' => 'Perfil actualizado.', 'perfil' => $perfil]);

        case 'delete':
            if ($method !== 'POST') {
                tarjetas_error("Usa POST para 'delete'.", 405);
            }

            $id = tarjetas_int($body['id'] ?? 0);
            if ($id === 0) tarjetas_error('ID inválido.', 400);

            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM `{$prefix}cards` WHERE perfil_id = ?")->execute([$id]);
                $stmt = $pdo->prepare("DELETE FROM `{$prefix}perfiles` WHERE id = ?");
                $stmt->execute([$id]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            if ($stmt->rowCount() === 0) {
                tarjetas_error('Perfil no encontrado.', 404);
            }

            tarjetas_ok(['message' => 'Perfil eliminado junto con sus tarjetas.']);

        default:
            tarjetas_error('Acción no reconocida.', 404);
    }
} catch (PDOException $e) {
    error_log('[tarjetas/perfiles] ' . $e->getMessage());
    tarjetas_error('Error de base de datos.', 500);
}

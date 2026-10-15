<?php
/**
 * ============================================================
 * Tarjetas — API de Emisores (billeteras / emisores de tarjetas)
 * ============================================================
 * Catálogo configurable de emisores (AstroPay, Belo, Lemon, ...)
 * con su color por defecto. Al crear un perfil se selecciona un
 * emisor y su color se hereda (editable).
 *
 * Endpoints (?action=...):
 *   GET  list   → todos los emisores
 *   POST create → nuevo emisor { nombre, color }
 *   POST update → editar { id, nombre, color }
 *   POST delete → eliminar { id } (los perfiles conservan su color)
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

$pdo    = TarjetasDB::connect();
$prefix = tarjetas_prefix();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$body   = $method === 'POST' ? tarjetas_read_json_body() : [];

/** Devuelve un emisor por id o null. */
function tarjetas_fetch_emisor(PDO $pdo, string $prefix, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM `{$prefix}emisores` WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

try {
    switch ($action) {

        case 'list':
            if ($method !== 'GET') {
                tarjetas_error("Usa GET para 'list'.", 405);
            }

            $rows = $pdo->query(
                "SELECT * FROM `{$prefix}emisores` ORDER BY nombre ASC"
            )->fetchAll();

            tarjetas_ok(['emisores' => $rows]);

        case 'create':
            if ($method !== 'POST') {
                tarjetas_error("Usa POST para 'create'.", 405);
            }

            $nombre = tarjetas_clean_text($body['nombre'] ?? '', 80);
            $color  = tarjetas_color($body['color'] ?? '');

            if ($nombre === '') {
                tarjetas_error('El nombre del emisor es obligatorio.', 400);
            }

            $stmt = $pdo->prepare(
                "INSERT INTO `{$prefix}emisores` (nombre, color) VALUES (?, ?)"
            );
            $stmt->execute([$nombre, $color]);
            $id = (int) $pdo->lastInsertId();

            tarjetas_ok(['message' => 'Emisor creado.', 'emisor' => tarjetas_fetch_emisor($pdo, $prefix, $id)], 201);

        case 'update':
            if ($method !== 'POST') {
                tarjetas_error("Usa POST para 'update'.", 405);
            }

            $id     = tarjetas_int($body['id'] ?? 0);
            $nombre = tarjetas_clean_text($body['nombre'] ?? '', 80);
            $color  = tarjetas_color($body['color'] ?? '');

            if ($id === 0)      tarjetas_error('ID inválido.', 400);
            if ($nombre === '') tarjetas_error('El nombre del emisor es obligatorio.', 400);

            $stmt = $pdo->prepare(
                "UPDATE `{$prefix}emisores` SET nombre = ?, color = ? WHERE id = ?"
            );
            $stmt->execute([$nombre, $color, $id]);

            $emisor = tarjetas_fetch_emisor($pdo, $prefix, $id);
            if ($emisor === null) {
                tarjetas_error('Emisor no encontrado.', 404);
            }

            tarjetas_ok(['message' => 'Emisor actualizado.', 'emisor' => $emisor]);

        case 'delete':
            if ($method !== 'POST') {
                tarjetas_error("Usa POST para 'delete'.", 405);
            }

            $id = tarjetas_int($body['id'] ?? 0);
            if ($id === 0) tarjetas_error('ID inválido.', 400);

            // Los perfiles ya guardan su propio color; sólo se desvincula el emisor.
            $pdo->prepare("UPDATE `{$prefix}perfiles` SET emisor_id = NULL WHERE emisor_id = ?")->execute([$id]);
            $stmt = $pdo->prepare("DELETE FROM `{$prefix}emisores` WHERE id = ?");
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                tarjetas_error('Emisor no encontrado.', 404);
            }

            tarjetas_ok(['message' => 'Emisor eliminado.']);

        default:
            tarjetas_error('Acción no reconocida.', 404);
    }
} catch (PDOException $e) {
    error_log('[tarjetas/emisores] ' . $e->getMessage());
    tarjetas_error('Error de base de datos.', 500);
}

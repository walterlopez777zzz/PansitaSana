<?php
/**
 * ============================================================
 * Tareas — API de Comentarios de misión
 * ============================================================
 * Endpoints (?action=...):
 *   GET  list?mision_id= → comentarios de una misión (orden cronológico)
 *   POST create          → nuevo comentario
 *   POST delete          → eliminar comentario
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/domain.php';

const TAREAS_COMMENT_MAXLEN = 4000;

$pdo    = TareasDB::connect();
$prefix = tareas_prefix();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$body   = $method === 'POST' ? tareas_read_json_body() : [];

try {
    switch ($action) {

        // ─── LISTAR ──────────────────────────────────────────
        case 'list':
            if ($method !== 'GET') {
                tareas_error("Usa GET para 'list'.", 405);
            }

            $misionId = tareas_int($_GET['mision_id'] ?? 0);
            if ($misionId === 0) tareas_error('mision_id inválido.', 400);

            tareas_ok(['comments' => tareas_list_comments($pdo, $prefix, $misionId)]);

        // ─── CREAR ───────────────────────────────────────────
        case 'create':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'create'.", 405);
            }

            $misionId = tareas_int($body['mision_id'] ?? 0);
            $text     = tareas_clean_text($body['body'] ?? '', TAREAS_COMMENT_MAXLEN);
            if ($misionId === 0) tareas_error('mision_id inválido.', 400);
            if ($text === '')    tareas_error('El comentario no puede estar vacío.', 400);

            if (!tareas_mision_exists($pdo, $prefix, $misionId)) {
                tareas_error('Misión no encontrada.', 404);
            }

            $stmt = $pdo->prepare(
                "INSERT INTO `{$prefix}comments` (mision_id, body) VALUES (?, ?)"
            );
            $stmt->execute([$misionId, $text]);
            $id = (int) $pdo->lastInsertId();

            tareas_emit($pdo, 'misiones');
            tareas_ok([
                'message' => 'Comentario agregado.',
                'comment' => tareas_fetch_comment($pdo, $prefix, $id),
            ], 201);

        // ─── ELIMINAR ────────────────────────────────────────
        case 'delete':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'delete'.", 405);
            }

            $id = tareas_int($body['id'] ?? 0);
            if ($id === 0) tareas_error('ID inválido.', 400);

            $stmt = $pdo->prepare("DELETE FROM `{$prefix}comments` WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                tareas_error('Comentario no encontrado.', 404);
            }

            tareas_emit($pdo, 'misiones');
            tareas_ok(['message' => 'Comentario eliminado.']);

        default:
            tareas_error('Acción no reconocida.', 404);
    }
} catch (PDOException $e) {
    error_log('[tareas/comments] ' . $e->getMessage());
    tareas_error('Error de base de datos.', 500);
}

// ── Helpers ──────────────────────────────────────────────────

/** ¿Existe la misión indicada? */
function tareas_mision_exists(PDO $pdo, string $prefix, int $misionId): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM `{$prefix}misiones` WHERE id = ?");
    $stmt->execute([$misionId]);
    return (bool) $stmt->fetchColumn();
}

/** Comentarios de una misión en orden cronológico. */
function tareas_list_comments(PDO $pdo, string $prefix, int $misionId): array
{
    $stmt = $pdo->prepare(
        "SELECT id, mision_id, body, created_at
           FROM `{$prefix}comments`
          WHERE mision_id = ?
          ORDER BY created_at ASC, id ASC"
    );
    $stmt->execute([$misionId]);

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['id']        = (int) $row['id'];
        $row['mision_id'] = (int) $row['mision_id'];
    }
    unset($row);

    return $rows;
}

/** Un comentario por id o null. */
function tareas_fetch_comment(PDO $pdo, string $prefix, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id, mision_id, body, created_at FROM `{$prefix}comments` WHERE id = ?"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['id']        = (int) $row['id'];
    $row['mision_id'] = (int) $row['mision_id'];
    return $row;
}

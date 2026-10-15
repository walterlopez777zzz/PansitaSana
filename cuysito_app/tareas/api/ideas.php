<?php
/**
 * ============================================================
 * Tareas — API de Ideas (captura rápida)
 * ============================================================
 * Endpoints (?action=...):
 *   GET  list      → ideas no archivadas
 *   POST create    → nueva idea
 *   POST update    → editar contenido / color
 *   POST toggle_pin→ fijar / desfijar
 *   POST archive   → archivar (soft delete)
 *   POST restore   → restaurar una idea archivada (deshacer)
 *   POST convert   → convertir idea en misión
 *   POST revert_convert → deshacer la conversión idea → misión
 *   POST reorder   → reordenar ideas (drag & drop)
 *   POST duplicate → clonar una idea
 *   POST set_tags  → fijar las etiquetas de una idea
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/domain.php';

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

            tareas_ok(['ideas' => tareas_list_ideas($pdo, $prefix)]);

        // ─── CREAR ───────────────────────────────────────────
        case 'create':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'create'.", 405);
            }

            $content = tareas_clean_text($body['content'] ?? '', TAREAS_IDEA_MAXLEN);
            $accent  = tareas_enum($body['accent'] ?? '', TAREAS_ACCENTS, 'violet');

            if ($content === '') {
                tareas_error('La idea no puede estar vacía.', 400);
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO `{$prefix}ideas` (content, accent) VALUES (?, ?)"
                );
                $stmt->execute([$content, $accent]);
                $id = (int) $pdo->lastInsertId();

                if (isset($body['tag_ids']) && is_array($body['tag_ids'])) {
                    tareas_set_tags($pdo, $prefix, 'idea', $id, $body['tag_ids']);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            $idea = tareas_fetch_idea($pdo, $prefix, $id);
            tareas_emit($pdo, 'ideas');
            tareas_ok(['message' => 'Idea guardada.', 'idea' => $idea], 201);

        // ─── EDITAR ──────────────────────────────────────────
        case 'update':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'update'.", 405);
            }

            $id      = tareas_int($body['id'] ?? 0);
            $content = tareas_clean_text($body['content'] ?? '', TAREAS_IDEA_MAXLEN);
            $accent  = tareas_enum($body['accent'] ?? '', TAREAS_ACCENTS, 'violet');

            if ($id === 0)        tareas_error('ID inválido.', 400);
            if ($content === '')  tareas_error('La idea no puede estar vacía.', 400);

            $stmt = $pdo->prepare(
                "UPDATE `{$prefix}ideas` SET content = ?, accent = ?
                  WHERE id = ? AND status <> 'archived'"
            );
            $stmt->execute([$content, $accent, $id]);

            if ($stmt->rowCount() === 0 && !tareas_fetch_idea($pdo, $prefix, $id)) {
                tareas_error('Idea no encontrada.', 404);
            }

            tareas_emit($pdo, 'ideas');
            tareas_ok(['message' => 'Idea actualizada.', 'idea' => tareas_fetch_idea($pdo, $prefix, $id)]);

        // ─── FIJAR / DESFIJAR ────────────────────────────────
        case 'toggle_pin':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'toggle_pin'.", 405);
            }

            $id = tareas_int($body['id'] ?? 0);
            if ($id === 0) tareas_error('ID inválido.', 400);

            $stmt = $pdo->prepare(
                "UPDATE `{$prefix}ideas` SET is_pinned = 1 - is_pinned
                  WHERE id = ? AND status <> 'archived'"
            );
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                tareas_error('Idea no encontrada.', 404);
            }

            tareas_emit($pdo, 'ideas');
            tareas_ok(['idea' => tareas_fetch_idea($pdo, $prefix, $id)]);

        // ─── ARCHIVAR ────────────────────────────────────────
        case 'archive':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'archive'.", 405);
            }

            $id = tareas_int($body['id'] ?? 0);
            if ($id === 0) tareas_error('ID inválido.', 400);

            $stmt = $pdo->prepare(
                "UPDATE `{$prefix}ideas` SET status = 'archived'
                  WHERE id = ? AND status <> 'archived'"
            );
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                tareas_error('Idea no encontrada o ya archivada.', 404);
            }

            tareas_emit($pdo, 'ideas');
            tareas_ok(['message' => 'Idea archivada.']);

        // ─── RESTAURAR (deshacer archivado) ──────────────────
        case 'restore':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'restore'.", 405);
            }

            $id = tareas_int($body['id'] ?? 0);
            if ($id === 0) tareas_error('ID inválido.', 400);

            // Se reconstruye el estado previo: una idea con misión asociada
            // vuelve a 'converted'; el resto vuelve a 'active'.
            $stmt = $pdo->prepare(
                "UPDATE `{$prefix}ideas`
                    SET status = IF(converted_mision_id IS NULL, 'active', 'converted')
                  WHERE id = ? AND status = 'archived'"
            );
            $stmt->execute([$id]);

            $idea = tareas_fetch_idea($pdo, $prefix, $id);
            if (!$idea) {
                tareas_error('Idea no encontrada.', 404);
            }

            tareas_emit($pdo, 'ideas');
            tareas_ok(['message' => 'Idea restaurada.', 'idea' => $idea]);

        // ─── CONVERTIR IDEA → MISIÓN ─────────────────────────
        case 'convert':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'convert'.", 405);
            }

            $id = tareas_int($body['id'] ?? 0);
            if ($id === 0) tareas_error('ID inválido.', 400);

            $idea = tareas_fetch_idea($pdo, $prefix, $id);
            if (!$idea || $idea['status'] === 'archived') {
                tareas_error('Idea no encontrada.', 404);
            }
            if ($idea['status'] === 'converted') {
                tareas_error('Esta idea ya fue convertida en misión.', 409);
            }

            $priority = tareas_enum($body['priority'] ?? '', TAREAS_MIS_PRIORITIES, 'media');
            // El título sale del cuerpo (recortado) o de la idea misma.
            $title = tareas_clean_text($body['title'] ?? $idea['content'], 160);
            if ($title === '') {
                $title = tareas_clean_text($idea['content'], 160);
            }
            // La descripción es opcional (igual que al crear/editar misión): se
            // guarda lo enviado, o NULL si viene vacía.
            $description = tareas_clean_text($body['description'] ?? '', 4000);

            // La misión hereda el acento de la idea como pista visual.
            $accent = tareas_enum($idea['accent'] ?? '', TAREAS_ACCENTS, 'violet');

            $pdo->beginTransaction();
            try {
                $insert = $pdo->prepare(
                    "INSERT INTO `{$prefix}misiones` (title, description, priority, accent, origin_idea_id)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $insert->execute([$title, $description !== '' ? $description : null, $priority, $accent, $id]);
                $misionId = (int) $pdo->lastInsertId();

                // Traslada las etiquetas de la idea a la misión generada.
                $ideaTags = tareas_tags_for($pdo, $prefix, 'idea', [$id])[$id] ?? [];
                if ($ideaTags) {
                    tareas_set_tags($pdo, $prefix, 'mision', $misionId, array_column($ideaTags, 'id'));
                }

                $pdo->prepare(
                    "UPDATE `{$prefix}ideas` SET status = 'converted', converted_mision_id = ?
                      WHERE id = ?"
                )->execute([$misionId, $id]);

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            tareas_emit($pdo, 'all');
            tareas_ok([
                'message'    => 'Idea convertida en misión.',
                'idea'       => tareas_fetch_idea($pdo, $prefix, $id),
                'mision_id'  => $misionId,
            ], 201);

        // ─── DESHACER CONVERSIÓN (idea ← misión) ─────────────
        case 'revert_convert':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'revert_convert'.", 405);
            }

            $id = tareas_int($body['id'] ?? 0);
            if ($id === 0) tareas_error('ID inválido.', 400);

            $idea = tareas_fetch_idea($pdo, $prefix, $id);
            if (!$idea) {
                tareas_error('Idea no encontrada.', 404);
            }
            if ($idea['status'] !== 'converted' || $idea['converted_mision_id'] === null) {
                tareas_error('Esta idea no está convertida.', 409);
            }

            $misionId = (int) $idea['converted_mision_id'];

            // Transacción: se archiva la misión generada y la idea vuelve a
            // 'active' liberando el vínculo, de forma atómica.
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    "UPDATE `{$prefix}misiones` SET status = 'archivada' WHERE id = ?"
                )->execute([$misionId]);

                $pdo->prepare(
                    "UPDATE `{$prefix}ideas` SET status = 'active', converted_mision_id = NULL
                      WHERE id = ?"
                )->execute([$id]);

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            tareas_emit($pdo, 'all');
            tareas_ok([
                'message' => 'Conversión deshecha.',
                'idea'    => tareas_fetch_idea($pdo, $prefix, $id),
            ]);

        // ─── REORDENAR IDEAS (drag & drop) ───────────────────
        case 'reorder':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'reorder'.", 405);
            }

            $ids = is_array($body['ids'] ?? null) ? $body['ids'] : [];
            if (!$ids) tareas_error('Lista de ids vacía.', 400);

            $pdo->beginTransaction();
            try {
                tareas_apply_order($pdo, "{$prefix}ideas", $ids);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            tareas_emit($pdo, 'ideas');
            tareas_ok(['message' => 'Orden actualizado.']);

        // ─── DUPLICAR / CLONAR ───────────────────────────────
        case 'duplicate':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'duplicate'.", 405);
            }

            $id = tareas_int($body['id'] ?? 0);
            if ($id === 0) tareas_error('ID inválido.', 400);

            $source = tareas_fetch_idea($pdo, $prefix, $id);
            if (!$source || $source['status'] === 'archived') {
                tareas_error('Idea no encontrada.', 404);
            }

            $pdo->beginTransaction();
            try {
                $insert = $pdo->prepare(
                    "INSERT INTO `{$prefix}ideas` (content, accent) VALUES (?, ?)"
                );
                $insert->execute([$source['content'], $source['accent']]);
                $newId = (int) $pdo->lastInsertId();

                if ($source['tags']) {
                    tareas_set_tags($pdo, $prefix, 'idea', $newId, array_column($source['tags'], 'id'));
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            tareas_emit($pdo, 'ideas');
            tareas_ok(['message' => 'Idea duplicada.', 'idea' => tareas_fetch_idea($pdo, $prefix, $newId)], 201);

        // ─── FIJAR ETIQUETAS ─────────────────────────────────
        case 'set_tags':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'set_tags'.", 405);
            }

            $id = tareas_int($body['id'] ?? 0);
            if ($id === 0) tareas_error('ID inválido.', 400);
            if (!tareas_fetch_idea($pdo, $prefix, $id)) {
                tareas_error('Idea no encontrada.', 404);
            }

            $tagIds = is_array($body['tag_ids'] ?? null) ? $body['tag_ids'] : [];
            $pdo->beginTransaction();
            try {
                tareas_set_tags($pdo, $prefix, 'idea', $id, $tagIds);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            tareas_emit($pdo, 'ideas');
            tareas_ok(['idea' => tareas_fetch_idea($pdo, $prefix, $id)]);

        default:
            tareas_error('Acción no reconocida.', 404);
    }
} catch (PDOException $e) {
    error_log('[tareas/ideas] ' . $e->getMessage());
    tareas_error('Error de base de datos.', 500);
}

/** Normaliza tipos escalares de una fila de idea para el cliente. */
function tareas_shape_idea(array $row): array
{
    $row['id']                  = (int) $row['id'];
    $row['is_pinned']           = (int) $row['is_pinned'];
    $row['sort_order']          = (int) $row['sort_order'];
    $row['converted_mision_id'] = $row['converted_mision_id'] !== null ? (int) $row['converted_mision_id'] : null;
    return $row;
}

/** Ideas visibles (no archivadas ni convertidas) con etiquetas embebidas. */
function tareas_list_ideas(PDO $pdo, string $prefix): array
{
    $rows = $pdo->query(
        "SELECT id, content, accent, is_pinned, status, converted_mision_id, sort_order, created_at, updated_at
           FROM `{$prefix}ideas`
          WHERE status NOT IN ('archived', 'converted')
          ORDER BY is_pinned DESC, sort_order ASC, created_at DESC"
    )->fetchAll();

    if (!$rows) {
        return [];
    }

    $tags = tareas_tags_for($pdo, $prefix, 'idea', array_column($rows, 'id'));
    foreach ($rows as &$row) {
        $row = tareas_shape_idea($row);
        $row['tags'] = $tags[$row['id']] ?? [];
    }
    unset($row);

    return $rows;
}

/** Devuelve una idea por id (con etiquetas) o null. */
function tareas_fetch_idea(PDO $pdo, string $prefix, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id, content, accent, is_pinned, status, converted_mision_id, sort_order, created_at, updated_at
           FROM `{$prefix}ideas` WHERE id = ?"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $row = tareas_shape_idea($row);
    $row['tags'] = tareas_tags_for($pdo, $prefix, 'idea', [$id])[$id] ?? [];
    return $row;
}

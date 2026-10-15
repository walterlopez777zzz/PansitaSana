<?php
/**
 * ============================================================
 * Tareas — API de Misiones (tareas + checklists)
 * ============================================================
 * Endpoints (?action=...):
 *   GET  list        → misiones con su checklist y colaborador
 *   POST create      → nueva misión
 *   POST update      → editar título / descripción / prioridad
 *   POST set_status  → cambiar estado (pendiente/en_progreso/...)
 *   POST assign      → asignar / desasignar colaborador
 *   POST archive     → archivar (soft delete)
 *   POST restore     → restaurar una misión archivada (deshacer)
 *   POST add_item    → agregar ítem de checklist
 *   POST toggle_item → marcar / desmarcar ítem
 *   POST update_item → editar texto de un ítem
 *   POST delete_item → eliminar un ítem
 *   POST restore_item→ reinsertar un ítem eliminado (deshacer)
 *   POST reorder     → reordenar misiones (drag & drop)
 *   POST reorder_items→ reordenar ítems de checklist
 *   POST set_tags    → fijar las etiquetas de una misión
 *   POST duplicate   → clonar una misión (checklist + etiquetas)
 *   POST bulk        → acción en lote (estado/prioridad/asignar/archivar)
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
            tareas_ok(['misiones' => tareas_list_misiones($pdo, $prefix)]);

        // ─── CREAR ───────────────────────────────────────────
        case 'create':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'create'.", 405);
            }

            $title = tareas_clean_text($body['title'] ?? '', 160);
            if ($title === '') {
                tareas_error('El título es obligatorio.', 400);
            }

            $description  = tareas_clean_text($body['description'] ?? '', 4000);
            $priority     = tareas_enum($body['priority'] ?? '', TAREAS_MIS_PRIORITIES, 'media');
            $accent       = tareas_enum($body['accent'] ?? '', TAREAS_ACCENTS, 'violet');
            $dueDate      = tareas_due_date($body['due_date'] ?? null);
            $recurrence   = tareas_enum($body['recurrence'] ?? '', TAREAS_RECURRENCES, 'none');
            $colaboradorId = tareas_resolve_colaborador($pdo, $prefix, $body['colaborador_id'] ?? null);

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO `{$prefix}misiones` (title, description, priority, accent, due_date, recurrence, colaborador_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([$title, $description !== '' ? $description : null, $priority, $accent, $dueDate, $recurrence, $colaboradorId]);
                $id = (int) $pdo->lastInsertId();

                if (isset($body['tag_ids']) && is_array($body['tag_ids'])) {
                    tareas_set_tags($pdo, $prefix, 'mision', $id, $body['tag_ids']);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            tareas_emit($pdo, 'misiones');
            tareas_ok(['message' => 'Misión creada.', 'mision' => tareas_fetch_mision($pdo, $prefix, $id)], 201);

        // ─── EDITAR ──────────────────────────────────────────
        case 'update':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'update'.", 405);
            }

            $id = tareas_int($body['id'] ?? 0);
            if ($id === 0) tareas_error('ID inválido.', 400);

            $title = tareas_clean_text($body['title'] ?? '', 160);
            if ($title === '') tareas_error('El título es obligatorio.', 400);

            $description = tareas_clean_text($body['description'] ?? '', 4000);
            $priority    = tareas_enum($body['priority'] ?? '', TAREAS_MIS_PRIORITIES, 'media');
            $accent      = tareas_enum($body['accent'] ?? '', TAREAS_ACCENTS, 'violet');
            $dueDate     = tareas_due_date($body['due_date'] ?? null);
            $recurrence  = tareas_enum($body['recurrence'] ?? '', TAREAS_RECURRENCES, 'none');

            if (!tareas_fetch_mision($pdo, $prefix, $id)) {
                tareas_error('Misión no encontrada.', 404);
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    "UPDATE `{$prefix}misiones` SET title = ?, description = ?, priority = ?, accent = ?, due_date = ?, recurrence = ?
                      WHERE id = ?"
                );
                $stmt->execute([$title, $description !== '' ? $description : null, $priority, $accent, $dueDate, $recurrence, $id]);

                if (isset($body['tag_ids']) && is_array($body['tag_ids'])) {
                    tareas_set_tags($pdo, $prefix, 'mision', $id, $body['tag_ids']);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            tareas_emit($pdo, 'misiones');
            tareas_ok(['message' => 'Misión actualizada.', 'mision' => tareas_fetch_mision($pdo, $prefix, $id)]);

        // ─── CAMBIAR ESTADO ──────────────────────────────────
        case 'set_status':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'set_status'.", 405);
            }

            $id = tareas_int($body['id'] ?? 0);
            if ($id === 0) tareas_error('ID inválido.', 400);

            $status = tareas_enum($body['status'] ?? '', TAREAS_MIS_STATUSES, '');
            if ($status === '') tareas_error('Estado inválido.', 400);

            $before = tareas_fetch_mision($pdo, $prefix, $id);
            if (!$before) {
                tareas_error('Misión no encontrada.', 404);
            }

            // Al completar una misión recurrente con vencimiento se engendra la
            // próxima ocurrencia (clona datos y checklist en limpio) de forma
            // atómica. La ocurrencia original queda como historial completado.
            $spawnsNext = $status === 'completada'
                && $before['status'] !== 'completada'
                && ($before['recurrence'] ?? 'none') !== 'none'
                && !empty($before['due_date']);

            $spawnedId = 0;
            $completedExpr = $status === 'completada' ? 'UTC_TIMESTAMP()' : 'NULL';

            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    "UPDATE `{$prefix}misiones` SET status = ?, completed_at = {$completedExpr} WHERE id = ?"
                )->execute([$status, $id]);

                if ($spawnsNext) {
                    $nextDue = tareas_advance_due_date($before['due_date'], $before['recurrence']);
                    $spawnedId = tareas_spawn_recurrence($pdo, $prefix, (int) $before['id'], $nextDue);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            $payload = ['mision' => tareas_fetch_mision($pdo, $prefix, $id)];
            if ($spawnedId > 0) {
                $payload['spawned'] = tareas_fetch_mision($pdo, $prefix, $spawnedId);
                $payload['message'] = 'Misión completada · próxima ocurrencia generada.';
            }
            tareas_emit($pdo, 'misiones');
            tareas_ok($payload);

        // ─── ASIGNAR / DESASIGNAR ────────────────────────────
        case 'assign':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'assign'.", 405);
            }

            $id = tareas_int($body['id'] ?? 0);
            if ($id === 0) tareas_error('ID inválido.', 400);

            // colaborador_id = null/0 → desasignar (misión disponible).
            $colaboradorId = tareas_resolve_colaborador($pdo, $prefix, $body['colaborador_id'] ?? null);

            $stmt = $pdo->prepare(
                "UPDATE `{$prefix}misiones` SET colaborador_id = ? WHERE id = ?"
            );
            $stmt->execute([$colaboradorId, $id]);

            if (!tareas_fetch_mision($pdo, $prefix, $id)) {
                tareas_error('Misión no encontrada.', 404);
            }

            tareas_emit($pdo, 'misiones');
            tareas_ok(['mision' => tareas_fetch_mision($pdo, $prefix, $id)]);

        // ─── ARCHIVAR ────────────────────────────────────────
        case 'archive':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'archive'.", 405);
            }

            $id = tareas_int($body['id'] ?? 0);
            if ($id === 0) tareas_error('ID inválido.', 400);

            $stmt = $pdo->prepare(
                "UPDATE `{$prefix}misiones` SET status = 'archivada' WHERE id = ? AND status <> 'archivada'"
            );
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                tareas_error('Misión no encontrada o ya archivada.', 404);
            }

            tareas_emit($pdo, 'misiones');
            tareas_ok(['message' => 'Misión archivada.']);

        // ─── RESTAURAR (deshacer archivado) ──────────────────
        case 'restore':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'restore'.", 405);
            }

            $id = tareas_int($body['id'] ?? 0);
            if ($id === 0) tareas_error('ID inválido.', 400);

            // Estado al que se devuelve la misión (nunca 'archivada'): por
            // defecto vuelve a 'pendiente'. No se toca completed_at para
            // preservar la marca temporal original de una misión completada.
            $restoreStatuses = ['pendiente', 'en_progreso', 'completada'];
            $status = tareas_enum($body['status'] ?? '', $restoreStatuses, 'pendiente');

            $stmt = $pdo->prepare(
                "UPDATE `{$prefix}misiones` SET status = ? WHERE id = ? AND status = 'archivada'"
            );
            $stmt->execute([$status, $id]);

            $mision = tareas_fetch_mision($pdo, $prefix, $id);
            if (!$mision) {
                tareas_error('Misión no encontrada.', 404);
            }

            tareas_emit($pdo, 'misiones');
            tareas_ok(['message' => 'Misión restaurada.', 'mision' => $mision]);

        // ─── CHECKLIST: AGREGAR ÍTEM ─────────────────────────
        case 'add_item':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'add_item'.", 405);
            }

            $misionId = tareas_int($body['mision_id'] ?? 0);
            $content  = tareas_clean_text($body['content'] ?? '', 255);

            if ($misionId === 0) tareas_error('mision_id inválido.', 400);
            if ($content === '') tareas_error('El ítem no puede estar vacío.', 400);

            if (!tareas_fetch_mision($pdo, $prefix, $misionId)) {
                tareas_error('Misión no encontrada.', 404);
            }

            // sort_order = al final de la lista actual.
            $next = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM `{$prefix}checklist_items` WHERE mision_id = ?");
            $next->execute([$misionId]);
            $sortOrder = (int) $next->fetchColumn();

            $stmt = $pdo->prepare(
                "INSERT INTO `{$prefix}checklist_items` (mision_id, content, sort_order)
                 VALUES (?, ?, ?)"
            );
            $stmt->execute([$misionId, $content, $sortOrder]);

            tareas_emit($pdo, 'misiones');
            tareas_ok(['mision' => tareas_fetch_mision($pdo, $prefix, $misionId)], 201);

        // ─── CHECKLIST: MARCAR / DESMARCAR ───────────────────
        case 'toggle_item':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'toggle_item'.", 405);
            }

            $itemId = tareas_int($body['id'] ?? 0);
            if ($itemId === 0) tareas_error('ID inválido.', 400);

            $stmt = $pdo->prepare("UPDATE `{$prefix}checklist_items` SET is_done = 1 - is_done WHERE id = ?");
            $stmt->execute([$itemId]);

            $misionId = tareas_item_mision_id($pdo, $prefix, $itemId);
            if ($misionId === 0) tareas_error('Ítem no encontrado.', 404);

            tareas_emit($pdo, 'misiones');
            tareas_ok(['mision' => tareas_fetch_mision($pdo, $prefix, $misionId)]);

        // ─── CHECKLIST: EDITAR ÍTEM ──────────────────────────
        case 'update_item':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'update_item'.", 405);
            }

            $itemId  = tareas_int($body['id'] ?? 0);
            $content = tareas_clean_text($body['content'] ?? '', 255);

            if ($itemId === 0)   tareas_error('ID inválido.', 400);
            if ($content === '') tareas_error('El ítem no puede estar vacío.', 400);

            $stmt = $pdo->prepare("UPDATE `{$prefix}checklist_items` SET content = ? WHERE id = ?");
            $stmt->execute([$content, $itemId]);

            $misionId = tareas_item_mision_id($pdo, $prefix, $itemId);
            if ($misionId === 0) tareas_error('Ítem no encontrado.', 404);

            tareas_emit($pdo, 'misiones');
            tareas_ok(['mision' => tareas_fetch_mision($pdo, $prefix, $misionId)]);

        // ─── CHECKLIST: ELIMINAR ÍTEM ────────────────────────
        case 'delete_item':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'delete_item'.", 405);
            }

            $itemId = tareas_int($body['id'] ?? 0);
            if ($itemId === 0) tareas_error('ID inválido.', 400);

            $misionId = tareas_item_mision_id($pdo, $prefix, $itemId);
            if ($misionId === 0) tareas_error('Ítem no encontrado.', 404);

            $pdo->prepare("DELETE FROM `{$prefix}checklist_items` WHERE id = ?")->execute([$itemId]);

            tareas_emit($pdo, 'misiones');
            tareas_ok(['mision' => tareas_fetch_mision($pdo, $prefix, $misionId)]);

        // ─── CHECKLIST: RESTAURAR ÍTEM (deshacer) ────────────
        case 'restore_item':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'restore_item'.", 405);
            }

            $misionId = tareas_int($body['mision_id'] ?? 0);
            $content  = tareas_clean_text($body['content'] ?? '', 255);
            $isDone   = !empty($body['is_done']) ? 1 : 0;

            if ($misionId === 0) tareas_error('mision_id inválido.', 400);
            if ($content === '') tareas_error('El ítem no puede estar vacío.', 400);

            if (!tareas_fetch_mision($pdo, $prefix, $misionId)) {
                tareas_error('Misión no encontrada.', 404);
            }

            // Se respeta el sort_order original si vino; si no, va al final.
            $sortOrder = tareas_int($body['sort_order'] ?? 0);
            if ($sortOrder === 0) {
                $next = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM `{$prefix}checklist_items` WHERE mision_id = ?");
                $next->execute([$misionId]);
                $sortOrder = (int) $next->fetchColumn();
            }

            $stmt = $pdo->prepare(
                "INSERT INTO `{$prefix}checklist_items` (mision_id, content, is_done, sort_order)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$misionId, $content, $isDone, $sortOrder]);
            $newId = (int) $pdo->lastInsertId();

            tareas_emit($pdo, 'misiones');
            tareas_ok(['item_id' => $newId, 'mision' => tareas_fetch_mision($pdo, $prefix, $misionId)], 201);

        // ─── REORDENAR MISIONES (drag & drop) ────────────────
        case 'reorder':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'reorder'.", 405);
            }

            $ids = is_array($body['ids'] ?? null) ? $body['ids'] : [];
            if (!$ids) tareas_error('Lista de ids vacía.', 400);

            $pdo->beginTransaction();
            try {
                tareas_apply_order($pdo, "{$prefix}misiones", $ids);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            tareas_emit($pdo, 'misiones');
            tareas_ok(['message' => 'Orden actualizado.']);

        // ─── REORDENAR ÍTEMS DE CHECKLIST ────────────────────
        case 'reorder_items':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'reorder_items'.", 405);
            }

            $misionId = tareas_int($body['mision_id'] ?? 0);
            $ids      = is_array($body['ids'] ?? null) ? $body['ids'] : [];
            if ($misionId === 0) tareas_error('mision_id inválido.', 400);
            if (!$ids) tareas_error('Lista de ids vacía.', 400);

            $pdo->beginTransaction();
            try {
                tareas_apply_order($pdo, "{$prefix}checklist_items", $ids);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            tareas_emit($pdo, 'misiones');
            tareas_ok(['mision' => tareas_fetch_mision($pdo, $prefix, $misionId)]);

        // ─── FIJAR ETIQUETAS ─────────────────────────────────
        case 'set_tags':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'set_tags'.", 405);
            }

            $id = tareas_int($body['id'] ?? 0);
            if ($id === 0) tareas_error('ID inválido.', 400);
            if (!tareas_fetch_mision($pdo, $prefix, $id)) {
                tareas_error('Misión no encontrada.', 404);
            }

            $tagIds = is_array($body['tag_ids'] ?? null) ? $body['tag_ids'] : [];
            $pdo->beginTransaction();
            try {
                tareas_set_tags($pdo, $prefix, 'mision', $id, $tagIds);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            tareas_emit($pdo, 'misiones');
            tareas_ok(['mision' => tareas_fetch_mision($pdo, $prefix, $id)]);

        // ─── DUPLICAR / CLONAR ───────────────────────────────
        case 'duplicate':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'duplicate'.", 405);
            }

            $id = tareas_int($body['id'] ?? 0);
            if ($id === 0) tareas_error('ID inválido.', 400);

            $source = tareas_fetch_mision($pdo, $prefix, $id);
            if (!$source || $source['status'] === 'archivada') {
                tareas_error('Misión no encontrada.', 404);
            }

            // Clona como una misión nueva en limpio: misma prioridad, acento,
            // colaborador y checklist (sin marcar). No hereda vencimiento ni
            // recurrencia (la copia es un punto de partida, no una ocurrencia).
            $newTitle = tareas_clean_text($source['title'] . ' (copia)', 160);

            $pdo->beginTransaction();
            try {
                $insert = $pdo->prepare(
                    "INSERT INTO `{$prefix}misiones` (title, description, priority, accent, colaborador_id, origin_idea_id)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                $insert->execute([
                    $newTitle,
                    $source['description'],
                    $source['priority'],
                    $source['accent'],
                    $source['colaborador_id'],
                    $source['origin_idea_id'],
                ]);
                $newId = (int) $pdo->lastInsertId();

                if ($source['checklist']) {
                    $copyItem = $pdo->prepare(
                        "INSERT INTO `{$prefix}checklist_items` (mision_id, content, is_done, sort_order)
                         VALUES (?, ?, 0, ?)"
                    );
                    foreach ($source['checklist'] as $item) {
                        $copyItem->execute([$newId, $item['content'], (int) $item['sort_order']]);
                    }
                }

                if ($source['tags']) {
                    tareas_set_tags($pdo, $prefix, 'mision', $newId, array_column($source['tags'], 'id'));
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            tareas_emit($pdo, 'misiones');
            tareas_ok(['message' => 'Misión duplicada.', 'mision' => tareas_fetch_mision($pdo, $prefix, $newId)], 201);

        // ─── ACCIÓN EN LOTE ──────────────────────────────────
        case 'bulk':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'bulk'.", 405);
            }

            $ids = array_values(array_filter(array_map('intval', is_array($body['ids'] ?? null) ? $body['ids'] : [])));
            $op  = is_string($body['op'] ?? null) ? $body['op'] : '';
            if (!$ids) tareas_error('No hay misiones seleccionadas.', 400);

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->beginTransaction();
            try {
                switch ($op) {
                    case 'status':
                        $status = tareas_enum($body['value'] ?? '', TAREAS_MIS_RESTORE_STATUSES, '');
                        if ($status === '') tareas_error('Estado inválido.', 400);
                        $completedExpr = $status === 'completada' ? 'UTC_TIMESTAMP()' : 'NULL';
                        $pdo->prepare(
                            "UPDATE `{$prefix}misiones` SET status = ?, completed_at = {$completedExpr}
                              WHERE id IN ({$placeholders}) AND status <> 'archivada'"
                        )->execute([$status, ...$ids]);
                        break;
                    case 'priority':
                        $priority = tareas_enum($body['value'] ?? '', TAREAS_MIS_PRIORITIES, '');
                        if ($priority === '') tareas_error('Prioridad inválida.', 400);
                        $pdo->prepare(
                            "UPDATE `{$prefix}misiones` SET priority = ?
                              WHERE id IN ({$placeholders}) AND status <> 'archivada'"
                        )->execute([$priority, ...$ids]);
                        break;
                    case 'assign':
                        $colaboradorId = tareas_resolve_colaborador($pdo, $prefix, $body['value'] ?? null);
                        $pdo->prepare(
                            "UPDATE `{$prefix}misiones` SET colaborador_id = ?
                              WHERE id IN ({$placeholders}) AND status <> 'archivada'"
                        )->execute([$colaboradorId, ...$ids]);
                        break;
                    case 'archive':
                        $pdo->prepare(
                            "UPDATE `{$prefix}misiones` SET status = 'archivada'
                              WHERE id IN ({$placeholders}) AND status <> 'archivada'"
                        )->execute($ids);
                        break;
                    default:
                        tareas_error('Operación en lote no reconocida.', 400);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            tareas_emit($pdo, 'misiones');
            tareas_ok(['message' => 'Acción aplicada.', 'misiones' => tareas_list_misiones($pdo, $prefix)]);

        default:
            tareas_error('Acción no reconocida.', 404);
    }
} catch (PDOException $e) {
    error_log('[tareas/misiones] ' . $e->getMessage());
    tareas_error('Error de base de datos.', 500);
}

// ── Helpers ──────────────────────────────────────────────────

/**
 * Resuelve un colaborador_id de entrada: null/0 → null (sin asignar);
 * caso contrario valida que exista y no esté archivado.
 */
function tareas_resolve_colaborador(PDO $pdo, string $prefix, mixed $raw): ?int
{
    $id = tareas_int($raw);
    if ($id === 0) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id FROM `{$prefix}colaboradores` WHERE id = ? AND is_archived = 0");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        tareas_error('Colaborador inválido.', 400);
    }
    return $id;
}

/** mision_id de un ítem de checklist, o 0 si no existe. */
function tareas_item_mision_id(PDO $pdo, string $prefix, int $itemId): int
{
    $stmt = $pdo->prepare("SELECT mision_id FROM `{$prefix}checklist_items` WHERE id = ?");
    $stmt->execute([$itemId]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

/** Columnas de misión expuestas por la API (orden estable y explícito). */
function tareas_mision_columns(): string
{
    return "m.id, m.title, m.description, m.status, m.priority, m.accent,
            m.due_date, m.recurrence, m.colaborador_id, m.origin_idea_id,
            m.sort_order, m.completed_at, m.created_at, m.updated_at,
            c.name AS colaborador_name, c.color AS colaborador_color";
}

/** Normaliza tipos escalares de una fila de misión para el cliente. */
function tareas_shape_mision(array $row): array
{
    $row['id']             = (int) $row['id'];
    $row['sort_order']     = (int) $row['sort_order'];
    $row['colaborador_id'] = $row['colaborador_id'] !== null ? (int) $row['colaborador_id'] : null;
    $row['origin_idea_id'] = $row['origin_idea_id'] !== null ? (int) $row['origin_idea_id'] : null;
    return $row;
}

/** Devuelve todas las misiones no archivadas con checklist, etiquetas y colaborador embebidos. */
function tareas_list_misiones(PDO $pdo, string $prefix): array
{
    $columns = tareas_mision_columns();
    $rows = $pdo->query(
        "SELECT {$columns}
           FROM `{$prefix}misiones` m
           LEFT JOIN `{$prefix}colaboradores` c ON c.id = m.colaborador_id
          WHERE m.status <> 'archivada'
          ORDER BY m.sort_order ASC, m.created_at DESC"
    )->fetchAll();

    if (!$rows) {
        return [];
    }

    $ids    = array_column($rows, 'id');
    $items  = tareas_checklist_for($pdo, $prefix, $ids);
    $tags   = tareas_tags_for($pdo, $prefix, 'mision', $ids);
    $counts = tareas_meta_counts($pdo, $prefix, $ids);

    foreach ($rows as &$row) {
        $row = tareas_shape_mision($row);
        $row['checklist']         = $items[$row['id']] ?? [];
        $row['tags']              = $tags[$row['id']] ?? [];
        $row['comments_count']    = $counts[$row['id']]['comments'] ?? 0;
        $row['attachments_count'] = $counts[$row['id']]['attachments'] ?? 0;
    }
    unset($row);

    return $rows;
}

/** Trae una misión por id con checklist + etiquetas + colaborador. */
function tareas_fetch_mision(PDO $pdo, string $prefix, int $id): ?array
{
    $columns = tareas_mision_columns();
    $stmt = $pdo->prepare(
        "SELECT {$columns}
           FROM `{$prefix}misiones` m
           LEFT JOIN `{$prefix}colaboradores` c ON c.id = m.colaborador_id
          WHERE m.id = ?"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $row = tareas_shape_mision($row);
    $items  = tareas_checklist_for($pdo, $prefix, [$id]);
    $tags   = tareas_tags_for($pdo, $prefix, 'mision', [$id]);
    $counts = tareas_meta_counts($pdo, $prefix, [$id]);
    $row['checklist']         = $items[$id] ?? [];
    $row['tags']              = $tags[$id] ?? [];
    $row['comments_count']    = $counts[$id]['comments'] ?? 0;
    $row['attachments_count'] = $counts[$id]['attachments'] ?? 0;
    return $row;
}

/**
 * Conteo de comentarios y adjuntos por misión, agrupado por id.
 * Dos consultas agregadas en vez de subconsultas por fila (rendimiento).
 */
function tareas_meta_counts(PDO $pdo, string $prefix, array $misionIds): array
{
    $misionIds = array_values(array_filter(array_map('intval', $misionIds)));
    if (!$misionIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($misionIds), '?'));
    $counts = [];
    foreach (['comments' => "{$prefix}comments", 'attachments' => "{$prefix}attachments"] as $key => $table) {
        $stmt = $pdo->prepare(
            "SELECT mision_id, COUNT(*) AS n FROM `{$table}`
              WHERE mision_id IN ({$placeholders}) GROUP BY mision_id"
        );
        $stmt->execute($misionIds);
        foreach ($stmt->fetchAll() as $r) {
            $counts[(int) $r['mision_id']][$key] = (int) $r['n'];
        }
    }
    return $counts;
}

/**
 * Engendra la próxima ocurrencia de una misión recurrente: clona los datos
 * base con el nuevo vencimiento, copia la checklist en limpio (sin marcar)
 * y replica las etiquetas. Debe invocarse dentro de una transacción.
 * Devuelve el id de la misión creada.
 */
function tareas_spawn_recurrence(PDO $pdo, string $prefix, int $sourceId, ?string $nextDue): int
{
    $source = tareas_fetch_mision($pdo, $prefix, $sourceId);
    if (!$source) {
        return 0;
    }

    $insert = $pdo->prepare(
        "INSERT INTO `{$prefix}misiones`
            (title, description, status, priority, accent, due_date, recurrence, colaborador_id, origin_idea_id)
         VALUES (?, ?, 'pendiente', ?, ?, ?, ?, ?, ?)"
    );
    $insert->execute([
        $source['title'],
        $source['description'],
        $source['priority'],
        $source['accent'],
        $nextDue,
        $source['recurrence'],
        $source['colaborador_id'],
        $source['origin_idea_id'],
    ]);
    $newId = (int) $pdo->lastInsertId();

    if ($source['checklist']) {
        $copy = $pdo->prepare(
            "INSERT INTO `{$prefix}checklist_items` (mision_id, content, is_done, sort_order)
             VALUES (?, ?, 0, ?)"
        );
        foreach ($source['checklist'] as $item) {
            $copy->execute([$newId, $item['content'], (int) $item['sort_order']]);
        }
    }

    if ($source['tags']) {
        tareas_set_tags($pdo, $prefix, 'mision', $newId, array_column($source['tags'], 'id'));
    }

    return $newId;
}

/**
 * Checklist de un conjunto de misiones, agrupado por mision_id.
 * Usa placeholders parametrizados (anti inyección) para el IN(...).
 */
function tareas_checklist_for(PDO $pdo, string $prefix, array $misionIds): array
{
    $misionIds = array_values(array_filter(array_map('intval', $misionIds)));
    if (!$misionIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($misionIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, mision_id, content, is_done, sort_order, created_at, updated_at
           FROM `{$prefix}checklist_items`
          WHERE mision_id IN ({$placeholders})
          ORDER BY sort_order ASC, id ASC"
    );
    $stmt->execute($misionIds);

    $grouped = [];
    foreach ($stmt->fetchAll() as $item) {
        $item['id']         = (int) $item['id'];
        $item['mision_id']  = (int) $item['mision_id'];
        $item['is_done']    = (int) $item['is_done'];
        $item['sort_order'] = (int) $item['sort_order'];
        $grouped[$item['mision_id']][] = $item;
    }
    return $grouped;
}

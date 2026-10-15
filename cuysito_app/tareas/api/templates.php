<?php
/**
 * ============================================================
 * Tareas — API de Plantillas de misión
 * ============================================================
 * Endpoints (?action=...):
 *   GET  list        → plantillas con sus ítems
 *   POST create      → nueva plantilla (datos + checklist base)
 *   POST update      → editar plantilla y su checklist
 *   POST delete      → eliminar plantilla
 *   POST instantiate → crear una misión a partir de una plantilla
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/domain.php';

const TAREAS_TPL_MAX_ITEMS = 100;

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
            tareas_ok(['templates' => tareas_list_templates($pdo, $prefix)]);

        // ─── CREAR ───────────────────────────────────────────
        case 'create':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'create'.", 405);
            }

            $name = tareas_clean_text($body['name'] ?? '', 80);
            if ($name === '') {
                tareas_error('El nombre de la plantilla es obligatorio.', 400);
            }

            $title       = tareas_clean_text($body['title'] ?? '', 160);
            $description = tareas_clean_text($body['description'] ?? '', 4000);
            $priority    = tareas_enum($body['priority'] ?? '', TAREAS_MIS_PRIORITIES, 'media');
            $accent      = tareas_enum($body['accent'] ?? '', TAREAS_ACCENTS, 'violet');
            $items       = tareas_clean_items($body['items'] ?? []);

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO `{$prefix}templates` (name, title, description, priority, accent)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->execute([$name, $title, $description !== '' ? $description : null, $priority, $accent]);
                $id = (int) $pdo->lastInsertId();
                tareas_replace_template_items($pdo, $prefix, $id, $items);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            tareas_ok(['message' => 'Plantilla creada.', 'template' => tareas_fetch_template($pdo, $prefix, $id)], 201);

        // ─── EDITAR ──────────────────────────────────────────
        case 'update':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'update'.", 405);
            }

            $id = tareas_int($body['id'] ?? 0);
            if ($id === 0) tareas_error('ID inválido.', 400);
            if (!tareas_fetch_template($pdo, $prefix, $id)) {
                tareas_error('Plantilla no encontrada.', 404);
            }

            $name = tareas_clean_text($body['name'] ?? '', 80);
            if ($name === '') tareas_error('El nombre de la plantilla es obligatorio.', 400);

            $title       = tareas_clean_text($body['title'] ?? '', 160);
            $description = tareas_clean_text($body['description'] ?? '', 4000);
            $priority    = tareas_enum($body['priority'] ?? '', TAREAS_MIS_PRIORITIES, 'media');
            $accent      = tareas_enum($body['accent'] ?? '', TAREAS_ACCENTS, 'violet');
            $items       = tareas_clean_items($body['items'] ?? []);

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    "UPDATE `{$prefix}templates`
                        SET name = ?, title = ?, description = ?, priority = ?, accent = ?
                      WHERE id = ?"
                );
                $stmt->execute([$name, $title, $description !== '' ? $description : null, $priority, $accent, $id]);
                tareas_replace_template_items($pdo, $prefix, $id, $items);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            tareas_ok(['message' => 'Plantilla actualizada.', 'template' => tareas_fetch_template($pdo, $prefix, $id)]);

        // ─── ELIMINAR ────────────────────────────────────────
        case 'delete':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'delete'.", 405);
            }

            $id = tareas_int($body['id'] ?? 0);
            if ($id === 0) tareas_error('ID inválido.', 400);

            $stmt = $pdo->prepare("DELETE FROM `{$prefix}templates` WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                tareas_error('Plantilla no encontrada.', 404);
            }

            tareas_ok(['message' => 'Plantilla eliminada.']);

        // ─── INSTANCIAR (plantilla → misión) ─────────────────
        case 'instantiate':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'instantiate'.", 405);
            }

            $id = tareas_int($body['id'] ?? 0);
            if ($id === 0) tareas_error('ID inválido.', 400);

            $template = tareas_fetch_template($pdo, $prefix, $id);
            if (!$template) {
                tareas_error('Plantilla no encontrada.', 404);
            }

            $title    = tareas_clean_text($body['title'] ?? '', 160);
            if ($title === '') {
                $title = $template['title'] !== '' ? $template['title'] : $template['name'];
            }
            $dueDate  = tareas_due_date($body['due_date'] ?? null);

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO `{$prefix}misiones` (title, description, priority, accent, due_date)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $title,
                    $template['description'],
                    $template['priority'],
                    $template['accent'],
                    $dueDate,
                ]);
                $misionId = (int) $pdo->lastInsertId();

                if ($template['items']) {
                    $insert = $pdo->prepare(
                        "INSERT INTO `{$prefix}checklist_items` (mision_id, content, sort_order)
                         VALUES (?, ?, ?)"
                    );
                    foreach ($template['items'] as $position => $item) {
                        $insert->execute([$misionId, $item['content'], $position]);
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            tareas_emit($pdo, 'misiones');
            tareas_ok(['message' => 'Misión creada desde plantilla.', 'mision_id' => $misionId], 201);

        default:
            tareas_error('Acción no reconocida.', 404);
    }
} catch (PDOException $e) {
    error_log('[tareas/templates] ' . $e->getMessage());
    tareas_error('Error de base de datos.', 500);
}

// ── Helpers ──────────────────────────────────────────────────

/** Sanea una lista de ítems de checklist (array de strings → contenidos válidos). */
function tareas_clean_items(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $items = [];
    foreach ($raw as $entry) {
        $content = is_array($entry)
            ? tareas_clean_text($entry['content'] ?? '', 255)
            : tareas_clean_text($entry, 255);
        if ($content !== '') {
            $items[] = $content;
        }
        if (count($items) >= TAREAS_TPL_MAX_ITEMS) {
            break;
        }
    }
    return $items;
}

/** Reemplaza por completo los ítems de una plantilla (dentro de una transacción). */
function tareas_replace_template_items(PDO $pdo, string $prefix, int $templateId, array $items): void
{
    $pdo->prepare("DELETE FROM `{$prefix}template_items` WHERE template_id = ?")->execute([$templateId]);
    if (!$items) {
        return;
    }
    $insert = $pdo->prepare(
        "INSERT INTO `{$prefix}template_items` (template_id, content, sort_order) VALUES (?, ?, ?)"
    );
    foreach ($items as $position => $content) {
        $insert->execute([$templateId, $content, $position]);
    }
}

/** Plantillas con ítems embebidos. */
function tareas_list_templates(PDO $pdo, string $prefix): array
{
    $rows = $pdo->query(
        "SELECT id, name, title, description, priority, accent, created_at, updated_at
           FROM `{$prefix}templates`
          ORDER BY name ASC"
    )->fetchAll();

    if (!$rows) {
        return [];
    }

    $ids   = array_map('intval', array_column($rows, 'id'));
    $items = tareas_template_items_for($pdo, $prefix, $ids);

    foreach ($rows as &$row) {
        $row['id']    = (int) $row['id'];
        $row['items'] = $items[$row['id']] ?? [];
    }
    unset($row);

    return $rows;
}

/** Una plantilla por id con sus ítems o null. */
function tareas_fetch_template(PDO $pdo, string $prefix, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id, name, title, description, priority, accent, created_at, updated_at
           FROM `{$prefix}templates` WHERE id = ?"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['id']    = (int) $row['id'];
    $row['items'] = tareas_template_items_for($pdo, $prefix, [$id])[$id] ?? [];
    return $row;
}

/** Ítems de un conjunto de plantillas, agrupados por template_id. */
function tareas_template_items_for(PDO $pdo, string $prefix, array $templateIds): array
{
    $templateIds = array_values(array_filter(array_map('intval', $templateIds)));
    if (!$templateIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($templateIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, template_id, content, sort_order
           FROM `{$prefix}template_items`
          WHERE template_id IN ({$placeholders})
          ORDER BY sort_order ASC, id ASC"
    );
    $stmt->execute($templateIds);

    $grouped = [];
    foreach ($stmt->fetchAll() as $item) {
        $grouped[(int) $item['template_id']][] = [
            'id'         => (int) $item['id'],
            'content'    => $item['content'],
            'sort_order' => (int) $item['sort_order'],
        ];
    }
    return $grouped;
}

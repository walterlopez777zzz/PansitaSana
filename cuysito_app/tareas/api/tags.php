<?php
/**
 * ============================================================
 * Tareas — API de Etiquetas (tags)
 * ============================================================
 * Endpoints (?action=...):
 *   GET  list   → todas las etiquetas con conteo de uso
 *   POST create → nueva etiqueta (name + color)
 *   POST update → renombrar / recolorear
 *   POST delete → eliminar (los pivotes caen en cascada)
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/domain.php';

const TAREAS_TAG_MAXLEN = 40;

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
            tareas_ok(['tags' => tareas_list_tags($pdo, $prefix)]);

        // ─── CREAR ───────────────────────────────────────────
        case 'create':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'create'.", 405);
            }

            $name  = tareas_clean_text($body['name'] ?? '', TAREAS_TAG_MAXLEN);
            $color = tareas_tag_color($body['color'] ?? '');
            if ($name === '') {
                tareas_error('El nombre de la etiqueta es obligatorio.', 400);
            }

            $stmt = $pdo->prepare("SELECT id FROM `{$prefix}tags` WHERE name = ?");
            $stmt->execute([$name]);
            if ($stmt->fetch()) {
                tareas_error('Ya existe una etiqueta con ese nombre.', 409);
            }

            $stmt = $pdo->prepare("INSERT INTO `{$prefix}tags` (name, color) VALUES (?, ?)");
            $stmt->execute([$name, $color]);
            $id = (int) $pdo->lastInsertId();

            tareas_emit($pdo, 'tags');
            tareas_ok(['message' => 'Etiqueta creada.', 'tag' => tareas_fetch_tag($pdo, $prefix, $id)], 201);

        // ─── EDITAR ──────────────────────────────────────────
        case 'update':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'update'.", 405);
            }

            $id    = tareas_int($body['id'] ?? 0);
            $name  = tareas_clean_text($body['name'] ?? '', TAREAS_TAG_MAXLEN);
            $color = tareas_tag_color($body['color'] ?? '');
            if ($id === 0)   tareas_error('ID inválido.', 400);
            if ($name === '') tareas_error('El nombre de la etiqueta es obligatorio.', 400);

            $stmt = $pdo->prepare("SELECT id FROM `{$prefix}tags` WHERE name = ? AND id <> ?");
            $stmt->execute([$name, $id]);
            if ($stmt->fetch()) {
                tareas_error('Ya existe otra etiqueta con ese nombre.', 409);
            }

            $stmt = $pdo->prepare("UPDATE `{$prefix}tags` SET name = ?, color = ? WHERE id = ?");
            $stmt->execute([$name, $color, $id]);

            $tag = tareas_fetch_tag($pdo, $prefix, $id);
            if (!$tag) {
                tareas_error('Etiqueta no encontrada.', 404);
            }
            tareas_emit($pdo, 'tags');
            tareas_ok(['message' => 'Etiqueta actualizada.', 'tag' => $tag]);

        // ─── ELIMINAR ────────────────────────────────────────
        case 'delete':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'delete'.", 405);
            }

            $id = tareas_int($body['id'] ?? 0);
            if ($id === 0) tareas_error('ID inválido.', 400);

            $stmt = $pdo->prepare("DELETE FROM `{$prefix}tags` WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                tareas_error('Etiqueta no encontrada.', 404);
            }

            tareas_emit($pdo, 'tags');
            tareas_ok(['message' => 'Etiqueta eliminada.']);

        default:
            tareas_error('Acción no reconocida.', 404);
    }
} catch (PDOException $e) {
    error_log('[tareas/tags] ' . $e->getMessage());
    tareas_error('Error de base de datos.', 500);
}

// ── Helpers ──────────────────────────────────────────────────

/**
 * Valida el color de una etiqueta: acepta un token de acento de la paleta
 * o un color hex #rrggbb. Cae a 'violet' si es inválido.
 */
function tareas_tag_color(mixed $raw): string
{
    $value = is_string($raw) ? strtolower(trim($raw)) : '';
    if (in_array($value, TAREAS_ACCENTS, true)) {
        return $value;
    }
    if (preg_match('/^#[0-9a-f]{6}$/', $value) === 1) {
        return $value;
    }
    return 'violet';
}

/** Todas las etiquetas con su conteo de uso (ideas + misiones). */
function tareas_list_tags(PDO $pdo, string $prefix): array
{
    $rows = $pdo->query(
        "SELECT t.id, t.name, t.color, t.created_at,
                (SELECT COUNT(*) FROM `{$prefix}idea_tags`   it WHERE it.tag_id = t.id) AS idea_count,
                (SELECT COUNT(*) FROM `{$prefix}mision_tags` mt WHERE mt.tag_id = t.id) AS mision_count
           FROM `{$prefix}tags` t
          ORDER BY t.name ASC"
    )->fetchAll();

    foreach ($rows as &$row) {
        $row['id']           = (int) $row['id'];
        $row['idea_count']   = (int) $row['idea_count'];
        $row['mision_count'] = (int) $row['mision_count'];
        $row['usage']        = $row['idea_count'] + $row['mision_count'];
    }
    unset($row);

    return $rows;
}

/** Una etiqueta por id (con conteos) o null. */
function tareas_fetch_tag(PDO $pdo, string $prefix, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT t.id, t.name, t.color, t.created_at,
                (SELECT COUNT(*) FROM `{$prefix}idea_tags`   it WHERE it.tag_id = t.id) AS idea_count,
                (SELECT COUNT(*) FROM `{$prefix}mision_tags` mt WHERE mt.tag_id = t.id) AS mision_count
           FROM `{$prefix}tags` t
          WHERE t.id = ?"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['id']           = (int) $row['id'];
    $row['idea_count']   = (int) $row['idea_count'];
    $row['mision_count'] = (int) $row['mision_count'];
    $row['usage']        = $row['idea_count'] + $row['mision_count'];
    return $row;
}

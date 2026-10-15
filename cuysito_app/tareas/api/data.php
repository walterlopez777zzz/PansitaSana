<?php
/**
 * ============================================================
 * Tareas — API de Exportación / Importación
 * ============================================================
 * Endpoints (?action=...):
 *   GET  export?format=json      → backup completo (descarga .json)
 *   GET  export?format=csv&scope=misiones|ideas → CSV plano
 *   POST import                  → importa un backup JSON (aditivo)
 *
 * El backup usa NOMBRES de etiqueta (no ids) para ser portable
 * entre instalaciones. La importación es aditiva: nunca borra
 * datos existentes y reutiliza etiquetas por nombre.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/domain.php';

const TAREAS_BACKUP_VERSION = 1;

$pdo    = TareasDB::connect();
$prefix = tareas_prefix();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        // ─── EXPORTAR ────────────────────────────────────────
        case 'export':
            if ($method !== 'GET') {
                tareas_error("Usa GET para 'export'.", 405);
            }

            $format = strtolower((string) ($_GET['format'] ?? 'json'));
            if ($format === 'csv') {
                $scope = tareas_enum($_GET['scope'] ?? '', ['misiones', 'ideas'], 'misiones');
                tareas_export_csv($pdo, $prefix, $scope);
            }
            tareas_export_json($pdo, $prefix);

        // ─── IMPORTAR ────────────────────────────────────────
        case 'import':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'import'.", 405);
            }

            $body = tareas_read_json_body();
            // Acepta el backup directo o envuelto en {data: {...}}.
            $backup = isset($body['data']) && is_array($body['data']) ? $body['data'] : $body;

            $summary = tareas_import_backup($pdo, $prefix, $backup);
            tareas_emit($pdo, 'all');
            tareas_ok(['message' => 'Importación completada.', 'imported' => $summary]);

        default:
            tareas_error('Acción no reconocida.', 404);
    }
} catch (PDOException $e) {
    error_log('[tareas/data] ' . $e->getMessage());
    tareas_error('Error de base de datos.', 500);
}

// ── Exportación ──────────────────────────────────────────────

/** Arma el backup completo y lo envía como descarga JSON. */
function tareas_export_json(PDO $pdo, string $prefix): never
{
    $tagNamesByOwner = static function (PDO $pdo, string $pivot, string $col, string $prefix): array {
        $stmt = $pdo->query(
            "SELECT p.`{$col}` AS owner_id, t.name
               FROM `{$pivot}` p JOIN `{$prefix}tags` t ON t.id = p.tag_id
              ORDER BY t.name ASC"
        );
        $map = [];
        foreach ($stmt->fetchAll() as $r) {
            $map[(int) $r['owner_id']][] = $r['name'];
        }
        return $map;
    };

    $tags = $pdo->query("SELECT name, color FROM `{$prefix}tags` ORDER BY name ASC")->fetchAll();

    $ideaTags = $tagNamesByOwner($pdo, "{$prefix}idea_tags", 'idea_id', $prefix);
    $ideas = [];
    foreach ($pdo->query(
        "SELECT id, content, accent, is_pinned, status, sort_order, created_at
           FROM `{$prefix}ideas` ORDER BY id ASC"
    )->fetchAll() as $row) {
        $ideas[] = [
            'content'    => $row['content'],
            'accent'     => $row['accent'],
            'is_pinned'  => (int) $row['is_pinned'],
            'status'     => $row['status'],
            'sort_order' => (int) $row['sort_order'],
            'created_at' => $row['created_at'],
            'tags'       => $ideaTags[(int) $row['id']] ?? [],
        ];
    }

    $misionTags = $tagNamesByOwner($pdo, "{$prefix}mision_tags", 'mision_id', $prefix);

    $checklists = [];
    foreach ($pdo->query(
        "SELECT mision_id, content, is_done, sort_order
           FROM `{$prefix}checklist_items` ORDER BY mision_id ASC, sort_order ASC, id ASC"
    )->fetchAll() as $row) {
        $checklists[(int) $row['mision_id']][] = [
            'content'    => $row['content'],
            'is_done'    => (int) $row['is_done'],
            'sort_order' => (int) $row['sort_order'],
        ];
    }

    $comments = [];
    foreach ($pdo->query(
        "SELECT mision_id, body, created_at
           FROM `{$prefix}comments` ORDER BY mision_id ASC, created_at ASC, id ASC"
    )->fetchAll() as $row) {
        $comments[(int) $row['mision_id']][] = [
            'body'       => $row['body'],
            'created_at' => $row['created_at'],
        ];
    }

    $misiones = [];
    foreach ($pdo->query(
        "SELECT id, title, description, status, priority, accent, due_date, recurrence, sort_order, completed_at, created_at
           FROM `{$prefix}misiones` ORDER BY id ASC"
    )->fetchAll() as $row) {
        $mid = (int) $row['id'];
        $misiones[] = [
            'title'        => $row['title'],
            'description'  => $row['description'],
            'status'       => $row['status'],
            'priority'     => $row['priority'],
            'accent'       => $row['accent'],
            'due_date'     => $row['due_date'],
            'recurrence'   => $row['recurrence'],
            'sort_order'   => (int) $row['sort_order'],
            'completed_at' => $row['completed_at'],
            'created_at'   => $row['created_at'],
            'checklist'    => $checklists[$mid] ?? [],
            'comments'     => $comments[$mid] ?? [],
            'tags'         => $misionTags[$mid] ?? [],
        ];
    }

    $templates = [];
    $tplItems = [];
    foreach ($pdo->query(
        "SELECT template_id, content FROM `{$prefix}template_items` ORDER BY template_id ASC, sort_order ASC, id ASC"
    )->fetchAll() as $row) {
        $tplItems[(int) $row['template_id']][] = $row['content'];
    }
    foreach ($pdo->query(
        "SELECT id, name, title, description, priority, accent FROM `{$prefix}templates` ORDER BY id ASC"
    )->fetchAll() as $row) {
        $templates[] = [
            'name'        => $row['name'],
            'title'       => $row['title'],
            'description' => $row['description'],
            'priority'    => $row['priority'],
            'accent'      => $row['accent'],
            'items'       => $tplItems[(int) $row['id']] ?? [],
        ];
    }

    $payload = [
        'meta' => [
            'app'         => 'tareas',
            'version'     => TAREAS_BACKUP_VERSION,
            'exported_at' => gmdate('c'),
        ],
        'tags'      => $tags,
        'ideas'     => $ideas,
        'misiones'  => $misiones,
        'templates' => $templates,
    ];

    $filename = 'tareas-backup-' . gmdate('Ymd-His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/** Exporta misiones o ideas como CSV plano. */
function tareas_export_csv(PDO $pdo, string $prefix, string $scope): never
{
    $filename = "tareas-{$scope}-" . gmdate('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    // BOM para que Excel reconozca UTF-8.
    fwrite($out, "\xEF\xBB\xBF");

    if ($scope === 'ideas') {
        fputcsv($out, ['id', 'contenido', 'acento', 'fijada', 'estado', 'creada']);
        foreach ($pdo->query(
            "SELECT id, content, accent, is_pinned, status, created_at
               FROM `{$prefix}ideas` ORDER BY id ASC"
        )->fetchAll() as $row) {
            fputcsv($out, [
                $row['id'], $row['content'], $row['accent'],
                $row['is_pinned'] ? 'sí' : 'no', $row['status'], $row['created_at'],
            ]);
        }
    } else {
        fputcsv($out, ['id', 'titulo', 'descripcion', 'estado', 'prioridad', 'vencimiento', 'recurrencia', 'avance', 'creada']);
        $progress = tareas_checklist_progress($pdo, $prefix);
        foreach ($pdo->query(
            "SELECT id, title, description, status, priority, due_date, recurrence, created_at
               FROM `{$prefix}misiones` ORDER BY id ASC"
        )->fetchAll() as $row) {
            $p = $progress[(int) $row['id']] ?? ['done' => 0, 'total' => 0];
            fputcsv($out, [
                $row['id'], $row['title'], $row['description'] ?? '', $row['status'], $row['priority'],
                $row['due_date'] ?? '', $row['recurrence'],
                $p['total'] > 0 ? "{$p['done']}/{$p['total']}" : '—',
                $row['created_at'],
            ]);
        }
    }

    fclose($out);
    exit;
}

/** Avance de checklist (done/total) por misión. */
function tareas_checklist_progress(PDO $pdo, string $prefix): array
{
    $rows = $pdo->query(
        "SELECT mision_id, COUNT(*) AS total, SUM(is_done) AS done
           FROM `{$prefix}checklist_items` GROUP BY mision_id"
    )->fetchAll();
    $map = [];
    foreach ($rows as $r) {
        $map[(int) $r['mision_id']] = ['done' => (int) $r['done'], 'total' => (int) $r['total']];
    }
    return $map;
}

// ── Importación ──────────────────────────────────────────────

/**
 * Importa un backup de forma aditiva y atómica. Devuelve un resumen de
 * cuántas entidades se crearon. Reutiliza etiquetas existentes por nombre.
 */
function tareas_import_backup(PDO $pdo, string $prefix, array $backup): array
{
    $summary = ['tags' => 0, 'ideas' => 0, 'misiones' => 0, 'templates' => 0];

    $pdo->beginTransaction();
    try {
        $tagIdByName = tareas_import_tags($pdo, $prefix, is_array($backup['tags'] ?? null) ? $backup['tags'] : [], $summary);

        // Ideas
        if (is_array($backup['ideas'] ?? null)) {
            $insertIdea = $pdo->prepare(
                "INSERT INTO `{$prefix}ideas` (content, accent, is_pinned, status, sort_order) VALUES (?, ?, ?, ?, ?)"
            );
            foreach ($backup['ideas'] as $idea) {
                if (!is_array($idea)) continue;
                $content = tareas_clean_text($idea['content'] ?? '', TAREAS_IDEA_MAXLEN);
                if ($content === '') continue;
                $accent = tareas_enum($idea['accent'] ?? '', TAREAS_ACCENTS, 'violet');
                $status = tareas_enum($idea['status'] ?? '', ['active', 'converted', 'archived'], 'active');
                // Las ideas convertidas pierden el vínculo (la misión no se referencia por id en el backup).
                if ($status === 'converted') {
                    $status = 'active';
                }
                $insertIdea->execute([
                    $content,
                    $accent,
                    !empty($idea['is_pinned']) ? 1 : 0,
                    $status,
                    (int) ($idea['sort_order'] ?? 0),
                ]);
                $ideaId = (int) $pdo->lastInsertId();
                tareas_link_tags_by_name($pdo, $prefix, 'idea', $ideaId, $idea['tags'] ?? [], $tagIdByName);
                $summary['ideas']++;
            }
        }

        // Misiones
        if (is_array($backup['misiones'] ?? null)) {
            $insertMis = $pdo->prepare(
                "INSERT INTO `{$prefix}misiones` (title, description, status, priority, accent, due_date, recurrence, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $insertItem = $pdo->prepare(
                "INSERT INTO `{$prefix}checklist_items` (mision_id, content, is_done, sort_order) VALUES (?, ?, ?, ?)"
            );
            $insertComment = $pdo->prepare(
                "INSERT INTO `{$prefix}comments` (mision_id, body) VALUES (?, ?)"
            );
            foreach ($backup['misiones'] as $mis) {
                if (!is_array($mis)) continue;
                $title = tareas_clean_text($mis['title'] ?? '', 160);
                if ($title === '') continue;
                $description = tareas_clean_text($mis['description'] ?? '', 4000);
                $insertMis->execute([
                    $title,
                    $description !== '' ? $description : null,
                    tareas_enum($mis['status'] ?? '', TAREAS_MIS_STATUSES, 'pendiente'),
                    tareas_enum($mis['priority'] ?? '', TAREAS_MIS_PRIORITIES, 'media'),
                    tareas_enum($mis['accent'] ?? '', TAREAS_ACCENTS, 'violet'),
                    tareas_due_date($mis['due_date'] ?? null),
                    tareas_enum($mis['recurrence'] ?? '', TAREAS_RECURRENCES, 'none'),
                    (int) ($mis['sort_order'] ?? 0),
                ]);
                $misId = (int) $pdo->lastInsertId();

                if (is_array($mis['checklist'] ?? null)) {
                    foreach ($mis['checklist'] as $pos => $item) {
                        $itemContent = is_array($item) ? tareas_clean_text($item['content'] ?? '', 255) : tareas_clean_text($item, 255);
                        if ($itemContent === '') continue;
                        $insertItem->execute([$misId, $itemContent, !empty($item['is_done']) ? 1 : 0, (int) ($item['sort_order'] ?? $pos)]);
                    }
                }
                if (is_array($mis['comments'] ?? null)) {
                    foreach ($mis['comments'] as $comment) {
                        $cbody = is_array($comment) ? tareas_clean_text($comment['body'] ?? '', 4000) : tareas_clean_text($comment, 4000);
                        if ($cbody === '') continue;
                        $insertComment->execute([$misId, $cbody]);
                    }
                }
                tareas_link_tags_by_name($pdo, $prefix, 'mision', $misId, $mis['tags'] ?? [], $tagIdByName);
                $summary['misiones']++;
            }
        }

        // Plantillas
        if (is_array($backup['templates'] ?? null)) {
            $insertTpl = $pdo->prepare(
                "INSERT INTO `{$prefix}templates` (name, title, description, priority, accent) VALUES (?, ?, ?, ?, ?)"
            );
            $insertTplItem = $pdo->prepare(
                "INSERT INTO `{$prefix}template_items` (template_id, content, sort_order) VALUES (?, ?, ?)"
            );
            foreach ($backup['templates'] as $tpl) {
                if (!is_array($tpl)) continue;
                $name = tareas_clean_text($tpl['name'] ?? '', 80);
                if ($name === '') continue;
                $description = tareas_clean_text($tpl['description'] ?? '', 4000);
                $insertTpl->execute([
                    $name,
                    tareas_clean_text($tpl['title'] ?? '', 160),
                    $description !== '' ? $description : null,
                    tareas_enum($tpl['priority'] ?? '', TAREAS_MIS_PRIORITIES, 'media'),
                    tareas_enum($tpl['accent'] ?? '', TAREAS_ACCENTS, 'violet'),
                ]);
                $tplId = (int) $pdo->lastInsertId();
                if (is_array($tpl['items'] ?? null)) {
                    foreach ($tpl['items'] as $pos => $item) {
                        $itemContent = is_array($item) ? tareas_clean_text($item['content'] ?? '', 255) : tareas_clean_text($item, 255);
                        if ($itemContent === '') continue;
                        $insertTplItem->execute([$tplId, $itemContent, (int) $pos]);
                    }
                }
                $summary['templates']++;
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $summary;
}

/** Crea/reutiliza etiquetas por nombre; devuelve mapa nombre→id. */
function tareas_import_tags(PDO $pdo, string $prefix, array $tags, array &$summary): array
{
    $map = [];
    foreach ($pdo->query("SELECT id, name FROM `{$prefix}tags`")->fetchAll() as $row) {
        $map[tareas_fold($row['name'])] = (int) $row['id'];
    }

    $insert = $pdo->prepare("INSERT INTO `{$prefix}tags` (name, color) VALUES (?, ?)");
    foreach ($tags as $tag) {
        if (!is_array($tag)) continue;
        $name = tareas_clean_text($tag['name'] ?? '', 40);
        if ($name === '' || isset($map[tareas_fold($name)])) {
            continue;
        }
        $color = is_string($tag['color'] ?? null) ? substr($tag['color'], 0, 20) : 'violet';
        $insert->execute([$name, $color]);
        $map[tareas_fold($name)] = (int) $pdo->lastInsertId();
        $summary['tags']++;
    }
    return $map;
}

/** Vincula una entidad a etiquetas por nombre, creando las que falten. */
function tareas_link_tags_by_name(PDO $pdo, string $prefix, string $type, int $ownerId, mixed $names, array &$tagIdByName): void
{
    if (!is_array($names) || !$names) {
        return;
    }
    [$pivot, $col] = tareas_tag_pivot($prefix, $type);
    $createTag = $pdo->prepare("INSERT INTO `{$prefix}tags` (name, color) VALUES (?, 'violet')");
    $link = $pdo->prepare("INSERT IGNORE INTO `{$pivot}` (`{$col}`, tag_id) VALUES (?, ?)");

    foreach ($names as $rawName) {
        $name = tareas_clean_text(is_string($rawName) ? $rawName : '', 40);
        if ($name === '') continue;
        $key = tareas_fold($name);
        if (!isset($tagIdByName[$key])) {
            $createTag->execute([$name]);
            $tagIdByName[$key] = (int) $pdo->lastInsertId();
        }
        $link->execute([$ownerId, $tagIdByName[$key]]);
    }
}

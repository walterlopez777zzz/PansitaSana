<?php
/**
 * ============================================================
 * Tareas — API de Colaboradores
 * ============================================================
 * Endpoints (?action=...):
 *   GET  list    → colaboradores activos
 *   POST create  → nuevo colaborador
 *   POST update  → editar nombre / color
 *   POST archive → archivar (las misiones quedan sin asignar)
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';

$pdo    = TareasDB::connect();
$prefix = tareas_prefix();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$body   = $method === 'POST' ? tareas_read_json_body() : [];

/** Valida un color hex (#rgb o #rrggbb); si no, usa el default. */
function tareas_color(mixed $raw, string $default = '#6c63ff'): string
{
    $value = is_string($raw) ? trim($raw) : '';
    return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) ? strtolower($value) : $default;
}

try {
    switch ($action) {

        case 'list':
            if ($method !== 'GET') {
                tareas_error("Usa GET para 'list'.", 405);
            }
            $stmt = $pdo->query(
                "SELECT id, name, color, created_at
                   FROM `{$prefix}colaboradores`
                  WHERE is_archived = 0
                  ORDER BY name ASC"
            );
            tareas_ok(['colaboradores' => $stmt->fetchAll()]);

        case 'create':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'create'.", 405);
            }

            $name  = tareas_clean_text($body['name'] ?? '', 60);
            $color = tareas_color($body['color'] ?? '');

            if ($name === '') {
                tareas_error('El nombre es obligatorio.', 400);
            }

            $stmt = $pdo->prepare("INSERT INTO `{$prefix}colaboradores` (name, color) VALUES (?, ?)");
            $stmt->execute([$name, $color]);
            $id = (int) $pdo->lastInsertId();

            tareas_emit($pdo, 'colaboradores');
            tareas_ok(['message' => 'Colaborador agregado.', 'colaborador' => tareas_fetch_colaborador($pdo, $prefix, $id)], 201);

        case 'update':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'update'.", 405);
            }

            $id    = tareas_int($body['id'] ?? 0);
            $name  = tareas_clean_text($body['name'] ?? '', 60);
            $color = tareas_color($body['color'] ?? '');

            if ($id === 0)   tareas_error('ID inválido.', 400);
            if ($name === '') tareas_error('El nombre es obligatorio.', 400);

            $stmt = $pdo->prepare("UPDATE `{$prefix}colaboradores` SET name = ?, color = ? WHERE id = ? AND is_archived = 0");
            $stmt->execute([$name, $color, $id]);

            if (!tareas_fetch_colaborador($pdo, $prefix, $id)) {
                tareas_error('Colaborador no encontrado.', 404);
            }

            tareas_emit($pdo, 'colaboradores');
            tareas_ok(['colaborador' => tareas_fetch_colaborador($pdo, $prefix, $id)]);

        case 'archive':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'archive'.", 405);
            }

            $id = tareas_int($body['id'] ?? 0);
            if ($id === 0) tareas_error('ID inválido.', 400);

            $stmt = $pdo->prepare("UPDATE `{$prefix}colaboradores` SET is_archived = 1 WHERE id = ? AND is_archived = 0");
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                tareas_error('Colaborador no encontrado.', 404);
            }

            tareas_emit($pdo, 'colaboradores');
            tareas_ok(['message' => 'Colaborador archivado.']);

        default:
            tareas_error('Acción no reconocida.', 404);
    }
} catch (PDOException $e) {
    error_log('[tareas/colaboradores] ' . $e->getMessage());
    tareas_error('Error de base de datos.', 500);
}

/** Trae un colaborador por id o null. */
function tareas_fetch_colaborador(PDO $pdo, string $prefix, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT id, name, color, created_at FROM `{$prefix}colaboradores` WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

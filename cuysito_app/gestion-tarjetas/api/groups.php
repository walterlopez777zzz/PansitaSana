<?php
/**
 * ============================================================
 * Card Manager — Groups API Controller
 * ============================================================
 * Endpoints (via ?action=...):
 *
 *   GET  ?action=list           → All groups ordered by sort_order
 *   POST ?action=create         → Create a new group
 *   POST ?action=update         → Edit an existing group
 *   POST ?action=delete         → Delete group (nullifies group_id in cards)
 *   POST ?action=reorder_groups → Batch-update sort_order for groups
 *   POST ?action=reorder_cards  → Batch-update card_sort_order for cards in a group
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/db.php';

const VALID_TYPES_GROUPS = ['dollarapp', 'astropay'];
const GROUP_COLUMNS = 'id, name, card_type, cardholder_name, cvu, bg_color, card_color, resets_remaining, monthly_resets_used, window_started_at, sort_order, created_at';

function jsonResponse(array $payload, int $httpCode = 200): never {
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $message, int $httpCode = 400): never {
    jsonResponse(['status' => 'error', 'message' => $message], $httpCode);
}

function readJsonBody(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw)) jsonError('Cuerpo de la solicitud vacío.', 400);
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) jsonError('JSON inválido: ' . json_last_error_msg(), 400);
    return $data;
}

function validateGroupName(string $raw): string {
    $name = mb_substr(trim($raw), 0, 80);
    if ($name === '') jsonError('El nombre del grupo es obligatorio.', 400);
    return $name;
}

function validateGroupType(string $raw): string {
    $type = strtolower(trim($raw));
    if (!in_array($type, VALID_TYPES_GROUPS, true)) {
        jsonError('Tipo inválido. Valores: ' . implode(', ', VALID_TYPES_GROUPS), 400);
    }
    return $type;
}

function sanitizeGroupCvu(string $raw, bool $required = false): string {
    $digits = preg_replace('/\D/', '', $raw);
    if ($required && $digits === '') {
        jsonError('El CVU del grupo es obligatorio.', 400);
    }
    if ($digits !== '' && (strlen($digits) < 6 || strlen($digits) > 22)) {
        jsonError('El CVU debe tener entre 6 y 22 dígitos.', 400);
    }
    return $digits;
}

/**
 * Validate and sanitize a hex color string (#RRGGBB).
 * Returns the normalized value or null if empty/invalid.
 */
function sanitizeHexColor(?string $raw): ?string {
    if ($raw === null || trim($raw) === '') return null;
    $hex = strtolower(trim($raw));
    // Accept #RGB or #RRGGBB and normalize to #RRGGBB
    if (preg_match('/^#([0-9a-f]{3})$/', $hex, $m)) {
        $hex = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
    }
    if (!preg_match('/^#[0-9a-f]{6}$/', $hex)) return null;
    return $hex;
}

try {
    $pdo    = Database::connect();
    $method = $_SERVER['REQUEST_METHOD'];
    $action = '';

    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';
    } elseif ($method === 'POST') {
        $body   = readJsonBody();
        $action = $body['action'] ?? '';
    } else {
        jsonError('Método HTTP no soportado.', 405);
    }

    switch ($action) {

        // ─── LIST ALL GROUPS ─────────────────────────────────
        case 'list':
            if ($method !== 'GET') jsonError("Usa GET para 'list'.", 405);

            // Runtime resilience: build column list based on which columns exist.
            // Each check is a cheap SHOW COLUMNS — avoids hard failures on un-run migrations.
            $hasBgColor   = count($pdo->query("SHOW COLUMNS FROM `card_manager_groups` LIKE 'bg_color'")->fetchAll())            > 0;
            $hasCardColor = count($pdo->query("SHOW COLUMNS FROM `card_manager_groups` LIKE 'card_color'")->fetchAll())          > 0;
            $hasResets    = count($pdo->query("SHOW COLUMNS FROM `card_manager_groups` LIKE 'resets_remaining'")->fetchAll())    > 0;
            $hasMonthly   = count($pdo->query("SHOW COLUMNS FROM `card_manager_groups` LIKE 'monthly_resets_used'")->fetchAll()) > 0;
            $hasWindow    = count($pdo->query("SHOW COLUMNS FROM `card_manager_groups` LIKE 'window_started_at'")->fetchAll())   > 0;

            if ($hasBgColor && $hasCardColor && $hasResets && $hasMonthly && $hasWindow) {
                $cols = GROUP_COLUMNS; // all columns present
            } elseif ($hasBgColor && $hasCardColor && $hasResets) {
                $cols = 'id, name, card_type, cardholder_name, cvu, bg_color, card_color, resets_remaining, sort_order, created_at';
            } elseif ($hasBgColor && $hasCardColor) {
                $cols = 'id, name, card_type, cardholder_name, cvu, bg_color, card_color, sort_order, created_at';
            } else {
                $cols = 'id, name, card_type, cardholder_name, cvu, sort_order, created_at';
            }

            $stmt = $pdo->query(
                "SELECT {$cols}
                 FROM card_manager_groups
                 ORDER BY sort_order ASC, created_at ASC"
            );
            $groups = $stmt->fetchAll();

            jsonResponse([
                'status' => 'success',
                'groups' => $groups,
            ]);

        // ─── CREATE GROUP ─────────────────────────────────────
        case 'create':
            if ($method !== 'POST') jsonError("Usa POST para 'create'.", 405);

            $name           = validateGroupName($body['name'] ?? '');
            $cardType       = validateGroupType($body['card_type'] ?? 'dollarapp');
            $cardholderName = mb_substr(trim($body['cardholder_name'] ?? ''), 0, 100);
            $cvu            = sanitizeGroupCvu($body['cvu'] ?? '', true); // required
            $bgColor        = sanitizeHexColor($body['bg_color'] ?? null);
            $cardColor      = sanitizeHexColor($body['card_color'] ?? null);

            // Assign sort_order = max + 1 to put new group at the end of the list
            $maxOrder = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), -1) FROM card_manager_groups')->fetchColumn();

            $resetsRemaining = 30;
            if (isset($body['resets_remaining'])) {
                $resetsRemaining = max(0, min(999, (int) $body['resets_remaining']));
            }
            // monthly_resets_used and window_started_at start at defaults — not user-settable on create
            $stmt = $pdo->prepare(
                'INSERT INTO card_manager_groups
                     (name, card_type, cardholder_name, cvu, bg_color, card_color, resets_remaining,
                      monthly_resets_used, window_started_at, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, NULL, ?)'
            );
            $stmt->execute([$name, $cardType, $cardholderName, $cvu, $bgColor, $cardColor, $resetsRemaining, $maxOrder + 1]);
            $newId = (int) $pdo->lastInsertId();

            $fetch = $pdo->prepare('SELECT ' . GROUP_COLUMNS . ' FROM card_manager_groups WHERE id = ?');
            $fetch->execute([$newId]);
            $group = $fetch->fetch();

            jsonResponse([
                'status'  => 'success',
                'message' => 'Grupo creado correctamente.',
                'group'   => $group,
            ], 201);

        // ─── UPDATE GROUP ─────────────────────────────────────
        case 'update':
            if ($method !== 'POST') jsonError("Usa POST para 'update'.", 405);

            $groupId = (int) ($body['id'] ?? 0);
            if ($groupId <= 0) jsonError('ID de grupo inválido.', 400);

            $check = $pdo->prepare('SELECT id FROM card_manager_groups WHERE id = ?');
            $check->execute([$groupId]);
            if (!$check->fetch()) jsonError('Grupo no encontrado.', 404);

            $name           = validateGroupName($body['name'] ?? '');
            $cardType       = validateGroupType($body['card_type'] ?? 'dollarapp');
            $cardholderName = mb_substr(trim($body['cardholder_name'] ?? ''), 0, 100);
            $cvu            = sanitizeGroupCvu($body['cvu'] ?? '', true); // required
            $bgColor        = sanitizeHexColor($body['bg_color'] ?? null);
            $cardColor      = sanitizeHexColor($body['card_color'] ?? null);

            $resetsRemaining  = null;
            $monthlyResetsUsed = null;
            $windowStartedAt  = false; // false = not provided, null = explicit reset

            if (isset($body['resets_remaining'])) {
                $resetsRemaining = max(0, min(999, (int) $body['resets_remaining']));
            }
            if (isset($body['monthly_resets_used'])) {
                $monthlyResetsUsed = max(0, min(10, (int) $body['monthly_resets_used']));
            }
            // window_started_at: accept ISO datetime string, 'reset' shortcut, or null to clear
            if (array_key_exists('window_started_at', $body)) {
                $raw = $body['window_started_at'];
                if ($raw === null || $raw === '') {
                    $windowStartedAt = null; // clear the window
                } elseif ($raw === 'now' || $raw === 'reset') {
                    $windowStartedAt = 'UTC_TIMESTAMP()'; // special marker
                } else {
                    // Validate it's a parseable datetime
                    try {
                        $dt = new DateTimeImmutable($raw, new DateTimeZone('UTC'));
                        $windowStartedAt = $dt->format('Y-m-d H:i:s');
                    } catch (Throwable $e) {
                        jsonError('window_started_at debe ser un datetime ISO válido, "reset" o null.', 400);
                    }
                }
            }

            $sql    = 'UPDATE card_manager_groups SET name = ?, card_type = ?, cardholder_name = ?, cvu = ?, bg_color = ?, card_color = ?';
            $params = [$name, $cardType, $cardholderName, $cvu, $bgColor, $cardColor];

            if ($resetsRemaining !== null) {
                $sql .= ', resets_remaining = ?'; $params[] = $resetsRemaining;
            }
            if ($monthlyResetsUsed !== null) {
                $sql .= ', monthly_resets_used = ?'; $params[] = $monthlyResetsUsed;
            }
            if ($windowStartedAt !== false) {
                if ($windowStartedAt === 'UTC_TIMESTAMP()') {
                    $sql .= ', window_started_at = UTC_TIMESTAMP()';
                } else {
                    $sql .= ', window_started_at = ?'; $params[] = $windowStartedAt;
                }
            }

            $sql .= ' WHERE id = ?';
            $params[] = $groupId;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $fetch = $pdo->prepare('SELECT ' . GROUP_COLUMNS . ' FROM card_manager_groups WHERE id = ?');
            $fetch->execute([$groupId]);
            $group = $fetch->fetch();

            jsonResponse([
                'status'  => 'success',
                'message' => 'Grupo actualizado correctamente.',
                'group'   => $group,
            ]);

        // ─── DELETE GROUP ─────────────────────────────────────
        case 'delete':
            if ($method !== 'POST') jsonError("Usa POST para 'delete'.", 405);

            $groupId = (int) ($body['id'] ?? 0);
            if ($groupId <= 0) jsonError('ID de grupo inválido.', 400);

            // Nullify group_id and reset card_sort_order for all cards in this group
            $unlink = $pdo->prepare(
                'UPDATE card_manager_cards
                 SET group_id = NULL, card_sort_order = 0
                 WHERE group_id = ?'
            );
            $unlink->execute([$groupId]);

            $del = $pdo->prepare('DELETE FROM card_manager_groups WHERE id = ?');
            $del->execute([$groupId]);

            if ($del->rowCount() === 0) jsonError('Grupo no encontrado.', 404);

            jsonResponse([
                'status'  => 'success',
                'message' => 'Grupo eliminado correctamente.',
            ]);

        // ─── REORDER GROUPS ───────────────────────────────────
        case 'reorder_groups':
            if ($method !== 'POST') jsonError("Usa POST para 'reorder_groups'.", 405);

            $items = $body['items'] ?? [];
            if (!is_array($items) || count($items) === 0) {
                jsonError('Se requiere un array de items.', 400);
            }

            $stmt = $pdo->prepare('UPDATE card_manager_groups SET sort_order = ? WHERE id = ?');
            foreach ($items as $item) {
                $id    = (int) ($item['id'] ?? 0);
                $order = (int) ($item['sort_order'] ?? 0);
                if ($id <= 0) continue;
                $stmt->execute([$order, $id]);
            }

            $hasCols = count($pdo->query("SHOW COLUMNS FROM `card_manager_groups` LIKE 'bg_color'")->fetchAll()) > 0;
            $cols    = $hasCols ? GROUP_COLUMNS : 'id, name, card_type, cardholder_name, cvu, sort_order, created_at';

            $fetch  = $pdo->query("SELECT {$cols} FROM card_manager_groups ORDER BY sort_order ASC, created_at ASC");
            $groups = $fetch->fetchAll();

            jsonResponse([
                'status'  => 'success',
                'message' => 'Grupos reordenados.',
                'groups'  => $groups,
            ]);

        // ─── REORDER CARDS WITHIN GROUP ──────────────────────
        case 'reorder_cards':
            if ($method !== 'POST') jsonError("Usa POST para 'reorder_cards'.", 405);

            $items = $body['items'] ?? [];
            if (!is_array($items) || count($items) === 0) {
                jsonError('Se requiere un array de items.', 400);
            }

            $stmt = $pdo->prepare(
                'UPDATE card_manager_cards
                 SET card_sort_order = ?
                 WHERE id = ? AND is_archived = 0'
            );
            foreach ($items as $item) {
                $id    = (int) ($item['id'] ?? 0);
                $order = (int) ($item['card_sort_order'] ?? 0);
                if ($id <= 0) continue;
                $stmt->execute([$order, $id]);
            }

            jsonResponse([
                'status'  => 'success',
                'message' => 'Tarjetas reordenadas.',
            ]);

        // ─── UNKNOWN ACTION ──────────────────────────────────
        default:
            jsonError("Acción '{$action}' no reconocida.", 404);
    }

} catch (PDOException $e) {
    error_log('[CardGroups API] DB Error: ' . $e->getMessage());
    jsonError('Error interno del servidor.', 500);
} catch (Throwable $e) {
    error_log('[CardGroups API] Error: ' . $e->getMessage());
    $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? (int) $e->getCode() : 500;
    jsonError($e->getMessage(), $code);
}

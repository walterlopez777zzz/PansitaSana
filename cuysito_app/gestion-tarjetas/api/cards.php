<?php
/**
 * ============================================================
 * Card Manager — RESTful API Controller
 * ============================================================
 * Endpoints (via ?action=...):
 *
 *   GET  ?action=list            → All active cards
 *   GET  ?action=server_time     → Current UTC server timestamp
 *   POST ?action=create          → Add a new card
 *   POST ?action=mark_use        → Mark one use of a card
 *   POST ?action=mark_reset      → Mark a reset (restore card to 5 uses)
 *   POST ?action=delete          → Soft-archive a card
 *   POST ?action=undo_mark_use   → Revert a marked use (restore prev state)
 *   POST ?action=undo_mark_reset → Revert a marked reset (restore prev state)
 *   POST ?action=undo_delete     → Un-archive a card
 *   POST ?action=update          → Update editable card fields
 *   POST ?action=bulk_delete     → Soft-archive multiple cards
 *   POST ?action=undo_bulk_delete → Un-archive multiple cards
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/db.php';

// ── Constants ────────────────────────────────────────────────

const MAX_USES         = 5;
const COOLDOWN_SECONDS = 86400;
const VALID_CARD_TYPES = ['dollarapp', 'astropay'];

/**
 * Shared SELECT column list — built at runtime based on which columns exist.
 * This makes the API resilient to un-run migrations.
 */
const CARD_COLUMNS_BASE = 'id, card_number, cvu, cvc, expiration_date, card_type, cardholder_name, uses_remaining, last_used_at, created_at';
const CARD_COLUMNS_EXT  = 'id, card_number, cvu, cvc, expiration_date, card_type, cardholder_name, group_id, card_sort_order, uses_remaining, last_used_at, created_at';

// ── Helpers ──────────────────────────────────────────────────

function jsonResponse(array $payload, int $httpCode = 200): never
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $message, int $httpCode = 400): never
{
    jsonResponse(['status' => 'error', 'message' => $message], $httpCode);
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');

    if (empty($raw)) {
        jsonError('Cuerpo de la solicitud vacío.', 400);
    }

    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonError('JSON inválido: ' . json_last_error_msg(), 400);
    }

    return $data;
}

function sanitizeCardNumber(string $raw): string
{
    $digits = preg_replace('/\D/', '', $raw);

    if (strlen($digits) < 13 || strlen($digits) > 16) {
        jsonError('El número de tarjeta debe tener entre 13 y 16 dígitos.', 400);
    }

    return $digits;
}

function validateCardholderName(string $raw): string
{
    $name = mb_substr(trim($raw), 0, 100);

    if ($name === '') {
        jsonError('El nombre del titular es obligatorio.', 400);
    }

    return $name;
}

function validateCardType(string $raw): string
{
    $type = strtolower(trim($raw));

    if (!in_array($type, VALID_CARD_TYPES, true)) {
        jsonError("Tipo de tarjeta inválido. Valores permitidos: " . implode(', ', VALID_CARD_TYPES) . '.', 400);
    }

    return $type;
}

function sanitizeCvu(string $raw): string
{
    $digits = preg_replace('/\D/', '', $raw);

    if ($digits === '') {
        return '';
    }

    if (strlen($digits) < 6 || strlen($digits) > 22) {
        jsonError('El CVU debe tener entre 6 y 22 dígitos.', 400);
    }

    return $digits;
}

function sanitizeCvc(string $raw): string
{
    $digits = preg_replace('/\D/', '', $raw);

    if (strlen($digits) < 3 || strlen($digits) > 4) {
        jsonError('El CVC es obligatorio y debe tener 3 o 4 dígitos.', 400);
    }

    return $digits;
}

function validateExpirationDate(string $raw): string
{
    $digits = preg_replace('/\D/', '', $raw);

    if (strlen($digits) !== 4) {
        jsonError('La fecha de vencimiento es obligatoria (formato MM/AA).', 400);
    }

    $month = (int) substr($digits, 0, 2);

    if ($month < 1 || $month > 12) {
        jsonError('Mes de vencimiento inválido (01-12).', 400);
    }

    return substr($digits, 0, 2) . '/' . substr($digits, 2, 2);
}

// ── Router ───────────────────────────────────────────────────

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

        // ─── LIST ALL ACTIVE CARDS ───────────────────────────
        case 'list':
            if ($method !== 'GET') {
                jsonError("Usa GET para 'list'.", 405);
            }

            // Runtime column detection — resilient to un-run migrations
            $hasSortOrder = count($pdo->query("SHOW COLUMNS FROM `card_manager_cards` LIKE 'card_sort_order'")->fetchAll()) > 0;
            $hasGroupId   = count($pdo->query("SHOW COLUMNS FROM `card_manager_cards` LIKE 'group_id'")->fetchAll()) > 0;

            $listColumns  = $hasGroupId ? CARD_COLUMNS_EXT : CARD_COLUMNS_BASE;
            $orderClause  = $hasSortOrder
                ? 'ORDER BY card_sort_order ASC, created_at DESC'
                : 'ORDER BY created_at DESC';

            $stmt = $pdo->query(
                "SELECT {$listColumns}
                 FROM card_manager_cards
                 WHERE is_archived = 0
                 {$orderClause}"
            );

            $cards     = $stmt->fetchAll();
            $serverNow = gmdate('Y-m-d\TH:i:s\Z');

            jsonResponse([
                'status'      => 'success',
                'server_time' => $serverNow,
                'cards'       => $cards,
            ]);

        // ─── SERVER TIME ─────────────────────────────────────
        case 'server_time':
            if ($method !== 'GET') {
                jsonError("Usa GET para 'server_time'.", 405);
            }

            jsonResponse([
                'status'      => 'success',
                'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);

        // ─── CREATE CARD ─────────────────────────────────────
        case 'create':
            if ($method !== 'POST') {
                jsonError("Usa POST para 'create'.", 405);
            }

            $cardNumber     = sanitizeCardNumber($body['card_number'] ?? '');
            $cvu            = sanitizeCvu($body['cvu'] ?? '');
            $cvc            = sanitizeCvc($body['cvc'] ?? '');
            $expirationDate = validateExpirationDate($body['expiration_date'] ?? '');
            $cardType       = validateCardType($body['card_type'] ?? '');
            $cardholderName = validateCardholderName($body['cardholder_name'] ?? '');
            $groupId = isset($body['group_id']) && $body['group_id'] !== null ? (int) $body['group_id'] : null;
            if ($groupId !== null && $groupId <= 0) $groupId = null;

            $stmt = $pdo->prepare(
                'INSERT INTO card_manager_cards (card_number, cvu, cvc, expiration_date, card_type, cardholder_name, group_id, uses_remaining)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$cardNumber, $cvu, $cvc, $expirationDate, $cardType, $cardholderName, $groupId, MAX_USES]);
            $newId = (int) $pdo->lastInsertId();

            // ── Auto-decrement group resets (monthly window, DollarApp only) ──
            // Rules:
            //  1. Only for DollarApp groups.
            //  2. Only when the group has > 3 active cards (first 3 are the base set).
            //  3. Max 10 resets per 30-day window; max 30 total (resets_remaining).
            //  4. If the window has never started or has expired, open a new one.
            //  5. The UPDATE uses conditional logic to prevent going below 0.
            $updatedGroup = null;
            if ($groupId !== null) {
                try {
                    $grp = $pdo->prepare(
                        'SELECT card_type, resets_remaining, monthly_resets_used, window_started_at
                           FROM card_manager_groups WHERE id = ?'
                    );
                    $grp->execute([$groupId]);
                    $groupRow = $grp->fetch();

                    if ($groupRow && $groupRow['card_type'] === 'dollarapp') {
                        $cnt = $pdo->prepare(
                            'SELECT COUNT(*) FROM card_manager_cards WHERE group_id = ? AND is_archived = 0'
                        );
                        $cnt->execute([$groupId]);
                        $totalCards = (int) $cnt->fetchColumn();

                        // Only apply limits from card #4 onwards
                        if ($totalCards > 3) {
                            $resetsRemaining = (int) $groupRow['resets_remaining'];
                            $monthlyUsed     = (int) $groupRow['monthly_resets_used'];
                            $windowStart     = $groupRow['window_started_at'];
                            $now             = new DateTimeImmutable('now', new DateTimeZone('UTC'));

                            // Determine if current window is active or expired/unstarted
                            $windowExpired = true;
                            if ($windowStart !== null) {
                                $windowDT    = new DateTimeImmutable($windowStart, new DateTimeZone('UTC'));
                                $elapsedSecs = $now->getTimestamp() - $windowDT->getTimestamp();
                                $windowExpired = ($elapsedSecs >= 30 * 86400);
                            }

                            if ($windowExpired) {
                                // Open new window — reset monthly counter
                                $monthlyUsed = 0;
                                $pdo->prepare(
                                    'UPDATE card_manager_groups
                                       SET monthly_resets_used = 0, window_started_at = UTC_TIMESTAMP()
                                     WHERE id = ?'
                                )->execute([$groupId]);
                            }

                            // Decrement within both caps (atomic: resets_remaining > 0 guard in SQL)
                            if ($monthlyUsed < 10 && $resetsRemaining > 0) {
                                $pdo->prepare(
                                    'UPDATE card_manager_groups
                                       SET resets_remaining    = resets_remaining    - 1,
                                           monthly_resets_used = monthly_resets_used + 1
                                     WHERE id = ? AND resets_remaining > 0'
                                )->execute([$groupId]);
                            }
                        }

                        // Return the refreshed group data so the frontend doesn't need a separate reload
                        $gFetch = $pdo->prepare(
                            'SELECT id, resets_remaining, monthly_resets_used, window_started_at
                               FROM card_manager_groups WHERE id = ?'
                        );
                        $gFetch->execute([$groupId]);
                        $updatedGroup = $gFetch->fetch();
                    }
                } catch (PDOException $_) {
                    // Columns may not exist yet (migration not run) — silently skip
                }
            }

            $fetch = $pdo->prepare('SELECT ' . CARD_COLUMNS_EXT . ' FROM card_manager_cards WHERE id = ?');
            $fetch->execute([$newId]);
            $card = $fetch->fetch();

            $response = [
                'status'      => 'success',
                'message'     => 'Tarjeta agregada correctamente.',
                'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
                'card'        => $card,
            ];
            if ($updatedGroup !== null) {
                $response['updated_group'] = $updatedGroup;
            }
            jsonResponse($response, 201);


        // ─── MARK USE ────────────────────────────────────────
        case 'mark_use':
            if ($method !== 'POST') {
                jsonError("Usa POST para 'mark_use'.", 405);
            }

            $cardId = (int) ($body['id'] ?? 0);

            if ($cardId <= 0) {
                jsonError('ID de tarjeta inválido.', 400);
            }

            $stmt = $pdo->prepare(
                'SELECT id, uses_remaining, last_used_at
                 FROM card_manager_cards
                 WHERE id = ? AND is_archived = 0'
            );
            $stmt->execute([$cardId]);
            $card = $stmt->fetch();

            if (!$card) {
                jsonError('Tarjeta no encontrada.', 404);
            }

            if ((int) $card['uses_remaining'] <= 0) {
                jsonError('La tarjeta no tiene usos disponibles.', 422);
            }

            if ($card['last_used_at'] !== null) {
                $lastUsed = new DateTimeImmutable($card['last_used_at'], new DateTimeZone('UTC'));
                $now      = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $elapsed  = $now->getTimestamp() - $lastUsed->getTimestamp();

                if ($elapsed < COOLDOWN_SECONDS) {
                    $remaining = COOLDOWN_SECONDS - $elapsed;
                    jsonError("Cooldown activo. Faltan {$remaining} segundos.", 429);
                }
            }

            $update = $pdo->prepare(
                'UPDATE card_manager_cards
                 SET uses_remaining = uses_remaining - 1,
                     last_used_at   = UTC_TIMESTAMP()
                 WHERE id = ? AND is_archived = 0'
            );
            $update->execute([$cardId]);

            $fetch = $pdo->prepare('SELECT ' . CARD_COLUMNS_EXT . ' FROM card_manager_cards WHERE id = ?');
            $fetch->execute([$cardId]);
            $updated = $fetch->fetch();

            jsonResponse([
                'status'      => 'success',
                'message'     => 'Uso marcado correctamente.',
                'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
                'card'        => $updated,
            ]);

        // ─── MARK RESET ──────────────────────────────────────
        case 'mark_reset':
            if ($method !== 'POST') {
                jsonError("Usa POST para 'mark_reset'.", 405);
            }

            $cardId = (int) ($body['id'] ?? 0);

            if ($cardId <= 0) {
                jsonError('ID de tarjeta inválido.', 400);
            }

            $stmt = $pdo->prepare(
                'SELECT id, uses_remaining, last_used_at
                 FROM card_manager_cards
                 WHERE id = ? AND is_archived = 0'
            );
            $stmt->execute([$cardId]);
            $card = $stmt->fetch();

            if (!$card) {
                jsonError('Tarjeta no encontrada.', 404);
            }

            if ((int) $card['uses_remaining'] === MAX_USES && $card['last_used_at'] === null) {
                jsonError('La tarjeta ya está en su estado original.', 422);
            }

            $update = $pdo->prepare(
                'UPDATE card_manager_cards
                 SET uses_remaining = ?,
                     last_used_at   = NULL
                 WHERE id = ? AND is_archived = 0'
            );
            $update->execute([MAX_USES, $cardId]);

            $fetch = $pdo->prepare('SELECT ' . CARD_COLUMNS_EXT . ' FROM card_manager_cards WHERE id = ?');
            $fetch->execute([$cardId]);
            $updated = $fetch->fetch();

            jsonResponse([
                'status'      => 'success',
                'message'     => 'Reinicio marcado correctamente.',
                'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
                'card'        => $updated,
            ]);

        // ─── DELETE (ARCHIVE) CARD ───────────────────────────
        case 'delete':
            if ($method !== 'POST') {
                jsonError("Usa POST para 'delete'.", 405);
            }

            $cardId = (int) ($body['id'] ?? 0);

            if ($cardId <= 0) {
                jsonError('ID de tarjeta inválido.', 400);
            }

            $stmt = $pdo->prepare(
                'UPDATE card_manager_cards
                 SET is_archived = 1
                 WHERE id = ? AND is_archived = 0'
            );
            $stmt->execute([$cardId]);

            if ($stmt->rowCount() === 0) {
                jsonError('Tarjeta no encontrada o ya archivada.', 404);
            }

            jsonResponse([
                'status'      => 'success',
                'message'     => 'Tarjeta archivada correctamente.',
                'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);

        // ─── UNDO MARK USE / UNDO MARK RESET ─────────────────
        case 'undo_mark_use':
        case 'undo_mark_reset':
            if ($method !== 'POST') {
                jsonError("Usa POST para '{$action}'.", 405);
            }

            $cardId       = (int) ($body['id'] ?? 0);
            $prevUses     = isset($body['prev_uses_remaining']) ? (int) $body['prev_uses_remaining'] : null;
            $prevLastUsed = $body['prev_last_used_at'] ?? '__UNSET__';

            if ($cardId <= 0) {
                jsonError('ID de tarjeta inválido.', 400);
            }

            if ($prevUses === null || $prevUses < 0 || $prevUses > MAX_USES) {
                jsonError('prev_uses_remaining inválido.', 400);
            }

            $check = $pdo->prepare('SELECT id FROM card_manager_cards WHERE id = ? AND is_archived = 0');
            $check->execute([$cardId]);

            if (!$check->fetch()) {
                jsonError('Tarjeta no encontrada.', 404);
            }

            if ($prevLastUsed === null || $prevLastUsed === '__UNSET__') {
                $update = $pdo->prepare(
                    'UPDATE card_manager_cards
                     SET uses_remaining = ?, last_used_at = NULL
                     WHERE id = ? AND is_archived = 0'
                );
                $update->execute([$prevUses, $cardId]);
            } else {
                $update = $pdo->prepare(
                    'UPDATE card_manager_cards
                     SET uses_remaining = ?, last_used_at = ?
                     WHERE id = ? AND is_archived = 0'
                );
                $update->execute([$prevUses, $prevLastUsed, $cardId]);
            }

            $fetch = $pdo->prepare('SELECT ' . CARD_COLUMNS_EXT . ' FROM card_manager_cards WHERE id = ?');
            $fetch->execute([$cardId]);
            $restored = $fetch->fetch();

            jsonResponse([
                'status'      => 'success',
                'message'     => 'Estado anterior restaurado.',
                'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
                'card'        => $restored,
            ]);

        // ─── UNDO DELETE (Un-archive a card) ─────────────────
        case 'undo_delete':
            if ($method !== 'POST') {
                jsonError("Usa POST para 'undo_delete'.", 405);
            }

            $cardId = (int) ($body['id'] ?? 0);

            if ($cardId <= 0) {
                jsonError('ID de tarjeta inválido.', 400);
            }

            $stmt = $pdo->prepare(
                'UPDATE card_manager_cards
                 SET is_archived = 0
                 WHERE id = ? AND is_archived = 1'
            );
            $stmt->execute([$cardId]);

            if ($stmt->rowCount() === 0) {
                jsonError('Tarjeta no encontrada o no está archivada.', 404);
            }

            $fetch = $pdo->prepare('SELECT ' . CARD_COLUMNS_EXT . ' FROM card_manager_cards WHERE id = ?');
            $fetch->execute([$cardId]);
            $card = $fetch->fetch();

            jsonResponse([
                'status'      => 'success',
                'message'     => 'Tarjeta restaurada correctamente.',
                'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
                'card'        => $card,
            ]);

        // ─── UPDATE CARD ─────────────────────────────────────
        case 'update':
            if ($method !== 'POST') {
                jsonError("Usa POST para 'update'.", 405);
            }

            $cardId         = (int) ($body['id'] ?? 0);
            $cardNumber     = sanitizeCardNumber($body['card_number'] ?? '');
            $cvu            = sanitizeCvu($body['cvu'] ?? '');
            $cvc            = sanitizeCvc($body['cvc'] ?? '');
            $expirationDate = validateExpirationDate($body['expiration_date'] ?? '');
            $cardType       = validateCardType($body['card_type'] ?? '');
            $cardholderName = validateCardholderName($body['cardholder_name'] ?? '');
            $groupId = array_key_exists('group_id', $body)
                ? (($body['group_id'] !== null && (int)$body['group_id'] > 0) ? (int)$body['group_id'] : null)
                : '__UNSET__';

            // Optional state fields — only modify if explicitly provided
            $hasUsesRemaining = array_key_exists('uses_remaining', $body);
            $hasLastUsedAt    = array_key_exists('last_used_at', $body);

            if ($hasUsesRemaining) {
                $usesRemaining = (int) $body['uses_remaining'];
                if ($usesRemaining < 0 || $usesRemaining > MAX_USES) {
                    jsonError('uses_remaining debe estar entre 0 y ' . MAX_USES . '.', 400);
                }
            }

            if ($hasLastUsedAt) {
                $lastUsedAt = $body['last_used_at'];
                if ($lastUsedAt !== null) {
                    // Validate it's a parseable datetime
                    $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $lastUsedAt, new DateTimeZone('UTC'));
                    if (!$parsed) {
                        $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $lastUsedAt, new DateTimeZone('UTC'));
                    }
                    if (!$parsed) {
                        jsonError('last_used_at debe ser un datetime UTC válido o null.', 400);
                    }
                    $lastUsedAt = $parsed->format('Y-m-d H:i:s');
                }
            }

            if ($cardId <= 0) {
                jsonError('ID de tarjeta inválido.', 400);
            }

            $check = $pdo->prepare('SELECT id FROM card_manager_cards WHERE id = ? AND is_archived = 0');
            $check->execute([$cardId]);

            if (!$check->fetch()) {
                jsonError('Tarjeta no encontrada.', 404);
            }

            $setClauses = [
                'card_number = ?',
                'cvu = ?',
                'cvc = ?',
                'expiration_date = ?',
                'card_type = ?',
                'cardholder_name = ?',
            ];
            $params = [$cardNumber, $cvu, $cvc, $expirationDate, $cardType, $cardholderName];

            if ($groupId !== '__UNSET__') {
                if ($groupId === null) {
                    $setClauses[] = 'group_id = NULL';
                } else {
                    $setClauses[] = 'group_id = ?';
                    $params[]     = $groupId;
                }
            }

            if ($hasUsesRemaining) {
                $setClauses[] = 'uses_remaining = ?';
                $params[]     = $usesRemaining;
            }

            if ($hasLastUsedAt) {
                if ($lastUsedAt === null) {
                    $setClauses[] = 'last_used_at = NULL';
                } else {
                    $setClauses[] = 'last_used_at = ?';
                    $params[]     = $lastUsedAt;
                }
            }

            $params[] = $cardId;

            $update = $pdo->prepare(
                'UPDATE card_manager_cards
                 SET ' . implode(', ', $setClauses) . '
                 WHERE id = ? AND is_archived = 0'
            );
            $update->execute($params);

            $fetch = $pdo->prepare('SELECT ' . CARD_COLUMNS_EXT . ' FROM card_manager_cards WHERE id = ?');
            $fetch->execute([$cardId]);
            $updated = $fetch->fetch();

            jsonResponse([
                'status'      => 'success',
                'message'     => 'Tarjeta actualizada correctamente.',
                'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
                'card'        => $updated,
            ]);

        // ─── BULK DELETE (Archive multiple cards) ─────────────
        case 'bulk_delete':
            if ($method !== 'POST') {
                jsonError("Usa POST para 'bulk_delete'.", 405);
            }

            $ids = $body['ids'] ?? [];

            if (!is_array($ids) || count($ids) === 0) {
                jsonError('Se requiere un array de IDs.', 400);
            }

            $sanitizedIds = array_filter(array_map('intval', $ids), fn($id) => $id > 0);

            if (count($sanitizedIds) === 0) {
                jsonError('IDs inválidos.', 400);
            }

            $placeholders = implode(',', array_fill(0, count($sanitizedIds), '?'));
            $stmt = $pdo->prepare(
                "UPDATE card_manager_cards
                 SET is_archived = 1
                 WHERE id IN ({$placeholders}) AND is_archived = 0"
            );
            $stmt->execute(array_values($sanitizedIds));

            $affected = $stmt->rowCount();

            jsonResponse([
                'status'      => 'success',
                'message'     => "{$affected} tarjeta(s) archivada(s).",
                'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
                'count'       => $affected,
            ]);

        // ─── UNDO BULK DELETE (Un-archive multiple cards) ────
        case 'undo_bulk_delete':
            if ($method !== 'POST') {
                jsonError("Usa POST para 'undo_bulk_delete'.", 405);
            }

            $ids = $body['ids'] ?? [];

            if (!is_array($ids) || count($ids) === 0) {
                jsonError('Se requiere un array de IDs.', 400);
            }

            $sanitizedIds = array_filter(array_map('intval', $ids), fn($id) => $id > 0);

            if (count($sanitizedIds) === 0) {
                jsonError('IDs inválidos.', 400);
            }

            $placeholders = implode(',', array_fill(0, count($sanitizedIds), '?'));

            $stmt = $pdo->prepare(
                "UPDATE card_manager_cards
                 SET is_archived = 0
                 WHERE id IN ({$placeholders}) AND is_archived = 1"
            );
            $stmt->execute(array_values($sanitizedIds));

            $fetch = $pdo->prepare(
                'SELECT ' . CARD_COLUMNS_EXT . "
                 FROM card_manager_cards
                 WHERE id IN ({$placeholders}) AND is_archived = 0
                 ORDER BY created_at DESC"
            );
            $fetch->execute(array_values($sanitizedIds));
            $cards = $fetch->fetchAll();

            jsonResponse([
                'status'      => 'success',
                'message'     => count($cards) . ' tarjeta(s) restaurada(s).',
                'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
                'cards'       => $cards,
            ]);

        // ─── UNKNOWN ACTION ──────────────────────────────────
        default:
            jsonError("Acción '{$action}' no reconocida.", 404);
    }

} catch (PDOException $e) {
    error_log('[CardManager API] DB Error: ' . $e->getMessage());
    jsonError('Error interno del servidor.', 500);
} catch (Throwable $e) {
    error_log('[CardManager API] Error: ' . $e->getMessage());
    $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? (int) $e->getCode() : 500;
    jsonError($e->getMessage(), $code);
}

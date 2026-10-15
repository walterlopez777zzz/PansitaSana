<?php
// api/raffle_private.php
// SORTEOS PRIVADOS / EXCLUSIVOS (lista de invitados por sorteo).
//
// Un sorteo con `raffles.is_private = 1` sólo admite la inscripción de los
// usuarios presentes en `raffle_invitations` para ese sorteo. A diferencia de
// `raffle_whitelist` (que EXIME de requisitos a quien está dentro pero no
// impide que el resto intente entrar), la invitación es una PUERTA: quien no
// está invitado es rechazado antes de cualquier cobro o inscripción, y quien sí
// lo está sigue sometido a los requisitos normales del sorteo (verificado,
// compras, anti-fraude, aforo, costo de entrada).

const RP_DENIED_MESSAGE = 'Este sorteo es privado y exclusivo. No te encuentras en la lista de invitados.';
const RP_DENIED_CODE = 'private_not_invited';

/**
 * DDL idempotente: columna `raffles.is_private` + tabla `raffle_invitations`.
 * Mismo patrón que rb_ensure_schema / cc_ensure_whitelist.
 */
function rp_ensure_schema(mysqli $conn): void
{
    if (!rp_has_private_column($conn)) {
        $conn->query("ALTER TABLE raffles ADD COLUMN is_private TINYINT(1) NOT NULL DEFAULT 0");
        rp_has_private_column($conn, true);
    }
    $conn->query(
        "CREATE TABLE IF NOT EXISTS raffle_invitations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            raffle_id INT NOT NULL,
            user_id INT NOT NULL,
            note VARCHAR(255) NOT NULL DEFAULT '',
            added_by INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_raffle_user (raffle_id, user_id),
            KEY idx_raffle (raffle_id),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/**
 * ¿Existe ya la columna `raffles.is_private`? Cacheado por petición para que
 * los endpoints públicos de sólo lectura (hub/status) puedan seleccionarla sin
 * romper en instalaciones donde el admin aún no guardó ningún sorteo con el
 * código nuevo.
 */
function rp_has_private_column(mysqli $conn, bool $refresh = false): bool
{
    static $has = null;
    if ($has === null || $refresh) {
        $res = $conn->query("SHOW COLUMNS FROM raffles LIKE 'is_private'");
        $has = ($res && $res->num_rows > 0);
    }
    return $has;
}

/** Expresión SQL segura para leer `is_private` aunque la columna no exista aún. */
function rp_private_column_sql(mysqli $conn, string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    return rp_has_private_column($conn) ? "{$prefix}is_private" : "0 AS is_private";
}

function rp_is_private(array $raffle): bool
{
    return (int)($raffle['is_private'] ?? 0) === 1;
}

function rp_is_invited(mysqli $conn, int $raffleId, int $userId): bool
{
    if ($raffleId <= 0 || $userId <= 0) return false;
    $stmt = $conn->prepare("SELECT 1 FROM raffle_invitations WHERE raffle_id = ? AND user_id = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param("ii", $raffleId, $userId);
    $stmt->execute();
    $found = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $found;
}

/**
 * Regla de acceso: devuelve true si el usuario puede intentar inscribirse
 * (sorteo público, o privado con invitación). No evalúa requisitos.
 */
function rp_can_access(mysqli $conn, array $raffle, int $userId): bool
{
    if (!rp_is_private($raffle)) return true;
    return rp_is_invited($conn, (int)$raffle['raffle_id'], $userId);
}

function rp_invite_add(mysqli $conn, int $raffleId, int $userId, string $note, int $adminId): void
{
    $note = mb_substr(trim($note), 0, 255);
    $stmt = $conn->prepare(
        "INSERT INTO raffle_invitations (raffle_id, user_id, note, added_by, created_at)
         VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE note = VALUES(note), added_by = VALUES(added_by)"
    );
    $stmt->bind_param("iisi", $raffleId, $userId, $note, $adminId);
    $stmt->execute();
    $stmt->close();
}

function rp_invite_remove(mysqli $conn, int $raffleId, int $userId): void
{
    $stmt = $conn->prepare("DELETE FROM raffle_invitations WHERE raffle_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $raffleId, $userId);
    $stmt->execute();
    $stmt->close();
}

function rp_invite_count(mysqli $conn, int $raffleId): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM raffle_invitations WHERE raffle_id = ?");
    $stmt->bind_param("i", $raffleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}

/**
 * Invitados de un sorteo con identidad pública, avatar y si ya participa.
 * Devuelve filas crudas (el llamador resuelve el avatar con AvatarCatalog).
 */
function rp_invite_rows(mysqli $conn, int $raffleId): array
{
    $sql = "SELECT i.user_id, i.note, i.created_at,
                   u.game_username, u.public_display_name, u.public_code, u.whatsapp_phone_e164,
                   u.is_verified, a.filename AS avatar,
                   (SELECT COUNT(*) FROM raffle_participants rp WHERE rp.raffle_id = i.raffle_id AND rp.user_id = i.user_id) AS is_part
            FROM raffle_invitations i
            JOIN users u ON u.id = i.user_id
            LEFT JOIN avatars a ON u.avatar_id = a.id
            WHERE i.raffle_id = ?
            ORDER BY i.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $raffleId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

<?php
// api/raffle_bans.php
// SISTEMA DE BANEOS DE SORTEOS FLASH (tabla dedicada `raffle_bans`).
// Restricciones exclusivas del módulo de sorteos: NO altera users.account_status.
//
// Niveles de baneo (ban_type):
//   - 'next_n_raffles': bloqueado durante los próximos N sorteos resueltos.
//   - 'until_date':     bloqueado hasta una fecha/hora (expires_at, UTC).
//   - 'permanent':      permaban de sorteos hasta que un admin lo levante.
//
// Al crear un ban se guarda un snapshot de IP y huella de dispositivo del
// usuario para detectar intentos de evadir el baneo con cuentas nuevas.

/**
 * Crea la tabla `raffle_bans` si no existe (DDL idempotente, mismo patrón
 * que rr_ensure_schema en raffle_review.php).
 */
function rb_ensure_schema(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS raffle_bans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            ban_type ENUM('next_n_raffles','until_date','permanent') NOT NULL DEFAULT 'permanent',
            raffles_remaining INT NULL,
            expires_at DATETIME NULL,
            reason VARCHAR(255) NOT NULL DEFAULT '',
            ip_snapshot VARCHAR(64) NOT NULL DEFAULT '',
            fingerprint_snapshot VARCHAR(64) NOT NULL DEFAULT '',
            created_by INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            lifted_at DATETIME NULL,
            lifted_by INT NULL,
            KEY idx_user (user_id),
            KEY idx_ip (ip_snapshot),
            KEY idx_fp (fingerprint_snapshot),
            KEY idx_active (lifted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/**
 * Fragmento SQL que define un ban "activo": no levantado y aún vigente
 * según su tipo (permanente, por fecha o por N sorteos restantes).
 */
function rb_active_condition_sql(string $alias = 'b'): string
{
    return "($alias.lifted_at IS NULL AND (
        $alias.ban_type = 'permanent'
        OR ($alias.ban_type = 'until_date' AND $alias.expires_at IS NOT NULL AND $alias.expires_at > UTC_TIMESTAMP())
        OR ($alias.ban_type = 'next_n_raffles' AND $alias.raffles_remaining IS NOT NULL AND $alias.raffles_remaining > 0)
    ))";
}

/**
 * Busca un ban activo que aplique al usuario actual, ya sea:
 *  - directamente por su cuenta (matched_by = 'user'),
 *  - por coincidencia de IP con un ban existente (matched_by = 'ip'),
 *  - por coincidencia de huella de dispositivo (matched_by = 'fingerprint').
 *
 * Devuelve la fila del ban (con datos públicos del usuario baneado original)
 * más la clave 'matched_by', o null si no hay ningún ban aplicable.
 */
function rb_find_active_ban(mysqli $conn, int $userId, string $ip, string $fingerprint): ?array
{
    $active = rb_active_condition_sql('b');
    $baseSql = "SELECT b.*, u.public_display_name, u.game_username, u.public_code
                FROM raffle_bans b
                JOIN users u ON u.id = b.user_id
                WHERE %s AND $active
                ORDER BY b.id DESC LIMIT 1";

    // 1) Ban directo de la cuenta
    $stmt = $conn->prepare(sprintf($baseSql, "b.user_id = ?"));
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) { $row['matched_by'] = 'user'; return $row; }

    // 2) Coincidencia por IP (cuenta nueva desde la misma red)
    if ($ip !== '' && $ip !== '0.0.0.0') {
        $stmt = $conn->prepare(sprintf($baseSql, "b.ip_snapshot <> '' AND b.ip_snapshot = ? AND b.user_id <> ?"));
        $stmt->bind_param("si", $ip, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) { $row['matched_by'] = 'ip'; return $row; }
    }

    // 3) Coincidencia por huella de dispositivo (mismo celular/PC)
    if ($fingerprint !== '') {
        $stmt = $conn->prepare(sprintf($baseSql, "b.fingerprint_snapshot <> '' AND b.fingerprint_snapshot = ? AND b.user_id <> ?"));
        $stmt->bind_param("si", $fingerprint, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) { $row['matched_by'] = 'fingerprint'; return $row; }
    }

    return null;
}

/**
 * Mensaje público de rechazo para el usuario que intenta inscribirse.
 * Incluye el usuario originalmente baneado cuando la coincidencia es por
 * IP/dispositivo (para que administración pueda revisar el caso) y la
 * indicación de contactar por WhatsApp si cree que es un error.
 */
function rb_public_message(array $ban): string
{
    $name = trim((string)($ban['public_display_name'] ?? '')) ?: trim((string)($ban['game_username'] ?? ''));
    $code = trim((string)($ban['public_code'] ?? ''));
    $who  = $name . ($code !== '' ? " ($code)" : '');
    $reason = trim((string)($ban['reason'] ?? ''));
    $reasonTxt = $reason !== '' ? " Motivo: $reason." : '';

    switch ($ban['ban_type']) {
        case 'next_n_raffles':
            $dur = "por los próximos " . (int)$ban['raffles_remaining'] . " sorteo(s)";
            break;
        case 'until_date':
            $dur = "hasta el " . (string)$ban['expires_at'] . " (UTC)";
            break;
        default:
            $dur = "de forma permanente";
    }

    $matched = (string)($ban['matched_by'] ?? 'user');
    if ($matched === 'ip') {
        return "Tu red (IP) está vinculada a un baneo de sorteos $dur (usuario baneado: $who).$reasonTxt Si crees que se trata de un error, contáctanos por WhatsApp.";
    }
    if ($matched === 'fingerprint') {
        return "Este dispositivo está vinculado a un baneo de sorteos $dur (usuario baneado: $who).$reasonTxt Si crees que se trata de un error, contáctanos por WhatsApp.";
    }
    return "Tu cuenta tiene un baneo de sorteos $dur.$reasonTxt Si crees que se trata de un error, contáctanos por WhatsApp.";
}

/**
 * Descuenta 1 sorteo a todos los bans activos de tipo 'next_n_raffles'.
 * Debe llamarse UNA sola vez por sorteo resuelto (el guard de idempotencia de
 * triggerRaffleResolve garantiza que los ganadores solo se insertan una vez,
 * por lo que este decremento tampoco se repite por reintentos del cron).
 */
function rb_decrement_on_resolve(mysqli $conn): void
{
    rb_ensure_schema($conn);
    $conn->query(
        "UPDATE raffle_bans
         SET raffles_remaining = raffles_remaining - 1
         WHERE ban_type = 'next_n_raffles'
           AND lifted_at IS NULL
           AND raffles_remaining IS NOT NULL
           AND raffles_remaining > 0"
    );
}

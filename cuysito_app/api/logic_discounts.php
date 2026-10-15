<?php
// public_html/api/logic_discounts.php
//
// Contrato temporal de daily_discounts ("UTC-First"):
//   - created_at / expires_at / used_at se guardan como UTC `Y-m-d H:i:s`.
//   - En SQL se comparan SIEMPRE contra UTC_TIMESTAMP(), nunca NOW(): así el
//     resultado no depende del `time_zone` de la sesión (la API pública fuerza
//     UTC; el panel admin usa la zona del servidor).
//   - Hacia el navegador viajan como ISO-8601 `Z` (UtcTime::sqlToIso).

require_once dirname(__DIR__) . '/core/UtcTime.php';

// Origen (spin_source) de los cupones que nacen de un Sorteo Flash en modo
// OFERTA. Comparten la tabla daily_discounts con el Giro Diario para que el
// carrito / checkout los apliquen sin lógica extra.
if (!defined('DD_SOURCE_RAFFLE_PRIZE')) define('DD_SOURCE_RAFFLE_PRIZE', 'raffle_prize');
if (!defined('DD_RAFFLE_PRIZE_LABEL')) define('DD_RAFFLE_PRIZE_LABEL', 'Premio Sorteo');
if (!defined('DD_RAFFLE_PRIZE_COLOR')) define('DD_RAFFLE_PRIZE_COLOR', '#a855f7');

if (!function_exists('dd_ensure_schema')) {
    /**
     * Columnas extra de daily_discounts para los cupones de sorteo (idempotente,
     * mismo patrón SHOW COLUMNS + ALTER que el resto de módulos):
     *   - raffle_id           INT NULL           → trazabilidad / estadísticas
     *   - coupon_value_coins  DECIMAL(12,2) NULL → valor del cupón en Bananos
     * Si spin_source es un ENUM sin 'raffle_prize', lo amplía.
     */
    function dd_ensure_schema($conn): void {
        static $done = false;
        if ($done || !($conn instanceof mysqli)) return;
        $done = true;

        $cols = [
            'raffle_id'          => 'INT NULL DEFAULT NULL',
            'coupon_value_coins' => 'DECIMAL(12,2) NULL DEFAULT NULL',
        ];
        foreach ($cols as $col => $def) {
            $res = $conn->query("SHOW COLUMNS FROM daily_discounts LIKE '$col'");
            if ($res && $res->num_rows === 0) {
                $conn->query("ALTER TABLE daily_discounts ADD COLUMN `$col` $def");
            }
            if ($res) $res->free();
        }

        $res = $conn->query("SHOW COLUMNS FROM daily_discounts LIKE 'spin_source'");
        if ($res && ($row = $res->fetch_assoc())) {
            $type = (string)($row['Type'] ?? '');
            if (stripos($type, 'enum(') === 0 && strpos($type, "'" . DD_SOURCE_RAFFLE_PRIZE . "'") === false) {
                $newType = rtrim($type, ')') . ",'" . DD_SOURCE_RAFFLE_PRIZE . "')";
                $nullSql = (($row['Null'] ?? 'YES') === 'NO') ? ' NOT NULL' : ' NULL';
                $defSql  = ($row['Default'] !== null) ? " DEFAULT '" . $conn->real_escape_string((string)$row['Default']) . "'" : '';
                $conn->query("ALTER TABLE daily_discounts MODIFY spin_source $newType$nullSql$defSql");
            }
        }
        if ($res) $res->free();
    }
}

if (!function_exists('dd_source_meta')) {
    /**
     * Metadatos de presentación por origen del descuento. El frontend usa
     * `label` como etiqueta y `color` (hex o null) para destacar el cupón.
     */
    function dd_source_meta($spinSource): array {
        $src = strtolower(trim((string)$spinSource));
        if ($src === DD_SOURCE_RAFFLE_PRIZE) {
            return ['source' => $src, 'label' => DD_RAFFLE_PRIZE_LABEL, 'color' => DD_RAFFLE_PRIZE_COLOR];
        }
        $labels = [
            'daily_free' => 'Giro Diario',
            'paid_coins' => 'Giro Pagado',
            'bonus_gift' => 'Giro Bonus',
        ];
        return ['source' => $src !== '' ? $src : 'daily_free', 'label' => $labels[$src] ?? 'Giro Diario', 'color' => null];
    }
}

if (!function_exists('dd_effective_status')) {
    /**
     * Estado real de una fila de daily_discounts: un 'active' cuyo expires_at
     * (UTC SQL) ya pasó se reporta como 'expired'; 'used' y demás se respetan.
     * Comparación determinista sobre el mismo instante para toda la petición.
     */
    function dd_effective_status(string $status, ?string $expiresAtSqlUtc, ?DateTimeInterface $now = null): string {
        if ($status !== 'active') return $status;
        $expires = UtcTime::fromSql($expiresAtSqlUtc);
        if ($expires === null) return $status;
        $ref = $now === null ? UtcTime::now() : $now;
        return $expires > $ref ? 'active' : 'expired';
    }
}

// Evitamos conflictos si se incluye varias veces
if (!function_exists('get_active_discount')) {

    /**
     * Busca si el usuario tiene un descuento activo para un item específico.
     * 
     * @param object $conn       Conexión a Base de Datos
     * @param int    $user_id    ID del usuario
     * @param string $item_id    ID del item (offerId de Fortnite)
     * 
     * @return array|null        Devuelve array con datos del descuento o NULL si no existe.
     */
    function get_active_discount($conn, $user_id, $item_id) {
        
        // 1. Coincide User y Item
        // 2. Status debe ser 'active' (ni usado, ni expirado)
        // 3. La fecha de expiración debe ser FUTURA (UTC vs UTC)
        // Si hay varios cupones para el mismo ítem gana el de mayor % y, a
        // igualdad, el que expira antes.
        $sql = "SELECT id, discount_percent, is_flash_prize, spin_source, expires_at
                FROM daily_discounts
                WHERE user_id = ?
                AND item_id = ?
                AND status = 'active'
                AND expires_at > UTC_TIMESTAMP()
                ORDER BY discount_percent DESC, expires_at ASC
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            // Log de error silencioso si falla la DB
            error_log("Error prepare get_active_discount: " . $conn->error);
            return null;
        }

        $stmt->bind_param("is", $user_id, $item_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            return $row; // Retornamos el descuento encontrado
        }

        return null; // No hay descuento válido
    }
}

if (!function_exists('calculate_final_price')) {

    /**
     * Calcula el precio final matemático.
     */
    function calculate_final_price($base_price, $discount_percent) {
        // Matemáticas simples: Precio * (1 - (20 / 100))
        // Ejemplo: 10.00 * 0.80 = 8.00
        $final = $base_price * (1 - ($discount_percent / 100));
        
        // Redondeamos a 2 decimales siempre para evitar errores de centavos
        return round($final, 2);
    }
}
?>
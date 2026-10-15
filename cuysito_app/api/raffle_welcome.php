<?php
/**
 * ============================================================================
 * raffle_welcome.php — "Ticket de Bienvenida" (Sorteo Flash)
 * ============================================================================
 * Un pase implícito, de UN SOLO USO y sin caducidad, que permite a un cliente
 * nuevo con una compra importante saltarse los requisitos ESTÁNDAR de compras
 * de un sorteo (min_orders_count / min_orders_date / min_spent_total).
 *
 * Nunca salta:
 *   - verified_only (regla explícita del sorteo),
 *   - anti-fraude (IP / huella / cuentas compartidas),
 *   - baneos, cupo, cuota de inscripción ni ventana de tiempo.
 *
 * No hay "activación": el ticket EXISTE si y solo si el usuario alcanza el
 * umbral con pedidos reales completados, NUNCA ha participado en un sorteo
 * (una fila en raffle_participants que no sea 'expelled' lo descalifica: el
 * pase es exclusivo para la PRIMERA experiencia de sorteo) y no lo ha
 * consumido antes. El consumo
 * se persiste en `raffle_welcome_tickets` (PRIMARY KEY user_id → la BD garantiza
 * el uso único incluso con peticiones concurrentes) y se marca en
 * `raffle_participants.used_welcome_ticket` para auditoría por sorteo.
 *
 * Valoración de compras (ver wt_qualifying_value):
 *   - Solo pedidos `estado = 'completado'` con dinero o Bananos reales; se
 *     excluyen los pedidos internos de premios (WIN-*, metodo_pago 'saldo').
 *   - Lente NATIVA: SUM(detalles_pedido.v_bucks) → paVos nominales del ítem
 *     (una skin de 1500 paVos comprada con descuento sigue valiendo 1500).
 *   - Lente PAGADA: (total_pagar + coins_value_money) convertidos a paVos con
 *     `configuracion_tienda.precio_por_100_pavos` (Bananos canjeados cuentan
 *     por su valor monetario). Cubre productos sin v_bucks (estáticos).
 *   - Modo por defecto `max`: el usuario califica si CUALQUIERA de las dos
 *     lentes alcanza el umbral.
 *
 * Asignación manual (compras por chat): un admin puede OTORGAR el pase a un
 * usuario desde /wabo → Sorteos Flash → "Pase de Bienvenida". Se guarda en
 * `raffle_welcome_grants` (PRIMARY KEY user_id) y equivale a calificar
 * económicamente y a ser "primer sorteo" (el admin decide); sigue siendo de
 * UN SOLO USO (raffle_welcome_tickets) y nunca salta anti-fraude/baneos.
 *
 * Ajustes (site_settings, editables en /wabo → Sorteos Flash → "🎟️ Ticket de Bienvenida"):
 *   welcome_ticket_enabled        1 | 0                       (1)
 *   welcome_ticket_min_value      umbral en paVos/Bananos     (1500)
 *   welcome_ticket_value_mode     max | native | paid | min   (max)
 *   welcome_ticket_scope          accumulated | single_order  (accumulated)
 *   welcome_ticket_include_manual 1 | 0 — compras por chat aprobadas (1)
 */

if (!defined('WT_DEFAULT_MIN_VALUE')) {
    define('WT_DEFAULT_MIN_VALUE', 1500.0);
    define('WT_SETTING_ENABLED', 'welcome_ticket_enabled');
    define('WT_SETTING_MIN_VALUE', 'welcome_ticket_min_value');
    define('WT_SETTING_VALUE_MODE', 'welcome_ticket_value_mode');
    define('WT_SETTING_SCOPE', 'welcome_ticket_scope');
    define('WT_SETTING_INCLUDE_MANUAL', 'welcome_ticket_include_manual');
}

if (!function_exists('wt_ensure_schema')) {
    function wt_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        $col = $conn->query("SHOW COLUMNS FROM raffle_participants LIKE 'used_welcome_ticket'");
        if ($col && $col->num_rows === 0) {
            $conn->query("ALTER TABLE raffle_participants ADD COLUMN used_welcome_ticket TINYINT(1) NOT NULL DEFAULT 0");
        }

        $conn->query("CREATE TABLE IF NOT EXISTS raffle_welcome_tickets (
            user_id INT NOT NULL,
            raffle_id INT NOT NULL,
            ticket_index INT NOT NULL DEFAULT 0,
            qualifying_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            threshold_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            value_mode VARCHAR(16) NOT NULL DEFAULT 'max',
            used_at DATETIME NOT NULL,
            PRIMARY KEY (user_id),
            KEY idx_raffle (raffle_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $conn->query("CREATE TABLE IF NOT EXISTS raffle_welcome_grants (
            user_id INT NOT NULL,
            granted_by INT NOT NULL DEFAULT 0,
            granted_by_name VARCHAR(64) NOT NULL DEFAULT '',
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            revoked_at DATETIME NULL DEFAULT NULL,
            revoked_by INT NULL DEFAULT NULL,
            PRIMARY KEY (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmt = $conn->prepare("INSERT IGNORE INTO site_settings (key_name, value) VALUES (?, ?)");
        if ($stmt) {
            foreach ([
                WT_SETTING_ENABLED        => '1',
                WT_SETTING_MIN_VALUE      => (string)(int)WT_DEFAULT_MIN_VALUE,
                WT_SETTING_VALUE_MODE     => 'max',
                WT_SETTING_SCOPE          => 'accumulated',
                WT_SETTING_INCLUDE_MANUAL => '1',
            ] as $key => $value) {
                $stmt->bind_param('ss', $key, $value);
                $stmt->execute();
            }
            $stmt->close();
        }
    }
}

if (!function_exists('wt_settings')) {
    /** @return array{enabled:bool, min_value:float, value_mode:string, scope:string, include_manual:bool} */
    function wt_settings(mysqli $conn): array
    {
        $s = [
            'enabled'        => true,
            'min_value'      => WT_DEFAULT_MIN_VALUE,
            'value_mode'     => 'max',
            'scope'          => 'accumulated',
            'include_manual' => true,
        ];
        $res = $conn->query("SELECT key_name, value FROM site_settings WHERE key_name IN ('"
            . WT_SETTING_ENABLED . "','" . WT_SETTING_MIN_VALUE . "','" . WT_SETTING_VALUE_MODE . "','"
            . WT_SETTING_SCOPE . "','" . WT_SETTING_INCLUDE_MANUAL . "')");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $v = trim((string)$row['value']);
                switch ($row['key_name']) {
                    case WT_SETTING_ENABLED:
                        $s['enabled'] = ($v === '1' || strtolower($v) === 'true');
                        break;
                    case WT_SETTING_MIN_VALUE:
                        if (is_numeric($v) && (float)$v > 0) $s['min_value'] = (float)$v;
                        break;
                    case WT_SETTING_VALUE_MODE:
                        $v = strtolower($v);
                        if (in_array($v, ['max', 'native', 'paid', 'min'], true)) $s['value_mode'] = $v;
                        break;
                    case WT_SETTING_SCOPE:
                        $v = strtolower($v);
                        if (in_array($v, ['accumulated', 'single_order'], true)) $s['scope'] = $v;
                        break;
                    case WT_SETTING_INCLUDE_MANUAL:
                        $s['include_manual'] = ($v === '1' || strtolower($v) === 'true');
                        break;
                }
            }
        }
        return $s;
    }
}

if (!function_exists('wt_real_orders_sql')) {
    /**
     * Fragmento SQL (empieza con AND) que deja fuera los pedidos internos que
     * genera la entrega de un premio de sorteo (codigo WIN-*, metodo_pago
     * 'saldo', total 0). Úsalo en TODA consulta sobre `pedidos` que cuente
     * compras reales para requisitos de sorteos, así un premio ganado nunca
     * suma a min_orders_count / min_spent_total ni regala un Ticket.
     */
    function wt_real_orders_sql(string $alias = ''): string
    {
        $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        return " AND {$p}codigo_pedido NOT LIKE 'WIN-%' AND COALESCE({$p}metodo_pago, '') <> 'saldo'";
    }
}

if (!function_exists('wt_raffle_requires_purchases')) {
    /**
     * true si el sorteo tiene algún requisito de compras que el Ticket puede
     * saltar (min_orders_count / min_orders_date / min_spent_total).
     */
    function wt_raffle_requires_purchases(?array $reqs): bool
    {
        if (!is_array($reqs)) return false;
        return (int)($reqs['min_orders_count'] ?? 0) > 0
            || !empty($reqs['min_orders_date'])
            || (float)($reqs['min_spent_total'] ?? 0) > 0;
    }
}

if (!function_exists('wt_has_prior_participation')) {
    /**
     * true si el usuario ya jugó algún sorteo (inscripción vigente en cualquier
     * raffle, distinto de $excludeRaffleId). Los expulsados que no volvieron a
     * entrar NO cuentan: su fila queda como 'expelled' y se ignora.
     */
    function wt_has_prior_participation(mysqli $conn, int $userId, int $excludeRaffleId = 0): bool
    {
        if ($userId <= 0) return false;
        $sql = "SELECT 1 FROM raffle_participants
                WHERE user_id = ? AND COALESCE(review_status, 'active') <> 'expelled'";
        if ($excludeRaffleId > 0) $sql .= " AND raffle_id <> ?";
        $sql .= " LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return false;
        if ($excludeRaffleId > 0) {
            $stmt->bind_param('ii', $userId, $excludeRaffleId);
        } else {
            $stmt->bind_param('i', $userId);
        }
        $stmt->execute();
        $has = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $has;
    }
}

if (!function_exists('wt_grant_row')) {
    /** Asignación manual vigente (no revocada) del pase o null. */
    function wt_grant_row(mysqli $conn, int $userId): ?array
    {
        if ($userId <= 0) return null;
        $stmt = $conn->prepare(
            "SELECT user_id, granted_by, granted_by_name, note, created_at
             FROM raffle_welcome_grants WHERE user_id = ? AND revoked_at IS NULL LIMIT 1"
        );
        if (!$stmt) return null;
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('wt_soles_per_100_pavos')) {
    /** Precio de venta de 100 paVos en soles (configuracion_tienda). 0 si no está configurado. */
    function wt_soles_per_100_pavos(mysqli $conn): float
    {
        $rate = 0.0;
        try {
            $res = $conn->query("SELECT valor FROM configuracion_tienda WHERE clave = 'precio_por_100_pavos' LIMIT 1");
            if ($res && ($row = $res->fetch_assoc())) $rate = (float)$row['valor'];
        } catch (Throwable $e) {
            $rate = 0.0;
        }
        return $rate > 0 ? $rate : 0.0;
    }
}

if (!function_exists('wt_soles_to_pavos')) {
    function wt_soles_to_pavos(float $soles, float $solesPer100): float
    {
        if ($soles <= 0 || $solesPer100 <= 0) return 0.0;
        return round(($soles / $solesPer100) * 100, 2);
    }
}

if (!function_exists('wt_qualifying_value')) {
    /**
     * Calcula el valor "calificante" del historial de compras reales del usuario.
     *
     * Una asignación manual vigente (wt_grant_row) hace que `qualifies` sea true
     * aunque el historial no alcance el umbral (`granted` = true).
     *
     * @return array{value:float, native:float, paid:float, orders:int, best_order:float, threshold:float, qualifies:bool, granted:bool, mode:string, scope:string}
     */
    function wt_qualifying_value(mysqli $conn, int $userId, ?array $settings = null): array
    {
        $settings = $settings ?? wt_settings($conn);
        $threshold = (float)$settings['min_value'];
        $mode = $settings['value_mode'];
        $rate = wt_soles_per_100_pavos($conn);

        $out = [
            'value' => 0.0, 'native' => 0.0, 'paid' => 0.0, 'orders' => 0, 'best_order' => 0.0,
            'threshold' => $threshold, 'qualifies' => false, 'granted' => false, 'mode' => $mode, 'scope' => $settings['scope'],
        ];
        if ($userId <= 0) return $out;
        $out['granted'] = wt_grant_row($conn, $userId) !== null;

        // Pedidos reales completados: se excluyen los pedidos internos que generan
        // los premios (WIN-*, pagados con 'saldo' y total 0) para que un premio
        // ganado nunca "regale" un Ticket de Bienvenida.
        $sql = "SELECT p.total_pagar, COALESCE(p.coins_value_money, 0) AS coins_money,
                       COALESCE((SELECT SUM(d.v_bucks * GREATEST(d.cantidad, 1)) FROM detalles_pedido d WHERE d.pedido_id = p.id), 0) AS native_vbucks
                FROM pedidos p
                WHERE p.user_id = ?
                  AND p.estado = 'completado'" . wt_real_orders_sql('p') . "
                  AND (p.total_pagar > 0 OR COALESCE(p.coins_redeemed, 0) > 0)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return $out;
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();

        $perOrder = [];
        while ($row = $res->fetch_assoc()) {
            $native = (float)$row['native_vbucks'];
            $paid = wt_soles_to_pavos((float)$row['total_pagar'] + (float)$row['coins_money'], $rate);
            $perOrder[] = ['native' => $native, 'paid' => $paid];
        }
        $stmt->close();

        // Compras aprobadas por chat (Revisión Diferida): solo tienen importe en soles.
        if (!empty($settings['include_manual'])) {
            try {
                $chk = $conn->query("SHOW TABLES LIKE 'raffle_manual_purchases'");
                if ($chk && $chk->num_rows > 0) {
                    $mStmt = $conn->prepare("SELECT amount FROM raffle_manual_purchases WHERE user_id = ? AND amount > 0");
                    if ($mStmt) {
                        $mStmt->bind_param('i', $userId);
                        $mStmt->execute();
                        $mRes = $mStmt->get_result();
                        while ($m = $mRes->fetch_assoc()) {
                            $perOrder[] = ['native' => 0.0, 'paid' => wt_soles_to_pavos((float)$m['amount'], $rate)];
                        }
                        $mStmt->close();
                    }
                }
            } catch (Throwable $e) {
                // Sin compras manuales: se ignora.
            }
        }

        $combine = static function (float $native, float $paid) use ($mode): float {
            switch ($mode) {
                case 'native': return $native;
                case 'paid':   return $paid;
                case 'min':    return min($native, $paid);
                default:       return max($native, $paid);
            }
        };

        $sumNative = 0.0; $sumPaid = 0.0; $best = 0.0;
        foreach ($perOrder as $o) {
            $sumNative += $o['native'];
            $sumPaid += $o['paid'];
            $best = max($best, $combine($o['native'], $o['paid']));
        }

        $out['orders'] = count($perOrder);
        $out['native'] = round($sumNative, 2);
        $out['paid'] = round($sumPaid, 2);
        $out['best_order'] = round($best, 2);
        $out['value'] = $settings['scope'] === 'single_order'
            ? $out['best_order']
            : round($combine($sumNative, $sumPaid), 2);
        $out['qualifies'] = $out['granted'] || ($threshold > 0 && $out['value'] >= $threshold);
        return $out;
    }
}

if (!function_exists('wt_bulk_qualifying')) {
    /**
     * Versión masiva de wt_qualifying_value() para el panel admin: recorre TODOS
     * los pedidos reales completados (+ compras manuales y grants) una sola vez
     * y devuelve, por user_id, el mismo cálculo más fechas útiles para métricas.
     *
     * @return array<int, array{value:float,native:float,paid:float,orders:int,best_order:float,qualifies:bool,granted:bool,
     *                          spent_soles:float,first_order_at:?string,last_order_at:?string,qualified_at:?string,
     *                          order_dates:array<int,array{at:string,soles:float}>}>
     */
    function wt_bulk_qualifying(mysqli $conn, ?array $settings = null): array
    {
        wt_ensure_schema($conn);
        $settings = $settings ?? wt_settings($conn);
        $threshold = (float)$settings['min_value'];
        $mode = $settings['value_mode'];
        $rate = wt_soles_per_100_pavos($conn);

        $combine = static function (float $native, float $paid) use ($mode): float {
            switch ($mode) {
                case 'native': return $native;
                case 'paid':   return $paid;
                case 'min':    return min($native, $paid);
                default:       return max($native, $paid);
            }
        };

        $perUser = [];
        $blank = static function (): array {
            return [
                'value' => 0.0, 'native' => 0.0, 'paid' => 0.0, 'orders' => 0, 'best_order' => 0.0,
                'qualifies' => false, 'granted' => false, 'spent_soles' => 0.0,
                'first_order_at' => null, 'last_order_at' => null, 'qualified_at' => null,
                'order_dates' => [], '_rows' => [],
            ];
        };

        $res = $conn->query(
            "SELECT p.user_id, p.fecha_creacion, p.total_pagar, COALESCE(p.coins_value_money, 0) AS coins_money,
                    COALESCE((SELECT SUM(d.v_bucks * GREATEST(d.cantidad, 1)) FROM detalles_pedido d WHERE d.pedido_id = p.id), 0) AS native_vbucks
             FROM pedidos p
             WHERE p.user_id IS NOT NULL AND p.user_id > 0
               AND p.estado = 'completado'" . wt_real_orders_sql('p') . "
               AND (p.total_pagar > 0 OR COALESCE(p.coins_redeemed, 0) > 0)
             ORDER BY p.fecha_creacion ASC"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $uid = (int)$row['user_id'];
                if (!isset($perUser[$uid])) $perUser[$uid] = $blank();
                $soles = (float)$row['total_pagar'] + (float)$row['coins_money'];
                $perUser[$uid]['_rows'][] = [
                    'at' => (string)$row['fecha_creacion'],
                    'native' => (float)$row['native_vbucks'],
                    'paid' => wt_soles_to_pavos($soles, $rate),
                    'soles' => $soles,
                ];
            }
            $res->free();
        }

        if (!empty($settings['include_manual'])) {
            $chk = $conn->query("SHOW TABLES LIKE 'raffle_manual_purchases'");
            if ($chk && $chk->num_rows > 0) {
                $mRes = $conn->query("SELECT user_id, amount, COALESCE(CONCAT(purchase_date, ' 12:00:00'), created_at) AS at FROM raffle_manual_purchases WHERE amount > 0 ORDER BY purchase_date ASC, id ASC");
                if ($mRes) {
                    while ($m = $mRes->fetch_assoc()) {
                        $uid = (int)$m['user_id'];
                        if (!isset($perUser[$uid])) $perUser[$uid] = $blank();
                        $perUser[$uid]['_rows'][] = [
                            'at' => (string)$m['at'], 'native' => 0.0,
                            'paid' => wt_soles_to_pavos((float)$m['amount'], $rate), 'soles' => (float)$m['amount'],
                        ];
                    }
                    $mRes->free();
                }
            }
            if ($chk) $chk->free();
        }

        $gRes = $conn->query("SELECT user_id FROM raffle_welcome_grants WHERE revoked_at IS NULL");
        if ($gRes) {
            while ($g = $gRes->fetch_assoc()) {
                $uid = (int)$g['user_id'];
                if (!isset($perUser[$uid])) $perUser[$uid] = $blank();
                $perUser[$uid]['granted'] = true;
            }
            $gRes->free();
        }

        foreach ($perUser as $uid => &$u) {
            usort($u['_rows'], static fn($a, $b) => strcmp($a['at'], $b['at']));
            $sumNative = 0.0; $sumPaid = 0.0; $best = 0.0; $spent = 0.0;
            foreach ($u['_rows'] as $o) {
                $sumNative += $o['native'];
                $sumPaid += $o['paid'];
                $spent += $o['soles'];
                $orderVal = $combine($o['native'], $o['paid']);
                $best = max($best, $orderVal);
                $u['order_dates'][] = ['at' => $o['at'], 'soles' => round($o['soles'], 2)];
                // Momento en que el historial cruza el umbral (fecha "de emisión" del pase).
                if ($u['qualified_at'] === null && $threshold > 0) {
                    $running = $settings['scope'] === 'single_order' ? $orderVal : $combine($sumNative, $sumPaid);
                    if ($running >= $threshold) $u['qualified_at'] = $o['at'];
                }
            }
            $u['orders'] = count($u['_rows']);
            $u['native'] = round($sumNative, 2);
            $u['paid'] = round($sumPaid, 2);
            $u['best_order'] = round($best, 2);
            $u['spent_soles'] = round($spent, 2);
            $u['value'] = $settings['scope'] === 'single_order' ? $u['best_order'] : round($combine($sumNative, $sumPaid), 2);
            $u['qualifies'] = $u['granted'] || ($threshold > 0 && $u['value'] >= $threshold);
            $u['first_order_at'] = $u['_rows'][0]['at'] ?? null;
            $u['last_order_at'] = $u['_rows'] ? $u['_rows'][count($u['_rows']) - 1]['at'] : null;
            unset($u['_rows']);
        }
        unset($u);
        return $perUser;
    }
}

if (!function_exists('wt_used_row')) {
    /** Fila de consumo del ticket o null si nunca se usó. */
    function wt_used_row(mysqli $conn, int $userId, bool $forUpdate = false): ?array
    {
        wt_ensure_schema($conn);
        $sql = "SELECT user_id, raffle_id, ticket_index, qualifying_value, threshold_value, used_at
                FROM raffle_welcome_tickets WHERE user_id = ? LIMIT 1" . ($forUpdate ? " FOR UPDATE" : "");
        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('wt_status')) {
    /**
     * Estado completo del Ticket de Bienvenida de un usuario.
     * `available` = habilitado + califica económicamente + nunca participó en
     * un sorteo (first_raffle) + nunca consumido.
     *
     * `reason` explica por qué NO está disponible: disabled | used |
     * prior_participation | not_qualified | null (disponible).
     *
     * Una asignación manual vigente (`granted`) cuenta como calificado y como
     * primer sorteo (decisión explícita del admin); el uso único se mantiene.
     *
     * @param int $excludeRaffleId sorteo en curso (su fila no cuenta como
     *        participación previa; útil cuando el usuario ya entró a ESTE).
     */
    function wt_status(mysqli $conn, int $userId, int $excludeRaffleId = 0): array
    {
        wt_ensure_schema($conn);
        $settings = wt_settings($conn);
        $status = [
            'enabled'   => $settings['enabled'],
            'available' => false,
            'qualifies' => false,
            'first_raffle' => false,
            'used'      => false,
            'used_in_raffle' => null,
            'used_at'   => null,
            'granted'   => false,
            'reason'    => null,
            'value'     => 0.0,
            'threshold' => (float)$settings['min_value'],
            'mode'      => $settings['value_mode'],
            'scope'     => $settings['scope'],
        ];
        if ($userId <= 0) return $status;

        $used = wt_used_row($conn, $userId);
        if ($used) {
            $status['used'] = true;
            $status['used_in_raffle'] = (int)$used['raffle_id'];
            $status['used_at'] = $used['used_at'];
        }

        $val = wt_qualifying_value($conn, $userId, $settings);
        $status['value'] = $val['value'];
        $status['qualifies'] = $val['qualifies'];
        $status['granted'] = $val['granted'];

        $status['first_raffle'] = $status['granted'] || !wt_has_prior_participation($conn, $userId, $excludeRaffleId);
        $status['available'] = $settings['enabled'] && $val['qualifies'] && $status['first_raffle'] && !$status['used'];

        if (!$status['available']) {
            if (!$settings['enabled'])         $status['reason'] = 'disabled';
            elseif ($status['used'])           $status['reason'] = 'used';
            elseif (!$status['first_raffle'])  $status['reason'] = 'prior_participation';
            else                               $status['reason'] = 'not_qualified';
        }
        return $status;
    }
}

if (!function_exists('wt_unavailable_message')) {
    /** Mensaje para el usuario según `reason` de wt_status(). */
    function wt_unavailable_message(array $status): string
    {
        switch ($status['reason'] ?? '') {
            case 'used':                return 'Tu Ticket de Bienvenida ya fue utilizado en otro sorteo.';
            case 'prior_participation': return 'Tu Ticket de Bienvenida es solo para tu primer sorteo: ya participaste en uno anteriormente.';
            case 'disabled':            return 'El Ticket de Bienvenida no está activo en este momento.';
            default:                    return 'No tienes un Ticket de Bienvenida disponible.';
        }
    }
}

if (!function_exists('wt_consume')) {
    /**
     * Consume el ticket dentro de la transacción del caller. La PRIMARY KEY de
     * user_id hace que una segunda inserción falle → uso único garantizado.
     * Lanza Exception si ya estaba consumido o no se pudo registrar.
     */
    function wt_consume(mysqli $conn, int $userId, int $raffleId, int $ticketIndex, float $qualifyingValue, float $threshold, string $mode): void
    {
        $stmt = $conn->prepare(
            "INSERT INTO raffle_welcome_tickets
                (user_id, raffle_id, ticket_index, qualifying_value, threshold_value, value_mode, used_at)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        );
        if (!$stmt) throw new Exception('No se pudo registrar el Ticket de Bienvenida.');
        $stmt->bind_param('iiidds', $userId, $raffleId, $ticketIndex, $qualifyingValue, $threshold, $mode);
        $dup = false;
        try {
            $ok = $stmt->execute();
            $dup = !$ok && (int)$stmt->errno === 1062;
        } catch (mysqli_sql_exception $e) {
            $ok = false;
            $dup = ((int)$e->getCode() === 1062);
        }
        $stmt->close();
        if (!$ok) {
            throw new Exception($dup
                ? 'Tu Ticket de Bienvenida ya fue utilizado en otro sorteo.'
                : 'No se pudo registrar el Ticket de Bienvenida.');
        }
    }
}

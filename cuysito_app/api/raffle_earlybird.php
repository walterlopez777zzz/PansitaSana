<?php
// api/raffle_earlybird.php
// Recompensas "Early-bird" (Bananos) + soporte de señales anti-fraude.
//
// La configuración vive dentro de raffles.requirements (JSON), bajo las claves
// 'early_bird' y 'anti_fraud'. No requiere cambios manuales de esquema: las
// tablas auxiliares se crean con CREATE TABLE IF NOT EXISTS (mismo patrón que
// raffle_viewers en raffle_status.php).

if (!function_exists('eb_parse_config')) {

    /**
     * Lee y normaliza la configuración early-bird desde el JSON de requisitos.
     * Devuelve null si está desactivada o mal formada.
     */
    function eb_parse_config($reqs): ?array
    {
        if (!is_array($reqs) || empty($reqs['early_bird']) || !is_array($reqs['early_bird'])) {
            return null;
        }
        $eb = $reqs['early_bird'];
        $mode = $eb['mode'] ?? 'off';
        if (!in_array($mode, ['slots', 'time', 'tiered'], true)) {
            return null;
        }

        if ($mode === 'slots') {
            $pool = (float)($eb['pool_total'] ?? 0);
            $maxWinners = (int)($eb['max_winners'] ?? 0);
            if ($pool <= 0 || $maxWinners <= 0) return null;
            return ['mode' => 'slots', 'pool_total' => $pool, 'max_winners' => $maxWinners];
        }

        if ($mode === 'time') {
            $pool = (float)($eb['pool_total'] ?? 0);
            $window = (int)($eb['window_seconds'] ?? 0);
            if ($pool <= 0 || $window <= 0) return null;
            $cfg = ['mode' => 'time', 'pool_total' => $pool, 'window_seconds' => $window];
            // Ancla manual opcional: si el admin pulsó "Iniciar ventana ahora",
            // se guarda aquí el datetime UTC desde el que corre el cronómetro.
            // Si está vacío, la ventana corre desde el start_time del sorteo.
            $ws = trim((string)($eb['window_started_at'] ?? ''));
            if ($ws !== '' && $ws !== '0000-00-00 00:00:00') {
                $cfg['window_started_at'] = $ws;
            }
            return $cfg;
        }

        // tiered
        $rawTiers = $eb['tiers'] ?? [];
        $tiers = [];
        if (is_array($rawTiers)) {
            foreach ($rawTiers as $t) {
                $tiers[] = round((float)$t, 2);
            }
        }
        // Quitar ceros sobrantes al final (puestos sin premio).
        while (!empty($tiers) && $tiers[count($tiers) - 1] <= 0) {
            array_pop($tiers);
        }
        if (empty($tiers)) return null;
        return ['mode' => 'tiered', 'tiers' => $tiers];
    }

    /**
     * Devuelve el datetime UTC desde el que corre la ventana del modo 'time'.
     * Prioridad: ancla manual (botón del panel) > start_time del sorteo (el
     * momento exacto en que pasa a estado 'open'). Devuelve null si todavía no
     * hay ancla, es decir, si la ventana aún no ha iniciado.
     */
    function eb_time_window_anchor(array $cfg, ?string $raffleStartTime): ?string
    {
        if (!empty($cfg['window_started_at'])) {
            return (string)$cfg['window_started_at'];
        }
        $st = trim((string)($raffleStartTime ?? ''));
        if ($st === '' || $st === '0000-00-00 00:00:00') return null;
        return $st;
    }

    /**
     * Crea las tablas auxiliares (idempotente).
     */
    function eb_ensure_tables(mysqli $conn): void
    {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS raffle_early_bird_rewards (
                id INT AUTO_INCREMENT PRIMARY KEY,
                raffle_id INT NOT NULL,
                user_id INT NOT NULL,
                ticket_index INT NOT NULL,
                position INT NOT NULL,
                mode VARCHAR(16) NOT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                status VARCHAR(12) NOT NULL DEFAULT 'pending',
                created_at DATETIME NOT NULL,
                settled_at DATETIME NULL,
                UNIQUE KEY uq_raffle_user (raffle_id, user_id),
                KEY idx_raffle_status (raffle_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $conn->query(
            "CREATE TABLE IF NOT EXISTS raffle_entry_signals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                raffle_id INT NOT NULL,
                user_id INT NOT NULL,
                ip_address VARCHAR(64) NOT NULL DEFAULT '',
                fingerprint VARCHAR(64) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_raffle_user (raffle_id, user_id),
                KEY idx_ip (raffle_id, ip_address),
                KEY idx_fp (raffle_id, fingerprint)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /**
     * Abona Bananos (coins) al usuario de forma atómica dentro de la
     * transacción activa. Garantiza la fila en coins, registra el movimiento
     * y deja una notificación.
     */
    function eb_credit(mysqli $conn, int $userId, float $amount, int $raffleId, string $title, string $message): void
    {
        if ($amount <= 0) return;

        $conn->query("INSERT IGNORE INTO coins (user_id, balance) VALUES ($userId, 0.00)");

        $upd = $conn->prepare("UPDATE coins SET balance = balance + ? WHERE user_id = ?");
        $upd->bind_param("di", $amount, $userId);
        $upd->execute();

        $refId = (string)$raffleId;
        $tx = $conn->prepare(
            "INSERT INTO coin_transactions (user_id, amount, reason, reference_type, reference_id, created_at)
             VALUES (?, ?, 'raffle_early_bird', 'raffle_id', ?, UTC_TIMESTAMP())"
        );
        $tx->bind_param("ids", $userId, $amount, $refId);
        $tx->execute();

        $notif = $conn->prepare(
            "INSERT INTO notifications (user_id, type, title, message, created_at)
             VALUES (?, 'reward', ?, ?, UTC_TIMESTAMP())"
        );
        if ($notif) {
            $notif->bind_param("iss", $userId, $title, $message);
            $notif->execute();
        }
    }

    /**
     * Evalúa y otorga la recompensa early-bird en el momento de la inscripción.
     * Debe llamarse DENTRO de la transacción de raffle_join (después de insertar
     * al participante). Devuelve la info para el modal o null si no aplica.
     *
     * Para los modos 'slots' y 'tiered' el monto es determinístico y se paga al
     * instante. Para 'time' el reparto depende de cuántos entren en la ventana,
     * así que se registra como 'pending' y se liquida al cerrar la ventana.
     */
    function eb_award_on_join(mysqli $conn, array $cfg, int $raffleId, int $userId, int $ticketIndex, ?string $raffleStartTime = null): ?array
    {
        $position = $ticketIndex + 1; // #1 = primer inscrito

        if ($cfg['mode'] === 'slots') {
            if ($ticketIndex >= $cfg['max_winners']) return null;
            $amount = round($cfg['pool_total'] / $cfg['max_winners'], 2);
            if ($amount <= 0) return null;
            eb_record_reward($conn, $raffleId, $userId, $ticketIndex, $position, 'slots', $amount, 'paid');
            eb_credit($conn, $userId, $amount, $raffleId,
                '¡Recompensa Early-bird!',
                "Fuiste el #$position en entrar y ganaste $amount bananos por tu rapidez.");
            return ['mode' => 'slots', 'position' => $position, 'amount' => $amount, 'status' => 'paid'];
        }

        if ($cfg['mode'] === 'tiered') {
            $tiers = $cfg['tiers'];
            if ($ticketIndex >= count($tiers)) return null;
            $amount = round((float)$tiers[$ticketIndex], 2);
            if ($amount <= 0) return null;
            eb_record_reward($conn, $raffleId, $userId, $ticketIndex, $position, 'tiered', $amount, 'paid');
            eb_credit($conn, $userId, $amount, $raffleId,
                '¡Recompensa Early-bird!',
                "Fuiste el #$position en entrar y ganaste $amount bananos.");
            return ['mode' => 'tiered', 'position' => $position, 'amount' => $amount, 'status' => 'paid'];
        }

        // mode === 'time': la ventana corre ESTRICTAMENTE desde que el sorteo
        // abre (start_time) o desde el ancla manual fijada con el botón del
        // panel. NO espera al primer inscrito: da urgencia real al "flash".
        $window = (int)$cfg['window_seconds'];
        $anchor = eb_time_window_anchor($cfg, $raffleStartTime);
        if ($anchor === null) return null; // la ventana aún no ha iniciado

        $elapsedStmt = $conn->prepare("SELECT TIMESTAMPDIFF(SECOND, ?, UTC_TIMESTAMP()) AS elapsed");
        $elapsedStmt->bind_param("s", $anchor);
        $elapsedStmt->execute();
        $elapsed = (int)($elapsedStmt->get_result()->fetch_assoc()['elapsed'] ?? PHP_INT_MAX);

        if ($elapsed < 0) return null;       // la ventana todavía no arranca
        if ($elapsed > $window) return null; // entró fuera de la ventana

        eb_record_reward($conn, $raffleId, $userId, $ticketIndex, $position, 'time', 0.00, 'pending');
        return [
            'mode' => 'time',
            'position' => $position,
            'amount' => 0,
            'status' => 'pending',
            'window_seconds' => $window,
            'window_ends_in' => max(0, $window - $elapsed)
        ];
    }

    function eb_record_reward(mysqli $conn, int $raffleId, int $userId, int $ticketIndex, int $position, string $mode, float $amount, string $status): void
    {
        $when = ($status === 'paid') ? 'UTC_TIMESTAMP()' : 'NULL';
        $sql = "INSERT IGNORE INTO raffle_early_bird_rewards
                    (raffle_id, user_id, ticket_index, position, mode, amount, status, created_at, settled_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), $when)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiiisds", $raffleId, $userId, $ticketIndex, $position, $mode, $amount, $status);
        $stmt->execute();
    }

    /**
     * Liquida el pozo por tiempo (modo 'time') una vez que la ventana cerró.
     * Best-effort, idempotente y con bloqueo de filas para evitar doble pago.
     * Debe llamarse FUERA de una transacción de inscripción.
     */
    function eb_settle_time_pool(mysqli $conn, int $raffleId, array $cfg, ?string $raffleStartTime = null): void
    {
        if ($cfg['mode'] !== 'time') return;
        $window = (int)$cfg['window_seconds'];
        $pool = (float)$cfg['pool_total'];

        try {
            // ¿Ya cerró la ventana? El ancla es start_time (o el botón manual),
            // NO el primer inscrito.
            $anchor = eb_time_window_anchor($cfg, $raffleStartTime);
            if ($anchor === null) return; // ventana aún no iniciada

            $aStmt = $conn->prepare("SELECT TIMESTAMPDIFF(SECOND, ?, UTC_TIMESTAMP()) AS elapsed");
            $aStmt->bind_param("s", $anchor);
            $aStmt->execute();
            $elapsed = (int)($aStmt->get_result()->fetch_assoc()['elapsed'] ?? -1);
            if ($elapsed < 0) return;          // aún no arranca
            if ($elapsed <= $window) return;   // ventana aún abierta

            $conn->begin_transaction();

            $sel = $conn->prepare(
                "SELECT id, user_id, position FROM raffle_early_bird_rewards
                 WHERE raffle_id = ? AND mode = 'time' AND status = 'pending' FOR UPDATE"
            );
            $sel->bind_param("i", $raffleId);
            $sel->execute();
            $pending = $sel->get_result()->fetch_all(MYSQLI_ASSOC);

            if (empty($pending)) { $conn->commit(); return; }

            $share = round($pool / count($pending), 2);

            $updReward = $conn->prepare(
                "UPDATE raffle_early_bird_rewards SET amount = ?, status = 'paid', settled_at = UTC_TIMESTAMP() WHERE id = ?"
            );
            foreach ($pending as $p) {
                $rid = (int)$p['id'];
                $uid = (int)$p['user_id'];
                $pos = (int)$p['position'];
                $updReward->bind_param("di", $share, $rid);
                $updReward->execute();
                eb_credit($conn, $uid, $share, $raffleId,
                    '¡Recompensa Early-bird!',
                    "Entraste en la ventana rápida (#$pos) y te llevaste $share bananos del pozo.");
            }

            $conn->commit();
        } catch (Throwable $e) {
            @$conn->rollback();
            error_log('eb_settle_time_pool error: ' . $e->getMessage());
        }
    }

    // =====================================================================
    // ANTI-FRAUDE (Límite por IP + Huella de dispositivo)
    // =====================================================================

    /**
     * Lee la configuración anti-fraude desde requirements. Por defecto el
     * límite por IP está ACTIVO con máximo 1, tal como pidió el negocio.
     */
    function af_parse_config($reqs): array
    {
        $af = (is_array($reqs) && isset($reqs['anti_fraud']) && is_array($reqs['anti_fraud']))
            ? $reqs['anti_fraud'] : [];
        // ip_limit_enabled ausente => activo por defecto.
        $ipEnabled = array_key_exists('ip_limit_enabled', $af) ? (int)$af['ip_limit_enabled'] : 1;
        $ipMax = isset($af['ip_limit_max']) ? max(1, (int)$af['ip_limit_max']) : 1;
        $fpEnabled = array_key_exists('fingerprint_enabled', $af) ? (int)$af['fingerprint_enabled'] : 1;
        return [
            'ip_limit_enabled' => $ipEnabled ? 1 : 0,
            'ip_limit_max' => $ipMax,
            'fingerprint_enabled' => $fpEnabled ? 1 : 0,
        ];
    }

    /**
     * Valida IP y huella para un usuario NO verificado. Devuelve un mensaje de
     * error si debe bloquearse, o null si puede entrar.
     *
     * REGLA CRÍTICA: los usuarios verificados NUNCA pasan por aquí (se exoneran
     * antes de llamar a esta función).
     */
    function af_check_entry(mysqli $conn, array $afCfg, int $raffleId, int $userId, string $ip, string $fingerprint): ?string
    {
        if (!$afCfg['ip_limit_enabled'] && !$afCfg['fingerprint_enabled']) {
            return null;
        }
        $max = $afCfg['ip_limit_max'];

        // Límite por IP: nº de cuentas DISTINTAS (≠ usuario actual) desde esta IP.
        if ($afCfg['ip_limit_enabled'] && $ip !== '' && $ip !== '0.0.0.0') {
            $stmt = $conn->prepare(
                "SELECT COUNT(DISTINCT user_id) AS c FROM raffle_entry_signals
                 WHERE raffle_id = ? AND ip_address = ? AND user_id <> ?"
            );
            $stmt->bind_param("isi", $raffleId, $ip, $userId);
            $stmt->execute();
            $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            if ($c >= $max) {
                return 'Se alcanzó el límite de cuentas permitidas desde tu red para este sorteo.';
            }
        }

        // Huella de dispositivo: mismo límite por dispositivo (reduce multicuenta).
        if ($afCfg['fingerprint_enabled'] && $fingerprint !== '') {
            $stmt = $conn->prepare(
                "SELECT COUNT(DISTINCT user_id) AS c FROM raffle_entry_signals
                 WHERE raffle_id = ? AND fingerprint = ? AND user_id <> ?"
            );
            $stmt->bind_param("isi", $raffleId, $fingerprint, $userId);
            $stmt->execute();
            $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            if ($c >= $max) {
                return 'Este dispositivo ya alcanzó el límite de cuentas permitidas para este sorteo.';
            }
        }

        return null;
    }

    /**
     * Registra la señal (IP + huella) del participante. Idempotente por usuario.
     */
    function af_record_signal(mysqli $conn, int $raffleId, int $userId, string $ip, string $fingerprint): void
    {
        $stmt = $conn->prepare(
            "INSERT INTO raffle_entry_signals (raffle_id, user_id, ip_address, fingerprint, created_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE ip_address = VALUES(ip_address), fingerprint = VALUES(fingerprint)"
        );
        if ($stmt) {
            $stmt->bind_param("iiss", $raffleId, $userId, $ip, $fingerprint);
            $stmt->execute();
        }
    }
}

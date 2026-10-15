<?php
/**
 * raffle_review.php
 * ----------------------------------------------------------------------------
 * Esquema y utilidades del flujo "Bajo Revisión" de los Sorteos Flash:
 *   - review_status en raffle_participants (active | under_review | expelled)
 *     -> 'expelled' deja el ticket como HUECO físico (no se borra la fila), para
 *        que la recta numérica conserve el número y el motor lo rechace.
 *   - raffle_strikes: historial de expulsiones por usuario (sin ban automático).
 *   - raffle_snapshots: foto de auditoría congelada en T_freeze (cierre de
 *     inscripción), para Provably Fair verificable.
 *
 * Todas las tablas/columnas se crean de forma idempotente (IF NOT EXISTS /
 * SHOW COLUMNS), igual que raffle_whitelist y las tablas early-bird, para no
 * requerir migración manual en producción.
 */

if (!function_exists('rr_ensure_column')) {
    /**
     * ALTER TABLE idempotente: agrega la columna solo si no existe.
     * $table y $column son identificadores internos (nunca input del usuario).
     */
    function rr_ensure_column(mysqli $conn, string $table, string $column, string $definition): void
    {
        $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($column) . "'");
        if ($res && $res->num_rows === 0) {
            $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }
}

if (!function_exists('rr_ensure_schema')) {

    function rr_ensure_schema(mysqli $conn): void
    {
        // 1. Columna review_status (hueco = 'expelled', no se borra la fila).
        $col = $conn->query("SHOW COLUMNS FROM raffle_participants LIKE 'review_status'");
        if ($col && $col->num_rows === 0) {
            $conn->query(
                "ALTER TABLE raffle_participants
                 ADD COLUMN review_status ENUM('active','under_review','expelled')
                 NOT NULL DEFAULT 'active'"
            );
        }

        // 2. Historial de expulsiones (strikes). Sin ban automático: el admin
        //    decide a mano si banea según la cantidad de strikes previos.
        $conn->query(
            "CREATE TABLE IF NOT EXISTS raffle_strikes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                raffle_id INT NOT NULL DEFAULT 0,
                reason VARCHAR(255) NOT NULL DEFAULT '',
                created_by INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                KEY idx_user (user_id),
                KEY idx_raffle (raffle_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // 3. Snapshot de auditoría congelado en T_freeze (cierre de inscripción).
        $conn->query(
            "CREATE TABLE IF NOT EXISTS raffle_snapshots (
                raffle_id INT NOT NULL PRIMARY KEY,
                snapshot_json MEDIUMTEXT NOT NULL,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // 4. Marca persistente de "entró declarando compra por chat" (Bajo
        //    Revisión). Sobrevive a aprobaciones/auto-entradas: se usa para la
        //    lista de Revisión Diferida (auditar tras el sorteo, con calma).
        $col2 = $conn->query("SHOW COLUMNS FROM raffle_participants LIKE 'declared'");
        if ($col2 && $col2->num_rows === 0) {
            $conn->query(
                "ALTER TABLE raffle_participants
                 ADD COLUMN declared TINYINT(1) NOT NULL DEFAULT 0"
            );
        }

        // 4b. Pre-elección de premio (UX): preferencia capturada al inscribirse
        //     ("Si llegas a ganar, ¿qué premio prefieres?"). Persiste por
        //     participante y permite aplicar la elección automáticamente si el
        //     usuario gana aunque haya cerrado la pestaña (fallback). NULL = sin
        //     preferencia → flujo de decisión manual normal.
        $colPre = $conn->query("SHOW COLUMNS FROM raffle_participants LIKE 'pre_chosen_prize'");
        if ($colPre && $colPre->num_rows === 0) {
            $conn->query(
                "ALTER TABLE raffle_participants
                 ADD COLUMN pre_chosen_prize ENUM('original','cashback','gamble') NULL DEFAULT NULL"
            );
        }

        // 4b-bis. Configuración COMPLETA del Doble o Nada anticipado: si el
        //     participante pre-elige "gamble", guardamos además la animación
        //     (coin/slot/roulette) y, en modo moneda, el lado (heads/tails). El
        //     servidor NUNCA adivina: si falta esta config, el fallback AFK
        //     entrega el premio original.
        $colPreAnim = $conn->query("SHOW COLUMNS FROM raffle_participants LIKE 'pre_gamble_anim'");
        if ($colPreAnim && $colPreAnim->num_rows === 0) {
            $conn->query(
                "ALTER TABLE raffle_participants
                 ADD COLUMN pre_gamble_anim ENUM('coin','slot','roulette') NULL DEFAULT NULL"
            );
        }
        // El lado admite moneda (heads/tails) y ruleta (red/black). Si la
        // columna existe como ENUM sin los colores, MySQL truncaría 'red' a ''
        // silenciosamente, así que la ampliamos (idempotente).
        $colPreSide = $conn->query("SHOW COLUMNS FROM raffle_participants LIKE 'pre_gamble_side'");
        if ($colPreSide && $colPreSide->num_rows === 0) {
            $conn->query(
                "ALTER TABLE raffle_participants
                 ADD COLUMN pre_gamble_side ENUM('heads','tails','red','black') NULL DEFAULT NULL"
            );
        } elseif ($colPreSide) {
            $preSideCol = $colPreSide->fetch_assoc();
            $preSideType = strtolower((string)($preSideCol['Type'] ?? ''));
            if (strpos($preSideType, 'enum(') === 0 && strpos($preSideType, "'red'") === false) {
                $conn->query(
                    "ALTER TABLE raffle_participants
                     MODIFY COLUMN pre_gamble_side ENUM('heads','tails','red','black') NULL DEFAULT NULL"
                );
            }
        }

        // Igual para la elección del ganador en vivo: si raffle_winners.coin_choice
        // es un ENUM que no admite 'red'/'black', el submit de ruleta se guardaría
        // como '' y la fila quedaría atascada sin bloque objetivo.
        $colCoinChoice = $conn->query("SHOW COLUMNS FROM raffle_winners LIKE 'coin_choice'");
        if ($colCoinChoice && $colCoinChoice->num_rows > 0) {
            $coinChoiceCol = $colCoinChoice->fetch_assoc();
            $coinChoiceType = strtolower((string)($coinChoiceCol['Type'] ?? ''));
            if (strpos($coinChoiceType, 'enum(') === 0 && strpos($coinChoiceType, "'red'") === false) {
                $conn->query(
                    "ALTER TABLE raffle_winners
                     MODIFY COLUMN coin_choice ENUM('heads','tails','red','black') NULL DEFAULT NULL"
                );
            }
        }

        // 4b-ter. Pre-selección del ítem del pool por puesto: JSON
        //     {"1":"p1_o2", "2":"p2_o1", ...} guardado desde el sub-modal de
        //     pre-elección. Si el participante gana un puesto con pool y está
        //     AFK, el servidor entrega su ítem pre-elegido; sin pre-selección
        //     se asigna la primera opción del pool (asignación automática).
        $colPrePool = $conn->query("SHOW COLUMNS FROM raffle_participants LIKE 'pre_pool_choices'");
        if ($colPrePool && $colPrePool->num_rows === 0) {
            $conn->query(
                "ALTER TABLE raffle_participants
                 ADD COLUMN pre_pool_choices TEXT NULL DEFAULT NULL"
            );
        }

        // 4c. Toggle del modal de pre-elección (config por sorteo). Activado por
        //     defecto. Si el admin lo desactiva, NO se muestra el modal al
        //     inscribirse y el ganador elige obligatoriamente en vivo.
        $colAllowPre = $conn->query("SHOW COLUMNS FROM raffles LIKE 'allow_pre_choice'");
        if ($colAllowPre && $colAllowPre->num_rows === 0) {
            $conn->query(
                "ALTER TABLE raffles
                 ADD COLUMN allow_pre_choice TINYINT(1) NOT NULL DEFAULT 1"
            );
        }

        // 4d. Ventana de auto-aplicación de la pre-elección (fase de decisión).
        //     `pre_apply_expires_at`: instante en que el polling de cualquier
        //     espectador aplica la pre-elección del ganador (≈15s tras iniciar la
        //     fase), MIENTRAS el contador global sigue mostrando el tiempo total
        //     (60→45). `pre_apply_cancelled`: el ganador en vivo pulsó el botón de
        //     pánico ("prefiero elegir manualmente") → no se auto-aplica y conserva
        //     el tiempo completo restante. Se expone a los espectadores para dar
        //     dramatismo ("¡Canceló su preselección!").
        $colPreApply = $conn->query("SHOW COLUMNS FROM raffle_winners LIKE 'pre_apply_expires_at'");
        if ($colPreApply && $colPreApply->num_rows === 0) {
            $conn->query(
                "ALTER TABLE raffle_winners
                 ADD COLUMN pre_apply_expires_at DATETIME NULL DEFAULT NULL"
            );
        }
        $colPreCancel = $conn->query("SHOW COLUMNS FROM raffle_winners LIKE 'pre_apply_cancelled'");
        if ($colPreCancel && $colPreCancel->num_rows === 0) {
            $conn->query(
                "ALTER TABLE raffle_winners
                 ADD COLUMN pre_apply_cancelled TINYINT(1) NOT NULL DEFAULT 0"
            );
        }

        // 4e. Premios seleccionables (Schema 2): estado de la selección concreta
        //     del ítem cuando el premio del puesto es un POOL.
        //     prize_state: awaiting_selection | selected | admin_pending |
        //     admin_resolved | NULL (premio fijo / sorteos legacy).
        //     final_prize: snapshot JSON del ítem definitivo (irreversible).
        $colPrizeState = $conn->query("SHOW COLUMNS FROM raffle_winners LIKE 'prize_state'");
        if ($colPrizeState && $colPrizeState->num_rows === 0) {
            $conn->query(
                "ALTER TABLE raffle_winners
                 ADD COLUMN prize_state VARCHAR(24) NULL DEFAULT NULL"
            );
        }
        $colFinalPrize = $conn->query("SHOW COLUMNS FROM raffle_winners LIKE 'final_prize'");
        if ($colFinalPrize && $colFinalPrize->num_rows === 0) {
            $conn->query(
                "ALTER TABLE raffle_winners
                 ADD COLUMN final_prize TEXT NULL DEFAULT NULL"
            );
        }
        $colPrizeSelAt = $conn->query("SHOW COLUMNS FROM raffle_winners LIKE 'prize_selected_at'");
        if ($colPrizeSelAt && $colPrizeSelAt->num_rows === 0) {
            $conn->query(
                "ALTER TABLE raffle_winners
                 ADD COLUMN prize_selected_at DATETIME NULL DEFAULT NULL"
            );
        }

        // 4f. Snapshot exacto del cashback acreditado. Evita recalcular importes
        //     históricos con precios o tasas que pueden cambiar después del sorteo.
        $colCashbackCredited = $conn->query("SHOW COLUMNS FROM raffle_winners LIKE 'cashback_credited_coins'");
        if ($colCashbackCredited && $colCashbackCredited->num_rows === 0) {
            $conn->query(
                "ALTER TABLE raffle_winners
                 ADD COLUMN cashback_credited_coins DECIMAL(12,2) NULL DEFAULT NULL"
            );
        }

        // 4g. Resto de columnas que leen/escriben la Vitrina y la entrega manual
        //     (pedido generado al elegir ítem, comprobante de entrega en /wabo).
        //     Se leen en cada sondeo de raffle_status.php: si faltan, TODO el
        //     endpoint cae con "Unknown column".
        rr_ensure_column($conn, 'raffle_winners', 'generated_order_id', 'INT NULL DEFAULT NULL');
        rr_ensure_column($conn, 'raffle_winners', 'delivery_status', "VARCHAR(16) NULL DEFAULT NULL");
        rr_ensure_column($conn, 'raffle_winners', 'delivered_at', 'DATETIME NULL DEFAULT NULL');
        rr_ensure_column($conn, 'raffle_winners', 'delivered_by', 'INT NULL DEFAULT NULL');
        rr_ensure_column($conn, 'raffle_winners', 'delivery_note', 'TEXT NULL DEFAULT NULL');
        rr_ensure_column($conn, 'raffle_winners', 'delivery_proof_image', 'TEXT NULL DEFAULT NULL');

        // 5. Compras manuales (dinero real cobrado por chat: WhatsApp/Messenger).
        //    Separada de `pedidos` (tienda web) y del estado VIP (is_verified).
        //    date-aware: cuenta para la elegibilidad solo si purchase_date >= minDate
        //    de la regla vigente del sorteo, así un cambio de regla la re-filtra solo.
        $conn->query(
            "CREATE TABLE IF NOT EXISTS raffle_manual_purchases (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                purchase_date DATE NOT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                channel ENUM('whatsapp','messenger','manual','other') NOT NULL DEFAULT 'manual',
                note VARCHAR(255) NOT NULL DEFAULT '',
                approved_by INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                KEY idx_user_date (user_id, purchase_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /**
     * Estadísticas de compras manuales de un usuario para la elegibilidad.
     * Si $minDate es null cuenta todo el historial; si no, solo las compras con
     * purchase_date >= minDate (date-aware → re-filtra solo al cambiar la regla).
     * @return array{count:int, sum:float}
     */
    function rr_manual_purchase_stats(mysqli $conn, int $userId, ?string $minDate = null): array
    {
        $tbl = $conn->query("SHOW TABLES LIKE 'raffle_manual_purchases'");
        if (!$tbl || $tbl->num_rows === 0) return ['count' => 0, 'sum' => 0.0];

        if ($minDate !== null && $minDate !== '') {
            $st = $conn->prepare(
                "SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS s
                 FROM raffle_manual_purchases WHERE user_id = ? AND purchase_date >= ?"
            );
            if (!$st) return ['count' => 0, 'sum' => 0.0];
            $st->bind_param("is", $userId, $minDate);
        } else {
            $st = $conn->prepare(
                "SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS s
                 FROM raffle_manual_purchases WHERE user_id = ?"
            );
            if (!$st) return ['count' => 0, 'sum' => 0.0];
            $st->bind_param("i", $userId);
        }
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        return ['count' => (int)($row['c'] ?? 0), 'sum' => (float)($row['s'] ?? 0)];
    }

    /** Inserta una compra manual (dinero real cobrado por chat). Devuelve el id. */
    function rr_add_manual_purchase(
        mysqli $conn,
        int $userId,
        string $purchaseDate,
        float $amount,
        string $channel = 'manual',
        string $note = '',
        int $adminId = 0
    ): int {
        rr_ensure_schema($conn);
        $allowed = ['whatsapp', 'messenger', 'manual', 'other'];
        if (!in_array($channel, $allowed, true)) $channel = 'manual';
        $ins = $conn->prepare(
            "INSERT INTO raffle_manual_purchases
                (user_id, purchase_date, amount, channel, note, approved_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        );
        if (!$ins) return 0;
        $ins->bind_param("isdssi", $userId, $purchaseDate, $amount, $channel, $note, $adminId);
        $ins->execute();
        return (int)$conn->insert_id;
    }

    /** Cuántas expulsiones (strikes) acumula históricamente un usuario. */
    function rr_strike_count(mysqli $conn, int $userId): int
    {
        $st = $conn->prepare("SELECT COUNT(*) AS c FROM raffle_strikes WHERE user_id = ?");
        if (!$st) return 0;
        $st->bind_param("i", $userId);
        $st->execute();
        return (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
    }

    /**
     * Construye y guarda el snapshot de auditoría de un sorteo en el momento del
     * cierre de inscripción (T_freeze). Incluye el espacio de tickets emitido y
     * los huecos (expulsados) para que el sorteo sea recomputable por cualquiera.
     */
    function rr_save_snapshot(mysqli $conn, int $raffleId): void
    {
        rr_ensure_schema($conn);

        // Espacio emitido = MAX(ticket_index) + 1 sobre TODAS las filas
        // (incluidos los huecos expulsados, que conservan su número).
        $maxStmt = $conn->prepare(
            "SELECT MAX(ticket_index) AS max_idx, COUNT(*) AS total
             FROM raffle_participants WHERE raffle_id = ?"
        );
        $maxStmt->bind_param("i", $raffleId);
        $maxStmt->execute();
        $maxRow = $maxStmt->get_result()->fetch_assoc();
        $maxTickets = ($maxRow['max_idx'] === null) ? 0 : ((int)$maxRow['max_idx'] + 1);

        // Tickets vacíos (expulsados) + roster de tickets válidos.
        $rosterStmt = $conn->prepare(
            "SELECT ticket_index, user_id, review_status
             FROM raffle_participants WHERE raffle_id = ? ORDER BY ticket_index ASC"
        );
        $rosterStmt->bind_param("i", $raffleId);
        $rosterStmt->execute();
        $res = $rosterStmt->get_result();

        $expelled = [];
        $roster   = [];
        while ($r = $res->fetch_assoc()) {
            $t = (int)$r['ticket_index'];
            if ($r['review_status'] === 'expelled') {
                $expelled[] = $t;
            } else {
                $roster[] = ['ticket' => $t, 'user_id' => (int)$r['user_id']];
            }
        }

        // Hash del roster válido (commitment): permite verificar que el roster no
        // se tocó después de congelar (la semilla del bloque BSC se revela luego).
        $rosterHash = hash('sha256', json_encode($roster));

        $snapshot = [
            'frozen_at'       => gmdate('Y-m-d H:i:s'),
            'max_tickets'     => $maxTickets,
            'expelled_tickets' => $expelled,
            'valid_count'     => count($roster),
            'roster_hash'     => $rosterHash,
        ];
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE);

        $ins = $conn->prepare(
            "INSERT INTO raffle_snapshots (raffle_id, snapshot_json, created_at)
             VALUES (?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE snapshot_json = VALUES(snapshot_json)"
        );
        if ($ins) {
            $ins->bind_param("is", $raffleId, $json);
            $ins->execute();
        }
    }

    /** Devuelve el snapshot guardado (array) o null si no existe. */
    function rr_get_snapshot(mysqli $conn, int $raffleId): ?array
    {
        $tbl = $conn->query("SHOW TABLES LIKE 'raffle_snapshots'");
        if (!$tbl || $tbl->num_rows === 0) return null;
        $st = $conn->prepare("SELECT snapshot_json FROM raffle_snapshots WHERE raffle_id = ?");
        if (!$st) return null;
        $st->bind_param("i", $raffleId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if (!$row) return null;
        $data = json_decode($row['snapshot_json'] ?? '', true);
        return is_array($data) ? $data : null;
    }
}

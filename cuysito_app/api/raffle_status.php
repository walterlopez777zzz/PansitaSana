<?php
// api/raffle_status.php
// V2: SOPORTE PARA BOTE, MUERTE SÚBITA Y ESTADOS DE DECISIÓN

header('Content-Type: application/json');
// --- BLOQUE ANTI-CACHÉ (HTTP + LiteSpeed + CDN + Nginx) ---
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("X-LiteSpeed-Cache-Control: no-cache");
header("Surrogate-Control: no-store");
header("X-Accel-Expires: 0");

date_default_timezone_set('America/Lima');
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/raffle_core.php';
require_once dirname(__DIR__) . '/core/AvatarCatalog.php';
require_once __DIR__ . '/raffle_earlybird.php';
require_once __DIR__ . '/raffle_welcome.php';
require_once __DIR__ . '/raffle_private.php';

function _outputJson($data) {
    $raw = json_encode($data);
    if (defined('MODO_DEBUG') && MODO_DEBUG) {
        echo $raw;
    } else {
        $sn = openssl_random_pseudo_bytes(16);
        $sd = openssl_encrypt($raw, 'aes-256-cbc', CART_SYNC_TOKEN, OPENSSL_RAW_DATA, $sn);
        echo json_encode(['_sd' => base64_encode($sd), '_sn' => base64_encode($sn)]);
    }
}

require_once dirname(__DIR__) . '/config/shop_config.php';
$cacheFile = ShopSourceConfig::activeSnapshotPath() ?? ShopSourceConfig::liveSnapshotPath();
$rpcUrl = 'https://bsc-dataseed.binance.org/';

// Iniciamos sesión (silenciosamente) para identificar al usuario que consulta
if (session_status() === PHP_SESSION_NONE) session_start();
$myUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

$raffleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($raffleId <= 0) {
    _outputJson(['status' => 'error', 'message' => 'ID inválido']);
    exit;
}

try {
    // CRITICAL: Force READ COMMITTED so every SELECT reads the latest committed
    // data, bypassing REPEATABLE READ snapshot isolation and MySQL query cache.
    // This eliminates stale reads when raffle_gamble.php commits from another connection.
    $conn->query("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");

    // ===== HEARTBEAT DE PRESENCIA (métricas en vivo del panel admin) =====
    // Cada poll del usuario registra/actualiza su presencia en la sala. Esto
    // alimenta los contadores "Rating en vivo" y "Espectadores Únicos" del admin.
    // Es best-effort: cualquier fallo aquí jamás debe romper el estado del sorteo.
    try {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS raffle_viewers (
                raffle_id INT NOT NULL,
                viewer_key VARCHAR(64) NOT NULL,
                user_id INT NOT NULL DEFAULT 0,
                last_seen DATETIME NOT NULL,
                PRIMARY KEY (raffle_id, viewer_key),
                KEY idx_last_seen (raffle_id, last_seen)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $viewerKey = $myUserId > 0 ? ('u' . $myUserId) : ('s' . substr(session_id() ?: 'anon', 0, 60));
        $hb = $conn->prepare(
            "INSERT INTO raffle_viewers (raffle_id, viewer_key, user_id, last_seen)
             VALUES (?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE last_seen = UTC_TIMESTAMP(), user_id = VALUES(user_id)"
        );
        if ($hb) {
            $hb->bind_param("isi", $raffleId, $viewerKey, $myUserId);
            $hb->execute();
            $hb->close();
        }
    } catch (Throwable $hbErr) { /* presencia best-effort */ }

    // ===== LIQUIDACIÓN EARLY-BIRD (pozo por tiempo) =====
    // Best-effort: si la ventana ya cerró, reparte el pozo aunque no haya nuevas
    // inscripciones. Nunca debe romper el estado del sorteo.
    try {
        eb_ensure_tables($conn);
        $ebReqStmt = $conn->prepare("SELECT requirements, start_time FROM raffles WHERE raffle_id = ?");
        $ebReqStmt->bind_param("i", $raffleId);
        $ebReqStmt->execute();
        $ebReqRow = $ebReqStmt->get_result()->fetch_assoc();
        $ebReqs = json_decode($ebReqRow['requirements'] ?? '{}', true);
        $ebStartTime = $ebReqRow['start_time'] ?? null;
        $ebCfg = eb_parse_config($ebReqs);
        if ($ebCfg && $ebCfg['mode'] === 'time') {
            eb_settle_time_pool($conn, $raffleId, $ebCfg, $ebStartTime);
        }
    } catch (Throwable $ebErr) { /* early-bird best-effort */ }

// 1. OBTENER DATOS (Incluyendo columnas V2 + Animaciones)
    $sql = "SELECT raffle_id, title, status, start_time, end_time, 
            server_seed_hash, server_seed_secret, bsc_block_hash, bsc_block_target, 
            winners_count, prize_config, replay_minutes,
            entry_cost, current_pot, max_participants,
            allow_cashback, allow_double_or_nothing, cashback_percent, decision_timeout,
            main_animation, gamble_animation, execution_mode, allow_winner_animation_choice,
            allow_pre_choice, requirements,
            animation_start_at, decision_phase_reached_at,
            " . rp_private_column_sql($conn) . "
            FROM raffles WHERE raffle_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $raffleId);
    $stmt->execute();
    $raffle = $stmt->get_result()->fetch_assoc();

    if (!$raffle) throw new Exception("Sorteo no encontrado");

    // =========================================================================
    // FIRST-CALLER RESOLUTION: State Machine Triggers
    // The first polling request that detects a state change does the heavy
    // lifting via raffle_core.php. Subsequent requests just read the result.
    // =========================================================================
    $stateChanged = false;

    // Pre-fetch the live BSC block ONCE for all triggers and the response.
    // This avoids redundant RPC calls (rate-limit safe) and provides a single
    // source of truth for block comparisons.
    $earlyLiveBlock = null;
    if (in_array($raffle['status'], ['locking', 'waiting_for_winner'], true) && $raffle['bsc_block_target']) {
        $earlyLiveBlock = getRpcBlockNumber($rpcUrl);
    }

    // Trigger 1: Time's Up — open → locking
    if ($raffle['status'] === 'open') {
        $endTime = strtotime($raffle['end_time']);
        if ($endTime !== false && $endTime <= time()) {
            $closeResult = triggerRaffleClose($conn, $raffleId, $rpcUrl);
            if ($closeResult['success']) {
                $stateChanged = true;
            }
        }
    }

    // Trigger 2: Block Reached — locking → waiting_for_winner / completed
    if ($raffle['status'] === 'locking') {
        $resolveResult = triggerRaffleResolve($conn, $raffleId, $rpcUrl, $cacheFile);
        if ($resolveResult['success']) {
            $stateChanged = true;
        }
    }

    // Trigger 3: Gamble Block Reached — resolve pending gambles server-side.
    // ANY client polling raffle_status.php can trigger this, making the system
    // bulletproof against the active winner disconnecting after submitting.
    if ($raffle['status'] === 'waiting_for_winner') {
        $gambleResult = triggerGambleResolve($conn, $raffleId, $rpcUrl, $cacheFile, $earlyLiveBlock);
        if ($gambleResult['success']) {
            $stateChanged = true;
        } else {
            error_log('[raffle_status] Trigger3 returned: ' . ($gambleResult['message'] ?? 'unknown'));
        }
    }

    // Data Freshness: Re-fetch raffle row so the JSON response reflects the new state
    if ($stateChanged) {
        $stmtRefresh = $conn->prepare($sql);
        $stmtRefresh->bind_param("i", $raffleId);
        $stmtRefresh->execute();
        $raffle = $stmtRefresh->get_result()->fetch_assoc();
        $stmtRefresh->close();
        if (!$raffle) throw new Exception("Sorteo no encontrado tras actualización de estado");
    }

    // Aseguramos el esquema (columnas review_status, pre-elección y la ventana
    // de auto-aplicación pre_apply_*) ANTES del lazy-start y del cierre por
    // timeout, ya que ambos usan esas columnas de raffle_winners.
    rr_ensure_schema($conn);
    wt_ensure_schema($conn);
    // Cupones de OFERTA (daily_discounts): DDL fuera de las transacciones de entrega.
    dd_ensure_schema($conn);

    // =========================================================================
    // LAZY START: Activate decision timer on first anim_finished signal.
    // The timer is NOT started at resolution time (decision_expires_at = NULL).
    // The first client whose animation finishes sends &anim_finished=true,
    // which triggers the real deadline calculation here.
    // =========================================================================
    if (($raffle['status'] ?? '') === 'waiting_for_winner' && !empty($_GET['anim_finished'])) {
        // GLOBAL STATE FORCING: Mark decision phase as reached (idempotent).
        // This flag lets late-joining or refreshing clients know that the
        // animation window should no longer block the decision UI.
        if (empty($raffle['decision_phase_reached_at'])) {
            $stmtDecPhase = $conn->prepare("UPDATE raffles SET decision_phase_reached_at = UTC_TIMESTAMP() WHERE raffle_id = ? AND decision_phase_reached_at IS NULL");
            $stmtDecPhase->bind_param("i", $raffleId);
            $stmtDecPhase->execute();
            $stmtDecPhase->close();
            $raffle['decision_phase_reached_at'] = date('Y-m-d H:i:s');
        }

        $stmtCheckNull = $conn->prepare(
            "SELECT COUNT(*) AS c FROM raffle_winners WHERE raffle_id = ? AND choice = 'pending' AND decision_expires_at IS NULL"
        );
        $stmtCheckNull->bind_param("i", $raffleId);
        $stmtCheckNull->execute();
        $nullCount = (int)($stmtCheckNull->get_result()->fetch_assoc()['c'] ?? 0);
        $stmtCheckNull->close();

        // SIN ALTERNATIVAS (Cashback y Doble o Nada inexistentes): el ganador
        // no tiene nada que decidir, así que en vez de abrir una ventana de
        // decisión vacía reclamamos su premio original en el acto, con la misma
        // entrega que el reclamo manual (pool → ítem pre-elegido o Vitrina). El
        // cierre por barrido de más abajo marca el sorteo como completado.
        if ($nullCount > 0 && !rr_raffle_has_alternatives($raffle)) {
            $conn->begin_transaction();
            try {
                $stmtSole = $conn->prepare(
                    "SELECT w.id, w.user_id, w.position, u.game_username, u.email, u.whatsapp_phone_e164, rp.pre_pool_choices
                     FROM raffle_winners w
                     JOIN users u ON u.id = w.user_id
                     LEFT JOIN raffle_participants rp ON rp.raffle_id = w.raffle_id AND rp.user_id = w.user_id
                     WHERE w.raffle_id = ? AND w.choice = 'pending' AND w.decision_expires_at IS NULL
                     FOR UPDATE"
                );
                $stmtSole->bind_param("i", $raffleId);
                $stmtSole->execute();
                $soleRows = $stmtSole->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmtSole->close();

                foreach ($soleRows as $soleRow) {
                    $sp = 'sp_sole_' . (int)$soleRow['id'];
                    $conn->query("SAVEPOINT $sp");
                    try {
                        rr_claim_original_prize($conn, $raffle, $soleRow, $soleRow['pre_pool_choices'] ?? null, $cacheFile, $soleRow);
                        $conn->query("RELEASE SAVEPOINT $sp");
                    } catch (Throwable $eSole) {
                        $conn->query("ROLLBACK TO SAVEPOINT $sp");
                        $conn->query("RELEASE SAVEPOINT $sp");
                        error_log("[LazyStart] Raffle $raffleId: auto-claim failed for winner row {$soleRow['id']}: " . $eSole->getMessage());
                        // Degradación: la fila entra al fallback por timeout de abajo.
                        $expiredNow = date('Y-m-d H:i:s');
                        $stmtDegrade = $conn->prepare("UPDATE raffle_winners SET decision_expires_at = ? WHERE id = ?");
                        $stmtDegrade->bind_param("si", $expiredNow, $soleRow['id']);
                        $stmtDegrade->execute();
                        $stmtDegrade->close();
                    }
                }
                $conn->commit();
                error_log("[LazyStart] Raffle $raffleId: no alternatives — original prize auto-claimed for " . count($soleRows) . " winner(s).");
            } catch (Throwable $eAuto) {
                $conn->rollback();
                error_log("[LazyStart] Raffle $raffleId: auto-claim transaction failed: " . $eAuto->getMessage());
            }
            $nullCount = 0;
        }

        if ($nullCount > 0) {
            $timeoutSeconds = (int)($raffle['decision_timeout'] ?? 60);
            if ($timeoutSeconds <= 0) $timeoutSeconds = 60;
            $lazyExpireDate = date('Y-m-d H:i:s', time() + $timeoutSeconds);

            // NUEVO MODELO DE TIEMPOS (P7): el contador GLOBAL muestra siempre el
            // tiempo total (p. ej. 60s) y va bajando de 60 → 45 → 0. La
            // pre-elección NO acorta el contador: se aplica en un instante
            // intermedio (≈15s tras iniciar la fase, cuando el contador marca 45)
            // mediante una marca aparte `pre_apply_expires_at`. Así:
            //   - Todos los ganadores reciben el deadline COMPLETO (display +
            //     deadline manual + último fallback).
            //   - Los que dejaron una pre-elección VÁLIDA y COMPLETA reciben
            //     además `pre_apply_expires_at = now + 15s`: el polling de
            //     cualquier espectador la aplica a los ~15s sin esperar los 60s.
            //   - Si el ganador en vivo pulsa "prefiero elegir manualmente"
            //     (cancel_auto), se marca `pre_apply_cancelled = 1` y conserva el
            //     tiempo completo restante (los 45s) para decidir a mano. La
            //     cancelación se muestra a los espectadores para dar dramatismo.
            $preApplySeconds = 15;
            if ($preApplySeconds >= $timeoutSeconds) {
                $preApplySeconds = max(1, $timeoutSeconds - 1);
            }
            $preApplyDate = date('Y-m-d H:i:s', time() + $preApplySeconds);
            $allowPreChoiceFlag = (int)($raffle['allow_pre_choice'] ?? 1);

            // 1) Deadline COMPLETO para TODOS los pendientes (display + manual).
            $stmtActivate = $conn->prepare(
                "UPDATE raffle_winners SET decision_expires_at = ? WHERE raffle_id = ? AND choice = 'pending' AND decision_expires_at IS NULL"
            );
            $stmtActivate->bind_param("si", $lazyExpireDate, $raffleId);
            $stmtActivate->execute();
            $stmtActivate->close();

            // 2) Ventana corta de auto-aplicación SOLO para pre-elecciones válidas
            //    y completas (no toca el contador global).
            if ($allowPreChoiceFlag === 1) {
                $stmtFast = $conn->prepare(
                    "UPDATE raffle_winners w
                     JOIN raffle_participants rp
                          ON rp.raffle_id = w.raffle_id AND rp.user_id = w.user_id
                     JOIN raffles r ON r.raffle_id = w.raffle_id
                     SET w.pre_apply_expires_at = ?, w.pre_apply_cancelled = 0
                     WHERE w.raffle_id = ?
                       AND w.choice = 'pending'
                       AND w.pre_apply_expires_at IS NULL
                       AND w.pre_apply_cancelled = 0
                       AND rp.pre_chosen_prize IS NOT NULL AND rp.pre_chosen_prize <> ''
                       AND (
                            rp.pre_chosen_prize = 'original'
                            OR (rp.pre_chosen_prize = 'cashback' AND r.allow_cashback = 1 AND r.cashback_percent > 0)
                            OR (rp.pre_chosen_prize = 'gamble'
                                AND r.allow_double_or_nothing = 1
                                AND r.execution_mode <> 'fast_batch'
                                AND rp.pre_gamble_anim IS NOT NULL AND rp.pre_gamble_anim <> ''
                                AND (rp.pre_gamble_anim <> 'coin' OR rp.pre_gamble_side IN ('heads','tails'))
                                AND (rp.pre_gamble_anim <> 'roulette' OR rp.pre_gamble_side IN ('red','black')))
                       )"
                );
                $stmtFast->bind_param("si", $preApplyDate, $raffleId);
                $stmtFast->execute();
                $stmtFast->close();
            }
            error_log("[LazyStart] Activated decision timer for raffle $raffleId — global {$timeoutSeconds}s / pre-apply {$preApplySeconds}s");
        }
    }

    if (($raffle['status'] ?? '') === 'waiting_for_winner') {
        $afkCutoffNow = date('Y-m-d H:i:s');
        $conn->begin_transaction();
        try {
            // SELF-HEAL: una apuesta de Moneda/Ruleta NUNCA es válida sin lado o
            // color elegido. Si una fila quedó en 'gamble_pending' sin coin_choice
            // y sin bloque objetivo (p. ej. por un ENUM que truncó el valor), la
            // asignación de bloque la ignoraría para siempre y el ganador quedaría
            // congelado en "Esperando resultado". Se devuelve a 'pending' con el
            // deadline vencido para que el fallback de abajo entregue el premio
            // original (o re-encole el gamble si la pre-elección sí está completa).
            $stmtHeal = $conn->prepare(
                "UPDATE raffle_winners
                 SET choice = 'pending', selected_animation_mode = NULL, coin_choice = NULL,
                     decision_expires_at = ?, pre_apply_expires_at = NULL
                 WHERE raffle_id = ?
                   AND choice = 'gamble_pending'
                   AND selected_animation_mode IN ('coin', 'roulette')
                   AND (coin_choice IS NULL OR coin_choice = '')
                   AND (gamble_block_target IS NULL OR gamble_block_target = 0)"
            );
            $stmtHeal->bind_param("si", $afkCutoffNow, $raffleId);
            $stmtHeal->execute();
            if ($stmtHeal->affected_rows > 0) {
                error_log("[SelfHeal] Raffle $raffleId: {$stmtHeal->affected_rows} apuesta(s) coin/roulette sin elección devuelta(s) a decisión/premio original.");
            }
            $stmtHeal->close();
            // Leemos también la pre-elección del ganador (pre_chosen_prize +
            // animación y lado del Doble o Nada) desde raffle_participants. Así el
            // cierre por timeout disparado por el polling de CUALQUIER espectador
            // aplica la configuración del ganador SIN depender de que él esté
            // presente o haciendo polling.
            // Una fila se auto-resuelve si:
            //   (a) venció el deadline COMPLETO (último fallback / decisión manual
            //       no tomada), o
            //   (b) venció la ventana corta de auto-aplicación de la pre-elección
            //       (pre_apply_expires_at) y NO fue cancelada por el ganador en
            //       vivo. En este caso `pre_chosen_prize` siempre está completo
            //       (solo se setea pre_apply_expires_at para pre-elecciones
            //       válidas), por lo que el cuerpo de abajo aplica exactamente su
            //       elección sin esperar el timeout completo.
            $stmtAfk = $conn->prepare(
                "SELECT w.id AS winner_row_id, w.user_id, w.position, u.game_username, u.email, u.whatsapp_phone_e164,
                        rp.pre_chosen_prize, rp.pre_gamble_anim, rp.pre_gamble_side, rp.pre_pool_choices
                 FROM raffle_winners w
                 JOIN users u ON w.user_id = u.id
                 LEFT JOIN raffle_participants rp ON rp.raffle_id = w.raffle_id AND rp.user_id = w.user_id
                 WHERE w.raffle_id = ?
                 AND w.choice = 'pending'
                 AND (w.coin_choice IS NULL OR w.coin_choice = '')
                 AND (w.gamble_block_target IS NULL OR w.gamble_block_target = 0)
                 AND (
                       (w.decision_expires_at IS NOT NULL AND w.decision_expires_at <= ?)
                       OR (w.pre_apply_expires_at IS NOT NULL AND w.pre_apply_cancelled = 0 AND w.pre_apply_expires_at <= ?)
                 )
                 FOR UPDATE"
            );
            $stmtAfk->bind_param("iss", $raffleId, $afkCutoffNow, $afkCutoffNow);
            $stmtAfk->execute();
            $afkRes = $stmtAfk->get_result();

            $processed = 0;
            while ($afkCase = $afkRes->fetch_assoc()) {
                
                $prizeConfig = json_decode($raffle['prize_config'] ?? '', true);
                if (!is_array($prizeConfig)) {
                    continue;
                }
                $prizeConfig = rr_prize_for_position($prizeConfig, (int)($afkCase['position'] ?? 1));

                $winnerRowId = (int)$afkCase['winner_row_id'];
                $afkUserId   = (int)$afkCase['user_id'];

                // Pre-elección del ganador leída de la DB. Si el admin desactivó
                // la pre-elección, se ignora y se entrega el premio original.
                $preChoice = $afkCase['pre_chosen_prize'] ?? null;
                if ((int)($raffle['allow_pre_choice'] ?? 1) !== 1) {
                    $preChoice = null;
                }

                $gambleOk   = rr_raffle_gamble_enabled($raffle);
                $cashbackOk = rr_raffle_cashback_enabled($raffle);

                // REGLA ESTRICTA: solo ejecutamos el Doble o Nada anticipado si
                // quedó COMPLETAMENTE configurado (animación y, en moneda, el
                // lado). Si está incompleto o pulsó "Saltar", entregamos el
                // PREMIO ORIGINAL. El servidor nunca autoselecciona Cara ni
                // cambia la animación por su cuenta.
                $preAnim = $afkCase['pre_gamble_anim'] ?? null;
                $preSide = $afkCase['pre_gamble_side'] ?? null;
                $gambleFullyConfigured = ($preAnim !== null && $preAnim !== '')
                    && ($preAnim !== 'coin' || ($preSide === 'heads' || $preSide === 'tails'))
                    && ($preAnim !== 'roulette' || ($preSide === 'red' || $preSide === 'black'));

                // --- Doble o Nada anticipado COMPLETO ---
                if ($preChoice === 'gamble' && $gambleOk && $gambleFullyConfigured) {
                    // Dejamos la fila en el estado canónico 'gamble_pending' con
                    // la animación y el lado EXACTOS del usuario. El bloque
                    // "Soft-Lock" de más abajo (o el batch de raffle_gamble.php)
                    // asigna el bloque BSC objetivo y triggerGambleResolve lo
                    // resuelve en los siguientes latidos — sin que el ganador
                    // tenga que estar presente.
                    $coinChoiceAfk = in_array($preAnim, ['coin', 'roulette'], true) ? $preSide : null;
                    $gjson = json_encode([
                        'wager' => 'double',
                        'prediction_side' => in_array($preAnim, ['coin', 'roulette'], true) ? $preSide : 'heads',
                        'animation_pref' => $preAnim,
                        'status' => 'processing_gamble',
                        'auto_afk' => true
                    ]);
                    $stmtG = $conn->prepare(
                        "UPDATE raffle_winners
                         SET choice = 'gamble_pending', selected_animation_mode = ?, coin_choice = ?, gamble_result = ?
                         WHERE id = ?"
                    );
                    $stmtG->bind_param("sssi", $preAnim, $coinChoiceAfk, $gjson, $winnerRowId);
                    $stmtG->execute();
                    $stmtG->close();
                    $processed += 1;
                    continue;
                }

                // --- Cashback anticipado COMPLETO ---
                if ($preChoice === 'cashback' && $cashbackOk) {
                    // $prizeConfig ya viene resuelto por posición (pool → valor máximo).
                    $coins = rr_cashback_coins($conn, $prizeConfig, $cacheFile, (float)($raffle['cashback_percent'] ?? 0));
                    rr_credit_coins($conn, $afkUserId, $coins, 'raffle_cashback', $raffleId);
                    $stmtCb = $conn->prepare("UPDATE raffle_winners SET choice = 'cashback', prize_sent = 1, cashback_credited_coins = ? WHERE id = ?");
                    $stmtCb->bind_param("di", $coins, $winnerRowId);
                    $stmtCb->execute();
                    $stmtCb->close();
                    $processed += 1;
                    continue;
                }

                // --- Premio original (default / fallback estricto) ---
                if (rr_prize_is_combo($prizeConfig)) {
                    // Combo: fijos entregados de inmediato; el pool se resuelve
                    // con la pre-elección (o la primera opción) como en el caso
                    // de pool suelto.
                    $comboOrderId = null;
                    $conn->query("SAVEPOINT sp_combo_$winnerRowId");
                    try {
                        $comboFixed = rr_deliver_combo_fixed($conn, $afkUserId, $prizeConfig, $cacheFile, $raffleId);
                        $comboOrderId = $comboFixed['order_id'];
                        $conn->query("RELEASE SAVEPOINT sp_combo_$winnerRowId");
                    } catch (Throwable $eCombo) {
                        $conn->query("ROLLBACK TO SAVEPOINT sp_combo_$winnerRowId");
                        $conn->query("RELEASE SAVEPOINT sp_combo_$winnerRowId");
                        error_log("[raffle_status] AFK combo fixed delivery failed for winner row $winnerRowId: " . $eCombo->getMessage());
                    }
                    $comboPool = rr_position_pool($prizeConfig);
                    if ($comboPool !== null) {
                        $afkOption = rr_pool_option_for_afk($comboPool, $afkCase['pre_pool_choices'] ?? null, (int)($afkCase['position'] ?? 1));
                        if ($afkOption !== null) {
                            rr_deliver_pool_option_safe($conn, $afkUserId, $winnerRowId, $afkOption, $cacheFile, $raffleId);
                        } else {
                            $stmtUpd = $conn->prepare("UPDATE raffle_winners SET choice = 'original', prize_sent = 0, prize_state = 'awaiting_selection' WHERE id = ?");
                            $stmtUpd->bind_param("i", $winnerRowId);
                            $stmtUpd->execute();
                            $stmtUpd->close();
                        }
                        if ($comboOrderId !== null) {
                            $stmtUpd = $conn->prepare("UPDATE raffle_winners SET generated_order_id = COALESCE(generated_order_id, ?) WHERE id = ?");
                            $stmtUpd->bind_param("ii", $comboOrderId, $winnerRowId);
                            $stmtUpd->execute();
                            $stmtUpd->close();
                        }
                    } else {
                        if ($comboOrderId !== null) {
                            $stmtUpd = $conn->prepare("UPDATE raffle_winners SET choice = 'original', generated_order_id = ?, prize_sent = 1 WHERE id = ?");
                            $stmtUpd->bind_param("ii", $comboOrderId, $winnerRowId);
                        } else {
                            $stmtUpd = $conn->prepare("UPDATE raffle_winners SET choice = 'original', generated_order_id = NULL, prize_sent = 1 WHERE id = ?");
                            $stmtUpd->bind_param("i", $winnerRowId);
                        }
                        $stmtUpd->execute();
                        $stmtUpd->close();
                    }
                    $processed += 1;
                    continue;
                }
                if (rr_prize_is_pool($prizeConfig)) {
                    // Pool: se entrega el ítem pre-elegido por el participante o,
                    // si saltó la pre-selección, la primera opción del pool
                    // (asignación automática). Solo queda en awaiting_selection
                    // si el pool no tiene opciones válidas.
                    $afkOption = rr_pool_option_for_afk($prizeConfig, $afkCase['pre_pool_choices'] ?? null, (int)($afkCase['position'] ?? 1));
                    if ($afkOption !== null) {
                        rr_deliver_pool_option_safe($conn, $afkUserId, $winnerRowId, $afkOption, $cacheFile, $raffleId);
                    } else {
                        $stmtUpd = $conn->prepare("UPDATE raffle_winners SET choice = 'original', prize_sent = 0, prize_state = 'awaiting_selection' WHERE id = ?");
                        $stmtUpd->bind_param("i", $winnerRowId);
                        $stmtUpd->execute();
                        $stmtUpd->close();
                    }
                    $processed += 1;
                    continue;
                }

                $orderId = deliverPrize($conn, $afkUserId, $afkCase, $prizeConfig, $cacheFile, $raffleId);

                if (is_numeric($orderId)) {
                    $orderIdInt = (int)$orderId;
                    $stmtUpd = $conn->prepare("UPDATE raffle_winners SET choice = 'original', generated_order_id = ?, prize_sent = 1 WHERE id = ?");
                    $stmtUpd->bind_param("ii", $orderIdInt, $winnerRowId);
                    $stmtUpd->execute();
                    $stmtUpd->close();
                } else {
                    $stmtUpd = $conn->prepare("UPDATE raffle_winners SET choice = 'original', generated_order_id = NULL, prize_sent = 1 WHERE id = ?");
                    $stmtUpd->bind_param("i", $winnerRowId);
                    $stmtUpd->execute();
                    $stmtUpd->close();
                }

                $processed += 1;
            }

            $stmtAfk->close();

            // Cierre por barrido: si ya no queda ninguna decisión principal
            // pendiente (pending / gamble_pending) el sorteo se marca completado,
            // haya sido este poll el que resolvió la última fila o no. Así un
            // sorteo cuyos ganadores ya decidieron nunca queda colgado en
            // waiting_for_winner (p. ej. si el endpoint que registró la última
            // decisión salió antes de evaluar el cierre).
            if (rr_raffle_close_if_all_decided($conn, $raffleId)) {
                $raffle['status'] = 'completed';
                error_log("[raffle_status] Raffle $raffleId closed by sweep (all winners decided).");
            }

            $conn->commit();
            
            // =========================================================================
            // CRITICAL FIX: AFK Soft-Lock Prevention
            // If every main decision is in but some gambler still has no target
            // block (e.g. the submit request's RPC call failed), assign it here
            // from ANY spectator's poll so the gamble never waits forever.
            // =========================================================================
            if (($raffle['status'] ?? '') === 'waiting_for_winner') {
                try {
                    // Check if there are gamblers waiting AND no more pending decisions
                    $stmtCheck = $conn->prepare(
                        "SELECT COUNT(*) AS pending_count FROM raffle_winners
                         WHERE raffle_id = ? AND (choice IS NULL OR choice = '' OR choice = 'pending')"
                    );
                    $stmtCheck->bind_param("i", $raffleId);
                    $stmtCheck->execute();
                    $pendingCount = (int)($stmtCheck->get_result()->fetch_assoc()['pending_count'] ?? 0);
                    $stmtCheck->close();
                    
                    // If no more pending decisions, check for gamblers needing a target block
                    if ($pendingCount === 0) {
                        $stmtGamblers = $conn->prepare(
                            "SELECT COUNT(*) AS gambler_count FROM raffle_winners
                             WHERE raffle_id = ?
                             AND choice = 'gamble_pending'
                             AND selected_animation_mode IS NOT NULL AND selected_animation_mode <> ''
                             AND (selected_animation_mode NOT IN ('coin', 'roulette') OR (coin_choice IS NOT NULL AND coin_choice <> ''))
                             AND (gamble_block_target IS NULL OR gamble_block_target = 0)"
                        );
                        $stmtGamblers->bind_param("i", $raffleId);
                        $stmtGamblers->execute();
                        $gamblerCount = (int)($stmtGamblers->get_result()->fetch_assoc()['gambler_count'] ?? 0);
                        $stmtGamblers->close();
                        
                        // If there are gamblers waiting, assign the target block NOW
                        if ($gamblerCount > 0) {
                            $rpcBlock = getRpcBlockNumber($rpcUrl);
                            if ($rpcBlock !== false && $rpcBlock > 0) {
                                $targetBlock = $rpcBlock + 65;
                                $serverSeed = bin2hex(random_bytes(32));
                                $serverHash = hash('sha256', $serverSeed);
                                
                                $conn->begin_transaction();
                                try {
                                    $stmtUpdate = $conn->prepare(
                                        "UPDATE raffle_winners
                                         SET gamble_block_target = ?, gamble_server_seed = ?, gamble_server_hash = ?
                                         WHERE raffle_id = ?
                                         AND choice = 'gamble_pending'
                                         AND selected_animation_mode IS NOT NULL AND selected_animation_mode <> ''
                                         AND (selected_animation_mode NOT IN ('coin', 'roulette') OR (coin_choice IS NOT NULL AND coin_choice <> ''))
                                         AND (gamble_block_target IS NULL OR gamble_block_target = 0)"
                                    );
                                    $stmtUpdate->bind_param("issi", $targetBlock, $serverSeed, $serverHash, $raffleId);
                                    $stmtUpdate->execute();
                                    $stmtUpdate->close();
                                    $conn->commit();
                                    
                                    error_log("[AFK-Fix] Assigned target block $targetBlock for $gamblerCount gambler(s) in raffle $raffleId");
                                } catch (Throwable $e2) {
                                    $conn->rollback();
                                    error_log("[AFK-Fix] Failed to assign target block: " . $e2->getMessage());
                                }
                            } else {
                                error_log("[AFK-Fix] RPC failed, target block assignment deferred for raffle $raffleId");
                            }
                        }
                    }
                } catch (Throwable $e3) {
                    error_log("[AFK-Fix] Error checking for waiting gamblers: " . $e3->getMessage());
                }
            }
            
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('AFK auto-claim error: ' . $e->getMessage());
        }
    }

    // (El esquema ya se aseguró arriba, antes del lazy-start.)

    // Toggle del modal de pre-elección (se lee aparte porque la columna se
    // garantiza recién en rr_ensure_schema; activado por defecto).
    $allowPreChoice = 1;
    $apcStmt = $conn->prepare("SELECT allow_pre_choice FROM raffles WHERE raffle_id = ? LIMIT 1");
    if ($apcStmt) {
        $apcStmt->bind_param("i", $raffleId);
        $apcStmt->execute();
        $apcRow = $apcStmt->get_result()->fetch_assoc();
        if ($apcRow) $allowPreChoice = (int)($apcRow['allow_pre_choice'] ?? 1);
    }

    // 2. CONTAR PARTICIPANTES (los huecos/expulsados no cuentan como activos).
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM raffle_participants WHERE raffle_id = ? AND review_status <> 'expelled'");
    $countStmt->bind_param("i", $raffleId);
    $countStmt->execute();
    $participantsCount = $countStmt->get_result()->fetch_assoc()['total'];

    // ===== ESTADO DEL USUARIO (para modal bloqueante vía polling) =====
    // Si el usuario fue expulsado de ESTE sorteo o baneado de la plataforma,
    // el frontend lo detecta en el polling y lanza el modal de retiro.
    $myReviewStatus  = null;  // null = no inscrito
    $myAccountBanned = false;
    $myPreChoice     = null;  // pre-elección de premio (FASE 2): original|cashback|gamble|null
    $myPreGambleAnim = null;  // coin|slot|roulette|null (config completa del DoN anticipado)
    $myPreGambleSide = null;  // heads|tails|null
    $myPrePoolChoices = null; // {position: option_key} | null
    // Ticket de Bienvenida (solo para el propio usuario). `available` = el
    // usuario lo tiene (primer sorteo, califica, no consumido); `applies_here` =
    // además este sorteo tiene requisitos de compras que el ticket saltaría y
    // el usuario no los bypassea (verificado). El aviso del front usa ambos.
    $myWelcomeTicket = ['available' => false, 'applies_here' => false, 'used_here' => false, 'reason' => null];
    if ($myUserId > 0) {
        $msStmt = $conn->prepare("SELECT review_status, pre_chosen_prize, pre_gamble_anim, pre_gamble_side, pre_pool_choices, used_welcome_ticket FROM raffle_participants WHERE raffle_id = ? AND user_id = ? LIMIT 1");
        if ($msStmt) {
            $msStmt->bind_param("ii", $raffleId, $myUserId);
            $msStmt->execute();
            $msRow = $msStmt->get_result()->fetch_assoc();
            if ($msRow) {
                $myReviewStatus = $msRow['review_status'] ?? 'active';
                $myWelcomeTicket['used_here'] = ((int)($msRow['used_welcome_ticket'] ?? 0) === 1);
                $myPreChoice    = $msRow['pre_chosen_prize'] ?? null;
                $myPreGambleAnim = $msRow['pre_gamble_anim'] ?? null;
                $myPreGambleSide = $msRow['pre_gamble_side'] ?? null;
                $prePoolRaw = $msRow['pre_pool_choices'] ?? null;
                if ($prePoolRaw) {
                    $prePoolDecoded = json_decode($prePoolRaw, true);
                    if (is_array($prePoolDecoded)) $myPrePoolChoices = $prePoolDecoded;
                }
            }
        }
        $myIsVerified = false;
        $abStmt = $conn->prepare("SELECT account_status, is_verified FROM users WHERE id = ? LIMIT 1");
        if ($abStmt) {
            $abStmt->bind_param("i", $myUserId);
            $abStmt->execute();
            $abRow = $abStmt->get_result()->fetch_assoc();
            if ($abRow && ($abRow['account_status'] ?? '') === 'banned') $myAccountBanned = true;
            if ($abRow && (int)($abRow['is_verified'] ?? 0) === 1) $myIsVerified = true;
        }
        // Disponibilidad del Ticket de Bienvenida: solo se calcula si aún puede
        // inscribirse (evita consultas extra en cada sondeo del resto del ciclo).
        if ($myReviewStatus === null && ($raffle['status'] ?? '') === 'open') {
            try {
                $wtStatus = wt_status($conn, $myUserId, $raffleId);
                $myWelcomeTicket['available'] = (bool)$wtStatus['available'];
                $myWelcomeTicket['reason'] = $wtStatus['reason'];
                $raffleReqs = json_decode($raffle['requirements'] ?? '{}', true);
                $myWelcomeTicket['applies_here'] = $myWelcomeTicket['available']
                    && !$myIsVerified
                    && wt_raffle_requires_purchases(is_array($raffleReqs) ? $raffleReqs : null);
            } catch (Throwable $wtErr) {
                error_log('welcome ticket status error: ' . $wtErr->getMessage());
            }
        }
    }

    // 3. CÁLCULOS DE TIEMPO Y AFORO
    $now = new DateTime();
    $end = new DateTime($raffle['end_time']);
    $start = new DateTime($raffle['start_time']);
    
    // Tiempo restante (Si aún no empieza, mostramos tiempo para inicio)
    if ($now < $start) {
        $timeLeft = $start->getTimestamp() - $now->getTimestamp();
        $isUpcoming = true;
    } else {
        $timeLeft = $end->getTimestamp() - $now->getTimestamp();
        $isUpcoming = false;
    }
    if ($timeLeft < 0) $timeLeft = 0;

    // Aforo (Muerte Súbita)
    $maxSlots = (int)$raffle['max_participants'];
    $slotsLeft = ($maxSlots > 0) ? max(0, $maxSlots - $participantsCount) : -1; // -1 es infinito

// Respuesta Base
    $gambleAnim = $raffle['gamble_animation'] ?? '';
    if (empty($gambleAnim)) {
        $gambleAnim = 'coin';
    }

    $response = [
        'status' => $raffle['status'],
        'is_logged_in' => ($myUserId > 0), // <--- ¡CRÍTICO! Bandera para que el frontend muestre el botón de LOGIN
        'allow_winner_animation_choice' => (int)($raffle['allow_winner_animation_choice'] ?? 0),
        'allow_double_or_nothing' => (bool)($raffle['allow_double_or_nothing'] ?? false),
        'allow_cashback' => (bool)($raffle['allow_cashback'] ?? false),
        'cashback_percent' => (int)($raffle['cashback_percent'] ?? 0),
        'allow_pre_choice' => (bool)$allowPreChoice, // toggle del modal de pre-elección
        'is_private' => rp_is_private($raffle),
        'my_invited' => (rp_is_private($raffle) && $myUserId > 0) ? rp_is_invited($conn, $raffleId, $myUserId) : null,
        'my_pre_choice' => $myPreChoice, // original | cashback | gamble | null
        'my_pre_gamble_anim' => $myPreGambleAnim, // coin | slot | roulette | null
        'my_pre_gamble_side' => $myPreGambleSide, // heads | tails | null
        'my_pre_pool_choices' => $myPrePoolChoices, // {position: option_key} | null
        'decision_timeout' => (int)($raffle['decision_timeout'] ?? 60),
        'participants' => $participantsCount,
        'slots_total' => $maxSlots,
        'slots_left' => $slotsLeft,
        'time_left_seconds' => $timeLeft,
        'is_upcoming' => $isUpcoming,
        'server_seed_hash' => $raffle['server_seed_hash'],
        'mode' => $raffle['execution_mode'], // 'standard_queue' o 'fast_batch'
        // [NUEVO] Enviamos configuración de animaciones
        'animations' => [
            'main' => $raffle['main_animation'] ?? 'roulette',
            'gamble' => $gambleAnim
        ],
        'economics' => [
            'entry_cost' => (float)$raffle['entry_cost'],
            'current_pot' => (float)$raffle['current_pot']
        ],
        'bsc_block_target' => $raffle['bsc_block_target'] ? (int)$raffle['bsc_block_target'] : null,
        'bsc_block_current' => null,
        'end_time' => $raffle['end_time'] ?? null,
        'start_time' => $raffle['start_time'] ?? null,
        'server_time_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'decision_phase_reached_at' => $raffle['decision_phase_reached_at'] ?? null,
        'my_review_status' => $myReviewStatus, // active | under_review | expelled | null
        'my_banned' => $myAccountBanned,
        'my_welcome_ticket' => $myWelcomeTicket, // {available, applies_here, used_here, reason}
        'winners' => []
    ];

    // Premios por puesto (Schema 2): vista pública para la tarjeta del sorteo
    // (preview desplegable de la vitrina) y para el podio. Legacy v1 se expone
    // como un premio fijo replicado por puesto.
    $prizePositionsPublic = [];
    $normalizedPrizes = rr_normalize_prizes($raffle['prize_config'] ?? '{}', (int)($raffle['winners_count'] ?? 1));
    foreach ($normalizedPrizes['positions'] as $posEntry) {
        $prizePositionsPublic[] = [
            'position' => (int)$posEntry['position'],
            'prize' => rr_prize_public_view(rr_prize_for_position($raffle['prize_config'] ?? '{}', (int)$posEntry['position']))
        ];
    }
    $response['prize_positions'] = $prizePositionsPublic;

    // Live block number for the locking countdown UI
    // Re-use the early fetch if available; otherwise fetch now.
    if (($raffle['status'] === 'locking' || $raffle['status'] === 'waiting_for_winner') && $raffle['bsc_block_target']) {
        $liveBlock = ($earlyLiveBlock !== null && $earlyLiveBlock !== false) ? $earlyLiveBlock : getRpcBlockNumber($rpcUrl);
        if ($liveBlock !== false) {
            $response['bsc_block_current'] = (int)$liveBlock;
        }
    }

// =========================================================================
    // ANIMATION PHASE — Multi-phase rehydration engine.
    // Uses strict Unix-timestamp comparison against animation_start_at.
    // Duration is DYNAMIC: base + per_scene × winners_count.
    // Returns granular scene_index so refreshing clients can skip to the
    // exact winner reveal that is globally in progress.
    // =========================================================================
    $animPhase = [
        'active'                 => false,
        'remaining_seconds'      => 0,
        'elapsed_seconds'        => 0,
        'total_duration'         => 0,
        'scene_index'            => -1,
        'scene_count'            => 0,
        'per_scene_seconds'      => 0,
        'base_seconds'           => 0,
        'global_decision_active' => false
    ];
    if ($raffle['status'] === 'waiting_for_winner'
        && !empty($raffle['animation_start_at'])
    ) {
        $animStartTs = strtotime($raffle['animation_start_at']);
        if ($animStartTs !== false) {
            $nowTs = time();
            $mainAnim = strtolower(trim($raffle['main_animation'] ?? 'roulette'));
            $wCount = max(1, (int)($raffle['winners_count'] ?? 1));

            if ($mainAnim === 'roulette') {
                $baseSeconds    = 20;
                $perSceneSeconds = 20;
            } else {
                $baseSeconds    = 30;
                $perSceneSeconds = 30;
            }

            $totalDuration = $baseSeconds + ($perSceneSeconds * $wCount);
            $elapsed   = max(0, $nowTs - $animStartTs);
            $remaining = max(0, $totalDuration - $elapsed);

            // Scene index: which winner is currently being revealed (0-based)
            $sceneIndex = -1; // -1 = still in base/intro phase
            if ($elapsed >= $baseSeconds) {
                $sceneIndex = min($wCount - 1, (int)floor(($elapsed - $baseSeconds) / $perSceneSeconds));
            }

            // Global decision flag: true when ANY client signaled anim done
            // OR the full animation window has naturally elapsed.
            $globalDecisionActive = !empty($raffle['decision_phase_reached_at'])
                                 || ($elapsed >= $totalDuration);

            $animPhase = [
                'active'                 => ($remaining > 0 && !$globalDecisionActive),
                'remaining_seconds'      => (int)$remaining,
                'elapsed_seconds'        => (int)$elapsed,
                'total_duration'         => (int)$totalDuration,
                'scene_index'            => (int)$sceneIndex,
                'scene_count'            => (int)$wCount,
                'per_scene_seconds'      => (int)$perSceneSeconds,
                'base_seconds'           => (int)$baseSeconds,
                'global_decision_active' => $globalDecisionActive
            ];
        }
    }
    $response['animation_phase'] = $animPhase;

    // ===== AUDITORÍA PÚBLICA (snapshot recta numérica + Provably Fair) =====
    // Snapshot congelado en T_freeze (max_tickets + huecos). La semilla del
    // servidor se revela SÓLO tras resolver; antes va null (commit-reveal).
    $snap = rr_get_snapshot($conn, $raffleId);
    if ($snap !== null) {
        $seedRevealed = in_array($raffle['status'], ['completed', 'waiting_for_winner'], true);
        $response['audit'] = [
            'max_tickets'      => (int)($snap['max_tickets'] ?? 0),
            'expelled_tickets' => array_values($snap['expelled_tickets'] ?? []),
            'roster_hash'      => $snap['roster_hash'] ?? '',
            'frozen_at'        => $snap['frozen_at'] ?? '',
            'server_seed_hash' => $raffle['server_seed_hash'] ?? '',
            'server_seed'      => $seedRevealed ? ($raffle['server_seed_secret'] ?? null) : null,
            'bsc_block_hash'   => $raffle['bsc_block_hash'] ?: null,
            'bsc_block_number' => $raffle['bsc_block_target'] ? (int)$raffle['bsc_block_target'] : null,
        ];
    }

// 4. DATOS DE GANADORES Y ENDGAME
    if ($raffle['status'] === 'completed' || $raffle['status'] === 'waiting_for_winner') {
        
        // Transparencia
        $response['transparency'] = [
            'server_seed_secret' => $raffle['server_seed_secret'],
            'bsc_block_hash' => $raffle['bsc_block_hash'],
            'bsc_block_number' => $raffle['bsc_block_target']
        ];

// Traer ganadores con Avatar Real (SQL_NO_CACHE bypasses MySQL query cache for real-time accuracy)
        $winSql = "SELECT SQL_NO_CACHE w.id, w.position, w.user_id, w.choice, w.decision_expires_at, w.gamble_result, w.gamble_block_target,
                   w.coin_choice, w.gamble_block_hash, w.gamble_server_hash, w.gamble_server_seed,
                   w.selected_animation_mode, w.gamble_resolved_at,
                   w.pre_apply_expires_at, w.pre_apply_cancelled,
                   w.prize_state, w.final_prize, w.prize_selected_at, w.cashback_credited_coins,
                   w.delivery_status, w.delivery_proof_image, w.delivery_note, w.delivered_at,
                   u.game_username, u.public_display_name,
                   a.filename as avatar_file,
                   rp.pre_chosen_prize, rp.pre_gamble_anim, rp.pre_gamble_side, rp.pre_pool_choices
                   FROM raffle_winners w 
                   LEFT JOIN users u ON w.user_id = u.id 
                   LEFT JOIN avatars a ON u.avatar_id = a.id 
                   LEFT JOIN raffle_participants rp ON rp.raffle_id = w.raffle_id AND rp.user_id = w.user_id
                   WHERE w.raffle_id = ? 
                   ORDER BY w.position ASC";
                   
        $winStmt = $conn->prepare($winSql);
        $winStmt->bind_param("i", $raffleId);
        $winStmt->execute();
        $resWinners = $winStmt->get_result();
        
        $myPendingAction = false;
        $decisionDeadline = 0;
        $activePosition = null;
        $activeDecisionDeadline = 0;
        $myPosition = null;
        $earliestGambleResolvedAt = null;

        while ($winner = $resWinners->fetch_assoc()) {
            
            $displayName = !empty($winner['public_display_name']) ? $winner['public_display_name'] : (!empty($winner['game_username']) ? $winner['game_username'] : ('User#' . (int)$winner['user_id']));
            $isMe = ($myUserId > 0 && (int)$winner['user_id'] === $myUserId);
            if ($isMe) {
                $myPosition = (int)$winner['position'];
            }

            $winnerTimeToDecide = null;
            if ($raffle['status'] === 'waiting_for_winner' && $winner['choice'] === 'pending' && !empty($winner['decision_expires_at'])) {
                $expTmp = new DateTime($winner['decision_expires_at']);
                $winnerTimeToDecide = max(0, $expTmp->getTimestamp() - $now->getTimestamp());
            }

            // Segundos restantes hasta auto-aplicar la pre-elección (ventana corta
            // ≈15s). NULL si no hay pre-elección que aplicar o si el ganador la
            // canceló. Permite al cliente sincronizar el overlay "Aplicando…" y a
            // los espectadores ver la marca / la cancelación con dramatismo.
            $winnerPreApplySeconds = null;
            $winnerPreApplyCancelled = (int)($winner['pre_apply_cancelled'] ?? 0) === 1;
            if ($raffle['status'] === 'waiting_for_winner' && $winner['choice'] === 'pending'
                && !$winnerPreApplyCancelled && !empty($winner['pre_apply_expires_at'])) {
                $preExpTmp = new DateTime($winner['pre_apply_expires_at']);
                $winnerPreApplySeconds = max(0, $preExpTmp->getTimestamp() - $now->getTimestamp());
            }

            if ($raffle['status'] === 'waiting_for_winner' && $winner['choice'] === 'pending') {
                $posTmp = (int)$winner['position'];
                $deadlineTmp = (int)($winnerTimeToDecide ?? 0);

                if ($activePosition === null || $posTmp < $activePosition) {
                    $activePosition = $posTmp;
                    $activeDecisionDeadline = $deadlineTmp;
                }

                if ($isMe) {
                    $myPendingAction = true;
                    $decisionDeadline = $deadlineTmp;
                }
            }

            // Lógica HUD: Interpretar estado del Gamble
            $gResRaw = is_string($winner['gamble_result'] ?? null) ? trim($winner['gamble_result']) : '';
            $gRes = json_decode($winner['gamble_result'] ?? '{}', true);
            $hudState = 'thinking'; // Estado base

            $hasGambleIntent = (
                (isset($gRes['status']) && $gRes['status'] === 'waiting_queue') ||
                ($winner['choice'] === 'gamble_pending') ||
                (!empty($winner['coin_choice']) && ($winner['gamble_block_target'] === null || (int)$winner['gamble_block_target'] === 0)) ||
                ($winner['gamble_block_target'] !== null && (int)$winner['gamble_block_target'] !== 0)
            );

            $hasResolvedGamble = (
                $winner['choice'] === 'double_won' ||
                $winner['choice'] === 'double_lost' ||
                $gResRaw === 'win' ||
                $gResRaw === 'loss' ||
                (is_array($gRes) && isset($gRes['result']) && ($gRes['result'] === 'win' || $gRes['result'] === 'loss'))
            );

            if ($winner['choice'] === 'original' || $winner['choice'] === 'cashback') {
                $hudState = 'done';
            } elseif ($hasResolvedGamble) {
                $hudState = 'done_gamble';
            } elseif (
                $winner['choice'] === 'gamble_pending' ||
                $winner['choice'] === '' ||
                $winner['choice'] === null ||
                $hasGambleIntent
            ) {
                if ($winner['choice'] === 'gamble_pending') {
                    $hudState = 'ready_queue';
                } elseif (isset($gRes['status']) && $gRes['status'] === 'waiting_queue') {
                    $hudState = 'ready_queue';
                } elseif ($winner['gamble_block_target'] !== null && (int)$winner['gamble_block_target'] !== 0) {
                    $hudState = 'spinning';
                }
            }

            // ── GAMBLE ANIMATION PHASE (per-winner rehydration) ──
            // Same pattern as the main animation_phase but for individual gamble
            // resolve animations. Prevents spoilers on refresh during gamble playback.
            $gambleAnimPhase = [
                'active' => false,
                'remaining_seconds' => 0,
                'elapsed_seconds' => 0,
                'total_duration' => 0,
                'animation_mode' => null,
                // Ancla absoluta del gamble (simétrica a animation_phase del
                // sorteo principal). El cliente usa anchor_elapsed_ms para
                // hacer UN seek al fotograma exacto del resolve y continuar
                // localmente a 60fps. resolve_seconds = duración SOLO del giro
                // de resolución (sin el cinematic posterior).
                'anchor_elapsed_ms' => 0,
                'resolve_seconds' => 0,
                'phase_started_at' => null
            ];
            if (($winner['choice'] === 'double_won' || $winner['choice'] === 'double_lost')
                && !empty($winner['gamble_resolved_at'])
            ) {
                $gResolvedTs = strtotime($winner['gamble_resolved_at']);
                if ($gResolvedTs !== false) {
                    $gNowTs = time();
                    $gAnimMode = strtolower(trim($winner['selected_animation_mode'] ?? 'coin'));
                    // Gamble animation durations (resolve + cinematic celebration):
                    //   Coin:     16s resolve + 4s cinematic + 5s buffer = 25s
                    //   Roulette: 22.5s resolve + 4s cinematic + 3.5s buffer = 30s
                    //   Slots:    17s resolve + 4s cinematic + 4s buffer = 25s
                    // resolve = duración del giro de resolución (debe coincidir
                    // con el cliente: coin 16s, roulette 22.5s→23, slots 17s).
                    if ($gAnimMode === 'roulette') {
                        $gTotalDuration = 30;
                        $gResolveSeconds = 23;
                    } elseif ($gAnimMode === 'slots' || $gAnimMode === 'slot') {
                        $gTotalDuration = 25;
                        $gResolveSeconds = 17;
                    } else {
                        $gTotalDuration = 25;
                        $gResolveSeconds = 16;
                    }
                    $gElapsed = max(0, $gNowTs - $gResolvedTs);
                    $gRemaining = max(0, $gTotalDuration - $gElapsed);
                    $gambleAnimPhase = [
                        'active' => ($gRemaining > 0),
                        'remaining_seconds' => (int)$gRemaining,
                        'elapsed_seconds' => (int)$gElapsed,
                        'total_duration' => (int)$gTotalDuration,
                        'animation_mode' => $gAnimMode,
                        'anchor_elapsed_ms' => (int)($gElapsed * 1000),
                        'resolve_seconds' => (int)$gResolveSeconds,
                        'phase_started_at' => gmdate('Y-m-d\TH:i:s\Z', $gResolvedTs)
                    ];
                }
            }

            // Track the earliest gamble_resolved_at for skip-during-gamble detection
            if (!empty($winner['gamble_resolved_at']) && ($winner['choice'] === 'double_won' || $winner['choice'] === 'double_lost')) {
                if ($earliestGambleResolvedAt === null || $winner['gamble_resolved_at'] < $earliestGambleResolvedAt) {
                    $earliestGambleResolvedAt = $winner['gamble_resolved_at'];
                }
            }

            $positionPrize = rr_prize_for_position($raffle['prize_config'] ?? '{}', (int)$winner['position']);
            $finalPrize = !empty($winner['final_prize']) ? json_decode($winner['final_prize'], true) : null;

            // Ítem de la Vitrina pre-elegido por el ganador (si sigue siendo
            // válido): permite mostrar "Aplicando: Crash Bandicoot" en vez del
            // genérico "Premio Original" mientras corre la ventana de auto-aplicación.
            $prePoolItem = null;
            if (($winner['pre_chosen_prize'] ?? '') === 'original' && !empty($winner['pre_pool_choices'])) {
                $preOpt = rr_pool_preselected_option(rr_position_pool($positionPrize), $winner['pre_pool_choices'], (int)$winner['position']);
                if (is_array($preOpt)) {
                    $prePoolItem = [
                        'kind'  => (string)($preOpt['kind'] ?? 'catalog'),
                        'name'  => (string)($preOpt['name'] ?? ($preOpt['label'] ?? 'Premio')),
                        'image' => (string)($preOpt['image_icon'] ?? ($preOpt['image'] ?? '')),
                        'option_key' => (string)($preOpt['option_key'] ?? ''),
                    ];
                }
            }
            $cashbackCredit = ['coins' => 0.0, 'source' => null];
            if (($winner['choice'] ?? '') === 'cashback') {
                $cashbackCredit = rr_cashback_credit_view(
                    $conn,
                    (int)$winner['user_id'],
                    $raffleId,
                    $winner['cashback_credited_coins'] ?? null,
                    $positionPrize,
                    $cacheFile,
                    (float)($raffle['cashback_percent'] ?? 0)
                );
            }
            $fulfillment = rr_winner_fulfillment(
                $winner['choice'] ?? '',
                $positionPrize,
                $finalPrize,
                $gRes,
                $cashbackCredit
            );

            // Filas antiguas de Doble o Nada pueden no llevar el monto dentro del
            // JSON. Preferimos el ledger real y solo al final la valuación vigente.
            if (($winner['choice'] ?? '') === 'double_won' && (float)$fulfillment['automatic_credit_coins'] <= 0) {
                $doubleCredit = rr_recorded_raffle_credit($conn, (int)$winner['user_id'], $raffleId, 'raffle_double_bonus');
                if ($doubleCredit > 0) {
                    $fulfillment['automatic_credit_coins'] = $doubleCredit;
                    $fulfillment['automatic_credit_source'] = 'ledger';
                } else {
                    $fulfillment['automatic_credit_coins'] = rr_prize_value_coins($conn, $positionPrize, $cacheFile);
                    $fulfillment['automatic_credit_source'] = 'legacy_recomputed';
                }
            }

            $manualDelivery = !empty($fulfillment['manual_delivery_required']);
            $publicDeliveryStatus = ($manualDelivery && $raffle['status'] === 'completed')
                ? (($winner['delivery_status'] ?? '') ?: 'pending')
                : null;
            $proofNames = [];
            if ($publicDeliveryStatus !== null) {
                $proofNames = array_values(array_filter(array_map('trim', explode(',', (string)($winner['delivery_proof_image'] ?? '')))));
            }
            $proofUrls = array_map(function ($name) {
                return '/assets/raffle_proofs/' . rawurlencode($name);
            }, $proofNames);

            $response['winners'][] = [
                'position' => $winner['position'],
                'winner_id' => (int)$winner['id'], // ID usado como Winner ID en el HMAC (auditoría)
                'user_id' => (int)$winner['user_id'],
                'username' => $displayName,
                'avatar' => AvatarCatalog::filename($conn, $winner['avatar_file'] ?? null), 
                'choice' => $winner['choice'], 
                'coin_choice' => $winner['coin_choice'] ?? null,
                'selected_animation_mode' => $winner['selected_animation_mode'] ?? null,
                'decision_expires_at' => $winner['decision_expires_at'],
                'time_to_decide' => $winnerTimeToDecide,
                'gamble_block_target' => $winner['gamble_block_target'],
                'gamble_block_hash' => $winner['gamble_block_hash'] ?? null,
                'gamble_server_hash' => $winner['gamble_server_hash'] ?? null,
                'gamble_server_seed' => ($raffle['status'] === 'completed') ? ($winner['gamble_server_seed'] ?? null) : null,
                'hud_state' => $hudState,
                'prediction_side' => $gRes['prediction_side'] ?? null,
                'gamble_result' => $gRes,
                'gamble_animation_phase' => $gambleAnimPhase,
                // Pre-elección del GANADOR (parte del reveal público): permite a
                // los espectadores ver qué eligió mientras se aplica su decisión.
                'pre_choice' => $winner['pre_chosen_prize'] ?? null,
                'pre_gamble_anim' => $winner['pre_gamble_anim'] ?? null,
                'pre_gamble_side' => $winner['pre_gamble_side'] ?? null,
                'pre_pool_item' => $prePoolItem,
                // Ventana de auto-aplicación (P7): segundos restantes hasta aplicar
                // la pre-elección y si el ganador la canceló (para el dramatismo).
                'pre_apply_seconds' => $winnerPreApplySeconds,
                'pre_apply_cancelled' => $winnerPreApplyCancelled,
                // Premios por puesto (Schema 2): config efectiva del puesto,
                // estado de selección del pool y snapshot del ítem definitivo.
                'prize' => rr_prize_public_view($positionPrize),
                'prize_state' => $winner['prize_state'] ?? null,
                'final_prize' => $finalPrize,
                // Contrato único de cumplimiento: la UI no infiere entrega manual
                // desde `delivery_status`, porque cashback/pérdidas no la requieren.
                'fulfillment' => $fulfillment,
                'cashback_credited_coins' => ($winner['choice'] ?? '') === 'cashback'
                    ? (float)$fulfillment['automatic_credit_coins']
                    : 0.0,
                'double_bonus_coins' => ($winner['choice'] ?? '') === 'double_won'
                    ? (float)$fulfillment['automatic_credit_coins']
                    : 0.0,
                'delivery_status' => $publicDeliveryStatus,
                'delivery_proof_urls' => $proofUrls,
                'delivery_proof_url' => $proofUrls[0] ?? null,
                'delivery_note' => $publicDeliveryStatus !== null ? ($winner['delivery_note'] ?? null) : null,
                'delivered_at' => $publicDeliveryStatus !== null ? ($winner['delivered_at'] ?? null) : null
            ];
        }

        $response['earliest_gamble_resolved_at'] = $earliestGambleResolvedAt;

        // Si el sorteo espera decisión... (resto del código igual)
        if ($raffle['status'] === 'waiting_for_winner') {
            $response['endgame'] = [
                'is_me' => $myPendingAction,
                'time_to_decide' => max(0, $myPendingAction ? $decisionDeadline : $activeDecisionDeadline),
                'my_position' => $myPosition,
                'active_position' => $activePosition,
                'options' => [
                    'allow_cashback' => (bool)$raffle['allow_cashback'],
                    'cashback_percent' => (int)$raffle['cashback_percent'],
                    'allow_double' => (bool)$raffle['allow_double_or_nothing'],
                    'allow_winner_animation_choice' => (int)($raffle['allow_winner_animation_choice'] ?? 0)
                ]
            ];

            // --- GAMBLE RACE DATA FOR SPECTATORS ---
            // Build a gamblers array from winners who have an active gamble block target
            $gambleRaceGamblers = [];
            $gambleRaceTarget = null;
            foreach ($response['winners'] as $wEntry) {
                if (
                    isset($wEntry['choice']) && $wEntry['choice'] === 'gamble_pending' &&
                    isset($wEntry['gamble_block_target']) && (int)$wEntry['gamble_block_target'] > 0
                ) {
                    if ($gambleRaceTarget === null) {
                        $gambleRaceTarget = (int)$wEntry['gamble_block_target'];
                    }
                    $gambleRaceGamblers[] = [
                        'username' => $wEntry['username'] ?? 'Jugador',
                        'avatar'   => AvatarCatalog::filename($conn, $wEntry['avatar'] ?? null)
                    ];
                }
            }
            if ($gambleRaceTarget !== null && count($gambleRaceGamblers) > 0) {
                // Extract gamble_server_hash from the first gambler that has one
                $gambleRaceServerHash = null;
                foreach ($response['winners'] as $wEntry) {
                    if (!empty($wEntry['gamble_server_hash'])) {
                        $gambleRaceServerHash = $wEntry['gamble_server_hash'];
                        break;
                    }
                }
                $response['gamble_race'] = [
                    'target_block'       => $gambleRaceTarget,
                    'bsc_block_current'  => $response['bsc_block_current'],
                    'gamble_server_hash' => $gambleRaceServerHash,
                    'gamblers'           => $gambleRaceGamblers
                ];
            }
        }
        
        // ... (resto del bloque igual)

        // Animación Replay (Solo para completed)
        if ($raffle['status'] === 'completed') {
            $minutesSinceEnd = ($now->getTimestamp() - $end->getTimestamp()) / 60;
            $safeReplayMinutes = max(60, (int)$raffle['replay_minutes']); 
            $response['show_animation'] = ($minutesSinceEnd <= $safeReplayMinutes);
        }
    }

// 5. LISTA DE ÚLTIMOS INSCRITOS
    $partsSql = "SELECT rp.user_id, u.game_username, u.public_display_name, rp.ticket_index AS ticket_index_raw, rp.entry_fee_paid,
                 a.filename as avatar_file -- <--- TRAEMOS EL ARCHIVO
                 FROM raffle_participants rp 
                 JOIN users u ON rp.user_id = u.id 
                 LEFT JOIN avatars a ON u.avatar_id = a.id -- <--- JOIN
                 WHERE rp.raffle_id = ? AND rp.review_status <> 'expelled'
                 ORDER BY rp.id ASC";
    
    $partsStmt = $conn->prepare($partsSql);
    $partsStmt->bind_param("i", $raffleId);
    $partsStmt->execute();
    $partsRes = $partsStmt->get_result();
    
    $participantsList = [];
    while($p = $partsRes->fetch_assoc()) {
        $pName = !empty($p['public_display_name']) ? $p['public_display_name'] : $p['game_username'];
        
        $participantsList[] = [
            'user_id' => (int)$p['user_id'],
            'username' => $pName,
            'avatar' => AvatarCatalog::filename($conn, $p['avatar_file'] ?? null), // Usamos el nombre del archivo real
            'ticket' => (int)$p['ticket_index_raw'],
            'paid' => (float)$p['entry_fee_paid'] > 0 
        ];
    }
    
    $response['participants_list'] = $participantsList;

    // Expulsados (transparencia): se muestran en gris en el sidebar / lista
    // final y como contador en la sala de bloqueo. Van aparte para no tocar la
    // recta de tickets que consume la animación (participants_list).
    $expelledList = [];
    $expSql = "SELECT rp.user_id, u.game_username, u.public_display_name, rp.ticket_index AS ticket_index_raw,
                 a.filename as avatar_file
                 FROM raffle_participants rp
                 JOIN users u ON rp.user_id = u.id
                 LEFT JOIN avatars a ON u.avatar_id = a.id
                 WHERE rp.raffle_id = ? AND rp.review_status = 'expelled'
                 ORDER BY rp.id ASC";
    $expStmt = $conn->prepare($expSql);
    if ($expStmt) {
        $expStmt->bind_param("i", $raffleId);
        $expStmt->execute();
        $expRes = $expStmt->get_result();
        while ($e = $expRes->fetch_assoc()) {
            $expelledList[] = [
                'user_id'  => (int)$e['user_id'],
                'username' => !empty($e['public_display_name']) ? $e['public_display_name'] : $e['game_username'],
                'avatar'   => AvatarCatalog::filename($conn, $e['avatar_file'] ?? null),
                'ticket'   => (int)$e['ticket_index_raw'],
                'expelled' => true
            ];
        }
        $expStmt->close();
    }
    $response['expelled_list'] = $expelledList;
    $response['expelled_count'] = count($expelledList);

    // ===== NEAR-MISS (retención post-sorteo) =====
    // Solo para sorteos terminados y usuarios logueados que participaron pero
    // NO ganaron. Calcula qué tan cerca estuvo su ticket del ticket ganador.
    if ($myUserId > 0 && $raffle['status'] === 'completed') {
        $myTickets = [];
        foreach ($participantsList as $p) {
            if ((int)$p['user_id'] === $myUserId) $myTickets[] = (int)$p['ticket'];
        }

        $iAmWinner = false;
        $winnerUserIds = [];
        foreach (($response['winners'] ?? []) as $w) {
            if (($w['choice'] ?? '') === 'burned') continue; // los quemados no son ganadores
            $winnerUserIds[(int)$w['user_id']] = true;
            if ((int)$w['user_id'] === $myUserId) $iAmWinner = true;
        }

        if (!empty($myTickets) && !$iAmWinner && !empty($winnerUserIds)) {
            // Ticket exacto sorteado (registrado en la meta de selección), con
            // fallback a los tickets de los usuarios ganadores.
            $winningTickets = [];
            $metaStmt = $conn->prepare("SELECT gamble_result FROM raffle_winners WHERE raffle_id = ? AND choice <> 'burned'");
            $metaStmt->bind_param("i", $raffleId);
            $metaStmt->execute();
            $metaRes = $metaStmt->get_result();
            while ($mw = $metaRes->fetch_assoc()) {
                $m = json_decode($mw['gamble_result'] ?? '', true);
                if (is_array($m) && isset($m['winning_ticket_index']) && (int)$m['winning_ticket_index'] >= 0) {
                    $winningTickets[] = (int)$m['winning_ticket_index'];
                }
            }
            if (empty($winningTickets)) {
                foreach ($participantsList as $p) {
                    if (isset($winnerUserIds[(int)$p['user_id']])) $winningTickets[] = (int)$p['ticket'];
                }
            }

            if (!empty($winningTickets)) {
                $bestMine = null; $bestWin = null; $bestDiff = PHP_INT_MAX;
                foreach ($myTickets as $mt) {
                    foreach ($winningTickets as $wt) {
                        $d = abs($mt - $wt);
                        if ($d < $bestDiff) { $bestDiff = $d; $bestMine = $mt; $bestWin = $wt; }
                    }
                }
                if ($bestMine !== null) {
                    $response['near_miss'] = [
                        'my_ticket' => $bestMine,
                        'winning_ticket' => $bestWin,
                        'diff' => $bestDiff
                    ];
                }
            }
        }
    }

    // =========================================================================
    // CHECKPOINT AUTORITATIVO — fase canónica + epoch monotónico.
    // El cliente usa esto para (a) descartar respuestas atrasadas/fuera de orden
    // (epoch < lastEpoch ⇒ regresión, se ignora) y (b) reforzar el enrutado a la
    // fase de decisión. Es 100% aditivo: no cambia ninguna lógica previa.
    // =========================================================================
    $cpStatus = $raffle['status'] ?? '';
    $cpScene = (int)($animPhase['scene_index'] ?? -1);
    $cpDecisionActive = !empty($animPhase['global_decision_active']);
    // Señal extra de decisión: algún ganador ya tiene su ventana de decisión
    // abierta (decision_expires_at). Cierra el punto ciego donde el flag global
    // aún no se había marcado pero las decisiones ya empezaron.
    $cpAnyDecisionWindow = false;
    $cpAnyGambleAnim = false;
    foreach (($response['winners'] ?? []) as $w) {
        if (!empty($w['decision_expires_at'])) { $cpAnyDecisionWindow = true; }
        if (!empty($w['gamble_animation_phase']) && !empty($w['gamble_animation_phase']['active'])) { $cpAnyGambleAnim = true; }
    }

    $cpPhase = 'OPEN';
    $cpEpoch = 10;
    if ($cpStatus === 'open') {
        $cpPhase = 'OPEN'; $cpEpoch = 10;
    } elseif ($cpStatus === 'locking') {
        $cpPhase = 'LOCKING'; $cpEpoch = 20;
    } elseif ($cpStatus === 'waiting_for_winner') {
        // Decisión y gamble comparten tier de epoch (800): el gamble ocurre
        // DESPUÉS de decidir, e incluso pueden alternar entre sí en multi-ganador,
        // así que NO deben provocar una regresión de epoch entre ellos.
        if ($cpAnyGambleAnim) {
            $cpPhase = 'GAMBLE_ANIM'; $cpEpoch = 800;
        } elseif (!empty($animPhase['active']) && !$cpDecisionActive && !$cpAnyDecisionWindow) {
            if ($cpScene < 0) { $cpPhase = 'SPIN_INTRO'; $cpEpoch = 30; }
            else { $cpPhase = 'SPIN_SCENE'; $cpEpoch = 31 + $cpScene; }
        } else {
            $cpPhase = 'DECISION'; $cpEpoch = 800;
        }
    } elseif ($cpStatus === 'completed') {
        $cpPhase = 'WINNERS_FINAL'; $cpEpoch = 900;
    }

    $cpDecision = ($cpDecisionActive || $cpAnyDecisionWindow);
    $response['checkpoint'] = [
        'phase'             => $cpPhase,
        'epoch'             => $cpEpoch,
        'server_time_utc'   => $response['server_time_utc'],
        'scene_index'       => $cpScene,
        'scene_count'       => (int)($animPhase['scene_count'] ?? 0),
        'anchor_elapsed_ms' => (int)($animPhase['elapsed_seconds'] ?? 0) * 1000,
        'main_animation'    => $raffle['main_animation'] ?? 'roulette',
        'decision_active'   => $cpDecision,
        'gamble_active'     => $cpAnyGambleAnim,
    ];
    $response['decision_active'] = $cpDecision;

    _outputJson($response);

} catch (Throwable $e) {
    http_response_code(500);
    _outputJson(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
<?php
// api/raffle_gamble.php
// MOTOR DE JUEGO REAL-TIME (API-FIRST)
// Maneja: Doble o Nada, Reclamo de Premios y Resolución Sincronizada (Batch)

header('Content-Type: application/json');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

date_default_timezone_set('America/Lima');
require_once __DIR__ . '/db_connect.php';
// Shared reward / valuation / Provably-Fair helpers (rr_*). Safe to include here:
// it only declares rr_* functions; deliverPrize()/findProductInCache() used by
// those helpers are provided locally further down in this file.
require_once __DIR__ . '/raffle_rewards.php';
require_once dirname(__DIR__) . '/core/AvatarCatalog.php';
require_once __DIR__ . '/raffle_review.php';

// --- CONFIGURACIÓN RPC (BSC/ETH) ---
// Usar un nodo rápido para evitar latencia en la API
$rpcUrl = 'https://bsc-dataseed.binance.org/'; // O tu nodo privado si tienes uno
require_once dirname(__DIR__) . '/config/shop_config.php';
$cacheFile = ShopSourceConfig::activeSnapshotPath() ?? ShopSourceConfig::liveSnapshotPath();

// --- INICIO DE SESIÓN ---
if (session_status() === PHP_SESSION_NONE) session_start();

function _outputJson($data) {
    $raw = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (defined('MODO_DEBUG') && MODO_DEBUG) {
        echo $raw;
    } else {
        $sn = openssl_random_pseudo_bytes(16);
        $sd = openssl_encrypt($raw, 'aes-256-cbc', CART_SYNC_TOKEN, OPENSSL_RAW_DATA, $sn);
        echo json_encode(['_sd' => base64_encode($sd), '_sn' => base64_encode($sn)]);
    }
}

$myUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// Validar Usuario
if ($myUserId <= 0) {
    _outputJson(['status' => 'error', 'message' => 'No autorizado']);
    exit;
}

$action = $_GET['action'] ?? '';
$raffleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$debugMode = (isset($_GET['debug']) && $_GET['debug'] === '1');

if ($raffleId <= 0) {
    _outputJson(['status' => 'error', 'message' => 'ID de sorteo inválido']);
    exit;
}

rr_ensure_schema($conn);
// Cupones de OFERTA (daily_discounts): DDL fuera de las transacciones de entrega.
dd_ensure_schema($conn);

// ===================================================================================
//  ACCIÓN 1: SUBMIT (El Usuario Vota)
//  - Guarda la decisión.
//  - Si es el último del grupo: Asigna Bloque Objetivo y Semilla para TODOS.
// ===================================================================================
if ($action === 'submit_gamble') {
    // Leer input JSON
    $input = json_decode(file_get_contents('php://input'), true);
    $mainChoiceRaw = $input['choice'] ?? ''; // 'original', 'cashback', 'double'
    $mainChoice = ($mainChoiceRaw === 'double') ? 'gamble_pending' : $mainChoiceRaw;
    $coinChoice = $input['coin_choice'] ?? null; // moneda: 'heads'/'tails' · ruleta: 'red'/'black' (solo si es double)
    $animationModeInput = $input['animation_mode'] ?? null; // 'coin', 'slot', 'roulette'

    $allowedAnimModes = ['coin', 'slot', 'roulette'];
    if (is_string($animationModeInput)) {
        $animationModeInput = strtolower(trim($animationModeInput));
    } else {
        $animationModeInput = null;
    }
    if ($animationModeInput !== null && !in_array($animationModeInput, $allowedAnimModes, true)) {
        $animationModeInput = null;
    }

    if (!in_array($mainChoice, ['original', 'cashback', 'gamble_pending'], true)) {
        _outputJson(['status' => 'error', 'message' => 'Opción inválida']);
        exit;
    }

    $hasGamificationAnimation = false;
    $colCheck = $conn->query("SHOW COLUMNS FROM raffles LIKE 'gamification_animation'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $hasGamificationAnimation = true;
    }
    $extraAnimCols = $hasGamificationAnimation ? ", gamification_animation" : "";

    $stmtRaffleCfg = $conn->prepare("SELECT gamble_animation" . $extraAnimCols . ", allow_winner_animation_choice, allow_cashback, cashback_percent, allow_double_or_nothing, execution_mode FROM raffles WHERE raffle_id = ? LIMIT 1");
    $stmtRaffleCfg->bind_param("i", $raffleId);
    $stmtRaffleCfg->execute();
    $raffleCfg = $stmtRaffleCfg->get_result()->fetch_assoc();
    $stmtRaffleCfg->close();

    if (!$raffleCfg) {
        _outputJson(['status' => 'error', 'message' => 'Sorteo no encontrado']);
        exit;
    }

    // Solo se aceptan las alternativas que el sorteo realmente ofrece (misma
    // regla que la pre-elección y que el cierre automático del servidor).
    if ($mainChoice === 'cashback' && !rr_raffle_cashback_enabled($raffleCfg)) {
        _outputJson(['status' => 'error', 'message' => 'El Cashback no está habilitado en este sorteo']);
        exit;
    }
    if ($mainChoice === 'gamble_pending' && !rr_raffle_gamble_enabled($raffleCfg)) {
        _outputJson(['status' => 'error', 'message' => 'El Doble o Nada no está habilitado en este sorteo']);
        exit;
    }

    $defaultAnimMode = $raffleCfg['gamble_animation'] ?? '';
    if ($hasGamificationAnimation && !empty($raffleCfg['gamification_animation'])) {
        $defaultAnimMode = $raffleCfg['gamification_animation'];
    }
    if (empty($defaultAnimMode) || !in_array($defaultAnimMode, $allowedAnimModes, true)) {
        $defaultAnimMode = 'coin';
    }

    $allowWinnerAnimChoice = (int)($raffleCfg['allow_winner_animation_choice'] ?? 0);
    $finalAnimMode = ($allowWinnerAnimChoice === 1 && $animationModeInput !== null)
        ? $animationModeInput
        : $defaultAnimMode;

    if ($mainChoice === 'gamble_pending') {
        if ($finalAnimMode === 'coin') {
            if (!in_array($coinChoice, ['heads', 'tails'], true)) {
                _outputJson(['status' => 'error', 'message' => 'Debes elegir CARA o SELLO']);
                exit;
            }
        } elseif ($finalAnimMode === 'roulette') {
            if (!in_array($coinChoice, ['red', 'black'], true)) {
                _outputJson(['status' => 'error', 'message' => 'Debes elegir ROJO o NEGRO']);
                exit;
            }
        } else {
            $coinChoice = null;
        }
    } else {
        $finalAnimMode = null;
        $coinChoice = null;
    }

    // INICIO TRANSACCIÓN ATÓMICA (Regla de Oro #1)
    $conn->begin_transaction();

    try {
        // 1. Bloquear y verificar al usuario actual
        // Usamos FOR UPDATE para que nadie modifique esta fila mientras leemos
        $stmtUser = $conn->prepare("SELECT id, choice, position, gamble_block_target FROM raffle_winners WHERE raffle_id = ? AND user_id = ? FOR UPDATE");
        $stmtUser->bind_param("ii", $raffleId, $myUserId);
        $stmtUser->execute();
        $userRow = $stmtUser->get_result()->fetch_assoc();

        if (!$userRow) {
            throw new Exception("No eres ganador de este sorteo.");
        }
        $existingChoice = $userRow['choice'];
        if ($existingChoice !== 'pending' && $existingChoice !== null && $existingChoice !== '') {
            // Idempotencia: Si ya eligió, no hacemos nada, solo devolvemos éxito
            $conn->commit();
            if ($existingChoice === 'gamble_pending') {
                $existingTarget = isset($userRow['gamble_block_target']) ? (int)$userRow['gamble_block_target'] : 0;
                if ($existingTarget > 0) {
                    $idempotentBlock = getRpcBlockNumber($rpcUrl);
                    _outputJson(['status' => 'target_set', 'target_block' => $existingTarget, 'bsc_block_current' => $idempotentBlock ? $idempotentBlock : null, 'message' => 'Grupo listo. Bloque ya asignado.']);
                } else {
                    _outputJson(['status' => 'waiting_for_others', 'message' => 'Esperando a otros ganadores...']);
                }
            } elseif ($existingChoice === 'original' || $existingChoice === 'cashback') {
                _outputJson(['status' => 'completed', 'message' => 'Premio reclamado']);
            } else {
                _outputJson(['status' => 'success', 'message' => 'Ya registrado', 'state' => 'registered']);
            }
            exit;
        }

        if ($mainChoice === 'original') {
            $stmtRaffle = $conn->prepare("SELECT raffle_id, prize_config, allow_pre_choice FROM raffles WHERE raffle_id = ? LIMIT 1");
            $stmtRaffle->bind_param("i", $raffleId);
            $stmtRaffle->execute();
            $raffleRow = $stmtRaffle->get_result()->fetch_assoc();
            $stmtRaffle->close();
            if (!$raffleRow) {
                throw new Exception('Sorteo no encontrado.');
            }

            // Pre-selección del ítem del pool hecha al inscribirse; el helper la
            // aplica si sigue vigente o deja la fila en awaiting_selection.
            $stmtPre = $conn->prepare("SELECT pre_pool_choices FROM raffle_participants WHERE raffle_id = ? AND user_id = ? LIMIT 1");
            $stmtPre->bind_param("ii", $raffleId, $myUserId);
            $stmtPre->execute();
            $preRow = $stmtPre->get_result()->fetch_assoc();
            $stmtPre->close();

            rr_claim_original_prize($conn, $raffleRow, [
                'id' => (int)$userRow['id'],
                'user_id' => $myUserId,
                'position' => (int)($userRow['position'] ?? 1)
            ], $preRow['pre_pool_choices'] ?? null, $cacheFile);
        } elseif ($mainChoice === 'cashback') {
            $rStmt = $conn->prepare("SELECT allow_cashback, cashback_percent, prize_config FROM raffles WHERE raffle_id = ? LIMIT 1");
            $rStmt->bind_param("i", $raffleId);
            $rStmt->execute();
            $raffleRow = $rStmt->get_result()->fetch_assoc();
            $rStmt->close();

            if (!$raffleRow) throw new Exception('Sorteo no encontrado.');
            if (empty($raffleRow['allow_cashback'])) throw new Exception('Cashback no permitido.');

            $percent = (float)$raffleRow['cashback_percent'];
            $prizeConfig = json_decode($raffleRow['prize_config'] ?? '', true);
            if (!is_array($prizeConfig)) {
                throw new Exception('Configuración de premio inválida.');
            }
            $prizeConfig = rr_prize_for_position($prizeConfig, (int)($userRow['position'] ?? 1));

            // Canonical valuation: percent of the prize's real value in Bananos
            // (coins amount / native pavos 1:1 / soles-equivalent for static
            // products). Same helper used by the cron and the queue path so the
            // displayed text and the credited amount always match.
            $coinsAmount = rr_cashback_coins($conn, $prizeConfig, $cacheFile, $percent);

            if ($coinsAmount <= 0) throw new Exception('El monto de canje calculado es 0. Contacta soporte.');
            $coinsAmount = round($coinsAmount, 2);

            $updBal = $conn->prepare("UPDATE coins SET balance = balance + ? WHERE user_id = ?");
            $updBal->bind_param("di", $coinsAmount, $myUserId);
            $updBal->execute();
            $updBal->close();

            $insTx = $conn->prepare("INSERT INTO coin_transactions (user_id, amount, reason, reference_type, reference_id, created_at) VALUES (?, ?, 'raffle_cashback', 'raffle_id', ?, UTC_TIMESTAMP())");
            $insTx->bind_param("idi", $myUserId, $coinsAmount, $raffleId);
            $insTx->execute();
            $insTx->close();

            $stmtUpdate = $conn->prepare("UPDATE raffle_winners SET choice = 'cashback', coin_choice = NULL, selected_animation_mode = NULL, generated_order_id = NULL, prize_sent = 1, cashback_credited_coins = ? WHERE id = ?");
            $stmtUpdate->bind_param("di", $coinsAmount, $userRow['id']);
            $stmtUpdate->execute();
            $stmtUpdate->close();
        } else {
            $sqlChoice = "UPDATE raffle_winners SET choice = ?, coin_choice = ?, selected_animation_mode = ? WHERE id = ?";
            $stmtUpdate = $conn->prepare($sqlChoice);
            $stmtUpdate->bind_param("sssi", $mainChoice, $coinChoice, $finalAnimMode, $userRow['id']);
            $stmtUpdate->execute();
            $stmtUpdate->close();
        }

        // 3. Verificar si faltan otros ganadores por decidir
        // Buscamos ganadores "pending" (que no sean 'burned') que aún no han elegido
        $sqlPending = "SELECT COUNT(*) as left_count FROM raffle_winners 
                       WHERE raffle_id = ?
                       AND (choice IS NULL OR choice = '' OR choice = 'pending')
                       AND (choice IS NULL OR choice <> 'burned')";
        $stmtPending = $conn->prepare($sqlPending);
        $stmtPending->bind_param("i", $raffleId);
        $stmtPending->execute();
        $pendingCount = $stmtPending->get_result()->fetch_assoc()['left_count'];

        // 4. EL DISPARO (Trigger): Si count es 0, todos han votado.
        // Asignamos el Bloque y la Semilla para el GRUPO ENTERO.
        if ($pendingCount == 0) {
            $stmtExistingTarget = $conn->prepare(
                "SELECT gamble_block_target FROM raffle_winners 
                 WHERE raffle_id = ?
                 AND choice = 'gamble_pending'
                 AND selected_animation_mode IS NOT NULL AND selected_animation_mode <> ''
                 AND (selected_animation_mode NOT IN ('coin', 'roulette') OR (coin_choice IS NOT NULL AND coin_choice <> ''))
                 AND gamble_block_target IS NOT NULL AND gamble_block_target <> 0
                 LIMIT 1"
            );
            $stmtExistingTarget->bind_param("i", $raffleId);
            $stmtExistingTarget->execute();
            $existingTargetRow = $stmtExistingTarget->get_result()->fetch_assoc();
            $existingTarget = $existingTargetRow ? (int)$existingTargetRow['gamble_block_target'] : 0;
            $stmtExistingTarget->close();

            // Sin apostadores que necesiten bloque no hay nada que pedir al RPC:
            // se cierra (o se deja esperando) en la misma transacción.
            $stmtNeedTarget = $conn->prepare(
                "SELECT COUNT(*) AS c FROM raffle_winners
                 WHERE raffle_id = ?
                 AND choice = 'gamble_pending'
                 AND (gamble_block_target IS NULL OR gamble_block_target = 0)"
            );
            $stmtNeedTarget->bind_param("i", $raffleId);
            $stmtNeedTarget->execute();
            $needTargetCount = (int)($stmtNeedTarget->get_result()->fetch_assoc()['c'] ?? 0);
            $stmtNeedTarget->close();

            if ($existingTarget === 0 && $needTargetCount === 0) {
                rr_raffle_close_if_all_decided($conn, $raffleId);
                $conn->commit();
                _outputJson(['status' => 'completed', 'message' => 'Premio reclamado']);
                exit;
            }

            if ($existingTarget > 0) {
                $stmtDone = $conn->prepare("SELECT COUNT(*) AS c FROM raffle_winners WHERE raffle_id = ? AND (choice = 'pending' OR choice = 'gamble_pending' OR choice = '' OR choice IS NULL)");
                $stmtDone->bind_param("i", $raffleId);
                $stmtDone->execute();
                $doneCount = (int)($stmtDone->get_result()->fetch_assoc()['c'] ?? 0);
                $stmtDone->close();
                if ($doneCount === 0) {
                    $stmtClose = $conn->prepare("UPDATE raffles SET status = 'completed' WHERE raffle_id = ?");
                    $stmtClose->bind_param("i", $raffleId);
                    $stmtClose->execute();
                    $stmtClose->close();
                } else {
                    $stmtWait = $conn->prepare("UPDATE raffles SET status = 'waiting_for_winner' WHERE raffle_id = ?");
                    $stmtWait->bind_param("i", $raffleId);
                    $stmtWait->execute();
                    $stmtWait->close();
                }

                $conn->commit();
                if ($mainChoice === 'gamble_pending') {
                    $earlyBlock = getRpcBlockNumber($rpcUrl);
                    _outputJson([
                        'status' => 'target_set',
                        'target_block' => $existingTarget,
                        'bsc_block_current' => $earlyBlock ? $earlyBlock : null,
                        'message' => 'Grupo listo. Bloque ya asignado.'
                    ]);
                } else {
                    _outputJson(['status' => 'completed', 'message' => 'Premio reclamado']);
                }
                exit;
            }

            // ============================================================
            // PHASE 1 COMMIT: Make choice visible to spectators IMMEDIATELY.
            // The slow RPC call below must NOT hold this transaction open.
            // ============================================================
            $conn->commit();

            // --- PHASE 2: Assign block target (involves slow external RPC) ---
            // This runs OUTSIDE any transaction so spectators already see
            // choice = 'gamble_pending' on their very next poll cycle.
            $rpcBlock = getRpcBlockNumber($rpcUrl);
            if (!$rpcBlock) {
                error_log("[submit_gamble] Phase 2: RPC failed for raffle $raffleId. Choice committed, target pending.");
                _outputJson(['status' => 'waiting_for_others', 'message' => 'Procesando bloque... espera.']);
                exit;
            }
            $targetBlock = $rpcBlock + 65; // +65 = ~36s race. Extra +30 blocks accounts for the 10-15s cinematic transition animation

            $serverSeed = bin2hex(random_bytes(32));
            $serverHash = hash('sha256', $serverSeed);

            // Phase 2 transaction: fast DB-only, no external calls
            $conn->begin_transaction();
            try {
                // Race-condition guard: another concurrent request may have
                // already assigned a target while we waited on the RPC call.
                $stmtRecheck = $conn->prepare(
                    "SELECT gamble_block_target FROM raffle_winners
                     WHERE raffle_id = ?
                     AND choice = 'gamble_pending'
                     AND gamble_block_target IS NOT NULL AND gamble_block_target > 0
                     LIMIT 1 FOR UPDATE"
                );
                $stmtRecheck->bind_param("i", $raffleId);
                $stmtRecheck->execute();
                $recheckRow = $stmtRecheck->get_result()->fetch_assoc();
                $stmtRecheck->close();

                if ($recheckRow && (int)$recheckRow['gamble_block_target'] > 0) {
                    // Another request already assigned — use theirs
                    $targetBlock = (int)$recheckRow['gamble_block_target'];
                    $conn->commit();
                    if ($mainChoice === 'gamble_pending') {
                        _outputJson([
                            'status' => 'target_set',
                            'target_block' => $targetBlock,
                            'bsc_block_current' => $rpcBlock,
                            'message' => 'Grupo listo. Bloque ya asignado.'
                        ]);
                    } else {
                        _outputJson(['status' => 'completed', 'message' => 'Premio reclamado']);
                    }
                    exit;
                }

                $sqlUpdateBatch = "UPDATE raffle_winners 
                                   SET gamble_block_target = ?, 
                                       gamble_server_seed = ?, 
                                       gamble_server_hash = ?
                                   WHERE raffle_id = ?
                                   AND choice = 'gamble_pending'
                                   AND selected_animation_mode IS NOT NULL AND selected_animation_mode <> ''
                                   AND (selected_animation_mode NOT IN ('coin', 'roulette') OR (coin_choice IS NOT NULL AND coin_choice <> ''))
                                   AND (gamble_block_target IS NULL OR gamble_block_target = 0)";
                
                $stmtBatch = $conn->prepare($sqlUpdateBatch);
                $stmtBatch->bind_param("issi", $targetBlock, $serverSeed, $serverHash, $raffleId);
                $stmtBatch->execute();

                $stmtDone = $conn->prepare("SELECT COUNT(*) AS c FROM raffle_winners WHERE raffle_id = ? AND (choice = 'pending' OR choice = 'gamble_pending' OR choice = '' OR choice IS NULL)");
                $stmtDone->bind_param("i", $raffleId);
                $stmtDone->execute();
                $doneCount = (int)($stmtDone->get_result()->fetch_assoc()['c'] ?? 0);
                $stmtDone->close();
                if ($doneCount === 0) {
                    $stmtClose = $conn->prepare("UPDATE raffles SET status = 'completed' WHERE raffle_id = ?");
                    $stmtClose->bind_param("i", $raffleId);
                    $stmtClose->execute();
                    $stmtClose->close();
                } else {
                    $stmtWait = $conn->prepare("UPDATE raffles SET status = 'waiting_for_winner' WHERE raffle_id = ?");
                    $stmtWait->bind_param("i", $raffleId);
                    $stmtWait->execute();
                    $stmtWait->close();
                }

                $conn->commit();
            } catch (Exception $e2) {
                $conn->rollback();
                // Phase 1 is already committed — choice is visible to spectators.
                // Log Phase 2 failure; triggerGambleResolve will handle target assignment.
                error_log("[submit_gamble] Phase 2 DB error for raffle $raffleId: " . $e2->getMessage());
                _outputJson(['status' => 'waiting_for_others', 'message' => 'Procesando... espera.']);
                exit;
            }

            if ($mainChoice === 'gamble_pending') {
                _outputJson([
                    'status' => 'target_set', 
                    'target_block' => $targetBlock,
                    'bsc_block_current' => $rpcBlock,
                    'message' => 'Grupo listo. Bloque asignado.'
                ]);
            } else {
                _outputJson(['status' => 'completed', 'message' => 'Premio reclamado']);
            }
        } else {
            $stmtDone = $conn->prepare("SELECT COUNT(*) AS c FROM raffle_winners WHERE raffle_id = ? AND (choice = 'pending' OR choice = 'gamble_pending' OR choice = '' OR choice IS NULL)");
            $stmtDone->bind_param("i", $raffleId);
            $stmtDone->execute();
            $doneCount = (int)($stmtDone->get_result()->fetch_assoc()['c'] ?? 0);
            $stmtDone->close();
            if ($doneCount === 0) {
                $stmtClose = $conn->prepare("UPDATE raffles SET status = 'completed' WHERE raffle_id = ?");
                $stmtClose->bind_param("i", $raffleId);
                $stmtClose->execute();
                $stmtClose->close();
            } else {
                $stmtWait = $conn->prepare("UPDATE raffles SET status = 'waiting_for_winner' WHERE raffle_id = ?");
                $stmtWait->bind_param("i", $raffleId);
                $stmtWait->execute();
                $stmtWait->close();
            }

            $conn->commit();
            if ($mainChoice === 'gamble_pending') {
                _outputJson([
                    'status' => 'waiting_for_others', 
                    'pending_partners' => $pendingCount,
                    'message' => 'Esperando a otros ganadores...'
                ]);
            } else {
                _outputJson(['status' => 'completed', 'message' => 'Premio reclamado']);
            }
        }

    } catch (Exception $e) {
        $conn->rollback();
        _outputJson(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

//  ACCIÓN 2: POLLING (El Navegador Pregunta)
//  - Revisa estado.
//  - Si ya salió el bloque -> Resuelve Matemáticamente (Resolve).
// ===================================================================================
if ($action === 'poll_status') {
    
    // Leer estado actual del usuario
    $sqlStatus = "SELECT id, choice, position, coin_choice, selected_animation_mode, gamble_block_target, gamble_block_hash, gamble_result, gamble_server_seed, gamble_server_hash 
                  FROM raffle_winners WHERE raffle_id = ? AND user_id = ?";
    $stmtStatus = $conn->prepare($sqlStatus);
    $stmtStatus->bind_param("ii", $raffleId, $myUserId);
    $stmtStatus->execute();
    $userData = $stmtStatus->get_result()->fetch_assoc();

    if (!$userData) {
        _outputJson(['status' => 'error', 'message' => 'No encontrado']);
        exit;
    }

    // CASO A: Ya terminó (Ganó o Perdió o Reclamó)
    $choice = $userData['choice'] ?? null;
    $resultRaw = $userData['gamble_result'] ?? null;
    $resultTrim = is_string($resultRaw) ? trim($resultRaw) : '';
    $resultStr = null;
    $outcomeSide = null;

    if ($choice === 'double_won') {
        $resultStr = 'win';
    } elseif ($choice === 'double_lost') {
        $resultStr = 'loss';
    }

    if ($resultStr === null && ($resultTrim === 'win' || $resultTrim === 'loss')) {
        $resultStr = $resultTrim;
    }

    if ($resultStr === null && $resultTrim !== '') {
        $decoded = json_decode($resultTrim, true);
        if (is_array($decoded)) {
            if (isset($decoded['result']) && ($decoded['result'] === 'win' || $decoded['result'] === 'loss')) {
                $resultStr = $decoded['result'];
            }
            if (isset($decoded['outcome_side']) && ($decoded['outcome_side'] === 'heads' || $decoded['outcome_side'] === 'tails')) {
                $outcomeSide = $decoded['outcome_side'];
            } elseif (isset($decoded['outcome']) && ($decoded['outcome'] === 'heads' || $decoded['outcome'] === 'tails')) {
                $outcomeSide = $decoded['outcome'];
            }
        }
    }

    if (in_array($choice, ['double_won', 'double_lost', 'original', 'cashback'], true) || $resultStr !== null) {
        _outputJson([
            'status' => 'finished',
            'result' => $resultStr,
            'choice' => $choice,
            'block_hash' => $userData['gamble_block_hash'] ?? null,
            'outcome' => $outcomeSide
        ]);
        exit;
    }

    // CASO B: Aún esperando que otros decidan
    if ($userData['gamble_block_target'] === null || (int)$userData['gamble_block_target'] === 0) {
        _outputJson(['status' => 'waiting_for_others']);
        exit;
    }

    // CASO C: Ya tenemos bloque objetivo. ¿Ya salió en la Blockchain?
    $targetBlock = (int)$userData['gamble_block_target'];
    
    // Consultamos bloque actual (RPC Ligero)
    $currentBlock = getRpcBlockNumber($rpcUrl);

    if ($currentBlock >= $targetBlock) {
        // ¡EL BLOQUE YA SALIÓ! MOMENTO DE RESOLVER (RESOLVE NOW)
        
        // Inicio Transacción para resolver (Idempotencia)
        $conn->begin_transaction();
        try {
            // Verificar bloqueo nuevamente
            $stmtLock = $conn->prepare("SELECT gamble_block_hash FROM raffle_winners WHERE id = ? FOR UPDATE");
            $stmtLock->bind_param("i", $userData['id']);
            $stmtLock->execute();
            $check = $stmtLock->get_result()->fetch_assoc();

            if (!empty($check['gamble_block_hash'])) {
                // Ya fue resuelto por otro proceso (o el usuario refrescó rápido)
                $conn->commit();
                _outputJson(['status' => 'resolving']); // El próximo poll traerá 'finished'
                exit;
            }

            // 1. Obtener el Hash del Bloque Objetivo (Solo una vez)
            // Nota: En un entorno real, idealmente cachearíamos esto si hay 100 usuarios, 
            // pero para grupos pequeños, llamar al RPC está bien.
            $blockHash = getRpcBlockHash($rpcUrl, $targetBlock);
            
            if (!$blockHash) throw new Exception("Bloque aún no propagado");

            // 2. Calcular Resultado (Matemática Provably Fair, canónica y compartida)
            // Formula: HMAC_SHA256(ServerSeed + BlockHash + UserID, key=ServerSeed)
            $serverSeed = $userData['gamble_server_seed'];
            $oc = rr_gamble_outcome($serverSeed, $blockHash, $userData['id']);
            $outcome = $oc['outcome'];

            $allowedAnimModes = ['coin', 'slot', 'roulette'];
            $selectedAnimMode = $userData['selected_animation_mode'] ?? null;
            if (!is_string($selectedAnimMode) || !in_array($selectedAnimMode, $allowedAnimModes, true)) {
                $selectedAnimMode = 'coin';
            }

            if ($selectedAnimMode === 'coin') {
                $didWin = ($outcome === ($userData['coin_choice'] ?? null));
            } elseif ($selectedAnimMode === 'roulette') {
                // Ruleta Rojo/Negro: heads=rojo, tails=negro; gana si acierta el color.
                // Filas legadas sin elección: paridad clásica (heads=win).
                $rouletteChoice = $userData['coin_choice'] ?? null;
                $rouletteColor = ($outcome === 'heads') ? 'red' : 'black';
                $didWin = in_array($rouletteChoice, ['red', 'black'], true)
                    ? ($rouletteColor === $rouletteChoice)
                    : ($outcome === 'heads');
            } else {
                // Modo sin predicción: resultado determinístico por paridad (heads=win, tails=loss)
                $didWin = ($outcome === 'heads');
            }

            $finalResult = $didWin ? 'win' : 'loss';
            $finalChoice = $didWin ? 'double_won' : 'double_lost';

            $orderIdSql = null;
            $prizeSent = $didWin ? 1 : 0;
            $doubleBonusCoins = 0.0;
            if ($didWin) {
                $stmtRaffle = $conn->prepare("SELECT prize_config FROM raffles WHERE raffle_id = ? LIMIT 1");
                $stmtRaffle->bind_param("i", $raffleId);
                $stmtRaffle->execute();
                $raffleRow = $stmtRaffle->get_result()->fetch_assoc();
                $stmtRaffle->close();
                if (!$raffleRow) {
                    throw new Exception('Sorteo no encontrado.');
                }
                $prizeConfig = json_decode($raffleRow['prize_config'] ?? '', true);
                if (!is_array($prizeConfig)) {
                    throw new Exception('Configuración de premio inválida.');
                }
                $prizeConfig = rr_prize_for_position($prizeConfig, (int)($userData['position'] ?? 1));

                $stmtU = $conn->prepare("SELECT game_username, email, whatsapp_phone_e164 FROM users WHERE id = ? LIMIT 1");
                $stmtU->bind_param("i", $myUserId);
                $stmtU->execute();
                $uData = $stmtU->get_result()->fetch_assoc();
                $stmtU->close();
                if (!$uData) {
                    throw new Exception('No se pudo cargar datos del usuario.');
                }

                // Fase 3: premio original (1x) + equivalente exacto en Bananos.
                $payout = rr_deliver_double_win($conn, $myUserId, $uData, $prizeConfig, $cacheFile, $raffleId);
                $orderIdSql = $payout['order_id'];
                $doubleBonusCoins = $payout['bonus_coins'];
            }

            $finalPayload = [
                'wager' => 'double',
                'animation_pref' => $selectedAnimMode,
                'prediction_side' => $userData['coin_choice'],
                'outcome_side' => $outcome,
                'result' => $finalResult,
                'status' => 'finished',
                'target_block' => $targetBlock,
                'block_hash' => $blockHash,
                'double_bonus_coins' => $doubleBonusCoins
            ];
            $finalGambleResult = json_encode($finalPayload);
            if ($finalGambleResult === false) {
                throw new Exception('No se pudo serializar gamble_result JSON: ' . json_last_error_msg());
            }

            // 3. Guardar Resultado en BD
            if ($orderIdSql !== null) {
                $sqlUpdateRes = "UPDATE raffle_winners 
                                 SET gamble_block_hash = ?, 
                                     gamble_result = ?, 
                                     choice = ?,
                                     generated_order_id = ?,
                                     prize_sent = ?,
                                     gamble_resolved_at = UTC_TIMESTAMP()
                                 WHERE id = ?";
                $stmtRes = $conn->prepare($sqlUpdateRes);
                $stmtRes->bind_param("sssiii", $blockHash, $finalGambleResult, $finalChoice, $orderIdSql, $prizeSent, $userData['id']);
                $stmtRes->execute();
                $stmtRes->close();
            } else {
                $sqlUpdateRes = "UPDATE raffle_winners 
                                 SET gamble_block_hash = ?, 
                                     gamble_result = ?, 
                                     choice = ?,
                                     generated_order_id = NULL,
                                     prize_sent = ?,
                                     gamble_resolved_at = UTC_TIMESTAMP()
                                 WHERE id = ?";
                $stmtRes = $conn->prepare($sqlUpdateRes);
                $stmtRes->bind_param("sssii", $blockHash, $finalGambleResult, $finalChoice, $prizeSent, $userData['id']);
                $stmtRes->execute();
                $stmtRes->close();
            }

            $stmtDone = $conn->prepare("SELECT COUNT(*) AS c FROM raffle_winners WHERE raffle_id = ? AND (choice = 'pending' OR choice = 'gamble_pending' OR choice = '' OR choice IS NULL)");
            $stmtDone->bind_param("i", $raffleId);
            $stmtDone->execute();
            $doneCount = (int)($stmtDone->get_result()->fetch_assoc()['c'] ?? 0);
            $stmtDone->close();
            if ($doneCount === 0) {
                $stmtClose = $conn->prepare("UPDATE raffles SET status = 'completed' WHERE raffle_id = ?");
                $stmtClose->bind_param("i", $raffleId);
                $stmtClose->execute();
                $stmtClose->close();
            } else {
                $stmtWait = $conn->prepare("UPDATE raffles SET status = 'waiting_for_winner' WHERE raffle_id = ?");
                $stmtWait->bind_param("i", $raffleId);
                $stmtWait->execute();
                $stmtWait->close();
            }

            $conn->commit();
            
            _outputJson([
                'status' => 'just_resolved',
                'result' => $finalResult,
                'outcome' => $outcome, // 'heads' o 'tails' real
                'block_hash' => $blockHash
            ]);

        } catch (Throwable $e) {
            $conn->rollback();
            if ($debugMode) {
                _outputJson([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile()
                ]);
            } else {
                // Si falla el RPC, decimos que seguimos esperando
                $gamblersArr = [];
                $stmtG = $conn->prepare(
                    "SELECT u.game_username, a.filename AS avatar
                     FROM raffle_winners rw
                     JOIN users u ON rw.user_id = u.id
                     LEFT JOIN avatars a ON u.avatar_id = a.id
                     WHERE rw.raffle_id = ?
                       AND rw.choice = 'gamble_pending'
                       AND rw.gamble_block_target IS NOT NULL
                       AND rw.gamble_block_target <> 0"
                );
                $stmtG->bind_param("i", $raffleId);
                $stmtG->execute();
                $resG = $stmtG->get_result();
                while ($gRow = $resG->fetch_assoc()) {
                    $gamblersArr[] = [
                        'username' => $gRow['game_username'] ?? 'Jugador',
                        'avatar'   => AvatarCatalog::filename($conn, $gRow['avatar'] ?? null)
                    ];
                }
                $stmtG->close();

                _outputJson([
                    'status' => 'waiting_for_block', 
                    'current' => $currentBlock, 
                    'target' => $targetBlock,
                    'bsc_block_current' => $currentBlock,
                    'gamblers' => $gamblersArr
                ]);
            }
        }
    } else {
        // El bloque aún no sale — include gamblers list
        $gamblersArr = [];
        $stmtG = $conn->prepare(
            "SELECT u.game_username, a.filename AS avatar
             FROM raffle_winners rw
             JOIN users u ON rw.user_id = u.id
             LEFT JOIN avatars a ON u.avatar_id = a.id
             WHERE rw.raffle_id = ?
               AND rw.choice = 'gamble_pending'
               AND rw.gamble_block_target IS NOT NULL
               AND rw.gamble_block_target <> 0"
        );
        $stmtG->bind_param("i", $raffleId);
        $stmtG->execute();
        $resG = $stmtG->get_result();
        while ($gRow = $resG->fetch_assoc()) {
            $gamblersArr[] = [
                'username' => $gRow['game_username'] ?? 'Jugador',
                'avatar'   => AvatarCatalog::filename($conn, $gRow['avatar'] ?? null)
            ];
        }
        $stmtG->close();

        _outputJson([
            'status' => 'waiting_for_block', 
            'current' => $currentBlock, 
            'target' => $targetBlock,
            'bsc_block_current' => $currentBlock,
            'gamblers' => $gamblersArr
        ]);
    }
    exit;
}

function findProductInCache($offerId, $cacheFile) {
    if (!file_exists($cacheFile)) return null;
    $json = file_get_contents($cacheFile);
    $data = json_decode($json, true);
    $shopItems = $data['entries'] ?? $data['shop'] ?? null;
    if (!is_array($shopItems)) return null;
    foreach ($shopItems as $item) {
        if (strcasecmp($item['offerId'], $offerId) === 0) return $item;
    }
    return null;
}

function createOrderHelper($conn, $userId, $userData, $prizeConfig, $cacheFile, $raffleId) {
    $pIdStr = $prizeConfig['id'] ?? 'sorteo-' . $raffleId;
    $pName = $prizeConfig['name'];
    $pImage = $prizeConfig['image'] ?? '';
    $cachedItem = null;
    if (!empty($prizeConfig['id'])) {
        $cachedItem = findProductInCache($prizeConfig['id'], $cacheFile);
    }

    $precioBase = 0.00;
    $vBucks = 0;
    $tipoProducto = 'Premio Sorteo';
    $sectionName = 'Sorteo';
    $storeRank = 0;
    $c1 = null; $c2 = null; $c3 = null;

    if ($cachedItem) {
        $precioBase = (float)($cachedItem['precio_base_soles'] ?? 0);
        $vBucks = (int)($cachedItem['vbucks'] ?? 0);
        $tipoProducto = $cachedItem['displayType'] ?? 'Atuendo';
        $sectionName = $cachedItem['section_name'] ?? 'Tienda';
        $storeRank = (int)($cachedItem['store_rank'] ?? 0);
        if (isset($cachedItem['colors'])) {
            if(!empty($cachedItem['colors']['color1'])) $c1 = $cachedItem['colors']['color1'];
            if(!empty($cachedItem['colors']['color2'])) $c2 = $cachedItem['colors']['color2'];
            if(!empty($cachedItem['colors']['color3'])) $c3 = $cachedItem['colors']['color3'];
        }
    }

// --- CORRECCIÓN SQL: Solo seleccionamos columnas que existen en la BD ---
    $stmtUser = $conn->prepare("SELECT game_username, email, whatsapp_phone_e164 FROM users WHERE id = ?");
    $stmtUser->bind_param("i", $userId);
    $stmtUser->execute();
    $resUser = $stmtUser->get_result();
    $dbUserInfo = $resUser->fetch_assoc();
    $stmtUser->close();

    // Lógica Whatsapp simplificada (Solo usamos la columna real)
    $userWhatsapp = '';
    if (!empty($userData['whatsapp_discord_cliente'])) {
        $userWhatsapp = $userData['whatsapp_discord_cliente'];
    } elseif (!empty($dbUserInfo['whatsapp_phone_e164'])) {
        $userWhatsapp = $dbUserInfo['whatsapp_phone_e164'];
    }

    $emailCliente = $dbUserInfo['email'] ?? ''; // Prevenimos null
    $gameNick = $dbUserInfo['game_username'] ?? 'Usuario'; // Prevenimos null

    $code = 'WIN-' . strtoupper(substr(md5(uniqid()), 0, 8));
    $notas = "GANADOR SORTEO #$raffleId (Reclamado)";
    $estado = 'pagado';
    $metodo = 'saldo';
    $estadoPago = 'notificado_pendiente_revision';
    $mode = 'self';
    $esRegalo = 0;
    $cero = 0.00;

    $sqlOrder = "INSERT INTO pedidos (
        user_id, codigo_pedido, email_cliente, whatsapp_discord_cliente, nickname_fortnite_cliente, 
        subtotal, monto_descuento, total_pagar, estado, metodo_pago, 
        notas_admin, fecha_creacion, fecha_pago, estado_pago_cliente, recipient_mode, es_regalo
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?, ?)";

    $stmtOrder = $conn->prepare($sqlOrder);
    $nickPlaceholder = 'Ganador Sorteo';
    $stmtOrder->bind_param("issssdddsssssi",
        $userId, $code, $emailCliente, $userWhatsapp, $nickPlaceholder,
        $precioBase, $precioBase, $cero, $estado, $metodo,
        $notas, $estadoPago, $mode, $esRegalo
    );

    if ($stmtOrder->execute()) {
        $oId = $conn->insert_id;
        $stmtOrder->close();

        $sqlDetail = "INSERT INTO detalles_pedido (
            pedido_id, producto_id_str, nombre_producto, imagen_producto_url, 
            precio_base, precio_final, cantidad, tipo_producto,
            v_bucks, color_1, color_2, color_3, section_name, store_rank
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmtDetail = $conn->prepare($sqlDetail);
        $cantidad = 1;
        $stmtDetail->bind_param("isssddisissssi",
            $oId, $pIdStr, $pName, $pImage,
            $precioBase, $cero, $cantidad, $tipoProducto,
            $vBucks, $c1, $c2, $c3, $sectionName, $storeRank
        );
        $stmtDetail->execute();
        $detailId = $conn->insert_id;
        $stmtDetail->close();

        $sqlDest = "INSERT INTO detalles_pedido_destinatarios (detalle_pedido_id, nickname_destinatario) VALUES (?, ?)";
        $stmtDest = $conn->prepare($sqlDest);
        $stmtDest->bind_param("is", $detailId, $gameNick);
        $stmtDest->execute();
        $stmtDest->close();

        return $oId;
    }

    $stmtOrder->close();
    return "NULL";
}

function deliverPrize($conn, $userId, $userData, $prizeConfig, $cacheFile, $raffleId) {
    if (($prizeConfig['type'] ?? '') === 'coins') {
        $amount = (float)($prizeConfig['amount'] ?? 0);
        $updBal = $conn->prepare("UPDATE coins SET balance = balance + ? WHERE user_id = ?");
        $updBal->bind_param("di", $amount, $userId);
        $updBal->execute();
        $updBal->close();

        $insTx = $conn->prepare("INSERT INTO coin_transactions (user_id, amount, reason, reference_type, reference_id, created_at) VALUES (?, ?, 'raffle_win', 'raffle_id', ?, UTC_TIMESTAMP())");
        $insTx->bind_param("idi", $userId, $amount, $raffleId);
        $insTx->execute();
        $insTx->close();
        return "NULL";
    }

    if (($prizeConfig['type'] ?? '') === 'product' || ($prizeConfig['type'] ?? '') === 'skin') {
        // Modo OFERTA: cupón personal en daily_discounts, nunca un pedido gratis.
        if (rr_prize_discount_percent($prizeConfig) > 0) {
            rr_deliver_offer_coupon($conn, $userId, $prizeConfig, $cacheFile, $raffleId);
            return "NULL";
        }
        return createOrderHelper($conn, $userId, $userData, $prizeConfig, $cacheFile, $raffleId);
    }

    return "NULL";
}

// --- FUNCIONES AUXILIARES RPC ---

function getRpcBlockNumber($url) {
    $payload = json_encode([
        "jsonrpc" => "2.0", "method" => "eth_blockNumber", "params" => [], "id" => 1
    ]);
    $res = execCurl($url, $payload);
    if (isset($res['result'])) {
        return hexdec($res['result']);
    }
    return 0;
}

function getRpcBlockHash($url, $blockNumber) {
    $hexBlock = '0x' . dechex($blockNumber);
    $payload = json_encode([
        "jsonrpc" => "2.0", "method" => "eth_getBlockByNumber", "params" => [$hexBlock, false], "id" => 1
    ]);
    $res = execCurl($url, $payload);
    if (isset($res['result']['hash'])) {
        return $res['result']['hash'];
    }
    return null;
}

function execCurl($url, $payload) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Timeout corto para no colgar el polling
    $output = curl_exec($ch);
    curl_close($ch);
    return json_decode($output, true);
}
?>
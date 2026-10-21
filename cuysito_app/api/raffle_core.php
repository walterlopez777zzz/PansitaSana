<?php
// api/raffle_core.php
// CORE LIBRARY V1.0 — Pure functions for Sorteo Flash "First-Caller Resolution"
// This file is NOT an API endpoint. No headers, no json_encode, no session_start.
// Require it from raffle_status.php / raffle_gamble.php and call the functions directly.

require_once __DIR__ . '/raffle_review.php';
require_once __DIR__ . '/raffle_rewards.php';
require_once __DIR__ . '/raffle_bans.php';

// =================================================================================
// HELPER: BSC RPC Call (Generic)
// =================================================================================
if (!function_exists('rpcCall')) {
    function rpcCall($url, $method, $params = []) {
        $ch = curl_init($url);
        $payload = json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => time()]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $result = curl_exec($ch);
        curl_close($ch);
        $decoded = json_decode(is_string($result) ? $result : '', true);
        return is_array($decoded) ? $decoded : [];
    }
}

// =================================================================================
// HELPER: Get current BSC block number (decimal int)
// Returns int block number or false on failure.
// Uses a 1-second micro-cache file to avoid hammering the RPC node under high
// concurrency (matches the 1s client polling of the block race so the HUD sees
// a fresh block on every poll). File locking (LOCK_EX) ensures only one process
// fetches at a time; all others read the cached value instantly.
// =================================================================================
if (!function_exists('getRpcBlockNumber')) {
    function getRpcBlockNumber($rpcUrl) {
        $cacheDir = dirname(__DIR__) . '/cache';
        $cacheFile = $cacheDir . '/bsc_block_cache.json';
        $ttl = 1; // seconds

        // --- Fast path: read cache without locking ---
        if (file_exists($cacheFile)) {
            $raw = @file_get_contents($cacheFile);
            if ($raw !== false) {
                $cached = @json_decode($raw, true);
                if (is_array($cached) && isset($cached['block'], $cached['ts']) && (time() - $cached['ts']) < $ttl) {
                    return (int)$cached['block'];
                }
            }
        }

        // --- Slow path: acquire exclusive lock, double-check, then fetch ---
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
        $fp = @fopen($cacheFile, 'c+');
        if (!$fp) {
            // File ops failed — fall back to direct RPC
            $resp = rpcCall($rpcUrl, 'eth_blockNumber');
            return isset($resp['result']) ? hexdec($resp['result']) : false;
        }

        // Non-blocking try: if another process holds the lock, just read stale cache
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            $raw = @stream_get_contents($fp);
            fclose($fp);
            if ($raw !== false) {
                $cached = @json_decode($raw, true);
                if (is_array($cached) && isset($cached['block'])) {
                    return (int)$cached['block'];
                }
            }
            // No usable cache — direct RPC as last resort
            $resp = rpcCall($rpcUrl, 'eth_blockNumber');
            return isset($resp['result']) ? hexdec($resp['result']) : false;
        }

        // We hold the lock — double-check freshness (another process may have just written)
        $raw = @stream_get_contents($fp);
        if ($raw !== false) {
            $cached = @json_decode($raw, true);
            if (is_array($cached) && isset($cached['block'], $cached['ts']) && (time() - $cached['ts']) < $ttl) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return (int)$cached['block'];
            }
        }

        // Fetch from RPC
        $resp = rpcCall($rpcUrl, 'eth_blockNumber');
        $block = isset($resp['result']) ? hexdec($resp['result']) : false;

        if ($block !== false) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode(['block' => (int)$block, 'ts' => time()]));
            fflush($fp);
        }

        flock($fp, LOCK_UN);
        fclose($fp);
        return $block;
    }
}

// =================================================================================
// HELPER: Get BSC block hash by block number (decimal int)
// Returns string hash or false on failure.
// =================================================================================
if (!function_exists('getRpcBlockHash')) {
    function getRpcBlockHash($rpcUrl, $blockNumber) {
        $blockHex = '0x' . dechex($blockNumber);
        $resp = rpcCall($rpcUrl, 'eth_getBlockByNumber', [$blockHex, false]);
        if (isset($resp['result']) && is_array($resp['result']) && !empty($resp['result']['hash'])) {
            return (string)$resp['result']['hash'];
        }
        return false;
    }
}

// =================================================================================
// HELPER: Find product in local shop cache JSON
// =================================================================================
if (!function_exists('findProductInCache')) {
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
}

// =================================================================================
// HELPER: Create order (pedido) for a raffle winner — Prepared Statements
// =================================================================================
if (!function_exists('createOrderHelper')) {
    function createOrderHelper($conn, $userId, $userData, $prizeConfig, $cacheFile, $raffleId) {
        $pIdStr = $prizeConfig['id'] ?? 'sorteo-' . $raffleId;
        $pName  = $prizeConfig['name'];
        $pImage = $prizeConfig['image'] ?? '';
        $cachedItem = null;
        if (!empty($prizeConfig['id'])) {
            $cachedItem = findProductInCache($prizeConfig['id'], $cacheFile);
        }

        $precioBase   = 0.00;
        $vBucks       = 0;
        $tipoProducto = 'Premio Sorteo';
        $sectionName  = 'Sorteo';
        $storeRank    = 0;
        $c1 = null; $c2 = null; $c3 = null;

        if ($cachedItem) {
            $precioBase   = (float)($cachedItem['precio_base_soles'] ?? 0);
            $vBucks       = (int)($cachedItem['vbucks'] ?? 0);
            $tipoProducto = $cachedItem['displayType'] ?? 'Atuendo';
            $sectionName  = $cachedItem['section_name'] ?? 'Tienda';
            $storeRank    = (int)($cachedItem['store_rank'] ?? 0);
            if (isset($cachedItem['colors'])) {
                if (!empty($cachedItem['colors']['color1'])) $c1 = $cachedItem['colors']['color1'];
                if (!empty($cachedItem['colors']['color2'])) $c2 = $cachedItem['colors']['color2'];
                if (!empty($cachedItem['colors']['color3'])) $c3 = $cachedItem['colors']['color3'];
            }
        }

        $stmtUser = $conn->prepare("SELECT game_username, email, whatsapp_phone_e164 FROM users WHERE id = ?");
        $stmtUser->bind_param("i", $userId);
        $stmtUser->execute();
        $dbUserInfo = $stmtUser->get_result()->fetch_assoc();
        $stmtUser->close();

        $userWhatsapp = '';
        if (!empty($userData['whatsapp_discord_cliente']))    $userWhatsapp = $userData['whatsapp_discord_cliente'];
        elseif (!empty($dbUserInfo['whatsapp_phone_e164']))   $userWhatsapp = $dbUserInfo['whatsapp_phone_e164'];

        $emailCliente = $dbUserInfo['email'];
        $gameNick     = $dbUserInfo['game_username'];

        $code       = 'WIN-' . strtoupper(substr(md5(uniqid()), 0, 8));
        $notas      = "GANADOR SORTEO #$raffleId (Reclamado)";
        $estado     = 'pagado';
        $metodo     = 'saldo';
        $estadoPago = 'notificado_pendiente_revision';
        $mode       = 'self';
        $esRegalo   = 0;
        $cero       = 0.00;

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

            $stmtDest = $conn->prepare("INSERT INTO detalles_pedido_destinatarios (detalle_pedido_id, nickname_destinatario) VALUES (?, ?)");
            $stmtDest->bind_param("is", $detailId, $gameNick);
            $stmtDest->execute();
            $stmtDest->close();

            return $oId;
        }

        $stmtOrder->close();
        return "NULL";
    }
}

// =================================================================================
// HELPER: Deliver prize (coins or product) to a winner
// =================================================================================
if (!function_exists('deliverPrize')) {
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

        if (in_array(($prizeConfig['type'] ?? ''), ['product', 'skin', 'custom', 'category'], true)) {
            // Modo OFERTA: cupón personal en daily_discounts, nunca un pedido gratis.
            if (rr_prize_discount_percent($prizeConfig) > 0) {
                rr_deliver_offer_coupon($conn, $userId, $prizeConfig, $cacheFile, $raffleId);
                return "NULL";
            }
            return createOrderHelper($conn, $userId, $userData, $prizeConfig, $cacheFile, $raffleId);
        }

        return "NULL";
    }
}

// =================================================================================
// FUNCTION 1: triggerRaffleClose
// Called when a raffle's time expires. Locks the row, fetches the current BSC block,
// sets bsc_block_target = currentBlock + 45 (~135s dramatic pause on BSC 3s blocks),
// and transitions status from 'open' → 'locking'.
//
// Returns: ['success' => bool, 'message' => string, 'block_target' => int|null]
// =================================================================================
function triggerRaffleClose($conn, $raffleId, $rpcUrl) {
    $raffleId = (int)$raffleId;
    if ($raffleId <= 0) {
        return ['success' => false, 'message' => 'Invalid raffle ID'];
    }

    $conn->begin_transaction();
    try {
        // Lock the raffle row
        $lockStmt = $conn->prepare("SELECT * FROM raffles WHERE raffle_id = ? FOR UPDATE");
        $lockStmt->bind_param("i", $raffleId);
        $lockStmt->execute();
        $raffleLocked = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();

        if (!$raffleLocked) {
            $conn->rollback();
            return ['success' => false, 'message' => 'Raffle not found'];
        }

        // Guard: must be 'open' and time must have expired
        if ($raffleLocked['status'] !== 'open') {
            $conn->rollback();
            return ['success' => false, 'message' => 'Raffle is not open (current: ' . $raffleLocked['status'] . ')'];
        }

        $endTime = strtotime($raffleLocked['end_time']);
        if ($endTime === false || $endTime > time()) {
            $conn->rollback();
            return ['success' => false, 'message' => 'Raffle has not expired yet'];
        }

        // Fetch current BSC block number
        $currentBlock = getRpcBlockNumber($rpcUrl);
        if ($currentBlock === false) {
            $conn->rollback();
            return ['success' => false, 'message' => 'RPC error: could not fetch current block number'];
        }

        // Target block = current + 45 (~135 seconds on BSC @ ~3s/block)
        $blockTarget = $currentBlock + 45;

        // Transition: open → locking
        $updStmt = $conn->prepare("UPDATE raffles SET status = 'locking', bsc_block_target = ? WHERE raffle_id = ?");
        $updStmt->bind_param("ii", $blockTarget, $raffleId);
        $updStmt->execute();
        $updStmt->close();

        // T_FREEZE: congelamos el roster (espacio de tickets + huecos expulsados)
        // ANTES de que se conozca la semilla (hash del bloque BSC objetivo, que
        // aún no se ha minado). Esto hace el sorteo recomputable y a prueba de
        // manipulación: tras este punto no se admiten más cambios de roster.
        try {
            rr_save_snapshot($conn, $raffleId);
        } catch (Throwable $snapErr) {
            error_log('raffle snapshot error: ' . $snapErr->getMessage());
        }

        $conn->commit();

        return [
            'success'      => true,
            'message'      => 'Raffle locked. Waiting for target block.',
            'block_current' => $currentBlock,
            'block_target'  => $blockTarget
        ];

    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'message' => 'Exception: ' . $e->getMessage()];
    }
}

// =================================================================================
// FUNCTION 2: triggerRaffleResolve
// Called once the BSC chain has reached bsc_block_target for a 'locking' raffle.
// Fetches the target block hash, runs the Provably Fair selection algorithm,
// inserts winners (and burns), and transitions status to 'waiting_for_winner'
// or 'completed'.
//
// Returns: ['success' => bool, 'message' => string, ...]
// =================================================================================
function triggerRaffleResolve($conn, $raffleId, $rpcUrl, $cacheFile) {
    $raffleId = (int)$raffleId;
    if ($raffleId <= 0) {
        return ['success' => false, 'message' => 'Invalid raffle ID'];
    }

    rb_ensure_schema($conn);
    $conn->begin_transaction();
    try {
        // Lock the raffle row
        $lockStmt = $conn->prepare("SELECT * FROM raffles WHERE raffle_id = ? FOR UPDATE");
        $lockStmt->bind_param("i", $raffleId);
        $lockStmt->execute();
        $raffleLocked = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();

        if (!$raffleLocked) {
            $conn->rollback();
            return ['success' => false, 'message' => 'Raffle not found'];
        }

        // Guard: must be in 'locking' state
        if ($raffleLocked['status'] !== 'locking') {
            $conn->rollback();
            return ['success' => false, 'message' => 'Raffle is not in locking state (current: ' . $raffleLocked['status'] . ')'];
        }

        $blockTarget = (int)$raffleLocked['bsc_block_target'];
        if ($blockTarget <= 0) {
            $conn->rollback();
            return ['success' => false, 'message' => 'No valid bsc_block_target set'];
        }

        // Check current block >= target
        $currentBlock = getRpcBlockNumber($rpcUrl);
        if ($currentBlock === false) {
            $conn->rollback();
            return ['success' => false, 'message' => 'RPC error: could not fetch current block number'];
        }

        if ($currentBlock < $blockTarget) {
            $conn->rollback();
            return [
                'success'       => false,
                'message'       => 'Target block not yet reached',
                'block_current' => $currentBlock,
                'block_target'  => $blockTarget
            ];
        }

        // Idempotency: skip if winners already exist
        $existingStmt = $conn->prepare("SELECT COUNT(*) AS c FROM raffle_winners WHERE raffle_id = ?");
        $existingStmt->bind_param("i", $raffleId);
        $existingStmt->execute();
        $existingCount = (int)($existingStmt->get_result()->fetch_assoc()['c'] ?? 0);
        $existingStmt->close();
        if ($existingCount > 0) {
            $conn->rollback();
            return ['success' => false, 'message' => 'Winners already calculated (idempotency guard)'];
        }

        // Fetch the exact hash of the target block
        $targetBlockHash = getRpcBlockHash($rpcUrl, $blockTarget);
        if ($targetBlockHash === false) {
            $conn->rollback();
            return ['success' => false, 'message' => 'RPC error: could not fetch target block hash'];
        }

        // =====================================================================
        // PROVABLY FAIR MATH — canonical outcome shared with raffle_gamble.php
        // =====================================================================
        $serverSeedSecret = (string)($raffleLocked['server_seed_secret'] ?? '');
        $masterSeed = hash('sha256', $serverSeedSecret . $targetBlockHash);

        // Load participants
        $partsStmt = $conn->prepare("SELECT * FROM raffle_participants WHERE raffle_id = ? ORDER BY ticket_index ASC");
        $partsStmt->bind_param("i", $raffleId);
        $partsStmt->execute();
        $resParts = $partsStmt->get_result();

        $participants = [];
        while ($row = $resParts->fetch_assoc()) {
            $participants[] = $row;
        }
        $partsStmt->close();

        // =====================================================================
        // RECTA NUMÉRICA CON TICKETS ESTÁTICOS
        // ---------------------------------------------------------------------
        // El espacio muestral es el TOTAL DE TICKETS EMITIDOS, incluidos los
        // HUECOS de participantes expulsados (review_status = 'expelled'), que
        // conservan su número físico. El sorteo elige un NÚMERO DE TICKET; si
        // cae en un hueco/expulsado o en un ticket ya ganador, se RECHAZA y se
        // redibuja de forma determinista (sufijo _r1, _r2, ...) hasta caer en
        // un ticket válido. Así el número auditado SIEMPRE coincide con el
        // ganador visual y jamás cae en un asiento vacío.
        $maxTicket     = -1;   // mayor ticket_index emitido (incluye huecos)
        $validByTicket = [];   // ticket_index => participante válido (no expulsado)
        foreach ($participants as $p) {
            $t = (int)($p['ticket_index'] ?? -1);
            if ($t < 0) continue;
            if ($t > $maxTicket) $maxTicket = $t;
            if (($p['review_status'] ?? 'active') === 'expelled') continue; // hueco
            if ((int)($p['user_id'] ?? 0) <= 0) continue;
            $validByTicket[$t] = $p;
        }
        $maxTickets = $maxTicket + 1; // tamaño de la recta: [0, maxTickets)

        // Usuarios válidos únicos (los huecos no cuentan).
        $seenUsers = [];
        foreach ($validByTicket as $p) {
            $seenUsers[(int)$p['user_id']] = true;
        }
        $totalUsers = count($seenUsers);

        // 0 tickets emitidos o 0 usuarios válidos (todos expulsados) → cancel
        if ($maxTickets <= 0 || $totalUsers === 0) {
            $upd = $conn->prepare("UPDATE raffles SET status = 'cancelled', bsc_block_hash = ? WHERE raffle_id = ?");
            $upd->bind_param("si", $targetBlockHash, $raffleId);
            $upd->execute();
            $upd->close();
            $conn->commit();
            return ['success' => true, 'message' => 'Cancelled (0 valid users)', 'new_status' => 'cancelled'];
        }

        // Determine burn/winner counts
        $burnCount   = max(0, (int)($raffleLocked['burn_count'] ?? 0));
        $winnersCount = max(1, (int)($raffleLocked['winners_count'] ?? 1));

        if ($burnCount >= $totalUsers) {
            $burnCount = max(0, $totalUsers - 1);
        }
        $winnersCount = min($winnersCount, max(1, $totalUsers - $burnCount));
        $totalSlots   = $burnCount + $winnersCount;

        // Deterministic, BIAS-FREE selection sobre la recta numérica.
        //
        // El slot seed da un entero de 32 bits en [0, 2^32 - 1]. Un "valor %
        // maxTickets" NO es uniforme salvo que maxTickets divida 2^32, por lo
        // que rechazamos la cola corta por encima del mayor múltiplo de
        // maxTickets que cabe en el rango (sesgo de módulo). Además rechazamos
        // los tickets que sean HUECO (expulsado) o que ya hayan ganado. Cada
        // rechazo redibuja con el sufijo _r$attempt → 100% determinista y
        // recomputable por cualquiera con (server_seed + bsc_block_hash).
        $randSpace = 4294967296; // 2^32 — primeros 8 hex del slot seed
        $maxAttempts = 100000;   // guardarraíl anti-bucle (caso patológico)
        $selected = [];
        $takenTickets = []; // tickets ya asignados a un slot
        for ($i = 0; $i < $totalSlots; $i++) {
            if (count($validByTicket) <= 0) break;

            // Mayor múltiplo de maxTickets que cabe en [0, 2^32).
            $acceptLimit = $randSpace - ($randSpace % $maxTickets);

            $attempt  = 0;
            $slotSeed = '';
            $ticket   = -1;
            while ($attempt < $maxAttempts) {
                $label    = ($attempt === 0) ? 'salt_index_' . $i : 'salt_index_' . $i . '_r' . $attempt;
                $slotSeed = hash_hmac('sha256', $label, $masterSeed);
                $decVal   = hexdec(substr($slotSeed, 0, 8));
                $attempt++;
                if ($decVal >= $acceptLimit) continue;       // sesgo de módulo
                $cand = $decVal % $maxTickets;
                if (!isset($validByTicket[$cand])) continue; // hueco / expulsado
                if (isset($takenTickets[$cand]))   continue; // ya ganó
                $ticket = $cand;
                break;
            }

            // Guardarraíl determinista: si tras maxAttempts no cayó en válido
            // (ratio de huecos extremo), elegimos sobre los tickets válidos
            // restantes ordenados, con el mismo decVal. Caso prácticamente
            // imposible en sorteos reales; mantiene la terminación garantizada.
            if ($ticket < 0) {
                $remaining = array_keys($validByTicket);
                sort($remaining);
                $decVal = hexdec(substr($slotSeed, 0, 8));
                $ticket = $remaining[$decVal % count($remaining)];
            }

            $pick       = $validByTicket[$ticket];
            $pickUserId = (int)($pick['user_id'] ?? 0);
            $takenTickets[$ticket] = true;

            // Quitar TODOS los tickets de este usuario del mapa válido (defensivo).
            foreach ($validByTicket as $tk => $pp) {
                if ((int)($pp['user_id'] ?? 0) === $pickUserId) {
                    unset($validByTicket[$tk]);
                    $takenTickets[$tk] = true;
                }
            }

            $selected[] = [
                'slot_index' => $i,
                'slot_seed'  => $slotSeed,
                'user'       => $pick
            ];
        }

        // Determine gamification options
        $mode = $raffleLocked['execution_mode'] ?? 'standard_queue';
        $forceNoGamble = ($mode === 'fast_batch');

        // standard_queue ALWAYS enters waiting_for_winner: the reveal timeline
        // (animation_start_at) and the HUD depend on that phase. Whether the
        // winners actually get to decide is settled at lazy-start in
        // raffle_status.php: without alternatives (rr_raffle_has_alternatives)
        // the original prize is auto-claimed and the raffle closes right away.
        // fast_batch has no reveal timeline, so it only enters the phase when
        // some alternative is enabled and otherwise delivers here.
        $hasOptions = $forceNoGamble ? rr_raffle_has_alternatives($raffleLocked) : true;
        $prizeConfig = json_decode((string)($raffleLocked['prize_config'] ?? '{}'), true);

        // LAZY START: decision_expires_at is left NULL here.
        // raffle_status.php will activate it when the first client
        // finishes the reveal animation and sends anim_finished=true.

        // Prepare insert statements
        $insBurn = $conn->prepare(
            "INSERT INTO raffle_winners (raffle_id, user_id, position, prize_sent, choice, gamble_result) VALUES (?, ?, ?, 1, 'burned', ?)"
        );
        $insPend = $conn->prepare(
            "INSERT INTO raffle_winners (raffle_id, user_id, position, prize_sent, choice, gamble_result) VALUES (?, ?, ?, 0, 'pending', ?)"
        );
        $insDeliveredWithOrder = $conn->prepare(
            "INSERT INTO raffle_winners (raffle_id, user_id, position, prize_sent, choice, generated_order_id, gamble_result) VALUES (?, ?, ?, 1, 'original', ?, ?)"
        );
        $insDeliveredNoOrder = $conn->prepare(
            "INSERT INTO raffle_winners (raffle_id, user_id, position, prize_sent, choice, gamble_result) VALUES (?, ?, ?, 1, 'original', ?)"
        );
        // Pool prize without immediate decision phase: the winner still has to
        // pick their concrete item, so the row is created unsent and flagged
        // awaiting_selection instead of shipping an undetermined order.
        $insAwaitingSelection = $conn->prepare(
            "INSERT INTO raffle_winners (raffle_id, user_id, position, prize_sent, choice, gamble_result, prize_state) VALUES (?, ?, ?, 0, 'original', ?, 'awaiting_selection')"
        );

        // Pre-elección de Vitrina guardada al inscribirse ({posición: option_key}).
        // Si sigue siendo válida se entrega en el acto; solo sin ella el ganador
        // queda en awaiting_selection.
        $selPrePool = $conn->prepare("SELECT pre_pool_choices FROM raffle_participants WHERE raffle_id = ? AND user_id = ? LIMIT 1");
        $applyPreselectedPool = function (int $uid, int $pos, $posPrize, int $rowId) use ($conn, $selPrePool, $cacheFile, $raffleId): bool {
            $pool = rr_position_pool($posPrize);
            if ($pool === null || !$selPrePool) return false;
            $selPrePool->bind_param("ii", $raffleId, $uid);
            $selPrePool->execute();
            $pp = $selPrePool->get_result()->fetch_assoc();
            $preOpt = rr_pool_preselected_option($pool, $pp['pre_pool_choices'] ?? null, $pos);
            if ($preOpt === null) return false;
            return rr_deliver_pool_option_safe($conn, $uid, $rowId, $preOpt, $cacheFile, $raffleId);
        };

        $winnerPos = 1;
        $burnPos   = $winnersCount + 1;

        foreach ($selected as $idx => $slot) {
            $uid = (int)($slot['user']['user_id'] ?? 0);
            if ($uid <= 0) continue;

            $isBurn = ($idx < $burnCount);
            $pos    = $isBurn ? $burnPos++ : $winnerPos++;

            $meta = json_encode([
                'master_seed'  => $masterSeed,
                'slot_index'   => (int)$slot['slot_index'],
                'slot_seed'    => (string)$slot['slot_seed'],
                'block_hash'   => $targetBlockHash,
                'block_number' => $blockTarget,
                'role'         => $isBurn ? 'burned' : 'winner',
                // Auditable record of the exact drawn ticket for this slot.
                'winning_ticket_index' => (int)($slot['user']['ticket_index'] ?? -1)
            ]);

            if ($isBurn) {
                $insBurn->bind_param("iiis", $raffleId, $uid, $pos, $meta);
                $insBurn->execute();
                continue;
            }

            if ($hasOptions) {
                $insPend->bind_param("iiis", $raffleId, $uid, $pos, $meta);
                $insPend->execute();
            } else {
                // No gamble allowed: deliver immediately (prize resolved per position)
                $posPrize = rr_prize_for_position($prizeConfig, $pos);
                if (rr_prize_is_combo($posPrize)) {
                    // Combo: ship the fixed items right away; if a pool remains,
                    // the winner still picks it through the vitrina.
                    $comboFixed = rr_deliver_combo_fixed($conn, $uid, $posPrize, $cacheFile, $raffleId);
                    $comboOrderId = $comboFixed['order_id'];
                    if (rr_position_pool($posPrize) !== null) {
                        $insAwaitingSelection->bind_param("iiis", $raffleId, $uid, $pos, $meta);
                        $insAwaitingSelection->execute();
                        $awaitRowId = (int)$conn->insert_id;
                        if ($comboOrderId !== null) {
                            $conn->query("UPDATE raffle_winners SET generated_order_id = " . (int)$comboOrderId . " WHERE id = $awaitRowId");
                        }
                        $applyPreselectedPool($uid, $pos, $posPrize, $awaitRowId);
                        continue;
                    }
                    if ($comboOrderId !== null) {
                        $insDeliveredWithOrder->bind_param("iiiis", $raffleId, $uid, $pos, $comboOrderId, $meta);
                        $insDeliveredWithOrder->execute();
                    } else {
                        $insDeliveredNoOrder->bind_param("iiis", $raffleId, $uid, $pos, $meta);
                        $insDeliveredNoOrder->execute();
                    }
                    continue;
                }
                if (rr_prize_is_pool($posPrize)) {
                    $insAwaitingSelection->bind_param("iiis", $raffleId, $uid, $pos, $meta);
                    $insAwaitingSelection->execute();
                    $applyPreselectedPool($uid, $pos, $posPrize, (int)$conn->insert_id);
                    continue;
                }
                $uInfo = $conn->query("SELECT game_username, email, whatsapp_phone_e164 FROM users WHERE id = $uid")->fetch_assoc();
                $fullUserData = array_merge($slot['user'], $uInfo ?: []);
                $orderIdRaw = deliverPrize($conn, $uid, $fullUserData, $posPrize, $cacheFile, $raffleId);
                $orderId = is_numeric($orderIdRaw) ? (int)$orderIdRaw : null;

                if ($orderId !== null) {
                    $insDeliveredWithOrder->bind_param("iiiis", $raffleId, $uid, $pos, $orderId, $meta);
                    $insDeliveredWithOrder->execute();
                } else {
                    $insDeliveredNoOrder->bind_param("iiis", $raffleId, $uid, $pos, $meta);
                    $insDeliveredNoOrder->execute();
                }
            }
        }

        // Final status
        $newState = $hasOptions ? 'waiting_for_winner' : 'completed';

        if ($newState === 'waiting_for_winner') {
            $upd = $conn->prepare("UPDATE raffles SET status = ?, bsc_block_hash = ?, animation_start_at = UTC_TIMESTAMP() WHERE raffle_id = ?");
        } else {
            $upd = $conn->prepare("UPDATE raffles SET status = ?, bsc_block_hash = ? WHERE raffle_id = ?");
        }
        $upd->bind_param("ssi", $newState, $targetBlockHash, $raffleId);
        $upd->execute();
        $upd->close();

        $insBurn->close();
        $insPend->close();
        $insDeliveredWithOrder->close();
        $insDeliveredNoOrder->close();
        $insAwaitingSelection->close();
        if ($selPrePool) $selPrePool->close();

        if ($newState === 'completed') {
            try {
                rb_decrement_on_resolve($conn, $raffleId);
            } catch (Throwable $banErr) {
                error_log('[triggerRaffleResolve] ban countdown failed: ' . $banErr->getMessage());
            }
        }
        $conn->commit();

        return [
            'success'          => true,
            'message'          => 'Raffle resolved successfully',
            'new_status'       => $newState,
            'block_target'     => $blockTarget,
            'block_hash'       => $targetBlockHash,
            'master_seed'      => $masterSeed,
            'winners_inserted' => $winnersCount,
            'burns_inserted'   => $burnCount
        ];

    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'message' => 'Exception: ' . $e->getMessage()];
    }
}

// =================================================================================
// FUNCTION 3: triggerGambleResolve
// Called from raffle_status.php during 'waiting_for_winner' when a pending gamble's
// target block has been reached. ANY client polling raffle_status.php can trigger this,
// making the system bulletproof against the active winner disconnecting.
//
// For each unresolved gambler whose target block <= currentBlock:
//   1. Fetch the block hash (once per target block).
//   2. Compute the Provably Fair HMAC outcome.
//   3. Deliver prizes (2x) if won.
//   4. Update the winner row with the result.
//   5. If all winners are done, mark the raffle as 'completed'.
//
// Returns: ['success' => bool, 'resolved_count' => int, ...]
// =================================================================================
function triggerGambleResolve($conn, $raffleId, $rpcUrl, $cacheFile, $currentBlock = null) {
    $raffleId = (int)$raffleId;
    if ($raffleId <= 0) {
        error_log("[triggerGambleResolve] ABORT: Invalid raffle ID ($raffleId)");
        return ['success' => false, 'message' => 'Invalid raffle ID'];
    }
    rb_ensure_schema($conn);

    // Find unresolved gamble_pending winners with a valid target block
    $stmtFind = $conn->prepare(
        "SELECT w.id, w.user_id, w.position, w.gamble_block_target, w.gamble_server_seed,
                w.coin_choice, w.selected_animation_mode, w.gamble_block_hash
         FROM raffle_winners w
         WHERE w.raffle_id = ?
           AND w.choice = 'gamble_pending'
           AND w.gamble_block_target IS NOT NULL
           AND w.gamble_block_target > 0
           AND (w.gamble_block_hash IS NULL OR w.gamble_block_hash = '')"
    );
    if ($stmtFind === false) {
        error_log("[triggerGambleResolve] ABORT: prepare() failed for SELECT — " . $conn->error);
        return ['success' => false, 'message' => 'SQL prepare error: ' . $conn->error];
    }
    $stmtFind->bind_param("i", $raffleId);
    $stmtFind->execute();
    $resFind = $stmtFind->get_result();

    $pendingGamblers = [];
    while ($row = $resFind->fetch_assoc()) {
        $pendingGamblers[] = $row;
    }
    $stmtFind->close();

    if (count($pendingGamblers) === 0) {
        error_log("[triggerGambleResolve] ABORT: No pending gamblers found for raffle $raffleId");
        return ['success' => false, 'message' => 'No pending gamblers to resolve'];
    }

    // Use the caller-provided current block to avoid redundant RPC calls.
    // Fall back to an RPC call only if not provided.
    if ($currentBlock === null || $currentBlock === false) {
        $currentBlock = getRpcBlockNumber($rpcUrl);
    }
    if ($currentBlock === false || $currentBlock <= 0) {
        error_log("[triggerGambleResolve] ABORT: getRpcBlockNumber failed for raffle $raffleId");
        return ['success' => false, 'message' => 'RPC error: could not fetch current block'];
    }

    // Group by target block — only resolve groups whose target is reached
    $targetGroups = [];
    foreach ($pendingGamblers as $g) {
        $tb = (int)$g['gamble_block_target'];
        if ($currentBlock >= $tb) {
            $targetGroups[$tb][] = $g;
        }
    }

    if (count($targetGroups) === 0) {
        error_log("[triggerGambleResolve] ABORT: Target block not reached. currentBlock=$currentBlock, pendingTargets=" . implode(',', array_map(function($g){ return $g['gamble_block_target']; }, $pendingGamblers)));
        return ['success' => false, 'message' => 'Target block not yet reached', 'bsc_block_current' => $currentBlock];
    }

    // Load prize config once
    $stmtPrize = $conn->prepare("SELECT prize_config FROM raffles WHERE raffle_id = ? LIMIT 1");
    $stmtPrize->bind_param("i", $raffleId);
    $stmtPrize->execute();
    $prizeRow = $stmtPrize->get_result()->fetch_assoc();
    $stmtPrize->close();
    $prizeConfig = json_decode($prizeRow['prize_config'] ?? '{}', true);
    if (!is_array($prizeConfig)) $prizeConfig = [];

    $resolved = 0;

    foreach ($targetGroups as $targetBlock => $gamblers) {
        // Get block hash once per target block
        $blockHash = getRpcBlockHash($rpcUrl, (int)$targetBlock);
        if (!$blockHash) {
            error_log("[triggerGambleResolve] WARN: getRpcBlockHash returned false for targetBlock=$targetBlock, raffle=$raffleId — will retry next poll");
            continue;
        }

        foreach ($gamblers as $gambler) {
            $conn->begin_transaction();
            try {
                // Idempotency: lock row and re-check
                $stmtLock = $conn->prepare("SELECT gamble_block_hash FROM raffle_winners WHERE id = ? FOR UPDATE");
                $stmtLock->bind_param("i", $gambler['id']);
                $stmtLock->execute();
                $lockCheck = $stmtLock->get_result()->fetch_assoc();
                $stmtLock->close();

                if (!empty($lockCheck['gamble_block_hash'])) {
                    $conn->commit();
                    $resolved++;
                    continue;
                }

                // --- Provably Fair Math (canonical, shared with raffle_gamble.php) ---
                $serverSeed = $gambler['gamble_server_seed'];
                $oc = rr_gamble_outcome($serverSeed, $blockHash, $gambler['id']);
                $outcome = $oc['outcome'];

                $selectedAnimMode = $gambler['selected_animation_mode'] ?? 'coin';
                $allowedAnimModes = ['coin', 'slot', 'roulette'];
                if (!in_array($selectedAnimMode, $allowedAnimModes, true)) {
                    $selectedAnimMode = 'coin';
                }

                if ($selectedAnimMode === 'coin') {
                    $didWin = ($outcome === ($gambler['coin_choice'] ?? null));
                } elseif ($selectedAnimMode === 'roulette') {
                    // Ruleta Rojo/Negro: heads=rojo, tails=negro; gana si acierta el color.
                    // Filas legadas sin elección: paridad clásica (heads=win).
                    $rouletteChoice = $gambler['coin_choice'] ?? null;
                    $rouletteColor = ($outcome === 'heads') ? 'red' : 'black';
                    $didWin = in_array($rouletteChoice, ['red', 'black'], true)
                        ? ($rouletteColor === $rouletteChoice)
                        : ($outcome === 'heads');
                } else {
                    $didWin = ($outcome === 'heads');
                }

                $finalResult = $didWin ? 'win' : 'loss';
                $finalChoice = $didWin ? 'double_won' : 'double_lost';

                // Deliver win (Fase 3): original prize + equivalente en Bananos.
                $orderIdSql = null;
                $prizeSent = $didWin ? 1 : 0;
                $doubleBonusCoins = 0.0;
                if ($didWin && !empty($prizeConfig)) {
                    $userId = (int)$gambler['user_id'];
                    $posPrize = rr_prize_for_position($prizeConfig, (int)($gambler['position'] ?? 1));
                    $stmtU = $conn->prepare("SELECT game_username, email, whatsapp_phone_e164 FROM users WHERE id = ? LIMIT 1");
                    $stmtU->bind_param("i", $userId);
                    $stmtU->execute();
                    $uData = $stmtU->get_result()->fetch_assoc();
                    $stmtU->close();
                    if ($uData) {
                        $payout = rr_deliver_double_win($conn, $userId, $uData, $posPrize, $cacheFile, $raffleId);
                        $orderIdSql = $payout['order_id'];
                        $doubleBonusCoins = $payout['bonus_coins'];
                    }
                }

                $finalPayload = json_encode([
                    'wager' => 'double',
                    'animation_pref' => $selectedAnimMode,
                    'prediction_side' => $gambler['coin_choice'],
                    'outcome_side' => $outcome,
                    'result' => $finalResult,
                    'status' => 'finished',
                    'target_block' => (int)$targetBlock,
                    'block_hash' => $blockHash,
                    'double_bonus_coins' => $doubleBonusCoins
                ]);

                // Update winner row
                if ($orderIdSql !== null) {
                    $stmtRes = $conn->prepare(
                        "UPDATE raffle_winners SET gamble_block_hash = ?, gamble_result = ?, choice = ?, generated_order_id = ?, prize_sent = ?, gamble_resolved_at = UTC_TIMESTAMP() WHERE id = ?"
                    );
                    $stmtRes->bind_param("sssiii", $blockHash, $finalPayload, $finalChoice, $orderIdSql, $prizeSent, $gambler['id']);
                } else {
                    $stmtRes = $conn->prepare(
                        "UPDATE raffle_winners SET gamble_block_hash = ?, gamble_result = ?, choice = ?, generated_order_id = NULL, prize_sent = ?, gamble_resolved_at = UTC_TIMESTAMP() WHERE id = ?"
                    );
                    $stmtRes->bind_param("sssii", $blockHash, $finalPayload, $finalChoice, $prizeSent, $gambler['id']);
                }
                $stmtRes->execute();
                $stmtRes->close();

                $conn->commit();
                $resolved++;
                error_log("[triggerGambleResolve] OK: Resolved winner ID {$gambler['id']} → $finalChoice (outcome=$outcome, block=$targetBlock)");

            } catch (Throwable $e) {
                $conn->rollback();
                error_log("[triggerGambleResolve] ERROR: winner ID {$gambler['id']}: " . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            }
        }
    }

    // If any gambler was resolved, check if the raffle should be completed
    if ($resolved > 0) {
        $stmtDone = $conn->prepare(
            "SELECT COUNT(*) AS c FROM raffle_winners WHERE raffle_id = ? AND (choice = 'pending' OR choice = 'gamble_pending' OR choice = '' OR choice IS NULL)"
        );
        $stmtDone->bind_param("i", $raffleId);
        $stmtDone->execute();
        $doneCount = (int)($stmtDone->get_result()->fetch_assoc()['c'] ?? 0);
        $stmtDone->close();

        if ($doneCount === 0) {
            $stmtClose = $conn->prepare("UPDATE raffles SET status = 'completed' WHERE raffle_id = ?");
            $stmtClose->bind_param("i", $raffleId);
            $stmtClose->execute();
            $stmtClose->close();
            try {
                rb_decrement_on_resolve($conn, $raffleId);
            } catch (Throwable $banErr) {
                error_log('[triggerGambleResolve] ban countdown failed: ' . $banErr->getMessage());
            }
            error_log("[triggerGambleResolve] Raffle $raffleId → completed (all winners resolved)");
        }
    }

    error_log("[triggerGambleResolve] DONE: raffle=$raffleId, resolved=$resolved/" . count($pendingGamblers));
    return ['success' => ($resolved > 0), 'message' => "Resolved $resolved gambler(s)", 'resolved_count' => $resolved];
}

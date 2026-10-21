<?php
// api/raffle_join.php
// VERSIÓN V2 (BLINDADA): ENTRY FEE + BOTE DINÁMICO + MUERTE SÚBITA
// Autor: Tu Asistente IA

// Evitar caché
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header('Content-Type: application/json');

date_default_timezone_set('America/Lima'); 

// 1. GESTIÓN DE SESIÓN Y SEGURIDAD
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

// --- CANDADO DE SEGURIDAD (ANTES DE TODO) ---
// Si no hay usuario en sesión, matamos el proceso inmediatamente.
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    http_response_code(401); // Error 401 = No Autorizado
    _outputJson([
        'status' => 'error', 
        'message' => 'Debes iniciar sesión para participar.',
        'require_login' => true // Bandera para que el frontend sepa que debe abrir login
    ]);
    exit;
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/raffle_earlybird.php';
require_once __DIR__ . '/raffle_review.php';
require_once __DIR__ . '/raffle_welcome.php';
require_once __DIR__ . '/raffle_private.php';
require_once __DIR__ . '/raffle_bans.php';

$userId = (int)$_SESSION['user_id'];
$raffleId = isset($_POST['raffle_id']) ? (int)$_POST['raffle_id'] : 0;

// Huella de dispositivo (hash ligero generado en el front). Sanitizado.
$fingerprint = isset($_POST['fp']) ? preg_replace('/[^a-f0-9]/i', '', substr((string)$_POST['fp'], 0, 64)) : '';
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if ($raffleId <= 0) {
    http_response_code(400);
    _outputJson(['status' => 'error', 'message' => 'ID de sorteo inválido.']);
    exit;
}

$signals = rb_client_signals();
$fingerprint = $signals['fp'];
$clientIp = $signals['ip'] !== '' ? $signals['ip'] : '0.0.0.0';
try {
    rb_ensure_schema($conn);
    rb_track_device($conn, $userId, $clientIp, $fingerprint, $signals['did']);
    $activeBan = rb_find_active_ban($conn, $userId, $clientIp, $fingerprint, $signals['did']);
    if ($activeBan) {
        if (($activeBan['matched_by'] ?? 'user') !== 'user') {
            rb_log_evasion($conn, $activeBan, $userId, $clientIp, $fingerprint, $signals['did'], $raffleId, 'join');
        }
        http_response_code(403);
        _outputJson([
            'status' => 'error',
            'code' => 'raffle_banned',
            'message' => rb_public_message($activeBan),
            'ban' => rb_ban_payload($conn, $activeBan),
        ]);
        exit;
    }
} catch (Throwable $banError) {
    error_log('[raffle_join] ban check failed: ' . $banError->getMessage());
    http_response_code(503);
    _outputJson(['status' => 'error', 'message' => 'No se pudo validar tu acceso al sorteo. Intenta nuevamente.']);
    exit;
}

try {
    // ---------------------------------------------------------
    // FASE 1: VALIDACIONES PREVIAS (Lectura Rápida)
    // ---------------------------------------------------------
    
    // Verificamos si ya participa antes de iniciar transacciones pesadas
    $checkStmt = $conn->prepare("SELECT id FROM raffle_participants WHERE raffle_id = ? AND user_id = ?");
    $checkStmt->bind_param("ii", $raffleId, $userId);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows > 0) {
        throw new Exception("Ya estás inscrito en este sorteo.");
    }

    // Traemos datos básicos del usuario para validar requisitos
    $userStmt = $conn->prepare("SELECT is_verified FROM users WHERE id = ?");
    $userStmt->bind_param("i", $userId);
    $userStmt->execute();
    $userData = $userStmt->get_result()->fetch_assoc();
    if (!$userData) throw new Exception("Usuario no encontrado.");

    // Traemos configuración del sorteo (Lectura sin bloqueo aún)
    $stmt = $conn->prepare("SELECT * FROM raffles WHERE raffle_id = ?");
    $stmt->bind_param("i", $raffleId);
    $stmt->execute();
    $raffle = $stmt->get_result()->fetch_assoc();

    if (!$raffle) throw new Exception("El sorteo no existe.");

    // Validar Estado y Tiempo
    $now = date('Y-m-d H:i:s');
    if ($raffle['status'] !== 'open') throw new Exception("El sorteo no está abierto.");
    if ($now > $raffle['end_time']) throw new Exception("El tiempo de inscripción ha terminado.");

    // SORTEO PRIVADO / EXCLUSIVO: solo invitados. Se evalúa antes de requisitos,
    // aforo y cobro; la invitación no exime de los requisitos (eso es la whitelist).
    rp_ensure_schema($conn);
    if (!rp_can_access($conn, $raffle, $userId)) {
        http_response_code(403);
        _outputJson(['status' => 'error', 'code' => RP_DENIED_CODE, 'message' => RP_DENIED_MESSAGE]);
        exit;
    }

    // Validar Requisitos (Verificado, Recency, Whale)
    $reqs = json_decode($raffle['requirements'] ?? '{}', true);

    // LISTA BLANCA MANUAL (rescate del admin para ESTE sorteo).
    // Si el usuario está en la whitelist de este raffle, se IGNORAN todas las
    // reglas de elegibilidad y anti-fraude (verificado, compras recientes, gasto,
    // IP, huella). NO afecta el aforo ni el costo de entrada.
    $isWhitelisted = false;
    $conn->query(
        "CREATE TABLE IF NOT EXISTS raffle_whitelist (
            id INT AUTO_INCREMENT PRIMARY KEY,
            raffle_id INT NOT NULL,
            user_id INT NOT NULL,
            reason VARCHAR(255) NOT NULL DEFAULT '',
            added_by INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_raffle_user (raffle_id, user_id),
            KEY idx_raffle (raffle_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $wlStmt = $conn->prepare("SELECT 1 FROM raffle_whitelist WHERE raffle_id = ? AND user_id = ? LIMIT 1");
    if ($wlStmt) {
        $wlStmt->bind_param("ii", $raffleId, $userId);
        $wlStmt->execute();
        $isWhitelisted = $wlStmt->get_result()->num_rows > 0;
    }

    // PASE VERDE PARA VERIFICADOS: un usuario Verificado es un cliente de
    // confianza (historial de compras/gasto). Igual que la whitelist, se salta
    // por completo los filtros de "compras recientes" (min_orders) y "gasto
    // histórico" (min_spent), porque muchos compran manualmente por chat y el
    // sistema no detecta esas compras web. El pase verde SOLO exime de los
    // requisitos: NO exime de pagar el entry_cost (se cobra en la FASE 2) ni
    // del aforo, y el anti-fraude ya los exime más abajo.
    $isVerified = ((int)($userData['is_verified'] ?? 0) === 1);
    $bypassRequirements = $isWhitelisted || $isVerified;

    if (!$isWhitelisted) {
        // 1. Verificado (restricción del sorteo: si es "solo verificados",
        //    los NO verificados quedan fuera). No es un filtro de compras.
        if (!empty($reqs['verified_only']) && $reqs['verified_only'] == true) {
            if ($userData['is_verified'] != 1) throw new Exception("Sorteo exclusivo para usuarios verificados.");
        }
    }

    // FLUJO "BAJO REVISIÓN": si el usuario no cumple las compras detectadas por
    // el sistema, en vez de bloquearlo le ofrecemos declarar que compró por chat.
    // Si declara (botón del modal amigable), entra marcado 'under_review' para que
    // el admin lo audite en vivo desde la Sala de Mando. Sin ban automático.
    $declared = isset($_POST['declared']) && (((string)$_POST['declared']) === '1' || ((string)$_POST['declared']) === 'true');
    $reviewStatus = 'active';

    // TICKET DE BIENVENIDA: el front solo envía use_welcome_ticket=1 después de
    // que el usuario confirma el modal. Sin ese flag NUNCA se consume.
    $useWelcomeTicket = isset($_POST['use_welcome_ticket'])
        && (((string)$_POST['use_welcome_ticket']) === '1' || ((string)$_POST['use_welcome_ticket']) === 'true');
    $welcomeTicketPending = false;
    $welcomeTicketInfo = null;

    // Aseguramos el esquema de revisión (incluye raffle_manual_purchases) antes
    // de consultarlo en la elegibilidad.
    rr_ensure_schema($conn);

    if (!$bypassRequirements) {
        $failReason  = '';
        $minDateInfo = '';
        // Regla de fecha vigente del sorteo: las compras manuales solo cuentan si
        // su purchase_date >= minDate. Así un cambio de regla las re-filtra solo.
        $minDateRule = !empty($reqs['min_orders_date']) ? $reqs['min_orders_date'] : null;

        // 2. Compras Recientes = pedidos web + COMPRAS MANUALES (date-aware).
        //    Se aplica si hay cantidad mínima O fecha (la fecha es opcional: sin
        //    ella cuentan todas las compras completadas). Los pedidos internos
        //    de premios (WIN-*) NO cuentan como compra real.
        $minCountRule = isset($reqs['min_orders_count']) ? (int)$reqs['min_orders_count'] : 0;
        if ($minCountRule > 0 || $minDateRule !== null) {
            $minDate  = $minDateRule;
            $minCount = $minCountRule > 0 ? $minCountRule : 1;
            $orderSql = "SELECT COUNT(*) as total FROM pedidos WHERE user_id = ? AND estado = 'completado'" . wt_real_orders_sql();
            if ($minDate !== null) {
                $orderSql .= " AND fecha_creacion >= ?";
                $oStmt = $conn->prepare($orderSql);
                $oStmt->bind_param("is", $userId, $minDate);
            } else {
                $oStmt = $conn->prepare($orderSql);
                $oStmt->bind_param("i", $userId);
            }
            $oStmt->execute();
            $webOrders = (int)($oStmt->get_result()->fetch_assoc()['total'] ?? 0);
            $manual    = rr_manual_purchase_stats($conn, $userId, $minDate);
            $effOrders = $webOrders + $manual['count'];
            if ($effOrders < $minCount) {
                $failReason  = $minDate !== null
                    ? "Requisito: Mínimo $minCount compra(s) desde $minDate."
                    : "Requisito: Mínimo $minCount compra(s) completada(s).";
                $minDateInfo = (string)$minDate;
            }
        }

        // 3. Gasto Histórico = gasto web + monto de COMPRAS MANUALES (date-aware).
        if ($failReason === '' && !empty($reqs['min_spent_total']) && $reqs['min_spent_total'] > 0) {
            $minSpent = (float)$reqs['min_spent_total'];
            $sStmt = $conn->prepare("SELECT SUM(total_pagar) as total FROM pedidos WHERE user_id = ? AND estado = 'completado'" . wt_real_orders_sql());
            $sStmt->bind_param("i", $userId);
            $sStmt->execute();
            $webSpent = (float)($sStmt->get_result()->fetch_assoc()['total'] ?? 0);
            $manualSp = rr_manual_purchase_stats($conn, $userId, $minDateRule);
            $effSpent = $webSpent + $manualSp['sum'];
            if ($effSpent < $minSpent) {
                $failReason = "Requisito: Gasto histórico > S/ $minSpent.";
            }
        }

        if ($failReason !== '') {
            // TICKET DE BIENVENIDA: salta SOLO los requisitos de compras (no el
            // verified_only ya evaluado, ni el anti-fraude/aforo/cuota de abajo).
            // Solo existe si el usuario califica económicamente, es su PRIMER
            // sorteo (sin participaciones previas vigentes) y nunca lo usó.
            $wtStatus = wt_status($conn, $userId, $raffleId);
            if ($useWelcomeTicket) {
                if (!$wtStatus['available']) {
                    throw new Exception(wt_unavailable_message($wtStatus));
                }
                $welcomeTicketPending = true;
                $welcomeTicketInfo = $wtStatus;
            } elseif ($wtStatus['available'] && !$declared) {
                // Pedimos confirmación explícita antes de gastar el ticket.
                _outputJson([
                    'status'                            => 'needs_welcome_ticket_confirmation',
                    'needs_welcome_ticket_confirmation' => true,
                    'message'                           => $failReason,
                    'welcome_ticket'                    => [
                        'value'     => $wtStatus['value'],
                        'threshold' => $wtStatus['threshold'],
                    ],
                ]);
                exit;
            } elseif (!$declared) {
                // Sin compras detectadas: ofrecemos el modal de declaración.
                _outputJson([
                    'status'            => 'needs_declaration',
                    'needs_declaration' => true,
                    'message'           => $failReason,
                    'min_date'          => $minDateInfo,
                ]);
                exit;
            } else {
                // Declaró → entra BAJO REVISIÓN (política permisiva).
                $reviewStatus = 'under_review';
            }
        }
    }

    // Aseguramos las tablas auxiliares (early-bird + señales anti-fraude).
    eb_ensure_tables($conn);
    // Esquema de revisión (columna review_status + tablas de strikes/snapshot).
    rr_ensure_schema($conn);
    // Esquema del Ticket de Bienvenida (DDL fuera de la transacción).
    wt_ensure_schema($conn);

    // Config early-bird / anti-fraude (viven dentro de requirements JSON).
    $ebConfig = eb_parse_config($reqs);
    $afConfig = af_parse_config($reqs);

    // 4. ANTI-FRAUDE (IP + Huella). EXCEPCIÓN: verificados y whitelisted nunca se bloquean.
    if (!$isVerified && !$isWhitelisted) {
        $afError = af_check_entry($conn, $afConfig, $raffleId, $userId, $clientIp, $fingerprint);
        if ($afError !== null) {
            throw new Exception($afError);
        }
    }

    // Liquidación perezosa del pozo por tiempo (best-effort, fuera de transacción).
    if ($ebConfig && $ebConfig['mode'] === 'time') {
        eb_settle_time_pool($conn, $raffleId, $ebConfig, $raffle['start_time'] ?? null);
    }

    // ---------------------------------------------------------
    // FASE 2: TRANSACCIÓN CRÍTICA (Escritura y Dinero)
    // ---------------------------------------------------------
    $conn->begin_transaction();

    try {
        // A. BLOQUEO INTELIGENTE: Bloqueamos la fila del sorteo para evitar concurrencia en cupos y bote
        // Esto previene que entren 51 personas si el limite es 50
        $lockStmt = $conn->prepare("SELECT status, end_time, entry_cost, is_pot_enabled, pot_allocation_percent, max_participants, winners_count FROM raffles WHERE raffle_id = ? FOR UPDATE");
        $lockStmt->bind_param("i", $raffleId);
        $lockStmt->execute();
        $rLock = $lockStmt->get_result()->fetch_assoc();

        // Re-validaciones post-bloqueo (Por si cambió milisegundos antes)
        if ($rLock['status'] !== 'open') throw new Exception("El sorteo acaba de cerrar.");

        $dupStmt = $conn->prepare("SELECT id FROM raffle_participants WHERE raffle_id = ? AND user_id = ? FOR UPDATE");
        $dupStmt->bind_param("ii", $raffleId, $userId);
        $dupStmt->execute();
        if ($dupStmt->get_result()->num_rows > 0) throw new Exception("Ya estás inscrito en este sorteo.");

        // A2. TICKET DE BIENVENIDA: re-validación bajo bloqueo (doble clic /
        // pestañas paralelas / cambio de umbral entre el modal y la confirmación).
        if ($welcomeTicketPending) {
            if (wt_used_row($conn, $userId, true) !== null) {
                throw new Exception("Tu Ticket de Bienvenida ya fue utilizado en otro sorteo.");
            }
            $wtCheck = wt_qualifying_value($conn, $userId);
            if (!$wtCheck['granted'] && wt_has_prior_participation($conn, $userId, $raffleId)) {
                throw new Exception("Tu Ticket de Bienvenida es solo para tu primer sorteo.");
            }
            if (!$wtCheck['qualifies']) {
                throw new Exception("Tu Ticket de Bienvenida ya no está disponible.");
            }
            $welcomeTicketInfo = $wtCheck;
        }
        
        // B. VALIDAR AFORO (SUDDEN DEATH) — los huecos (expulsados) no ocupan cupo.
        if ($rLock['max_participants'] > 0) {
            $countSql = "SELECT COUNT(*) as total FROM raffle_participants WHERE raffle_id = ? AND review_status <> 'expelled'";
            $cStmt = $conn->prepare($countSql);
            $cStmt->bind_param("i", $raffleId);
            $cStmt->execute();
            $currentParts = $cStmt->get_result()->fetch_assoc()['total'];
            
            if ($currentParts >= $rLock['max_participants']) {
                throw new Exception("¡Sorteo LLENO! Se alcanzaron los " . $rLock['max_participants'] . " cupos.");
            }
        }

        // C. COBRO DE ENTRADA (ENTRY FEE)
        $cost = (float)$rLock['entry_cost'];
        $moneyPaid = 0.00;
        
        if ($cost > 0) {
            // Verificar saldo del usuario (FOR UPDATE para bloquear su saldo)
            $balStmt = $conn->prepare("SELECT balance FROM coins WHERE user_id = ? FOR UPDATE");
            $balStmt->bind_param("i", $userId);
            $balStmt->execute();
            $resBal = $balStmt->get_result()->fetch_assoc();
            $userBalance = $resBal ? (float)$resBal['balance'] : 0.00;

            if ($userBalance < $cost) {
                throw new Exception("Saldo insuficiente. Necesitas " . number_format($cost, 2) . " Bananos.");
            }

            // 1. Descontar Saldo
            $updBal = $conn->prepare("UPDATE coins SET balance = balance - ? WHERE user_id = ?");
            $updBal->bind_param("di", $cost, $userId);
            if (!$updBal->execute()) throw new Exception("Error al procesar el pago.");

// 2. Historial de Transacción
            $histSql = "INSERT INTO coin_transactions (user_id, amount, reason, reference_type, reference_id, created_at) VALUES (?, ?, 'raffle_entry', 'raffle_id', ?, UTC_TIMESTAMP())";
            $histStmt = $conn->prepare($histSql);
            $negCost = -$cost; // Guardamos en negativo
            $refIdStr = (string)$raffleId;
            
            // CORREGIDO: "ids" (3 letras) para 3 variables
            $histStmt->bind_param("ids", $userId, $negCost, $refIdStr);
            $histStmt->execute();

            // 3. Aumentar Bote (Si aplica)
            if ($rLock['is_pot_enabled'] == 1) {
                $allocPercent = (int)$rLock['pot_allocation_percent'];
                $potAdd = $cost * ($allocPercent / 100);
                if ($potAdd > 0) {
                    $potStmt = $conn->prepare("UPDATE raffles SET current_pot = current_pot + ? WHERE raffle_id = ?");
                    $potStmt->bind_param("di", $potAdd, $raffleId);
                    $potStmt->execute();
                }
            }
            
            $moneyPaid = $cost;
        }

// D. ASIGNAR TICKET (Lógica de secuencia BLINDADA)
        // Usamos FOR UPDATE para que nadie más lea el índice hasta que nosotros guardemos el nuestro.
        $idxSql = "SELECT MAX(ticket_index) as max_idx FROM raffle_participants WHERE raffle_id = ? FOR UPDATE";
        $idxStmt = $conn->prepare($idxSql);
        $idxStmt->bind_param("i", $raffleId);
        $idxStmt->execute();
        $rowIdx = $idxStmt->get_result()->fetch_assoc();
        $nextTicket = ($rowIdx['max_idx'] === null) ? 0 : $rowIdx['max_idx'] + 1;

        // E. INSERTAR PARTICIPANTE
        $ip = $clientIp;
        // 'declared' = 1 si entró declarando compra por chat (Bajo Revisión).
        // Marca persistente para la lista de Revisión Diferida del admin.
        $declaredFlag = ($reviewStatus === 'under_review') ? 1 : 0;
        $welcomeFlag = $welcomeTicketPending ? 1 : 0;
        $insSql = "INSERT INTO raffle_participants (raffle_id, user_id, ticket_index, entry_fee_paid, joined_at, ip_address, review_status, declared, used_welcome_ticket) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?, ?)";
        
        $insStmt = $conn->prepare($insSql);
        $insStmt->bind_param("iiidssii", $raffleId, $userId, $nextTicket, $moneyPaid, $ip, $reviewStatus, $declaredFlag, $welcomeFlag);
        
        if (!$insStmt->execute()) {
            throw new Exception("Error crítico al registrar inscripción.");
        }

        // E2. CONSUMIR TICKET DE BIENVENIDA (misma transacción: si algo falla
        // después, el rollback devuelve el ticket; la PK de user_id impide un
        // segundo consumo concurrente).
        if ($welcomeTicketPending) {
            wt_consume(
                $conn, $userId, $raffleId, (int)$nextTicket,
                (float)($welcomeTicketInfo['value'] ?? 0),
                (float)($welcomeTicketInfo['threshold'] ?? 0),
                (string)($welcomeTicketInfo['mode'] ?? 'max')
            );
        }

        // F. SEÑAL ANTI-FRAUDE (IP + Huella) para futuras validaciones.
        af_record_signal($conn, $raffleId, $userId, $ip, $fingerprint);

        // F2. FAST-FORWARD AL LLENARSE EL CUPO: si el aforo se completó
        // (inscritos >= max_participants), adelantamos el cierre a 60s para no
        // hacer esperar de más a la gente. Solo recorta si aún falta MÁS de 60s
        // (si ya falta menos, se respeta el tiempo normal, no lo aumentamos).
        if ((int)$rLock['max_participants'] > 0) {
            $ffStmt = $conn->prepare(
                "SELECT COUNT(*) AS total FROM raffle_participants
                 WHERE raffle_id = ? AND review_status <> 'expelled'"
            );
            $ffStmt->bind_param("i", $raffleId);
            $ffStmt->execute();
            $ffCount = (int)$ffStmt->get_result()->fetch_assoc()['total'];
            if ($ffCount >= (int)$rLock['max_participants']) {
                // Recorta a now+60s únicamente cuando todavía falta más de 60s.
                $conn->query(
                    "UPDATE raffles
                     SET end_time = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 SECOND)
                     WHERE raffle_id = " . (int)$raffleId . "
                     AND status = 'open'
                     AND end_time > DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 SECOND)"
                );
            }
        }

        // G. RECOMPENSA EARLY-BIRD (Bananos por rapidez). Best-effort: si algo
        // falla aquí, no debe tumbar la inscripción ya válida.
        $earlyBird = null;
        if ($ebConfig) {
            try {
                $earlyBird = eb_award_on_join($conn, $ebConfig, $raffleId, $userId, $nextTicket, $raffle['start_time'] ?? null);
            } catch (Throwable $ebErr) {
                error_log('early-bird award error: ' . $ebErr->getMessage());
                $earlyBird = null;
            }
        }

        $conn->commit();

        $resp = [
            'status' => 'success', 
            'message' => ($cost > 0) ? "¡Pago aceptado! Ticket #".$nextTicket : "¡Inscrito gratis! Ticket #".$nextTicket,
            'ticket' => $nextTicket,
            'cost' => $moneyPaid
        ];
        if ($earlyBird !== null) {
            $resp['early_bird'] = $earlyBird;
        }
        if ($welcomeTicketPending) {
            $resp['welcome_ticket_used'] = true;
            $resp['message'] = "¡Inscrito con tu Ticket de Bienvenida! Ticket #" . $nextTicket;
        }
        _outputJson($resp);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    http_response_code(400);
    _outputJson(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
<?php
// /public_html/cuenta/api_cuenta.php

// 1. Configuración de errores (Siempre primero)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// 2. SEGURIDAD DE SESIÓN Y ANTI-CACHÉ (Reemplaza a session_start)
// Sube un nivel (..) para buscar la carpeta api
require_once __DIR__ . '/../api/session_security.php';
require_once __DIR__ . '/../api/profanity_check.php';
require_once __DIR__ . '/../core/AvatarCatalog.php';

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

$action = $_REQUEST['action'] ?? 'get_dashboard';

// --- SUB-ACTION OBFUSCATION (Phase 2) ---
// Maps short codes → real sub-action names.
// Both raw names AND codes are accepted (backward compatible).
$sub_obfuscation_map = [
    's1' => 'get_session',
    'd2' => 'get_dashboard',
    'o3' => 'get_order_details',
    'u4' => 'update_profile',
    'x5' => 'logout',
    'n6' => 'mark_notifications_read',
];
if (isset($sub_obfuscation_map[$action])) {
    $action = $sub_obfuscation_map[$action];
}

if ($action === 'logout') {
    $path_to_db_logout = __DIR__ . '/../wabo/core/db_connect.php';
    if (file_exists($path_to_db_logout)) {
        require_once $path_to_db_logout;
    } else {
        $path_to_db_logout_fallback = __DIR__ . '/../api/db_connect.php';
        if (file_exists($path_to_db_logout_fallback)) {
            require_once $path_to_db_logout_fallback;
        }
    }

    require_once __DIR__ . '/../api/remember_me.php';
    if (isset($conn) && $conn instanceof mysqli) {
        banano_remember_forget_token($conn);
    } else {
        banano_remember_forget_token(null);
    }

    session_unset();
    session_destroy();
    header('Location: /login/');
    exit;
}

// 3. Conexión a Base de Datos
$path_to_db = __DIR__ . '/../wabo/core/db_connect.php';
if (!file_exists($path_to_db)) { 
    // Si falla la DB, devolvemos error 500 y JSON válido
    http_response_code(500); 
    header('Content-Type: application/json; charset=utf-8');
    _outputJson(['status'=>'error','message'=>'DB config error']); exit; 
}
require_once $path_to_db;

// 4. Cabeceras de respuesta JSON
header('Content-Type: application/json; charset=utf-8');

// 5. Verificación de Usuario
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in_client']) || $_SESSION['logged_in_client'] !== true) {
    http_response_code(401); 
    _outputJson(['status'=>'error','message'=>'No autorizado']); 
    exit;
}
$user_id = (int)$_SESSION['user_id'];

if (!banano_client_session_is_valid($conn, $user_id)) {
    http_response_code(401);
    _outputJson(['status'=>'error','message'=>'Sesión expirada']);
    exit;
}

function getSettings($conn) {
    $s=[]; $r=$conn->query("SELECT key_name, value FROM site_settings");
    while($row=$r->fetch_assoc()) $s[$row['key_name']]=$row['value'];
    return $s;
}

function calcCooldown($dateStr, $days) {
    if(!$dateStr) return ['can_change'=>true, 'next'=>'Ahora', 'remaining'=>0];
    $next = strtotime($dateStr) + ($days*86400);
    $now = time();
    return $now>=$next ? ['can_change'=>true, 'next'=>'Ahora'] : ['can_change'=>false, 'next'=>date('d/m/Y', $next)];
}

function banano_is_placeholder_username($val) {
    if ($val === null) return false;
    $s = trim((string)$val);
    if ($s === '') return false;
    return preg_match('/^Usuario_[a-f0-9]{4,}$/i', $s) === 1;
}

function banano_safe_column_name($name) {
    $s = (string)$name;
    return preg_match('/^[a-zA-Z0-9_]+$/', $s) ? $s : '';
}

function banano_column_exists($conn, $table, $column) {
    $t = banano_safe_column_name($table);
    $c = banano_safe_column_name($column);
    if (!$t || !$c) return false;

    $res = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    if (!$res) return false;
    return $res->num_rows > 0;
}

function banano_ensure_country_schema($conn) {
    if (!banano_column_exists($conn, 'users', 'country_iso2')) {
        $ok = $conn->query("ALTER TABLE users ADD country_iso2 VARCHAR(5) NULL");
        if (!$ok) {
            if ((int)($conn->errno ?? 0) !== 1060) {
                throw new Exception('No se pudo habilitar el campo País.');
            }
        }
    }

    if (!banano_column_exists($conn, 'users', 'country_updated_at')) {
        $ok = $conn->query("ALTER TABLE users ADD country_updated_at DATETIME NULL");
        if (!$ok) {
            if ((int)($conn->errno ?? 0) !== 1060) {
                throw new Exception('No se pudo habilitar el campo País.');
            }
        }
    }
}

try {
    if ($action === 'get_session') {
        $stmt = $conn->prepare("SELECT id, public_code, email, game_username, public_display_name, avatar_id, is_verified FROM users WHERE id=? LIMIT 1");
        if (!$stmt) {
            throw new Exception('Error interno');
        }
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$u) {
            throw new Exception('Usuario no encontrado');
        }

        $avatar = AvatarCatalog::resolveUser($conn, $user_id, $u['avatar_id'] ?? null);

        _outputJson([
            'status' => 'success',
            'user' => [
                'id' => (int)$u['id'],
                'public_code' => $u['public_code'] ?? null,
                'email' => $u['email'] ?? null,
                'game_username' => $u['game_username'] ?? null,
                'public_display_name' => $u['public_display_name'] ?? null,
                'avatar_id' => $avatar ? $avatar['id'] : null,
                'avatar_filename' => $avatar ? $avatar['filename'] : null,
                'avatar_url' => $avatar ? $avatar['url'] : null,
                'is_verified' => $u['is_verified'] ?? 0
            ],
            'avatars_list' => []
        ]);

    } elseif ($action === 'get_dashboard') {
        $st = getSettings($conn);
        $cd_days = (int)($st['cooldown_days_general'] ?? 30);

        $ords = [];
        $notifs = [];
        $avail_avatars = [];

        // Datos Usuario
        $stmt = $conn->prepare("SELECT u.*, COALESCE(c.balance, 0.00) as coins FROM users u LEFT JOIN coins c ON u.id=c.user_id WHERE u.id=?");
        $stmt->bind_param('i', $user_id); $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();

        if (!$u) {
            throw new Exception('Usuario no encontrado');
        }

        if (banano_is_placeholder_username($u['game_username'] ?? null)) {
            $u['game_username'] = null;
        }
        if (banano_is_placeholder_username($u['public_display_name'] ?? null)) {
            $u['public_display_name'] = null;
        }

        // Calcular Cooldowns
        $u['edit_rules'] = [
            'game_username' => calcCooldown($u['game_username_updated_at'], $cd_days),
            'public_name'   => calcCooldown($u['public_display_name_updated_at'], $cd_days),
            'whatsapp'      => calcCooldown($u['phone_updated_at'], $cd_days),
            'country_iso2'  => calcCooldown($u['country_updated_at'] ?? null, $cd_days)
        ];
        
        // Bloqueos Admin
        if($u['game_username_locked']) $u['edit_rules']['game_username']['can_change']=false;
        if($u['public_name_locked'])   $u['edit_rules']['public_name']['can_change']=false;
        if($u['whatsapp_locked'])      $u['edit_rules']['whatsapp']['can_change']=false;

        // --- CALCULO DE PROGRESO DE VERIFICACIÓN ---
        $verif_data = ['is_verified' => (bool)$u['is_verified'], 'percent' => 100, 'msg' => '¡Verificado!'];
        
        if (!$u['is_verified']) {
            $min_ord = (int)($st['min_completed_orders_for_verification'] ?? 3);
            $min_spent = (float)($st['min_total_spent_for_verification'] ?? 50);
            
            // Contar pedidos SELF completados
            $sql_prog = "SELECT COUNT(*) as c, SUM(total_pagar) as s FROM pedidos WHERE user_id=? AND estado='completado' AND recipient_mode='self'";
            $stmt_p = $conn->prepare($sql_prog); $stmt_p->bind_param('i', $user_id); $stmt_p->execute();
            $prog = $stmt_p->get_result()->fetch_assoc();
            
            $curr_ord = (int)$prog['c'];
            $curr_spent = (float)$prog['s'];
            
            $miss_ord = max(0, $min_ord - $curr_ord);
            $miss_spent = max(0, $min_spent - $curr_spent);
            
            // Calcular porcentaje simple (basado en pedidos para simplificar UI)
            $per_ord = min(100, ($curr_ord / $min_ord) * 100);
            
            if ($miss_ord > 0) {
                if ($miss_ord === 1) {
                    $verif_data['msg'] = "Te falta 1 pedido a tu cuenta para verificarte.";
                } else {
                    $verif_data['msg'] = "Te faltan $miss_ord pedidos a tu cuenta para verificarte.";
                }
                $verif_data['percent'] = $per_ord;
            } elseif ($miss_spent > 0) {
                $verif_data['msg'] = "Te faltan S/ " . number_format($miss_spent, 2) . " en compras para verificarte.";
                $verif_data['percent'] = 90;
            } else {
                $verif_data['msg'] = "¡Casi listo! Tu verificación está pendiente de procesar.";
                $verif_data['percent'] = 99;
            }
        }
        $u['verification_progress'] = $verif_data;

        $avatar = AvatarCatalog::resolveUser($conn, $user_id, $u['avatar_id'] ?? null);

        $user_payload = [
            'game_username' => $u['game_username'] ?? null,
            'public_code' => $u['public_code'] ?? null,
            'email' => $u['email'] ?? null,
            'coins' => $u['coins'] ?? 0,
            'is_verified' => $u['is_verified'] ?? 0,
            'verification_progress' => $u['verification_progress'] ?? null,
            'edit_rules' => $u['edit_rules'] ?? null,
            'whatsapp_phone_e164' => $u['whatsapp_phone_e164'] ?? null,
            'public_display_name' => $u['public_display_name'] ?? null,
            'avatar_id' => $avatar ? $avatar['id'] : null,
            'avatar_filename' => $avatar ? $avatar['filename'] : null,
            'avatar_url' => $avatar ? $avatar['url'] : null,
            'country_iso2' => $u['country_iso2'] ?? null
        ];

        $redeem_min_coins = (int)($st['redeem_min_coins'] ?? 0);
        if ($redeem_min_coins < 0) { $redeem_min_coins = 0; }
        $redeem_step_coins = (int)($st['redeem_step_coins'] ?? 1);
        if ($redeem_step_coins <= 0) { $redeem_step_coins = 1; }
        $public_config = [
            'redeem_min_coins' => $redeem_min_coins,
            'redeem_step_coins' => $redeem_step_coins
        ];

        // Pedidos (historial COMPLETO, sin límite). No exponemos el id secuencial:
        // el frontend usa exclusivamente 'codigo_pedido' como identificador.
        $stmt_o = $conn->prepare("SELECT codigo_pedido, total_pagar, estado, cashback_earned, fecha_creacion, (SELECT JSON_ARRAYAGG(JSON_OBJECT('nombre_producto',dp.nombre_producto)) FROM detalles_pedido dp WHERE dp.pedido_id=p.id) as j FROM pedidos p WHERE user_id=? ORDER BY id DESC");
        $stmt_o->bind_param('i', $user_id); $stmt_o->execute();
        $ords = $stmt_o->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach($ords as &$o) { 
            $d=json_decode($o['j']??'[]',true); $o['c']=is_array($d)?count($d):0; $o['f']=$d[0]['nombre_producto']??'Ítem'; unset($o['j']); 
        }
// ... (código anterior de pedidos) ...
        
// 5. Notificaciones
        $sql_notif = "SELECT id, type, title, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 5";
        $stmt_n = $conn->prepare($sql_notif);
        $stmt_n->bind_param('i', $user_id);
        $stmt_n->execute();
        $notifs = $stmt_n->get_result()->fetch_all(MYSQLI_ASSOC);
        
// 6. OBTENER LISTA DE AVATARES (Vital para el selector): sólo activos con archivo en disco
        $avail_avatars = AvatarCatalog::publicList($conn);
        
        // ENVÍO FINAL DE DATOS DEL DASHBOARD
        _outputJson([
            'status'=>'success', 
            'user'=>$user_payload, 
            'orders'=>$ords, 
            'notifications'=>$notifs,
            'avatars_list' => $avail_avatars,
            'config' => $public_config
        ]);

    } elseif ($action === 'get_order_details') {

        // El frontend identifica el pedido por su 'codigo_pedido' (alfanumérico),
        // NUNCA por el id auto-incremental.
        $order_code = trim((string)($_GET['order_code'] ?? ''));
        if ($order_code === '') {
            throw new Exception('Pedido no válido');
        }

        // El id secuencial se usa SOLO internamente para los JOINs de detalles;
        // se elimina antes de enviar la respuesta para no exponer el volumen de ventas.
        $stmt_o = $conn->prepare("SELECT id, codigo_pedido, nickname_fortnite_cliente, recipient_mode, subtotal, monto_descuento, cupon_usado_codigo, coins_redeemed, coins_value_money, cashback_earned, total_pagar, estado, fecha_creacion FROM pedidos WHERE codigo_pedido = ? AND user_id = ? LIMIT 1");
        if (!$stmt_o) {
            throw new Exception('Error interno');
        }
        $stmt_o->bind_param('si', $order_code, $user_id);
        $stmt_o->execute();
        $order = $stmt_o->get_result()->fetch_assoc();
        $stmt_o->close();

        if (!$order) {
            throw new Exception('Pedido no encontrado');
        }

        // id solo para uso interno; se quita del payload que ve el frontend.
        $order_id = (int)$order['id'];
        unset($order['id']);

        $recipients = [];
        $stmt_r = $conn->prepare(
            "SELECT DISTINCT dpd.nickname_destinatario
             FROM detalles_pedido_destinatarios dpd
             JOIN detalles_pedido dp ON dpd.detalle_pedido_id = dp.id
             WHERE dp.pedido_id = ?
             ORDER BY dpd.nickname_destinatario ASC"
        );
        if ($stmt_r) {
            $stmt_r->bind_param('i', $order_id);
            $stmt_r->execute();
            $res_r = $stmt_r->get_result();
            while ($row = $res_r->fetch_assoc()) {
                if (!empty($row['nickname_destinatario'])) {
                    $recipients[] = $row['nickname_destinatario'];
                }
            }
            $stmt_r->close();
        }

        $products = [];
        $stmt_p = $conn->prepare(
            "SELECT 
                dp.nombre_producto,
                dp.cantidad,
                dp.tipo_producto,
                dp.precio_final,
                dp.precio_base,
                dp.imagen_producto_url,
                dp.section_name,
                dp.store_rank,
                dp.v_bucks,
                dp.color_1,
                dp.color_2,
                dp.color_3,
                dpd.nickname_destinatario
            FROM detalles_pedido dp
            LEFT JOIN detalles_pedido_destinatarios dpd ON dp.id = dpd.detalle_pedido_id
            WHERE dp.pedido_id = ?
            ORDER BY dp.id ASC"
        );
        if ($stmt_p) {
            $stmt_p->bind_param('i', $order_id);
            $stmt_p->execute();
            $products = $stmt_p->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_p->close();
        }

        _outputJson([
            'status' => 'success',
            'order' => $order,
            'recipients' => $recipients,
            'products' => $products
        ]);

    } elseif ($action === 'mark_notifications_read') {
        // Marca como leídas TODAS las notificaciones pendientes del usuario.
        // El backend es la fuente de la verdad: tras esto el contador queda en 0.
        $stmt_mr = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        if (!$stmt_mr) {
            throw new Exception('Error interno');
        }
        $stmt_mr->bind_param('i', $user_id);
        $stmt_mr->execute();
        $marked = $stmt_mr->affected_rows;
        $stmt_mr->close();

        _outputJson(['status' => 'success', 'marked' => (int)$marked]);

    } elseif ($action === 'update_profile') {

        // ══════════════════════════════════════════════════════════════
        // BATCH MODE: when 'updates' param is present, validate ALL
        // fields first and only save when everything passes (atomic).
        // Uses the same u4 sub-action to avoid routing issues.
        // ══════════════════════════════════════════════════════════════
        $raw_batch = $_REQUEST['updates'] ?? $_POST['updates'] ?? null;
        if ($raw_batch !== null && $raw_batch !== '') {
            $batch_updates = json_decode($raw_batch, true);
            if (!is_array($batch_updates) || empty($batch_updates)) {
                throw new Exception('No hay campos para actualizar');
            }

            $batch_map = [
                'game_username' => ['col'=>'game_username', 'upd'=>'game_username_updated_at', 'norm'=>'game_username_norm', 'lock'=>'game_username_locked'],
                'whatsapp'      => ['col'=>'whatsapp_phone_e164', 'upd'=>'phone_updated_at', 'norm'=>null, 'lock'=>'whatsapp_locked'],
                'public_name'   => ['col'=>'public_display_name', 'upd'=>'public_display_name_updated_at', 'norm'=>'public_display_name_norm', 'lock'=>'public_name_locked'],
                'avatar_id'     => ['col'=>'avatar_id', 'upd'=>'avatar_updated_at', 'norm'=>null, 'lock'=>null],
                'country_iso2'  => ['col'=>'country_iso2', 'upd'=>'country_updated_at', 'norm'=>null, 'lock'=>null]
            ];

            $stmt=$conn->prepare("SELECT * FROM users WHERE id=?");
            $stmt->bind_param('i',$user_id); $stmt->execute();
            $curr=$stmt->get_result()->fetch_assoc();
            if (!$curr) throw new Exception('Usuario no encontrado');

            $st = getSettings($conn);
            $cd_days = (int)($st['cooldown_days_general'] ?? 30);

            // PHASE 1: Validate ALL, collect ALL errors
            $errors = [];
            $valid_updates = [];

            foreach ($batch_updates as $upd) {
                $bf = $upd['field'] ?? '';
                $bv = trim($upd['value'] ?? '');
                if (!isset($batch_map[$bf])) { $errors[$bf] = 'Campo inválido'; continue; }
                if ($bv === '') { $errors[$bf] = 'Valor vacío'; continue; }
                $bcfg = $batch_map[$bf];

                if ($bf === 'country_iso2') {
                    banano_ensure_country_schema($conn);
                    $bv = strtolower(preg_replace('/[^a-zA-Z]/', '', $bv));
                    if ($bv === '' || strlen($bv) !== 2) { $errors[$bf] = 'País inválido'; continue; }
                }
                if ($bcfg['lock'] !== null && isset($curr[$bcfg['lock']]) && $curr[$bcfg['lock']] == 1) {
                    $errors[$bf] = 'Campo bloqueado por administración.'; continue;
                }
                $skip_cd = false;
                if ($bf === 'game_username' && banano_is_placeholder_username($curr['game_username'] ?? null)) $skip_cd = true;
                if ($bf === 'public_name' && banano_is_placeholder_username($curr['public_display_name'] ?? null)) $skip_cd = true;
                if ($bf !== 'avatar_id' && !$skip_cd) {
                    $cd = calcCooldown($curr[$bcfg['upd']], $cd_days);
                    if (!$cd['can_change']) { $errors[$bf] = 'Cambio disponible en: '.$cd['next']; continue; }
                }
                if ($bf === 'public_name') {
                    $charCheck = validatePublicNameChars($bv);
                    if (!$charCheck['ok']) { $errors[$bf] = $charCheck['reason']; continue; }
                    $profanityCheck = containsProfanity($bv);
                    if ($profanityCheck['found']) { $errors[$bf] = $profanityCheck['reason']; continue; }
                }
                if ($bf === 'avatar_id' && AvatarCatalog::find($conn, $bv) === null) {
                    $errors[$bf] = 'Avatar no disponible.'; continue;
                }
                $bnorm = null;
                if ($bcfg['norm']) {
                    $bnorm = strtolower(str_replace(' ', '', $bv));
                    $chk=$conn->prepare("SELECT id FROM users WHERE {$bcfg['norm']} = ? AND id != ?");
                    $chk->bind_param('si', $bnorm, $user_id); $chk->execute();
                    if ($chk->get_result()->num_rows > 0) { $errors[$bf] = 'Ese nombre ya está en uso.'; continue; }
                }
                if ($bf === 'whatsapp') {
                    $bv = preg_replace('/[^0-9+]/', '', $bv);
                    $chk_wa=$conn->prepare("SELECT id FROM users WHERE whatsapp_phone_e164 = ? AND id != ?");
                    $chk_wa->bind_param('si', $bv, $user_id); $chk_wa->execute();
                    if ($chk_wa->get_result()->num_rows > 0) { $errors[$bf] = 'Ese número de WhatsApp ya está registrado.'; continue; }
                }
                $valid_updates[] = ['field'=>$bf, 'value'=>$bv, 'cfg'=>$bcfg, 'norm_val'=>$bnorm];
            }

            if (!empty($errors)) {
                http_response_code(400);
                _outputJson(['status'=>'error', 'field_errors'=>$errors, 'message'=>'Revisa los campos marcados.']);
                // _outputJson does not exit, so exit explicitly:
                exit;
            }

            // PHASE 2: Save ALL in a transaction
            $conn->begin_transaction();
            try {
                foreach ($valid_updates as $vu) {
                    $bf=$vu['field']; $bv=$vu['value']; $bcfg=$vu['cfg']; $bnorm=$vu['norm_val'];
                    if ($bcfg['norm']) {
                        $sql = "UPDATE users SET {$bcfg['col']}=?, {$bcfg['norm']}=?, {$bcfg['upd']}=NOW() WHERE id=?";
                        $up = $conn->prepare($sql); $up->bind_param('ssi', $bv, $bnorm, $user_id);
                    } else {
                        $sql = "UPDATE users SET {$bcfg['col']}=?, {$bcfg['upd']}=NOW() WHERE id=?";
                        $up = $conn->prepare($sql);
                        if ($bf === 'avatar_id') { $iv=(int)$bv; $up->bind_param('ii', $iv, $user_id); }
                        else { $up->bind_param('si', $bv, $user_id); }
                    }
                    if (!$up->execute()) throw new Exception('Error al guardar: '.$conn->error);
                }
                $conn->commit();
                _outputJson(['status'=>'success']);
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
        }

        // ══════════════════════════════════════════════════════════════
        // SINGLE FIELD MODE (original behavior, backward compatible)
        // ══════════════════════════════════════════════════════════════
        $field = $_POST['field'] ?? '';
        $val = trim($_POST['value'] ?? '');
        if($val === '') throw new Exception("Valor vacío");

        // Mapeo seguro frontend -> columna BD
        $map = [
            'game_username' => ['col'=>'game_username', 'upd'=>'game_username_updated_at', 'norm'=>'game_username_norm', 'lock'=>'game_username_locked'],
            'whatsapp'      => ['col'=>'whatsapp_phone_e164', 'upd'=>'phone_updated_at', 'norm'=>null, 'lock'=>'whatsapp_locked'],
            'public_name'   => ['col'=>'public_display_name', 'upd'=>'public_display_name_updated_at', 'norm'=>'public_display_name_norm', 'lock'=>'public_name_locked'],
            'avatar_id'     => ['col'=>'avatar_id', 'upd'=>'avatar_updated_at', 'norm'=>null, 'lock'=>null],
            'country_iso2'  => ['col'=>'country_iso2', 'upd'=>'country_updated_at', 'norm'=>null, 'lock'=>null]
        ];

        if (!isset($map[$field])) throw new Exception("Campo inválido");
        $cfg = $map[$field];

        if ($field === 'country_iso2') {
            banano_ensure_country_schema($conn);
            $val = strtolower(preg_replace('/[^a-zA-Z]/', '', $val));
            if ($val === '' || strlen($val) !== 2) {
                throw new Exception('País inválido');
            }
        }

        // Obtener datos actuales del usuario
        $stmt=$conn->prepare("SELECT * FROM users WHERE id=?"); 
        $stmt->bind_param('i',$user_id); 
        $stmt->execute();
        $curr=$stmt->get_result()->fetch_assoc();
        
        // 1. Verificar Bloqueo
        if($cfg['lock'] !== null && isset($curr[$cfg['lock']]) && $curr[$cfg['lock']] == 1) {
            throw new Exception("Campo bloqueado por administración.");
        }
        
        // 2. Verificar Cooldown (Avatar no tiene cooldown estricto, los demás sí)
        $skip_cooldown = false;
        if ($field === 'game_username' && banano_is_placeholder_username($curr['game_username'] ?? null)) {
            $skip_cooldown = true;
        }
        if ($field === 'public_name' && banano_is_placeholder_username($curr['public_display_name'] ?? null)) {
            $skip_cooldown = true;
        }

        if ($field !== 'avatar_id' && !$skip_cooldown) {
            $st = getSettings($conn);
            $cd = calcCooldown($curr[$cfg['upd']], (int)($st['cooldown_days_general']??30));
            if(!$cd['can_change']) throw new Exception("Disponible el: ".$cd['next']);
        }

        if ($field === 'avatar_id' && AvatarCatalog::find($conn, $val) === null) {
            throw new Exception('Avatar no disponible.');
        }

        // 2b. Validar nombre público: caracteres permitidos + profanidad
        if ($field === 'public_name') {
            $charCheck = validatePublicNameChars($val);
            if (!$charCheck['ok']) {
                throw new Exception($charCheck['reason']);
            }
            $profanityCheck = containsProfanity($val);
            if ($profanityCheck['found']) {
                throw new Exception($profanityCheck['reason']);
            }
        }

        // 3. Validar unicidad y Guardar
        if($cfg['norm']) {
            // Caso Nicknames (con validación de duplicados)
            $norm_val = strtolower(str_replace(' ','' ,$val));
            $chk=$conn->prepare("SELECT id FROM users WHERE {$cfg['norm']} = ? AND id != ?");
            $chk->bind_param('si', $norm_val, $user_id); 
            $chk->execute();
            if($chk->get_result()->num_rows > 0) throw new Exception("Ese nombre ya está en uso.");
            
            $sql = "UPDATE users SET {$cfg['col']}=?, {$cfg['norm']}=?, {$cfg['upd']}=NOW() WHERE id=?";
            $up = $conn->prepare($sql); 
            $up->bind_param('ssi', $val, $norm_val, $user_id);
        } else {
            // Caso WhatsApp y Avatar
            if($field=='whatsapp') {
                $val = preg_replace('/[^0-9+]/','',$val);
                // Verificar unicidad del número de WhatsApp
                $chk_wa=$conn->prepare("SELECT id FROM users WHERE whatsapp_phone_e164 = ? AND id != ?");
                $chk_wa->bind_param('si', $val, $user_id);
                $chk_wa->execute();
                if($chk_wa->get_result()->num_rows > 0) throw new Exception("Ese número de WhatsApp ya está registrado.");
            }
            
            $sql = "UPDATE users SET {$cfg['col']}=?, {$cfg['upd']}=NOW() WHERE id=?";
            $up = $conn->prepare($sql); 
            
            // CORRECCIÓN CRÍTICA DE TIPOS
            if ($field === 'avatar_id') {
                $intVal = (int)$val;
                $up->bind_param('ii', $intVal, $user_id); // Entero
            } else {
                $up->bind_param('si', $val, $user_id); // String
            }
        }

        if($up->execute()) {
            _outputJson(['status'=>'success']);
        } else {
            throw new Exception("Error al guardar en BD: ".$conn->error);
        }

    }

} catch (Exception $e) {
    http_response_code(400); 
    _outputJson(['status'=>'error','message'=>$e->getMessage()]);
}
?>

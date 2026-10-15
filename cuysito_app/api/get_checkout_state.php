<?php
// /api/get_checkout_state.php
// VERSIÓN 3.0 - ARQUITECTURA DE DATOS FINAL Y CORRECTA

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/session_security.php';
header('Content-Type: application/json');

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/api/db_connect.php';
require_once ROOT_PATH . '/api/coupon_validation_logic.php';
require_once ROOT_PATH . '/wabo/core/shop_helper.php';
require_once ROOT_PATH . '/api/logic_discounts.php';

function sendJsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    $raw = json_encode($data);
    if (defined('MODO_DEBUG') && MODO_DEBUG) {
        echo $raw;
    } else {
        $sn = openssl_random_pseudo_bytes(16);
        $sd = openssl_encrypt($raw, 'aes-256-cbc', CART_SYNC_TOKEN, OPENSSL_RAW_DATA, $sn);
        echo json_encode(['_sd' => base64_encode($sd), '_sn' => base64_encode($sn)]);
    }
    exit;
}

// 1. OBTENER CARRITO
if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
    // Si no hay carrito, devolvemos estructura vacía para evitar errores JS
    sendJsonResponse(['success' => true, 'data' => [
        'products' => [],
        'subtotal_original' => 0,
        'descuento_total' => 0,
        'total_final' => 0,
        'coupon_message' => null,
        'coupon_info' => null,
        'user_coins' => null
    ]]);
}
$cart_items = array_values($_SESSION['cart']);
// --- INICIO BLOQUE 2 ---
try {
    $shop_response = get_fortnite_shop_data();
    if (!$shop_response['result']) {
        throw new Exception("No se pudieron obtener los datos de la tienda para validar secciones.");
    }
} catch (Exception $e) {
    // Si la API de la tienda falla, no podemos validar cupones de sección, pero el checkout debe continuar.
    $shop_response = ['sections' => []];
}

$shop_lookup_map = [];
foreach ($shop_response['sections'] as $section) {
    foreach ($section['entries'] as $entry) {
        // Guardamos el nombre de la sección (con espacios) para cada offerId
        $shop_lookup_map[$entry['offerId']] = $section['name'];
    }
}
// --- FIN BLOQUE 2 ---

// 2. OBTENER DATOS COMPLETOS DEL PAYLOAD
$json_payload = json_decode(file_get_contents('php://input'), true);
$codigo_cupon = trim($json_payload['codigo_cupon'] ?? '');
// --- ¡NUEVO! Leemos los destinatarios y el whatsapp que envía el frontend ---
$destinatarios = $json_payload['destinatarios'] ?? [];
$whatsapp = trim($json_payload['whatsapp'] ?? '');

// --- INICIO BLOQUE 3 (REEMPLAZO CON LECTURA DE REGLAS DB) ---
$productos_para_validar = [];
$productos_reglas = []; // Mapa temporal para guardar reglas

// Pre-cargar reglas de productos estáticos para no hacer mil consultas
$static_ids = [];
foreach ($cart_items as $item) {
    if (!empty($item['is_static'])) {
        $static_ids[] = (int)$item['offerId'];
    }
}

if (!empty($static_ids)) {
    $ids_str = implode(',', $static_ids);
    $res_rules = $conn->query("SELECT id, permitir_canje_monedas, requiere_nickname FROM productos_estaticos WHERE id IN ($ids_str)");
    while ($row = $res_rules->fetch_assoc()) {
        $productos_reglas[$row['id']] = $row;
    }
}

foreach ($cart_items as $item) {
    $offerId = $item['offerId'];
    $section_name = $shop_lookup_map[$offerId] ?? null;
    
    // Reglas por defecto (API products)
    $permite_canje = 1;
    $pide_nick = 0; // API products ya tienen su lógica, esto es para estáticos

    // Si es estático, leemos de la DB
    if (!empty($item['is_static']) && isset($productos_reglas[$offerId])) {
        $permite_canje = (int)$productos_reglas[$offerId]['permitir_canje_monedas'];
        $pide_nick = (int)$productos_reglas[$offerId]['requiere_nickname'];
    }

    // Guardamos la info para validación interna
    $productos_para_validar[] = [
        'offerId'   => $offerId,
        'section'   => $section_name,
        'tipo'      => $item['type'],
        'precio'    => (float)$item['price'],
        'precio_base' => (float)$item['price_base'],
        'cantidad'  => (int)$item['quantity'],
        'is_static' => (bool)$item['is_static'],
        // Datos extra para pasarlos al frontend abajo
        'permitir_canje' => $permite_canje,
        'requiere_nickname' => $pide_nick
    ];
}
// --- FIN BLOQUE 3 ---

$coupon_info_for_frontend = null;
$coupon_message = null;

// 3. VALIDAR CUPÓN (VERSIÓN COMPLETA Y MEJORADA)
if (!empty($codigo_cupon)) {
    // Llamamos a la validación
    $validation_result = validarCuponCompleto(
        $conn, 
        $codigo_cupon, 
        $productos_para_validar, 
        $whatsapp,
        $destinatarios,
        $_SERVER['REMOTE_ADDR'],
        $_SESSION['user_id'] ?? null
    );
    
    // CASO A: ÉXITO
    if ($validation_result['status'] === 'success') {
        $coupon_info_for_frontend = $validation_result['data'];
        
        // Mensaje por defecto (técnico)
        $texto_mensaje = $validation_result['message'];

        // VERIFICACIÓN DE MENSAJE PERSONALIZADO (MSG_EXITO)
        // Si el admin puso un mensaje en el panel, usamos ese.
        if (!empty($coupon_info_for_frontend['msg_exito'])) {
            $texto_mensaje = $coupon_info_for_frontend['msg_exito'];
        }

        $coupon_message = ['text' => $texto_mensaje, 'type' => 'success'];
    } 
    // CASO B: ERROR (Aquí estaba el problema, antes borraba la info)
    else {
        // Intentamos recuperar la info del cupón AUNQUE haya fallado la validación.
        // Esto sirve para mostrar el "Mensaje Troll" o efectos de error.
        $coupon_info_for_frontend = $validation_result['data'] ?? null;
        
        // Mensaje por defecto (técnico, ej: "Este cupón ya expiró")
        $texto_mensaje = $validation_result['message'];

        // VERIFICACIÓN DE MENSAJE TROLL (MSG_ERROR)
        // Si el cupón existe (tenemos datos) y tiene mensaje de error personalizado...
        if ($coupon_info_for_frontend && !empty($coupon_info_for_frontend['msg_error'])) {
            $texto_mensaje = $coupon_info_for_frontend['msg_error'];
        }

        $coupon_message = ['text' => $texto_mensaje, 'type' => 'error'];
    }
}


// 4. CONSTRUIR ESTADO BASE
$productos_finales = [];
$subtotal_original_total = 0;
$subtotal_con_ofertas_manuales = 0;

foreach ($cart_items as $item) {
    // --- INICIO LÓGICA DE RE-CÁLCULO DE PRECIO (SPIN) ---
    $precio_base_item = (float)$item['price_base'];
    $precio_final_item = (float)$item['price']; // Precio actual en sesión
    $tiene_oferta = $item['has_offer'];

    // Verificación en tiempo real del descuento personal
    if (isset($_SESSION['user_id'])) {
        $spin_discount = get_active_discount($conn, $_SESSION['user_id'], $item['offerId']);
        if ($spin_discount) {
            // Si existe descuento activo, forzamos el cálculo
            $precio_calculado = calculate_final_price($precio_base_item, $spin_discount['discount_percent']);
            
            // Usamos este precio priorizando sobre lo que diga la sesión
            $precio_final_item = $precio_calculado;
            $tiene_oferta = true;
        }
    }
    // --- FIN LÓGICA DE RE-CÁLCULO ---

    // Usamos las variables calculadas arriba para los sumatorios
    $subtotal_original_total += $precio_base_item * (int)$item['quantity'];
    $subtotal_con_ofertas_manuales += $precio_final_item * (int)$item['quantity'];
    
    // ... (El resto del código del foreach sigue igual: Buscar reglas específicas...)
    
    // Buscar reglas específicas para este item (que calculamos en el Bloque 3)
    $regla_canje = 1; 
    $regla_nick = 0;
    
    foreach($productos_para_validar as $pv) {
        if($pv['offerId'] == $item['offerId']) {
            $regla_canje = $pv['permitir_canje'];
            $regla_nick = $pv['requiere_nickname'];
            break;
        }
    }

$productos_finales[$item['offerId']] = [
        'id' => $item['offerId'], 
        'name' => $item['name'], 
        'quantity' => $item['quantity'],
        'price' => $precio_final_item, // Usamos la variable recalculada
        'price_base' => $precio_base_item,
        'has_offer' => $tiene_oferta,  // Usamos la variable recalculada
        // ... resto de campos igual ...
        'img' => $item['image'], 
        'is_static' => $item['is_static'],
        'type' => $item['type'], 
        'vbucks' => $item['vbucks'], 
        'colors' => $item['colors'] ?? null,
        'section_name' => $item['section_name'] ?? null,
        'store_rank' => isset($item['store_rank']) ? (int)$item['store_rank'] : 0,
        'rarity' => $item['rarity'],
        // NUEVAS BANDERAS PARA EL FRONTEND
        'permitir_canje' => $regla_canje,
        'requiere_nickname' => $regla_nick
    ];
}

// 5. OBTENER DESCUENTO DEL CUPÓN (SI LO HAY)
$descuento_del_cupon = 0;
if ($coupon_info_for_frontend !== null) {
    $descuento_del_cupon = (float)($coupon_info_for_frontend['descuento_aplicado'] ?? 0);
}
        // --- INICIO DEL BLOQUE A AÑADIR ---
        
        // Para la parte VISUAL en la lista de productos, si el cupón fue válido,
        // actualizamos el precio del producto afectado para que el frontend lo pueda dibujar.
        if (isset($coupon_info_for_frontend['productos_afectados'])) {
            foreach ($coupon_info_for_frontend['productos_afectados'] as $affected_product) {
                $id = $affected_product['id'];
                if (isset($productos_finales[$id])) {
                    // Actualizamos el precio final de este producto específico en la respuesta.
                    $productos_finales[$id]['price'] = (float)$affected_product['precio_final'];
                }
            }
        }

        // --- FIN DEL BLOQUE A AÑADIR ---
// ==============================================================================
// === INICIO: REEMPLAZAR BLOQUE "LÓGICA DE CÁLCULO FINAL" EN get_checkout_state.php ===
// ==============================================================================

// --- LÓGICA DE CÁLCULO FINAL (LÓGICA DE REEMPLAZO) ---

// 1. Empezamos asumiendo que el total es el subtotal con ofertas manuales.
$total_final_calculado = $subtotal_con_ofertas_manuales;

// 2. Si hay un cupón válido, ajustamos el total.
if (abs($descuento_del_cupon) > 0 && isset($coupon_info_for_frontend['productos_afectados'])) {
    
    // 3. Identificamos los productos afectados por el cupón.
    $ids_afectados_por_cupon = array_column($coupon_info_for_frontend['productos_afectados'], 'id');
    
    // 4. Recorremos los productos del carrito para hacer el ajuste.
    foreach ($cart_items as $item) {
        // Si este item está en la lista de afectados por el cupón...
        if (in_array($item['offerId'], $ids_afectados_por_cupon)) {
            
            // "Devolvemos" el descuento manual que ya estaba aplicado en '$subtotal_con_ofertas_manuales'.
            // Para eso, calculamos la diferencia entre su precio base y su precio de oferta.
            $descuento_manual_del_item = ((float)$item['price_base'] * (int)$item['quantity']) - ((float)$item['price'] * (int)$item['quantity']);
            
            // Sumamos esa diferencia de vuelta al total. Ahora el total considera este item a su precio completo.
            $total_final_calculado += $descuento_manual_del_item;
        }
    }
    
    // 5. Finalmente, restamos el descuento del cupón que se calculó sobre el precio base.
    $total_final_calculado -= $descuento_del_cupon;
}

// 6. El descuento total es simplemente la diferencia entre el precio original y lo que se va a pagar.
$descuento_total_calculado = $subtotal_original_total - $total_final_calculado;

// ==============================================================================
// === FIN DEL BLOQUE PARA REEMPLAZAR ===========================================
// ==============================================================================
// === INICIO: LÓGICA DE MONEDAS (COINS) ===
// ==============================================================================
$user_coins_info = null;

// Solo si el usuario está logueado
if (isset($_SESSION['user_id']) && isset($_SESSION['logged_in_client']) && $_SESSION['logged_in_client'] === true) {
    $uid = (int)$_SESSION['user_id'];

    if (!banano_client_session_is_valid($conn, $uid)) {
        $user_coins_info = null;
    } else {

    // 1. Obtener configuración de monedas
    $sql_conf = "SELECT key_name, value FROM site_settings WHERE key_name IN ('coins_unit_coins', 'coins_unit_money', 'redeem_min_coins', 'redeem_step_coins')";
    $res_conf = $conn->query($sql_conf);
    $conf = [];

    while($r = $res_conf->fetch_assoc()) $conf[$r['key_name']] = $r['value'];

    $unit_coins = (int)($conf['coins_unit_coins'] ?? 100);
    $unit_money = (float)($conf['coins_unit_money'] ?? 1.30);
    $redeem_min = (int)($conf['redeem_min_coins'] ?? 150);
    $redeem_step = (int)($conf['redeem_step_coins'] ?? 50);

    // 2. Obtener saldo actual
    $stmt_c = $conn->prepare("SELECT balance FROM coins WHERE user_id = ?");
    $stmt_c->bind_param('i', $uid);
    $stmt_c->execute();
    $row_c = $stmt_c->get_result()->fetch_assoc();
    $balance = (float)($row_c['balance'] ?? 0);
    $stmt_c->close();

    // 3. Calcular valor monetario máximo canjeable
    // El usuario solo puede canjear en bloques (steps) y si supera el mínimo.
    
    $coins_canjeables = 0;
    $dinero_canjeable = 0.00;

    if ($balance >= $redeem_min) {
        // Redondeamos hacia abajo al múltiplo más cercano del step
        // Ej: Balance 157, Step 50 -> 150 canjeables.
        $coins_canjeables = floor($balance / $redeem_step) * $redeem_step;
        
        // Calculamos cuánto dinero vale eso
        if ($unit_coins > 0) {
            $dinero_canjeable = ($coins_canjeables * $unit_money) / $unit_coins;
        }
    }

    $user_coins_info = [
        'balance' => $balance,
        'redeemable_coins' => $coins_canjeables,
        'redeemable_money' => round($dinero_canjeable, 2),
        'config' => [
            'min' => $redeem_min,
            'step' => $redeem_step,
            'ratio_coins' => $unit_coins,
            'ratio_money' => $unit_money
        ]
    ];
    }
}
// ==============================================================================
// === FIN: LÓGICA DE MONEDAS ===
// ==============================================================================

// 7. ENVIAR RESPUESTA CONSISTENTE
sendJsonResponse(['success' => true, 'data' => [
    'products' => array_values($productos_finales),
    'subtotal_original' => round($subtotal_original_total, 2),
    'descuento_total' => round($descuento_total_calculado, 2),
    'total_final' => round($total_final_calculado, 2),
    'coupon_message' => $coupon_message,
    'coupon_info' => $coupon_info_for_frontend,
    'user_coins' => $user_coins_info // <--- ESTA ES LA NUEVA LÍNEA
]]);
?>
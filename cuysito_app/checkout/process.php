<?php

// =================================================================
// Banano Store - PROCESADOR DE PEDIDOS v4.1 (Alineado con Cerebro Central)
// =================================================================

// --- MODO DEPURACIÓN ---
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// --- MODO DEPURACIÓN A PRUEBA DE FALLOS v3 ---

// 1. Forzamos a PHP a que sepa que queremos registrar errores.
ini_set('log_errors', 1);

// 2. Le decimos a PHP la ruta ABSOLUTA Y EXACTA de nuestro archivo de log.
// Usamos el nombre que sugeriste: errores_process.log
ini_set('error_log', __DIR__ . '/errores_process.log');

// 3. Creamos nuestra función de depuración que usará la configuración nativa de PHP.
function debug_log($message) {
    // Formateamos el mensaje para que sea fácil de leer.
    $log_entry = '[CHECKOUT_DEBUG] ' . (is_array($message) || is_object($message) ? print_r($message, true) : $message);
    
    // Usamos la función error_log() nativa. Es la más fiable.
    error_log($log_entry);
}

debug_log("================== NUEVO PEDIDO (Log a prueba de fallos activado) ==================");

require_once __DIR__ . '/../api/session_security.php';
debug_log("Sesión iniciada.");

// --- DEPENDENCIAS Y CONEXIÓN A BD ---
require_once __DIR__ . '/../api/db_connect.php'; 
require_once __DIR__ . '/../api/coupon_validation_logic.php'; 
require_once __DIR__ . '/../api/logic_discounts.php'; // <--- NUEVA LÍNEA: Cerebro de descuentos

debug_log("Dependencias (BD y Lógica de Cupón) incluidas.");

if (isset($_SESSION['user_id']) && isset($_SESSION['logged_in_client']) && $_SESSION['logged_in_client'] === true) {
    $uid_check = (int)$_SESSION['user_id'];
    banano_client_session_is_valid($conn, $uid_check);
}

if (!isset($conn) || !$conn->ping()) {
    debug_log("¡ERROR FATAL! La conexión a la base de datos FALLÓ.");
    responder_json(false, 'Error de conexión con el servidor.');
}

// --- FUNCIÓN DE RESPUESTA ESTANDARIZADA ---
function responder_json($success, $message, $data = []) {
    http_response_code($success ? 200 : 400);
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    if (isset($GLOBALS['conn'])) $GLOBALS['conn']->close();
    exit();
}

// --- PASO 1: VERIFICACIONES INICIALES Y LECTURA DE DATOS ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    debug_log("Acceso denegado: Método no es POST.");
    die("CONFIRMADO: La petición está llegando como " . $_SERVER['REQUEST_METHOD']); // <--- PRUEBA DEFINITIVA
    responder_json(false, 'Método no permitido.');
}
$data = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    debug_log("Error: Petición mal formada (JSON inválido).");
    responder_json(false, 'Los datos del pedido están corruptos.');
}
debug_log("JSON decodificado con éxito.");
debug_log("Datos crudos de productos recibidos: " . print_r($data['products'], true));
if (empty($data['products'])) { // La sesión ya no es la fuente de verdad aquí
    debug_log("Los productos del JSON están vacíos.");
    responder_json(false, 'Tu carrito está vacío o ha ocurrido un error.');
}
// ... al inicio del script o antes del bloque try ...
$cupon_id_para_registrar = null; // <-- AÑADE ESTA LÍNEA

// ---------------------------------
// ...
// --- INICIO DE LA TRANSACCIÓN ---
$conn->begin_transaction();
debug_log("Iniciando transacción de base de datos.");
try {
    
// --- PASO 2: EXTRACCIÓN, SANITIZACIÓN Y VALIDACIÓN DE DATOS ---
debug_log("Extrayendo y sanitizando datos...");
$contact = $data['contact'] ?? [];
$email_cliente = filter_var($contact['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null;
$whatsapp_cliente = htmlspecialchars(trim($contact['whatsapp'] ?? ''), ENT_QUOTES, 'UTF-8');
$recipients = $data['recipients'] ?? [];
$metodo_pago = htmlspecialchars($data['selectedPayment'] ?? '', ENT_QUOTES, 'UTF-8');
$codigo_cupon_cliente = !empty($data['coupon']['code']) ? htmlspecialchars(trim($data['coupon']['code']), ENT_QUOTES, 'UTF-8') : null;
$ip_cliente = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$products_from_json = $data['products'] ?? [];

// ======================================================================
// --- INICIO: VALIDACIÓN DEFINITIVA BASADA EN PAYLOAD REAL ---
// ======================================================================

// 1. Determinar si se necesita un nickname.
// Requieren nickname: los productos de la API (no estáticos) Y los productos
// estáticos marcados como "regalable" (requiere_nickname = 1). El flag
// requiere_nickname lo inyecta get_checkout_state.php leyéndolo de la BD
// (productos_estaticos), por lo que aquí ya viaja en el payload de cada producto.
$requiereNickname = false;
if (isset($products_from_json) && is_array($products_from_json)) {
    foreach ($products_from_json as $product) {
        $is_static = !empty($product['is_static']);
        $es_regalable = ((int)($product['requiere_nickname'] ?? 0)) === 1;
        if (!$is_static || $es_regalable) {
            $requiereNickname = true;
            break;
        }
    }
}
debug_log("[VALIDACIÓN FINAL] Análisis de Payload: ¿Requiere Nickname? -> " . ($requiereNickname ? 'SÍ' : 'NO'));

// 2. Extraer el nickname y realizar validaciones.
$nickname_principal = '';
$errores = [];

if (empty($whatsapp_cliente)) {
    $errores[] = 'WhatsApp';
}
if (empty($metodo_pago)) {
    $errores[] = 'Método de pago';
}

if ($requiereNickname) {
    // Solo si se requiere, intentamos obtenerlo y validarlo.
    if (!empty($recipients[0]['name'])) {
        $nickname_principal = htmlspecialchars(trim($recipients[0]['name']), ENT_QUOTES, 'UTF-8');
    }
    
    // Si después de intentar obtenerlo sigue vacío, es un error.
    if (empty($nickname_principal)) {
        $errores[] = 'Nickname';
    } elseif (mb_strlen($nickname_principal, 'UTF-8') > 16) {
        throw new Exception("El nombre de usuario no puede exceder 16 caracteres.");
    }

    // Validar longitud de todos los destinatarios
    foreach ($recipients as $r) {
        $rName = trim($r['name'] ?? '');
        if ($rName !== '' && mb_strlen($rName, 'UTF-8') > 16) {
            throw new Exception("El nombre de usuario no puede exceder 16 caracteres.");
        }
    }
}

// 3. Si después de todo, hay errores, lanzamos la excepción.
if (!empty($errores)) {
    // Usamos el mensaje original que espera el frontend
    throw new Exception("Datos críticos incompletos (WhatsApp, Nickname o Método de pago).");
}

// ======================================================================
// --- INTEGRACIÓN USUARIOS v3.0 (MODO EXPLÍCITO + SEGURIDAD) ---
// ======================================================================
$user_id_db = null;
$recipient_mode = 'friend'; // Default seguro
$counts_toward_verification = 0;
$cashback_mode = 'none';
// Cashback base (2%) para todos los logueados; el verificado (5%) solo en modo 'self'.
$cashback_use_verified_rate = false;

// Capturamos el modo que envía el JS
$explicit_mode_js = $data['explicit_recipient_mode'] ?? 'friend';

if (isset($_SESSION['user_id']) && isset($_SESSION['logged_in_client']) && $_SESSION['logged_in_client'] === true) {
    $user_id_db = (int)$_SESSION['user_id'];
    
    // Obtenemos datos reales para validar
    $stmt_check = $conn->prepare("SELECT game_username, game_username_norm, is_verified FROM users WHERE id = ?");
    $stmt_check->bind_param('i', $user_id_db);
    $stmt_check->execute();
    $res_check = $stmt_check->get_result();
    
    if ($row_user = $res_check->fetch_assoc()) {
        
        // 1. Limpieza de destinatarios (Solo los que tienen nombre)
        $valid_recipients = array_filter($recipients, function($r) {
            return !empty(trim($r['name'] ?? ''));
        });
        $count_recipients = count($valid_recipients);

// 2. Lógica de Decisión (MEJORADA PARA ESTÁTICOS)
        if ($count_recipients > 1) {
            $recipient_mode = 'multi';
            $counts_toward_verification = 0;
        } 
        elseif ($explicit_mode_js === 'self') {
            // CASO A: Hay nickname (Pase de batalla, Skins)
            if ($count_recipients === 1) {
                $input_nick = strtolower(preg_replace('/\s+/', '', $nickname_principal));
                $db_nick = strtolower(preg_replace('/\s+/', '', $row_user['game_username_norm']));
                
                if (strpos($input_nick, $db_nick) !== false || $input_nick === $db_nick) {
                    $recipient_mode = 'self';
                    $counts_toward_verification = 1;
                } else {
                    $recipient_mode = 'friend'; 
                }
            } 
            // CASO B: NO hay nickname (Pavos, Recargas, Packs sin nick)
            // Si el usuario dijo "Para Mí" y no se requiere nick, confiamos en él porque la entrega es a su WhatsApp/Cuenta.
            else {
                $recipient_mode = 'self';
                $counts_toward_verification = 1;
            }
        } 
        else {
            $recipient_mode = 'friend';
        }

        // --- CASHBACK: todos los usuarios logueados ganan cashback ---
        // Base (2%) para cualquier modo (self, friend, multi, ...).
        // Verificado (5%) únicamente para cuentas verificadas comprando en modo 'self'.
        $cashback_use_verified_rate = ($row_user['is_verified'] == 1 && $recipient_mode === 'self');
        // El modo refleja la tasa aplicada: 'verified' cuando se usa la tasa de
        // verificado (5%), 'base' para el resto de usuarios logueados (2%).
        $cashback_mode = $cashback_use_verified_rate ? 'verified' : 'base';

        debug_log("Modo Final Decidido: $recipient_mode (Verif: $counts_toward_verification, Cashback: " . ($cashback_use_verified_rate ? 'verificado 5%' : 'base 2%') . ")");
        
    } else {
        $user_id_db = null; // Usuario no encontrado en BD (raro)
    }
    $stmt_check->close();
}
// ======================================================================
// ======================================================================   
     // --- PASO 2.5: VALIDACIÓN DE EXPIRACIÓN DE PRODUCTOS ---
    debug_log("Iniciando validación de expiración de productos...");
    $current_time = time(); // Timestamp actual en UTC.
    foreach ($products_from_json as $product) {
        // Buscamos el timestamp que guardamos en cart_manager.php
        if (isset($product['expires_at_timestamp']) && !is_null($product['expires_at_timestamp'])) {
            if ($product['expires_at_timestamp'] <= $current_time) {
                // Si encontramos UN solo producto expirado, detenemos todo.
                $expired_product_name = htmlspecialchars($product['name'] ?? 'Producto desconocido', ENT_QUOTES, 'UTF-8');
                $error_message = "Lo sentimos, el producto '{$expired_product_name}' en tu carrito ha expirado. Por favor, vuelve al carrito, elimínalo y continúa tu compra.";
                debug_log("¡FALLÓ LA VALIDACIÓN! Producto expirado encontrado: {$expired_product_name}");
                throw new Exception($error_message);
            }
        }
    }
    debug_log("Validación de expiración superada. Ningún producto ha caducado.");

// ================================================================================================
// === INICIO: REEMPLAZAR ESTE BLOQUE COMPLETO EN process.php =====================================
// ================================================================================================

// --- PASO 3: CÁLCULO FINAL Y AUTORITATIVO DE PRECIOS (LÓGICA ANTI-DOBLE-DESCUENTO) ---
debug_log("PASO 3: Iniciando cálculo final y autoritativo de precios.");

// --- PASO 3.1. OBTENER CARRITO SEGURO Y FUSIONAR CON DATOS DEL CLIENTE ---
$cart_servidor = $_SESSION['cart'] ?? [];
if (empty($cart_servidor)) {
    throw new Exception("Tu sesión de carrito ha expirado. Por favor, vuelve a la tienda.");
}
// Creamos un mapa del carrito del servidor para un acceso seguro y rápido.
$cart_servidor_map = array_column($cart_servidor, null, 'offerId');

// Ahora, construimos el array final fusionando la información del cliente (cantidad, regalos)
// con la información segura del servidor (precios).
$productos_finales = [];
$products_from_json = $data['products'] ?? [];

foreach ($products_from_json as $producto_cliente) {
    $id = $producto_cliente['offerId'] ?? $producto_cliente['id'];
    if (isset($cart_servidor_map[$id])) {
        // Tomamos el producto del servidor como base segura.
        $producto_seguro = $cart_servidor_map[$id];
        
        // Sobrescribimos con los datos del cliente que son válidos.
        $producto_seguro['quantity'] = (int)($producto_cliente['quantity'] ?? 1);
        $producto_seguro['assignments'] = $producto_cliente['assignments'] ?? [];
        
        // Lo añadimos al array final que usaremos para todos los cálculos.
        $productos_finales[] = $producto_seguro;
    }
}

// Si después de la fusión no hay productos, algo salió mal.
if (empty($productos_finales)) {
    throw new Exception("Hubo un problema al verificar los productos de tu carrito.");
}

// --- DETECCIÓN DE RESERVA ---
// Si al menos uno de los productos estáticos del carrito tiene modo_reserva = 1, el pedido completo
// queda marcado como reserva. Es responsabilidad del staff procesar luego la entrega.
$es_reserva = 0;
$static_ids_for_reserva = [];
foreach ($productos_finales as $p) {
    if (!empty($p['is_static']) && isset($p['offerId'])) {
        $pid = (int)$p['offerId'];
        if ($pid > 0) {
            $static_ids_for_reserva[$pid] = true;
        }
    }
}
if (!empty($static_ids_for_reserva)) {
    $ids = array_keys($static_ids_for_reserva);
    $placeholders_r = implode(',', array_fill(0, count($ids), '?'));
    $types_r = str_repeat('i', count($ids));
    $sql_r = "SELECT 1 FROM productos_estaticos WHERE id IN ($placeholders_r) AND modo_reserva = 1 LIMIT 1";
    if ($stmt_r = $conn->prepare($sql_r)) {
        $stmt_r->bind_param($types_r, ...$ids);
        $stmt_r->execute();
        if ($stmt_r->get_result()->fetch_row()) {
            $es_reserva = 1;
        }
        $stmt_r->close();
    }
}

// A partir de aquí, el resto de tu código (Paso 3.2 en adelante) funciona con el array `$productos_finales` ya corregido.
// 3.2. CALCULAR TOTALES BASE (SIN CUPÓN)
$subtotal_servidor_base = 0; // Suma de todos los 'price_base'
$total_con_ofertas = 0;     // Suma de todos los 'price' (con ofertas manuales + SPIN)
$productos_para_validar = []; // Array para enviar a la función de validación

// NOTA: Usamos &$producto por referencia para modificar el precio permanentemente en el array
// y que así se guarde correctamente en la base de datos más abajo.
foreach ($productos_finales as &$producto) {
    $cantidad = (int)($producto['quantity'] ?? 1);
    $precio_base = (float)($producto['price_base'] ?? $producto['price']);
    $precio_oferta = (float)($producto['price']); // Precio original de sesión

// ... dentro del foreach ($productos_finales as &$producto) ...

    // --- INICIO INTEGRACIÓN DAILY SPIN (CON SEGURIDAD DE DESTINATARIO) ---
    // 1. Verificamos si hay descuento en la BD
    if (isset($_SESSION['user_id'])) {
        $spin_discount = get_active_discount($conn, $_SESSION['user_id'], $producto['offerId']);
        
        if ($spin_discount) {
            // 2. VALIDACIÓN CRÍTICA: ¿Para quién es la compra?
            if ($recipient_mode === 'self') {
                // CASO A: Es para mí -> APLICO DESCUENTO Y LO QUEMO
                
                // Recalculamos el precio oferta
                $precio_oferta = calculate_final_price($precio_base, $spin_discount['discount_percent']);
                
                // Actualizamos el producto
                $producto['price'] = $precio_oferta; 
                
                // Guardamos el ID para quemarlo al final
                if (!isset($discount_ids_to_burn)) $discount_ids_to_burn = [];
                $discount_ids_to_burn[] = $spin_discount['id'];

                debug_log("Descuento Spin APLICADO a '{$producto['offerId']}': Nuevo precio S/ $precio_oferta (ID: {$spin_discount['id']})");
            } else {
                // CASO B: Es para un amigo/multi -> ANULO EL DESCUENTO (SEGURIDAD)
                // Si venía con descuento de la sesión, lo revertimos al precio base
                
                $precio_oferta = $precio_base; 
                $producto['price'] = $precio_base; // Restauramos precio en el array
                
                debug_log("Descuento Spin ANULADO para '{$producto['offerId']}': El modo es '{$recipient_mode}' (Solo válido para 'self'). Precio revertido a S/ $precio_base");
            }
        }
    }
    // --- FIN INTEGRACIÓN DAILY SPIN ---

    $subtotal_servidor_base += $precio_base * $cantidad;
    $total_con_ofertas += $precio_oferta * $cantidad;
    
    // Preparamos los datos para la función de validación
    $productos_para_validar[] = [
        'offerId'   => $producto['offerId'],
        'tipo'      => $producto['type'],
        'precio'    => $precio_oferta, // Enviamos el precio ya rebajado para validar cupones
        'precio_base' => $precio_base,
        'cantidad'  => $cantidad,
        'is_static' => (bool)$producto['is_static']
    ];
}
unset($producto); // Romper la referencia para evitar bugs en bucles futuros
debug_log("Totales sin cupón calculados: Subtotal Base=S/{$subtotal_servidor_base}, Total con Ofertas=S/{$total_con_ofertas}");

// 3.3. VALIDAR CUPÓN Y OBTENER DESCUENTO CORRECTO
$descuento_del_cupon = 0;
$resultado_validacion = null;
if ($codigo_cupon_cliente) {
    debug_log("Validando cupón '{$codigo_cupon_cliente}' con la lógica central...");
    
    // ¡REUTILIZAMOS LA LÓGICA PERFECTA! No reinventamos la rueda.
    $resultado_validacion = validarCuponCompleto(
        $conn, $codigo_cupon_cliente, $productos_para_validar, $whatsapp_cliente, 
        array_column($recipients, 'name'), $ip_cliente,
        $user_id_db
    );

    if ($resultado_validacion['status'] === 'success') {
        $descuento_del_cupon = (float)($resultado_validacion['data']['descuento_aplicado'] ?? 0);
        $cupon_id_para_registrar = $resultado_validacion['data']['cupon_id'] ?? null;
        debug_log("Cupón VÁLIDO. Descuento calculado (sobre precio base): S/{$descuento_del_cupon}");
    } else {
        // Si la validación falla aquí (ej. el cupón expiró mientras el cliente pagaba), lanzamos un error.
        debug_log("¡FALLO DE VALIDACIÓN DE CUPÓN EN EL ÚLTIMO MOMENTO! Mensaje: " . $resultado_validacion['message']);
        throw new Exception("El cupón '{$codigo_cupon_cliente}' ya no es válido. " . $resultado_validacion['message']);
    }
}

// 3.4. APLICAR LÓGICA DE REEMPLAZO Y CALCULAR TOTALES FINALES
$total_servidor_final = $total_con_ofertas; // Empezamos con el total de ofertas manuales.

if (abs($descuento_del_cupon) > 0 && isset($resultado_validacion['data']['productos_afectados'])) {
    debug_log("Aplicando lógica de REEMPLAZO de descuento.");
    $ids_afectados_por_cupon = array_column($resultado_validacion['data']['productos_afectados'], 'id');
    
    foreach ($productos_finales as $item) {
        if (in_array($item['offerId'], $ids_afectados_por_cupon)) {
            // "Devolvemos" el descuento manual que ya estaba incluido en '$total_con_ofertas'.
            $descuento_manual_del_item = ((float)$item['price_base'] * (int)$item['quantity']) - ((float)$item['price'] * (int)$item['quantity']);
            $total_servidor_final += $descuento_manual_del_item;
            debug_log("Ajuste para '{$item['name']}': se devuelven S/{$descuento_manual_del_item} de descuento manual.");
        }
    }
    // Finalmente, restamos el descuento del cupón (que se calculó sobre el precio base).
    $total_servidor_final -= $descuento_del_cupon;
}

$descuento_servidor = $subtotal_servidor_base - $total_servidor_final;

// Redondeamos para evitar problemas de precisión en la BD
$subtotal_servidor_base = round($subtotal_servidor_base, 2);
$descuento_servidor = round($descuento_servidor, 2);
$total_servidor_final = round($total_servidor_final, 2);

debug_log("Cálculos finales y unificados: Subtotal Base=S/{$subtotal_servidor_base}, Descuento Total=S/{$descuento_servidor}, Total Final=S/{$total_servidor_final}");

// ================================================================================================
// === FIN DEL BLOQUE DE REEMPLAZO ================================================================
// ================================================================================================

// AHORA LAS VARIABLES ESTÁN LISTAS Y SON CORRECTAS PARA EL SIGUIENTE PASO DE GUARDADO EN BD
// ================================================================================================
// --- FIN DEL BLOQUE DE CÁLCULO ---
// ================================================================================================

// ==============================================================================
// === LÓGICA DE CANJE DE MONEDAS (BACKEND) =====================================
// ==============================================================================
$coins_redeemed = 0;
$coins_money_value = 0.00;

// Obtenemos la cantidad solicitada desde el JS (explicit_coins) o 0
$coins_requested = isset($data['explicit_coins']) ? (int)$data['explicit_coins'] : 0;

if ($coins_requested > 0) {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in_client']) || $_SESSION['logged_in_client'] !== true) {
        throw new Exception("Debes iniciar sesión para canjear monedas.");
    }

    $uid_check_coins = (int)$_SESSION['user_id'];
    if (!banano_client_session_is_valid($conn, $uid_check_coins)) {
        throw new Exception("Sesión expirada.");
    }

    // --- NUEVO: VALIDACIÓN DE RESTRICCIONES DE PRODUCTOS ---
    foreach ($productos_finales as $prod) {
        // Solo verificamos productos estáticos, los de la API siempre permiten canje (por defecto)
        if (!empty($prod['is_static'])) {
            $stmt_r = $conn->prepare("SELECT permitir_canje_monedas, nombre FROM productos_estaticos WHERE id = ?");
            $prod_id = (int)($prod['offerId'] ?? 0); // Asegúrate que offerId es el ID numérico
            $stmt_r->bind_param('i', $prod_id);
            $stmt_r->execute();
            $res_r = $stmt_r->get_result();
            if ($row_r = $res_r->fetch_assoc()) {
                if ($row_r['permitir_canje_monedas'] == 0) {
                    // SI ENCUENTRA UN PRODUCTO PROHIBIDO, DETIENE TODO EL PEDIDO
                    throw new Exception("El producto '{$row_r['nombre']}' no se puede pagar con monedas.");
                }
            }
            $stmt_r->close();
        }
    }
    // -------------------------------------------------------
    $uid = (int)$_SESSION['user_id'];

    // 1. Obtener reglas y saldo fresco de la BD
    $sql_c = "SELECT u.balance, s1.value as min, s2.value as step, s3.value as unit_c, s4.value as unit_m 
              FROM coins u
              JOIN site_settings s1 ON s1.key_name = 'redeem_min_coins'
              JOIN site_settings s2 ON s2.key_name = 'redeem_step_coins'
              JOIN site_settings s3 ON s3.key_name = 'coins_unit_coins'
              JOIN site_settings s4 ON s4.key_name = 'coins_unit_money'
              WHERE u.user_id = ?";
    
    $stmt_c = $conn->prepare($sql_c);
    $stmt_c->bind_param('i', $uid);
    $stmt_c->execute();
    $res_c = $stmt_c->get_result();
    $c_data = $res_c->fetch_assoc();
    $stmt_c->close();

    if ($c_data) {
        $balance = (float)$c_data['balance'];
        $min = (int)$c_data['min'];
        $step = (int)$c_data['step'];
        $unit_coins = (int)$c_data['unit_c'];
        $unit_money = (float)$c_data['unit_m'];

        // 2. Validaciones de Seguridad
        if ($balance >= $min && $coins_requested <= $balance && $coins_requested >= $min) {
            // Verificar si es múltiplo del step (o aproximado)
            if ($coins_requested % $step == 0) {
                
                // 3. Calcular Descuento Monetario
                $coins_redeemed = $coins_requested;
                $coins_money_value = round(($coins_redeemed * $unit_money) / $unit_coins, 2);

                // 4. Aplicar Descuento al Total
                $total_servidor_final -= $coins_money_value;
                
                // Evitar totales negativos
                if ($total_servidor_final < 0) {
                    $total_servidor_final = 0;
                    // Opcional: Ajustar monedas gastadas para que solo cubran el total exacto (más complejo)
                }
                
                debug_log("Canje de Monedas Aprobado: $coins_redeemed coins = S/ $coins_money_value");
            } else {
                debug_log("Error Monedas: Cantidad $coins_requested no respeta el paso de $step.");
            }
        } else {
            debug_log("Error Monedas: Balance insuficiente o mínimo no alcanzado.");
        }
    }
}
// ==============================================================================

// --- PASO 4, 5 y 6: GUARDADO EN BASE DE DATOS ---

// --- UTC-FIRST (estándar empresarial) ---
// A partir de aquí la conexión escribe SIEMPRE en UTC puro. Pinneamos el time_zone
// de la sesión MySQL a UTC para que, sin importar si las columnas de fecha son DATETIME
// o TIMESTAMP (MySQL convierte las TIMESTAMP según el huso de la sesión al insertar/leer),
// UTC_TIMESTAMP()/gmdate() se almacenen como UTC real y NO en la hora local del servidor (ET/UTC-4).
// Se ejecuta DESPUÉS de validar el cupón (~línea 380) para no alterar sus comparaciones con NOW().
$conn->query("SET time_zone = '+00:00'");
date_default_timezone_set('UTC');

// (La lógica para generar código de pedido y tiempos de reserva se mantiene)
$codigo_pedido = "B-" . substr(time(), -6);

// ============================================================================
// === RESERVA INTELIGENTE: tiempo estándar + expiración por rotación de tienda ===
// ============================================================================
// Regla general: damos N minutos de reserva para pagar (ajustable en backend,
// `tiempo_reserva_normal_min`; en alta demanda, `tiempo_reserva_alta_demanda_min`).
// PERO si el caché maestro indica que algún ítem rotará y desaparecerá de la
// tienda antes de que venza esa reserva, el límite real debe acortarse a esa
// expiración. Aquí guardamos AMBOS datos en `pedidos`:
//   - fecha_reserva_expira  = creación + reserva estándar.
//   - fecha_expiracion_oferta = rotación EFECTIVA más cercana entre los ítems.
// El "min(reserva, oferta)" se resuelve en lectura (confirmation.php,
// check_order_status.php y la limpieza de api_pedidos.php), igual que ya hacía
// la expiración de oferta; así no duplicamos la lógica de capado.
$cfg_reserva = [];
if ($res_cfg_r = $conn->query("SELECT clave, valor FROM configuracion_tienda WHERE clave IN ('modo_tienda','tiempo_reserva_normal_min','tiempo_reserva_alta_demanda_min')")) {
    while ($r = $res_cfg_r->fetch_assoc()) { $cfg_reserva[$r['clave']] = $r['valor']; }
    $res_cfg_r->free();
}
$tiempo_reserva_min = (($cfg_reserva['modo_tienda'] ?? 'normal') === 'alta_demanda')
    ? (int)($cfg_reserva['tiempo_reserva_alta_demanda_min'] ?? 30)
    : (int)($cfg_reserva['tiempo_reserva_normal_min'] ?? 240);
if ($tiempo_reserva_min <= 0) { $tiempo_reserva_min = 240; }

// Rotación EFECTIVA más cercana entre los ítems del carrito. `expires_at_timestamp`
// ya viene del caché maestro (offerDates.out vía ShopRepository) menos el colchón
// de seguridad, calculado al añadir al carrito en cart_manager.php.
$soonest_item_expiry_ts = null;
foreach ($productos_finales as $pf) {
    if (isset($pf['expires_at_timestamp']) && $pf['expires_at_timestamp'] !== null) {
        $ts = (int)$pf['expires_at_timestamp'];
        if ($ts > 0 && ($soonest_item_expiry_ts === null || $ts < $soonest_item_expiry_ts)) {
            $soonest_item_expiry_ts = $ts;
        }
    }
}
$fecha_expiracion_oferta_db = ($soonest_item_expiry_ts !== null)
    ? gmdate('Y-m-d H:i:s', $soonest_item_expiry_ts)
    : null;
debug_log("Reserva inteligente: reserva_min={$tiempo_reserva_min}, expiracion_oferta=" . ($fecha_expiracion_oferta_db ?? 'NULL'));
// ============================================================================

if ($total_servidor_final <= 0) {
    $status_pedido = 'pagado';
    $fecha_pago_pedido = gmdate('Y-m-d H:i:s');
} else {
    $status_pedido = 'pendiente';
    $fecha_pago_pedido = null;
}

// --- CÁLCULO DE CASHBACK GANADO (EN MONEDAS) ---
$cashback_earned = 0.00;
if (($cashback_mode === 'base' || $cashback_mode === 'verified') && $total_servidor_final > 0) {
    // 1. Obtener porcentaje de cashback desde site_settings
    //    - cashback_percentage_verified (5%): verificado + modo 'self'
    //    - cashback_base (2%): resto de usuarios logueados (friend, multi, no verificado, ...)
    $cashback_setting_key = $cashback_use_verified_rate ? 'cashback_percentage_verified' : 'cashback_base';
    $stmt_cb_rate = $conn->prepare("SELECT value FROM site_settings WHERE key_name = ? LIMIT 1");
    if ($stmt_cb_rate) {
        $stmt_cb_rate->bind_param('s', $cashback_setting_key);
        $stmt_cb_rate->execute();
        $res_cb_rate = $stmt_cb_rate->get_result();
        if ($row_cb_rate = $res_cb_rate->fetch_assoc()) {
            $cashback_rate = (float)$row_cb_rate['value'];
            $cashback_dinero = round($total_servidor_final * $cashback_rate, 2);

            // 2. Convertir de dinero a monedas usando la tasa de configuracion_tienda
            $stmt_tasa = $conn->prepare("SELECT valor FROM configuracion_tienda WHERE clave = 'precio_por_100_pavos' LIMIT 1");
            if ($stmt_tasa) {
                $stmt_tasa->execute();
                $res_tasa = $stmt_tasa->get_result();
                if ($row_tasa = $res_tasa->fetch_assoc()) {
                    $precio_por_100 = (float)$row_tasa['valor'];
                    if ($precio_por_100 > 0) {
                        $cashback_earned = round(($cashback_dinero / $precio_por_100) * 100, 2);
                    }
                }
                $stmt_tasa->close();
            }
        }
        $stmt_cb_rate->close();
    }
    debug_log("Cashback: S/$cashback_dinero -> $cashback_earned monedas (rate: " . ($cashback_rate ?? 0) . ", tasa: S/" . ($precio_por_100 ?? 0) . "/100 monedas)");
}

// Insertar pedido principal (CON MONEDAS Y CASHBACK)
$stmtPedido = $conn->prepare(
    "INSERT INTO pedidos (
        codigo_pedido, email_cliente, whatsapp_discord_cliente, nickname_fortnite_cliente, 
        subtotal, monto_descuento, total_pagar, cupon_usado_codigo, metodo_pago, 
        ip_cliente, fecha_creacion, fecha_reserva_expira, fecha_expiracion_oferta,
        user_id, recipient_mode, counts_toward_verification, cashback_mode, cashback_earned,
        coins_redeemed, coins_value_money, estado, fecha_pago, es_reserva  
     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL ? MINUTE, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
 
 $stmtPedido->bind_param(
     'ssssdddsssisisisdidssi',
     $codigo_pedido, $email_cliente, $whatsapp_cliente, $nickname_principal, 
     $subtotal_servidor_base, $descuento_servidor, $total_servidor_final, 
     $codigo_cupon_cliente, $metodo_pago, $ip_cliente, 
     $tiempo_reserva_min, $fecha_expiracion_oferta_db,
     $user_id_db, $recipient_mode, $counts_toward_verification, $cashback_mode, $cashback_earned,
     $coins_redeemed, $coins_money_value, 
     $status_pedido, $fecha_pago_pedido, $es_reserva
 );
$stmtPedido->execute();
$pedido_id = $stmtPedido->insert_id;
$stmtPedido->close();
debug_log("Pedido principal insertado con ID: {$pedido_id}");

// Insertar detalles del pedido con precios base y final
debug_log("Insertando detalles del pedido con precios seguros...");
$recipients_map = array_column($recipients, null, 'id');

// CONSULTA PREPARADA COMPLETA Y CORRECTA
$stmtDetalles = $conn->prepare(
    "INSERT INTO detalles_pedido (pedido_id, producto_id_str, nombre_producto, imagen_producto_url, precio_base, precio_final, cantidad) 
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);
$stmtDestinatarios = $conn->prepare(
    "INSERT INTO detalles_pedido_destinatarios (detalle_pedido_id, nickname_destinatario) 
     VALUES (?, ?)"
);

// --- INICIO DEL BLOQUE DE GUARDADO EN BD CON DEPURACIÓN TOTAL ---
debug_log("PASO DE GUARDADO: Iniciando bucle para " . count($productos_finales) . " productos.");

// 1. Creamos un mapa de los productos con descuento a partir de la respuesta del "Cerebro Visual" para ver qué nos dice.
$productos_afectados_map = [];
if (isset($resultado_validacion['data']['productos_afectados']) && is_array($resultado_validacion['data']['productos_afectados'])) {
    $productos_afectados_map = array_column($resultado_validacion['data']['productos_afectados'], null, 'id');
    debug_log("GUARDADO: Mapa de productos afectados (del Cerebro Visual) creado: " . print_r($productos_afectados_map, true));
} else {
    debug_log("GUARDADO: No se encontraron 'productos_afectados' en la respuesta del Cerebro Visual o no es un array.");
}

// 2. Preparamos las consultas (sin cambios)
$stmtDetalles = $conn->prepare(
    "INSERT INTO detalles_pedido (pedido_id, producto_id_str, nombre_producto, imagen_producto_url, precio_base, precio_final, cantidad) 
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);
$stmtDestinatarios = $conn->prepare(
    "INSERT INTO detalles_pedido_destinatarios (detalle_pedido_id, nickname_destinatario) 
     VALUES (?, ?)"
);
$recipients_map = array_column($recipients, null, 'id');

// ================================================================================================
// === INICIO: REEMPLAZAR ESTE BLOQUE COMPLETO EN process.php =====================================
// ================================================================================================

// ================================================================================================
// === INICIO: REEMPLAZAR ESTE BLOQUE COMPLETO EN process.php (VERSIÓN A PRUEBA DE BALAS) ===
// ================================================================================================

// --- PASO FINAL: GUARDADO EN BASE DE DATOS (BLINDADO CONTRA FLOATS Y TIPOS DE BD) ---

// 1. Creamos un mapa con los precios finales correctos a partir de la validación del cupón.
$precios_finales_map = [];

// 2. Preparamos las consultas
$stmtDetalles = $conn->prepare(
    "INSERT INTO detalles_pedido (pedido_id, producto_id_str, nombre_producto, imagen_producto_url, precio_base, precio_final, cantidad, tipo_producto, section_name, store_rank, v_bucks, color_1, color_2, color_3, fecha_expiracion_item) 
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
$stmtDestinatarios = $conn->prepare(
    "INSERT INTO detalles_pedido_destinatarios (detalle_pedido_id, nickname_destinatario) 
     VALUES (?, ?)"
);

$recipients_map = array_column($data['recipients'] ?? [], null, 'id');

function normalizeColorHex8($value): ?string {
    if (!is_string($value)) {
        return null;
    }

    $value = strtolower(trim($value));
    $value = ltrim($value, '#');

    if ($value === '') {
        return null;
    }

    return preg_match('/^[0-9a-f]{8}$/', $value) === 1 ? $value : null;
}

// 3. Iteramos sobre los productos para guardarlos con el precio correcto y formateado.
foreach ($productos_finales as $product) {
    // Extracción de datos seguros del producto
    $product_id_str = htmlspecialchars($product['offerId'] ?? '', ENT_QUOTES, 'UTF-8');
    $product_name = htmlspecialchars($product['name'] ?? '', ENT_QUOTES, 'UTF-8');
    $product_image = htmlspecialchars($product['image'] ?? '', ENT_QUOTES, 'UTF-8');
    
    // --- LÓGICA DE PRECIO FINAL INDIVIDUAL (CON FORMATEO A STRING) ---
    $product_price_base = (float)($product['price_base'] ?? $product['price']);
    $precio_final_unitario_float = (float)$product['price'];
    
    if (isset($precios_finales_map[$product_id_str])) {
        $precio_final_unitario_float = (float)$precios_finales_map[$product_id_str];
    }
    
    // ¡CORRECCIÓN CLAVE! Convertimos los precios a un string con 2 decimales.
    $precio_base_string = number_format($product_price_base, 2, '.', '');
    $precio_final_string = number_format($precio_final_unitario_float, 2, '.', '');
    // --- FIN DE LA LÓGICA DE PRECIOS ---

    $product_type = $product['type'] ?? 'General';
    $product_quantity = (int)($product['quantity'] ?? 1);

    $sectionNameRaw = $product['section_name'] ?? 'General';
    $sectionNameRaw = is_string($sectionNameRaw) ? trim($sectionNameRaw) : 'General';
    $sectionName = $sectionNameRaw !== '' ? $sectionNameRaw : 'General';
    $sectionName = htmlspecialchars($sectionName, ENT_QUOTES, 'UTF-8');
    $storeRank = isset($product['store_rank']) ? (int)$product['store_rank'] : 0;

    $vbucks = (int)($product['vbucks'] ?? 0);
    $colors = isset($product['colors']) && is_array($product['colors']) ? $product['colors'] : [];
    $color1 = normalizeColorHex8($colors['color1'] ?? null);
    $color2 = normalizeColorHex8($colors['color2'] ?? null);
    $color3 = normalizeColorHex8($colors['color3'] ?? null);

    // Expiración EFECTIVA del ítem (rotación de tienda - colchón), congelada al
    // momento de la compra desde el caché maestro. La usa el panel admin y la
    // reserva inteligente. NULL para ítems sin fecha de rotación (p. ej. estáticos
    // sin oferta temporal).
    $fechaExpiracionItem = null;
    if (isset($product['expires_at_timestamp']) && $product['expires_at_timestamp'] !== null) {
        $ts_item = (int)$product['expires_at_timestamp'];
        if ($ts_item > 0) {
            $fechaExpiracionItem = gmdate('Y-m-d H:i:s', $ts_item);
        }
    }

    for ($i = 0; $i < $product_quantity; $i++) {
        $cantidad_unitaria = 1;

        // ¡CORRECCIÓN CLAVE! Cambiamos los tipos de 'd' (double) a 's' (string) para los precios.
        $stmtDetalles->bind_param(
            'isssssissiissss',
            $pedido_id,
            $product_id_str,
            $product_name,
            $product_image,
            $precio_base_string, // Enviamos el string "18.00"
            $precio_final_string, // Enviamos el string "16.20"
            $cantidad_unitaria,
            $product_type,
            $sectionName,
            $storeRank,
            $vbucks,
            $color1,
            $color2,
            $color3,
            $fechaExpiracionItem
        );
        if (!$stmtDetalles->execute()) {
            throw new Exception("Error al guardar detalle: " . $stmtDetalles->error);
        }
        $detalle_id = $stmtDetalles->insert_id;
        
// --- INSERTA ESTE BLOQUE EN SU LUGAR ---

// Lógica de destinatarios (simple, eficiente y correcta)
if (isset($product['assignments'][$i])) {
    $recipient_id = $product['assignments'][$i];
    if (isset($recipients_map[$recipient_id]) && !empty($recipients_map[$recipient_id]['name'])) {
        $recipient_name = htmlspecialchars($recipients_map[$recipient_id]['name'], ENT_QUOTES, 'UTF-8');
        $stmtDestinatarios->bind_param('is', $detalle_id, $recipient_name);
        if (!$stmtDestinatarios->execute()) {
            throw new Exception("Error al guardar destinatario: " . $stmtDestinatarios->error);
        }
    }
}

// --- FIN DEL BLOQUE A INSERTAR ---
    }
}

$stmtDetalles->close();
$stmtDestinatarios->close();

// ================================================================================================
// === FIN DEL BLOQUE DE REEMPLAZO ================================================================
// ================================================================================================


    // --- PASO 7: REGISTRAR EL USO DEL CUPÓN (SI APLICA) ---
    if ($cupon_id_para_registrar) {
        debug_log("Registrando uso del cupón ID: {$cupon_id_para_registrar}");
        $conn->query("UPDATE cupones SET usos_actuales = usos_actuales + 1 WHERE id = $cupon_id_para_registrar");
        $stmt_track = $conn->prepare("INSERT INTO cupones_usos_tracking (cupon_id, pedido_id, ip_usuario, whatsapp_usuario, user_id) VALUES (?, ?, ?, ?, ?)");
        $stmt_track->bind_param("iissi", $cupon_id_para_registrar, $pedido_id, $ip_cliente, $whatsapp_cliente, $user_id_db);
        $stmt_track->execute();
        $stmt_track->close();
        $stmt_track_dest = $conn->prepare("INSERT INTO cupones_usos_destinatario (cupon_id, destinatario_epic_id, pedido_id) VALUES (?, ?, ?)");
        $destinatarios_ya_registrados = [];
        foreach ($recipients as $recipient) {
            if (!empty($recipient['name'])) {
                $nombre_destinatario = strtolower(trim($recipient['name']));
                if (!in_array($nombre_destinatario, $destinatarios_ya_registrados)) {
                    $stmt_track_dest->bind_param("isi", $cupon_id_para_registrar, $nombre_destinatario, $pedido_id);
                    $stmt_track_dest->execute();
                    $destinatarios_ya_registrados[] = $nombre_destinatario;
                }
            }
        }
        $stmt_track_dest->close();
        debug_log("Tracking completo para el cupón registrado.");
    }

// --- PASO EXTRA: DESCONTAR MONEDAS ---
    if ($coins_redeemed > 0 && $user_id_db) {
        // 1. Insertar transacción de gasto (negativa)
        $stmt_spend = $conn->prepare("INSERT INTO coin_transactions (user_id, amount, reason, reference_type, reference_id) VALUES (?, ?, 'spend', 'order_id', ?)");
        $amount_negative = -1 * abs($coins_redeemed); // Asegurar negativo
        $stmt_spend->bind_param('idi', $user_id_db, $amount_negative, $pedido_id);
        $stmt_spend->execute();
        $stmt_spend->close();

        // 2. Actualizar saldo
        $stmt_upd_coin = $conn->prepare("UPDATE coins SET balance = balance - ? WHERE user_id = ?");
        $stmt_upd_coin->bind_param('di', $coins_redeemed, $user_id_db);
        $stmt_upd_coin->execute();
        $stmt_upd_coin->close();
        
        debug_log("Monedas descontadas: $coins_redeemed");
    }

    // --- PASO 8: CONFIRMAR TRANSACCIÓN Y LIMPIAR ---
$conn->commit();
debug_log("Transacción confirmada (commit).");
// ... después de $conn->commit();

// --- PASO EXTRA: QUEMAR DESCUENTOS SPIN USADOS ---
if (!empty($discount_ids_to_burn)) {
    // Convertimos el array de IDs a una lista separada por comas segura (ej: "1, 5, 9")
    $ids_string = implode(',', array_map('intval', $discount_ids_to_burn));
    
    // Actualizamos el estado a 'used' y ponemos la fecha de uso (UTC, como el resto de la tabla)
    $sql_burn = "UPDATE daily_discounts
                 SET status = 'used', used_at = UTC_TIMESTAMP()
                 WHERE id IN ($ids_string) AND status = 'active'";
                 
    if ($conn->query($sql_burn)) {
        debug_log("Descuentos Spin quemados con éxito: IDs [$ids_string]");
    } else {
        debug_log("Error al quemar descuentos Spin: " . $conn->error);
    }
}

// ... antes de unset($_SESSION['cart']);

// --- ¡AQUÍ ESTÁ EL CAMBIO! ---
// Eliminamos específicamente el carrito de la sesión. Es más seguro que session_destroy().
if (isset($_SESSION['cart'])) {
    unset($_SESSION['cart']);
    debug_log("Sesión del carrito limpiada con unset().");
}

// --- PASO 9: RESPUESTA DE ÉXITO ---
debug_log("¡ÉXITO! El pedido se guardó. Devolviendo respuesta JSON.");
responder_json(true, 'Pedido creado con éxito.', ['order_code' => $codigo_pedido]);

} catch (Exception $e) {
    $conn->rollback();
    debug_log("¡ERROR EN EL PROCESO! Se hizo rollback. Mensaje: " . $e->getMessage());
    responder_json(false, $e->getMessage());
}
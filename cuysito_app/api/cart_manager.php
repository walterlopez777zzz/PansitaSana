<?php
/**
 * API para la Gestión del Carrito de Compras (cart_manager.php)
 * Versión: 6.5 (Final, limpia y funcional)
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/session_security.php';
header('Content-Type: application/json');

define('ROOT_PATH', dirname(__DIR__));
define('MAX_QUANTITY_PER_ITEM', 3); 
require_once ROOT_PATH . '/api/db_connect.php';
require_once ROOT_PATH . '/api/logic_discounts.php'; // Importar el Cerebro de Descuentos
require_once ROOT_PATH . '/config/shop_config.php';
require_once ROOT_PATH . '/core/ShopRepository.php';
require_once ROOT_PATH . '/core/ShopCardPresentation.php';

/**
 * Fondo plano de las tarjetas Vía Cuenta por categoría (app.js
 * renderViaCuentaCatalog → seccionesConfig.color) en el formato RRGGBBAA de
 * `colors.color1`, para que carrito y checkout pinten el producto estático
 * igual que un ítem de la API.
 */
const STATIC_CARD_COLORS = [
    'Pavos' => '1e40afff',
    'Club'  => '553c9aff',
    'Pases' => 'f59e0bff',
    'Packs' => '3a3a3eff',
];

$safety_buffer_minutes = 5;
$sql_config = "SELECT valor FROM configuracion_tienda WHERE clave = 'tiempo_reserva_seguridad_min' LIMIT 1";
$result_config = $conn->query($sql_config);
if ($result_config && $result_config->num_rows > 0) {
    $safety_buffer_minutes = (int)$result_config->fetch_assoc()['valor'];
}
// Snapshot canónico interno (banano_primordial.json) con failover automático
// al fallback diario según el modo de fuente configurado.
$api_cache_file = ShopSourceConfig::activeSnapshotPath() ?? ShopSourceConfig::liveSnapshotPath();


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

/**
 * Instantánea completa del carrito. Toda respuesta exitosa la incluye para que
 * el cliente reemplace su estado sin rondas adicionales al servidor.
 */
function cartSnapshot(): array {
    $cart_items = array_values($_SESSION['cart'] ?? []);
    $subtotal = 0.0;
    foreach ($cart_items as $item) {
        $subtotal += (float)($item['price'] ?? 0) * (int)($item['quantity'] ?? 0);
    }
    return [
        'success'               => true,
        'cart_items'            => $cart_items,
        'item_count'            => count($cart_items),
        'subtotal'              => round($subtotal, 2),
        'max_quantity_per_item' => MAX_QUANTITY_PER_ITEM,
    ];
}

function sendCartSnapshot(string $message): void {
    sendJsonResponse(cartSnapshot() + ['message' => $message]);
}

function sendMaxQuantityError(): void {
    sendJsonResponse([
        'success'               => false,
        'code'                  => 'MAX_QUANTITY',
        'message'               => 'El límite por producto es de ' . MAX_QUANTITY_PER_ITEM . ' unidades.',
        'max_quantity_per_item' => MAX_QUANTITY_PER_ITEM,
    ], 409);
}

function findStaticProduct(string $productId, mysqli $db_connection): ?array {
    $sql = "SELECT id, nombre, precio_base, precio_oferta, oferta_fecha_expiracion, imagen_url, categoria FROM productos_estaticos WHERE id = ? LIMIT 1";
    $stmt = $db_connection->prepare($sql);
    $stmt->bind_param("s", $productId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($product = $result->fetch_assoc()) {
        $precioBase = (float)$product['precio_base'];
        $precioOferta = (float)$product['precio_oferta'];
        $esOfertaValida = $precioOferta > 0 && $precioOferta < $precioBase;

        if ($esOfertaValida && $product['oferta_fecha_expiracion']) {
            try {
                if (new DateTime() > new DateTime($product['oferta_fecha_expiracion'])) {
                    $esOfertaValida = false;
                }
            } catch (Exception $e) { $esOfertaValida = false; }
        }
        
        $precioFinal = $esOfertaValida ? $precioOferta : $precioBase;
        $cardColor = STATIC_CARD_COLORS[$product['categoria'] ?? ''] ?? null;

        return [
            'offerId'         => $product['id'],
            'name'            => $product['nombre'],
            'price_base'      => (float)$precioBase,
            'price_offer'     => $esOfertaValida ? (float)$product['precio_oferta'] : 0,
            'has_offer'       => $esOfertaValida,
            'final_price'     => $precioFinal,
            'image'           => $product['imagen_url'],
            'expiration_date' => $esOfertaValida ? $product['oferta_fecha_expiracion'] : null,
            'is_static'       => true,
            'rarity'          => null,
            'type'            => $product['categoria'] ?? 'Producto',
            'vbucks'          => 0,
            'colors'          => $cardColor === null ? null : ['color1' => $cardColor],
            'card'            => [
                'item_type'         => null,
                'item_id'           => null,
                'is_car_offer'      => false,
                'is_dynamic_bundle' => false,
                'image'             => $product['imagen_url'],
            ],
            'section_name'    => 'General',
            'store_rank'      => 0
        ];
    }
    return null;
}

function findApiProductInCache(string $offerId, string $cache_path): ?array {
    // Resolvemos el ítem a través del DAL (ShopRepository), que aplica los
    // overrides del Gestor de Capa de Datos (nombre/tipo/precio/rareza/colores)
    // incluyendo la inyección profunda. Así el nombre/categoría modificados
    // (caso "Pablito") fluyen al carrito y, de ahí, a todo el checkout. El
    // $cache_path se mantiene en la firma por compatibilidad pero ya no se lee
    // directamente, para no saltarse la capa de overrides.
    $item = ShopRepository::findByOfferId($offerId);
    if ($item === null) return null;

    $final_price = ($item['tiene_oferta_nuestra'] ?? false) ? $item['precio_oferta_soles'] : $item['precio_base_soles'];

    return [
        'offerId'         => $item['offerId'],
        'name'            => $item['displayName'],
        'price_base'      => (float)($item['precio_base_soles'] ?? 0),
        'price_offer'     => (float)($item['precio_oferta_soles'] ?? 0),
        'has_offer'       => (bool)($item['tiene_oferta_nuestra'] ?? false),
        'final_price'     => (float)$final_price,
        'image'           => $item['displayAssets'][0]['url'] ?? 'url_por_defecto.png',
        'expiration_date' => $item['offerDates']['out'] ?? null,
        'is_static'       => false,
        'rarity'          => $item['rarity']['id'] ?? null,
        'type'            => $item['displayType'] ?? null,
        'vbucks'          => $item['vbucks'] ?? 0,
        'colors'          => $item['colors'] ?? null,
        'card'            => ShopCardPresentation::fromEntry($item),
        'section_name'    => $item['section_name'] ?? null,
        'store_rank'      => isset($item['store_rank']) ? (int)$item['store_rank'] : 0
    ];
}

function findProductDetails(string $offerId, mysqli $conn, string $cache_path): ?array {
    $product_details = findStaticProduct($offerId, $conn);
    if (!$product_details) {
        $product_details = findApiProductInCache($offerId, $cache_path);
    }
    return $product_details;
}

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$action = $_GET['action'] ?? '';

// --- SUB-ACTION OBFUSCATION ---
// Maps short codes → real sub-action names.
// Both raw names AND codes are accepted (backward compatible).
$sub_obfuscation_map = [
    'b1' => 'buy_now',
    'a2' => 'add',
    'g3' => 'get',
    'r4' => 'remove',
    'q5' => 'update_quantity',
];
if (isset($sub_obfuscation_map[$action])) {
    $action = $sub_obfuscation_map[$action];
}

try {
    switch ($action) {
        case 'buy_now':
            $_SESSION['cart'] = [];
        
        case 'add':
            $offerId = $_POST['offerId'] ?? '';
            $quantity_to_add = (int)($_POST['quantity'] ?? 1);

            if (empty($offerId) || $quantity_to_add <= 0) {
                sendJsonResponse(['success' => false, 'message' => 'Faltan datos (ID o cantidad).'], 400);
            }

            // Validamos que la cantidad no exceda el máximo permitido
            $current_quantity = $_SESSION['cart'][$offerId]['quantity'] ?? 0;
            $new_quantity = $current_quantity + $quantity_to_add;

            if ($new_quantity > MAX_QUANTITY_PER_ITEM) {
                sendMaxQuantityError();
            }

            $product_details = findProductDetails($offerId, $conn, $api_cache_file);
            // --- INICIO: INTEGRACIÓN DAILY SPIN ---
            // Si el usuario está logueado, verificamos si tiene un descuento personal activo
            if ($product_details && isset($_SESSION['user_id'])) {
                $spin_discount = get_active_discount($conn, $_SESSION['user_id'], $offerId);
                
                if ($spin_discount) {
                    // Calculamos el nuevo precio usando la función del cerebro
                    $nuevo_precio = calculate_final_price($product_details['price_base'], $spin_discount['discount_percent']);
                    
                    // Sobreescribimos el precio final y marcamos que tiene oferta
                    $product_details['final_price'] = $nuevo_precio;
                    $product_details['price_offer'] = $nuevo_precio; // Para visualización
                    $product_details['has_offer']   = true;
                }
            }
            // --- FIN: INTEGRACIÓN DAILY SPIN ---

            if (!$product_details) {
                sendJsonResponse(['success' => false, 'message' => 'Producto no válido o no disponible.'], 404);
            }

            if (isset($_SESSION['cart'][$offerId])) {
                $_SESSION['cart'][$offerId]['quantity'] = $new_quantity;
            } else {
                $expiration_timestamp = null;
                if (!empty($product_details['expiration_date'])) {
                    $original_timestamp = strtotime($product_details['expiration_date']);
                    $expiration_timestamp = $original_timestamp - ($safety_buffer_minutes * 60);
                }
                
                $_SESSION['cart'][$offerId] = [
                    'offerId'              => $product_details['offerId'],
                    'name'                 => $product_details['name'],
                    'price'                => $product_details['final_price'],
                    'price_base'           => $product_details['price_base'],
                    'price_offer'          => $product_details['price_offer'],
                    'has_offer'            => $product_details['has_offer'],
                    'image'                => $product_details['image'],
                    'quantity'             => $new_quantity,
                    'expires_at_timestamp' => $expiration_timestamp,
                    'is_static'            => $product_details['is_static'],
                    'rarity'               => $product_details['rarity'] ?? null,
                    'type'                 => $product_details['type'] ?? null,
                    'vbucks'               => $product_details['vbucks'] ?? 0,
                    'colors'               => $product_details['colors'] ?? null,
                    'card'                 => $product_details['card'] ?? null,
                    'section_name'         => $product_details['section_name'] ?? null,
                    'store_rank'           => $product_details['store_rank'] ?? 0
                ];
            }
            sendCartSnapshot('Producto añadido.');
            break;

        case 'get':
            sendJsonResponse(cartSnapshot());
            break;

        case 'remove':
            $offerId = $_POST['offerId'] ?? '';
            if (empty($offerId)) {
                sendJsonResponse(['success' => false, 'message' => 'Falta el ID del producto.'], 400);
            }
            // Idempotente: eliminar una línea ausente deja el carrito en el estado deseado.
            unset($_SESSION['cart'][$offerId]);
            sendCartSnapshot('Producto eliminado.');
            break;
            
        case 'update_quantity':
            $offerId = $_POST['offerId'] ?? '';
            $quantity = (int)($_POST['quantity'] ?? 0);

            if (empty($offerId)) {
                sendJsonResponse(['success' => false, 'message' => 'Falta el ID del producto.'], 400);
            }

            if ($quantity > MAX_QUANTITY_PER_ITEM) {
                sendMaxQuantityError();
            }
            if ($quantity <= 0) {
                unset($_SESSION['cart'][$offerId]);
                sendCartSnapshot('Producto eliminado (cantidad 0).');
            }
            if (!isset($_SESSION['cart'][$offerId])) {
                sendJsonResponse(['success' => false, 'code' => 'NOT_FOUND', 'message' => 'El producto no está en el carrito.'], 404);
            }
            $_SESSION['cart'][$offerId]['quantity'] = $quantity;
            sendCartSnapshot('Cantidad actualizada.');
            break;

        default:
            sendJsonResponse(['success' => false, 'error' => "Acción no válida: '{$action}'."], 400);
            break;
    }
} catch (Exception $e) {
    error_log("Error fatal en cart_manager.php: " . $e->getMessage());
    sendJsonResponse(['success' => false, 'message' => 'Ha ocurrido un error interno en el servidor.'], 500);
}
<?php
// 1. Configuración de Errores (Lo primero para detectar fallos)
// Desactiva la visualización de errores para los usuarios
ini_set('display_errors', 0);
// Habilita el registro de errores en un archivo en el servidor
ini_set('log_errors', 1); 
// Reporta todos los errores para que se guarden en el log
error_reporting(E_ALL);

// 2. Seguridad de Sesión y Anti-Caché (CRÍTICO)
// Esto activa la sesión de 15 días, protege contra caché (LiteSpeed) y evita cruce de cuentas.
// Sube un nivel desde 'checkout' (..) y entra a 'api'
require_once __DIR__ . '/../api/session_security.php';

// 3. Iniciar Buffer de Salida
// Inicia el buffer después de configurar la seguridad
ob_start("minify_html"); 


/**
 * Banano Store - Checkout Dinámico v3.3 (Comisiones 100% configurables)
 */


// --- PASO 1: LEER LA CONFIGURACIÓN COMPLETA DE LA TIENDA ---
require_once dirname(__DIR__) . '/api/db_connect.php';
require_once dirname(__DIR__) . '/wabo/core/shop_helper.php'; // Incluimos el helper de la tienda

$config = [];
if (isset($conn) && $conn) {
    $sql = "SELECT clave, valor FROM configuracion_tienda";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $config[$row['clave']] = $row['valor'];
        }
    }
}

// --- PASO 2: FUNCIÓN AUXILIAR PARA CONSTRUIR TEXTO DE COMISIÓN ---
function buildFeeText($config, $id) {
    $fee = (float)($config["comision_{$id}_porcentaje"] ?? 0);
    $feeFixed = (float)($config["comision_{$id}_fija"] ?? 0);
    
    if ($fee > 0 && $feeFixed > 0) {
        return "{$fee}% + S/" . number_format($feeFixed, 2);
    } elseif ($fee > 0) {
        return "{$fee}% de comisión";
    } elseif ($feeFixed > 0) {
        return "S/" . number_format($feeFixed, 2) . " de comisión";
    }
    return 'Sin comisión';
}

// --- PASO 3: CONSTRUIR LA LISTA DE MÉTODOS DE PAGO HABILITADOS ---
$todos_los_metodos = [
    [ 
        'id' => 'yape', 'name' => 'Yape', 'category' => 'primary', 'borderColor' => 'border-purple-500', 
        'icon' => 'https://res.cloudinary.com/dri9gvpwm/image/upload/v1754242908/image_3_kdvxlm.png', 'config_key' => 'habilitar_yape_plin'
    ],
    [ 
        'id' => 'transfer', 'name' => 'Transferencia Bancaria', 'subtitle' => '(BCP, Interbank, etc.)', 'category' => 'primary', 'borderColor' => 'border-blue-600', 
        'icon' => 'https://res.cloudinary.com/dri9gvpwm/image/upload/v1754242866/image_2_f3hu0u.png', 'config_key' => 'habilitar_transferencia'
    ],
    [ 
        'id' => 'izipay', 'name' => 'Tarjeta con IziPay', 'category' => 'automatic', 'borderColor' => 'border-teal-500', 
        'icon' => 'https://res.cloudinary.com/dri9gvpwm/image/upload/v1754242821/image_1_rgcwvs.png', 'config_key' => 'habilitar_izipay'
    ],
    [ 
        'id' => 'paypal', 'name' => 'PayPal', 'category' => 'international', 'borderColor' => 'border-blue-500', 
        'icon' => 'https://res.cloudinary.com/dri9gvpwm/image/upload/v1754243004/pngwing.com_libljv.png', 'config_key' => 'habilitar_paypal'
    ],
    [ 
        'id' => 'crypto', 'name' => 'Cripto', 'category' => 'international', 'borderColor' => 'border-yellow-500', 
        'icon' => 'https://api.iconify.design/cryptocurrency-color/btc.svg', 'config_key' => 'habilitar_crypto'
    ],
];

$metodos_habilitados = [];
foreach ($todos_los_metodos as $metodo) {
    if (isset($config[$metodo['config_key']]) && $config[$metodo['config_key']] == 1) {
        $id = $metodo['id'];
        $metodo['fee'] = (float)($config["comision_{$id}_porcentaje"] ?? 0);
        $metodo['feeFixed'] = (float)($config["comision_{$id}_fija"] ?? 0);
        $metodo['feeText'] = buildFeeText($config, $id);
        
        // Controlar si se muestra como "Próximamente"
        if ($id === 'izipay' && isset($config['habilitar_izipay_proximamente']) && $config['habilitar_izipay_proximamente'] == 1) {
            $metodo['disabled'] = true;
            $metodo['feeText'] .= ' (Próximamente)';
        } else {
            $metodo['disabled'] = false;
        }

        $metodos_habilitados[] = $metodo;
    }
}

$payment_methods_json = json_encode(array_values($metodos_habilitados));

// --- PASO 4: OTRAS CONFIGURACIONES Y DATOS DEL CARRITO (MODIFICADO) ---
$mostrar_cupones = isset($config['habilitar_cupones']) ? ($config['habilitar_cupones'] == 1) : true;

// --- AÑADE ESTAS 3 LÍNEAS AQUÍ ---
$alerta_activa = isset($config['alerta_sin_regalos_activa']) && $config['alerta_sin_regalos_activa'] == '1';
$alerta_mensaje = $config['alerta_sin_regalos_mensaje'] ?? 'Hay un aviso importante sobre tu pedido.';
$alerta_mensaje_json = json_encode($alerta_mensaje);
// --- FIN DE LÍNEAS A AÑADIR ---

$cart_items = $_SESSION['cart'] ?? [];
$initial_products = [];

if (!empty($cart_items)) {
    // Se incluyen flags de tutorial / reserva para alimentar la cola de modales del checkout.
    $stmt_static = $conn->prepare("SELECT id, nombre, imagen_url, precio_base, precio_oferta, categoria,
        activar_tutorial, mensaje_tutorial, modo_reserva, mensaje_reserva
        FROM productos_estaticos WHERE id = ?");
    
    foreach ($cart_items as $item) {
        $is_static_product = isset($item['is_static']) && $item['is_static'] === true;
        $product_data = [];

        if ($is_static_product) {
            $static_product_id = $item['offerId'] ?? null;
            if ($static_product_id && $stmt_static) {
                $stmt_static->bind_param("i", $static_product_id);
                $stmt_static->execute();
                $result = $stmt_static->get_result();
                if ($row = $result->fetch_assoc()) {
                    $product_data = [
                        'id'        => $row['id'],
                        'name'      => $row['nombre'],
                        'img'       => $row['imagen_url'],
                        'type'      => $row['categoria'],
                        'is_static' => true,
                        'price'     => (float)($row['precio_oferta'] ?? $row['precio_base']),
                        'price_base'=> (float)$row['precio_base'],
                        'price_offer'=> (float)($row['precio_oferta'] ?? 0),
                        'has_offer' => !empty($row['precio_oferta']),
                        'rarity'    => null,
                        'vbucks'    => 0,
                        'colors'    => null,
                        'section_name' => 'General',
                        'store_rank' => 0,
                        'backgroundStyle' => generateProductBackgroundStyle(null),
                        'activar_tutorial' => (int)($row['activar_tutorial'] ?? 0) === 1,
                        'mensaje_tutorial' => $row['mensaje_tutorial'] ?? null,
                        'modo_reserva'     => (int)($row['modo_reserva'] ?? 0) === 1,
                        'mensaje_reserva'  => $row['mensaje_reserva'] ?? null
                    ];
                }
            }
        } else {
            // La lógica para productos de la API no cambia y funciona bien.
            $product_data = [
                'id'        => $item['offerId'] ?? uniqid(),
                'name'      => $item['name'] ?? 'Producto Desconocido',
                'img'       => $item['image'] ?? 'url_por_defecto.png',
                'type'      => $item['type'] ?? null,
                'is_static' => false,
                'price'     => (float)($item['price'] ?? 0),
                'price_base'=> (float)($item['price_base'] ?? $item['price'] ?? 0),
                'price_offer'=> (float)($item['price_offer'] ?? 0),
                'has_offer' => (bool)($item['has_offer'] ?? false),
                'rarity'    => $item['rarity'] ?? null,
                'vbucks'    => $item['vbucks'] ?? 0,
                'colors'    => $item['colors'] ?? null,
                'section_name' => $item['section_name'] ?? null,
                'store_rank' => isset($item['store_rank']) ? (int)$item['store_rank'] : 0,
                'backgroundStyle' => generateProductBackgroundStyle($item['colors'] ?? null),
                'activar_tutorial' => false,
                'mensaje_tutorial' => null,
                'modo_reserva'     => false,
                'mensaje_reserva'  => null
            ];
        }

        if (!empty($product_data)) {
            $product_data['quantity'] = (int)($item['quantity'] ?? 1);
            $product_data['assignments'] = [];
            $initial_products[] = $product_data;
        }
    }
    
    if ($stmt_static) { $stmt_static->close(); }
}
$initial_products_json = json_encode($initial_products);

// --- TUTORIALES PENDIENTES POR CATEGORÍA ---
// Una sola muestra por categoría aunque haya varios productos con tutorial activo.
$cart_tutorial_categories = [];
foreach ($initial_products as $p) {
    if (!empty($p['is_static']) && !empty($p['activar_tutorial']) && !empty($p['mensaje_tutorial'])) {
        $cat = (string)($p['type'] ?? '');
        if ($cat !== '' && !isset($cart_tutorial_categories[$cat])) {
            $cart_tutorial_categories[$cat] = (string)$p['mensaje_tutorial'];
        }
    }
}

// Para usuarios logueados, descartar las categorías ya vistas.
if (isset($_SESSION['user_id']) && !empty($cart_tutorial_categories)) {
    $uid_tut = (int)$_SESSION['user_id'];
    $cats_list = array_keys($cart_tutorial_categories);
    $placeholders = implode(',', array_fill(0, count($cats_list), '?'));
    $types_str = 'i' . str_repeat('s', count($cats_list));
    $sql_tut = "SELECT categoria FROM users_tutoriales_vistos WHERE user_id = ? AND categoria IN ($placeholders)";
    if ($stmt_t = $conn->prepare($sql_tut)) {
        $bind_args = array_merge([$uid_tut], $cats_list);
        $stmt_t->bind_param($types_str, ...$bind_args);
        $stmt_t->execute();
        $res_t = $stmt_t->get_result();
        while ($r = $res_t->fetch_assoc()) {
            unset($cart_tutorial_categories[$r['categoria']]);
        }
        $stmt_t->close();
    }
}

$tutoriales_pendientes = [];
foreach ($cart_tutorial_categories as $cat => $msg) {
    $tutoriales_pendientes[] = ['categoria' => $cat, 'mensaje' => $msg];
}
$tutoriales_pendientes_json = json_encode($tutoriales_pendientes);
$user_is_logged = isset($_SESSION['user_id']);


// ===================== INICIO DE LA NUEVA FUNCIÓN PHP =====================
/**
 * Genera la cadena de estilo CSS para el fondo de un producto.
 * Réplica de la lógica del worker.js.
 * @param array|null $colors El objeto de colores con 'color1', 'color2', 'color3'.
 * @return string La cadena de estilo CSS completa.
 */
function generateProductBackgroundStyle(?array $colors): string {
    if (!$colors) {
        return '';
    }

    $normalize = function ($value) {
        $raw = is_string($value) ? trim($value) : '';
        if ($raw === '') return null;
        $raw = ltrim($raw, '#');
        if (!preg_match('/^[0-9a-fA-F]{6,8}$/', $raw)) return null;
        return '#' . strtolower(substr($raw, 0, 6));
    };

    // Mismo orden que Cart/Worker: top=color1, bottom=fusión color3 -> color2
    $c1 = $normalize($colors['color1'] ?? null);
    $c2 = $normalize($colors['color3'] ?? null);
    $c3 = $normalize($colors['color2'] ?? null);

    $styles = [];
    if ($c1) $styles[] = '--fn-color-1: ' . $c1 . ';';
    if ($c2) $styles[] = '--fn-color-2: ' . $c2 . ';';
    if ($c3) $styles[] = '--fn-color-3: ' . $c3 . ';';

    return implode(' ', $styles);
}
// ====================== FIN DE LA NUEVA FUNCIÓN PHP =======================

// Render de un icono SVG del sprite (estilo moderno gamer/tech, mismo sistema que confirmation.php)
if (!function_exists('banano_icon')) {
    function banano_icon($name, $class = '') {
        $cls = trim('bi ' . $class);
        return '<svg class="' . htmlspecialchars($cls) . '" aria-hidden="true"><use href="#i-' . $name . '" xlink:href="#i-' . $name . '"></use></svg>';
    }
}

function minify_html($buffer) {
    // 1. Proteger el contenido de las etiquetas <script>
    $scripts = [];
    $buffer = preg_replace_callback(
        '/<script\b[^>]*>([\s\S]*?)<\/script>/i',
        function($matches) use (&$scripts) {
            // Guardamos el contenido del script completo, incluyendo las etiquetas
            $scripts[] = $matches[0];
            // Lo reemplazamos con un marcador de posición único
            return '<!-- SCRIPT_PLACEHOLDER_' . (count($scripts) - 1) . ' -->';
        },
        $buffer
    );

    // 2. Minificar el HTML (ahora seguro, porque no tiene JavaScript)
    $search = [
        '/\>[^\S ]+/s',     // Quita espacios en blanco después de las etiquetas
        '/[^\S ]+\</s',     // Quita espacios en blanco antes de las etiquetas
        '/(\s)+/s',         // Acorta múltiples espacios en blanco
        // Quita comentarios HTML, PERO IGNORA nuestros marcadores de posición
        '/<!--(?! SCRIPT_PLACEHOLDER_)(.|\s)*?-->/' 
    ];

    $replace = [
        '>',
        '<',
        '\\1',
        ''
    ];

    $buffer = preg_replace($search, $replace, $buffer);

    // 3. Restaurar el contenido de los scripts en su lugar
    if (!empty($scripts)) {
        $buffer = str_replace(
            array_map(function($i) { return '<!-- SCRIPT_PLACEHOLDER_' . $i . ' -->'; }, array_keys($scripts)),
            $scripts,
            $buffer
        );
    }

    return $buffer;
}
?>
 
 <!DOCTYPE html>
 <html lang="es">
 <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#0a0a0b">
    <title>Finalizar Compra - Banano Store</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.13/css/intlTelInput.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.13/js/intlTelInput.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" href="/assets/fonts/HeadingNowVariable.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow:ital,wght@0,400;0,700;1,700&display=swap">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'display': ['HeadingNowVariable', 'Barlow', 'sans-serif'],
                        'heading': ['HeadingNowVariable', 'Barlow', 'sans-serif'],
                        'body': ['HeadingNowVariable', 'Barlow', 'sans-serif'],
                        'num': ['HeadingNowVariable', 'Barlow', 'sans-serif']
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="/checkout/checkout.css">
</head>
 <body class="antialiased">
    <!-- ===================== SPRITE DE ICONOS SVG ===================== -->
    <svg width="0" height="0" class="hidden" aria-hidden="true" focusable="false">
        <defs>
            <symbol id="i-pavos" viewBox="0 0 24 24"><path fill-rule="evenodd" clip-rule="evenodd" fill="currentColor" d="M12 22.5C17.799 22.5 22.5 17.799 22.5 12C22.5 6.20101 17.799 1.5 12 1.5C6.20101 1.5 1.5 6.20101 1.5 12C1.5 17.799 6.20101 22.5 12 22.5ZM10.1051 7.5H6L9.31376 16.5H14.6306L18 7.5H13.8887L12.0896 13.7038L12.0031 14.0427H11.9784L11.9042 13.7038L10.1051 7.5Z"></path></symbol>
            <symbol id="i-bolt" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h9l-1 8 10-12h-9z"/></symbol>
            <symbol id="i-cart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h2.5l2.2 12.4a1.5 1.5 0 0 0 1.5 1.2h8.9a1.5 1.5 0 0 0 1.5-1.2L21 7H6"/></symbol>
            <symbol id="i-bag" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 7h12l1 13H5L6 7Z"/><path d="M9 7a3 3 0 0 1 6 0"/></symbol>
            <symbol id="i-receipt" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3v18l2-1.2L9 21l2-1.2L13 21l2-1.2L17 21l2-1.2V3l-2 1.2L15 3l-2 1.2L11 3 9 4.2 7 3 5 4.2Z"/><path d="M8 8h8"/><path d="M8 12h8"/><path d="M8 16h5"/></symbol>
            <symbol id="i-wallet" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></symbol>
            <symbol id="i-card" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2.5"/><path d="M2 10h20"/><path d="M6 15h4"/></symbol>
            <symbol id="i-users" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 5.2a3.2 3.2 0 0 1 0 6"/><path d="M18 14.4A6 6 0 0 1 21 20"/></symbol>
            <symbol id="i-user" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></symbol>
            <symbol id="i-whatsapp" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21l1.5-5A8.5 8.5 0 1 1 8 19.5L3 21Z"/><path d="M8.5 9.5c.3 2 2 3.7 4 4 .6.1 1.2-.1 1.4-.7l.3-.7-2-1-.6.7c-.8-.4-1.4-1-1.8-1.8l.7-.6-1-2-.7.3c-.5.2-.7.8-.6 1.5Z"/></symbol>
            <symbol id="i-shield" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></symbol>
            <symbol id="i-tag" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8.5V4a1 1 0 0 1 1-1h4.5a1 1 0 0 1 .7.3l10 10a1 1 0 0 1 0 1.4l-4.8 4.8a1 1 0 0 1-1.4 0l-10-10a1 1 0 0 1-.3-.7Z"/><circle cx="7.5" cy="7.5" r="1.3"/></symbol>
            <symbol id="i-ticket" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2 2 2 0 0 0 0 4 2 2 0 0 1-2 2H5a2 2 0 0 1-2-2 2 2 0 0 0 0-4Z"/><path d="M12 6v2"/><path d="M12 12v2"/><path d="M12 16v2"/></symbol>
            <symbol id="i-gift" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M5 12v9h14v-9"/><path d="M12 8C12 8 11 3 8.5 3a2 2 0 0 0 0 5z"/><path d="M12 8c0 0 1-5 3.5-5a2 2 0 0 1 0 5z"/></symbol>
            <symbol id="i-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></symbol>
            <symbol id="i-check-circle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.3 2.3 4.7-4.6"/></symbol>
            <symbol id="i-arrow-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></symbol>
            <symbol id="i-chevron-down" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></symbol>
            <symbol id="i-expand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6"/><path d="M9 21H3v-6"/><path d="M21 3l-7 7"/><path d="M3 21l7-7"/></symbol>
            <symbol id="i-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></symbol>
            <symbol id="i-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></symbol>
            <symbol id="i-loader" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></symbol>
            <symbol id="i-info" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/></symbol>
            <symbol id="i-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
            <symbol id="i-alert" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 2.5 20h19L12 3Z"/><path d="M12 10v4"/><path d="M12 17.5h.01"/></symbol>
            <symbol id="i-pen" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5Z"/></symbol>
            <symbol id="i-copy" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></symbol>
            <symbol id="i-book" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></symbol>
            <symbol id="icon-minus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/></symbol>
            <symbol id="icon-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></symbol>
            <symbol id="icon-cart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h2.5l2.2 12.4a1.5 1.5 0 0 0 1.5 1.2h8.9a1.5 1.5 0 0 0 1.5-1.2L21 7H6"/></symbol>
        </defs>
    </svg>
     <div class="snowfall-layer" aria-hidden="true">
         <canvas id="snowfall-canvas"></canvas>
     </div>
     <div class="container checkout-shell mx-auto max-w-7xl px-4 py-4 md:py-8">
         <header style="animation-delay: 0.1s" class="card checkout-header mb-4 md:mb-8">
              <div class="checkout-header__brand">
                 <a href="/" class="checkout-header__back" aria-label="Volver a la tienda" data-tooltip="Volver a la tienda">
                     <?php echo banano_icon('arrow-left', 'w-5 h-5'); ?>
                 </a>
                 <span class="checkout-header__title">Banano<em> Store</em></span>
                 <h1 class="sr-only">Finalizar compra</h1>
              </div>

              <nav class="checkout-steps" aria-label="Progreso de la compra">
                  <ol class="contents">
                      <li class="cstep is-done">
                          <span class="cstep-dot"><?php echo banano_icon('check', 'w-3.5 h-3.5'); ?></span>
                          <span class="cstep-label">Carrito</span>
                      </li>
                      <li class="cstep-bar is-done" aria-hidden="true"></li>
                      <li class="cstep is-active" aria-current="step">
                          <span class="cstep-dot"><?php echo banano_icon('card', 'w-3.5 h-3.5'); ?></span>
                          <span class="cstep-label">Checkout</span>
                      </li>
                      <li class="cstep-bar" aria-hidden="true"></li>
                      <li class="cstep">
                          <span class="cstep-dot"><?php echo banano_icon('check-circle', 'w-3.5 h-3.5'); ?></span>
                          <span class="cstep-label">Confirmación</span>
                      </li>
                  </ol>
              </nav>

              <!-- Perfil (avatar + nombre) inyectado por JS en #header-user-profile -->
              <div class="checkout-header__aside" data-role="header-aside"></div>
         </header>

             <div id="checkout-container">
                 <main class="checkout-layout">
                     <!-- ===== Columna principal: flujo por pasos ===== -->
                     <div class="checkout-flow">

                         <!-- PASO 1: Tu pedido -->
                         <section id="products-section" style="animation-delay: 0.2s" class="card ck-section" aria-labelledby="products-title">
                             <header class="ck-section__head">
                                 <span class="step-number" aria-hidden="true">1</span>
                                 <h2 id="products-title" class="ck-section__title">Tu pedido</h2>
                                 <span id="products-count" class="ck-section__count" aria-live="polite"></span>
                             </header>
                             <ul id="products-container" class="cart-lines" role="list"></ul>
                         </section>

                         <!-- PASO 2: Contacto y entrega -->
                         <section id="contact-section" style="animation-delay: 0.3s" class="card ck-section" aria-labelledby="contact-title">
                             <header class="ck-section__head">
                                 <span class="step-number" aria-hidden="true">2</span>
                                 <h2 id="contact-title" class="ck-section__title">Contacto y entrega</h2>
                                 <p class="ck-section__lead">Indica tu WhatsApp y a quién va el pedido.</p>
                             </header>

                             <fieldset class="ck-fieldset">
                                 <legend class="sr-only">Contacto</legend>
                                 <div class="ck-field ck-field--half">
                                     <label for="whatsapp" class="ck-label ck-label--wa"><?php echo banano_icon('whatsapp'); ?>Número de WhatsApp</label>
                                     <input type="tel" id="whatsapp" class="input-field" placeholder="912 345 678" autocomplete="tel" inputmode="tel">
                                 </div>
                             </fieldset>

                             <fieldset id="dest-section" class="ck-fieldset">
                                 <legend class="ck-fieldset__legend"><?php echo banano_icon('users'); ?>Destinatarios</legend>
                                 <p class="subtitle-mobile block md:hidden ck-fieldset__hint"></p>
                                 <p class="subtitle-desktop hidden md:block ck-fieldset__hint"></p>

                                 <div class="ck-segmented" role="group" aria-label="Modo de entrega">
                                     <button type="button" id="single-user-btn" class="ck-segmented__btn">Usuario único</button>
                                     <button type="button" id="multi-user-btn" class="ck-segmented__btn">Varios usuarios</button>
                                 </div>
                                 <div id="nick-assignment-container"></div>
                             </fieldset>
                         </section>

                         <!-- PASO 3 (móvil): Método de pago -->
                         <section id="payment-section-mobile" style="animation-delay: 0.4s" class="card ck-section lg-hidden" aria-labelledby="payment-title-mobile">
                             <header class="ck-section__head">
                                 <span class="step-number" aria-hidden="true">3</span>
                                 <h2 id="payment-title-mobile" class="ck-section__title">Método de pago</h2>
                             </header>
                             <div id="payment-methods-container-mobile" class="pm-list" role="radiogroup" aria-label="Método de pago"></div>
                         </section>

                         <!-- PASO 4 (móvil): Resumen colapsable -->
                         <details id="summary-section-mobile" style="animation-delay: 0.5s" class="card summary-sheet lg-hidden">
                             <summary class="summary-sheet__toggle">
                                 <span class="step-number" aria-hidden="true">4</span>
                                 <h2 class="ck-section__title">Resumen de compra</h2>
                                 <span class="summary-sheet__peek num" data-role="summary-peek" aria-hidden="true">S/ 0.00</span>
                                 <span class="summary-sheet__chevron"><?php echo banano_icon('chevron-down'); ?></span>
                             </summary>
                             <div class="summary-sheet__body">
                                 <div id="m-summary-items-container" class="summary-items"></div>

                                 <?php if ($mostrar_cupones): ?>
                                 <form class="coupon-form" data-coupon-form="mobile" novalidate>
                                     <label for="m-coupon-code" class="sr-only">Código de cupón</label>
                                     <input type="text" id="m-coupon-code" placeholder="Código de cupón" class="input-field" autocomplete="off" autocapitalize="characters" spellcheck="false">
                                     <button type="submit" id="m-apply-coupon-btn" class="cart-cta cart-cta--secondary cart-cta--compact">
                                         <span class="btn-text">Aplicar</span>
                                         <?php echo banano_icon('loader','btn-loader hidden w-5 h-5'); ?>
                                     </button>
                                     <div id="m-coupon-message" class="coupon-message" aria-live="polite"></div>
                                 </form>
                                 <?php endif; ?>

                                 <dl class="summary-totals">
                                     <div class="summary-row"><dt>Subtotal</dt><dd id="m-summary-subtotal">S/ 0.00</dd></div>
                                     <div id="m-summary-discount-row" class="summary-row hidden"><dt>Descuento</dt><dd id="m-summary-discount" class="text-[--success-color]">-S/ 0.00</dd></div>
                                     <div id="m-summary-coins-anchor"></div>
                                     <div id="m-summary-fee-row" class="summary-row hidden"><dt id="m-summary-fee-label">Comisión</dt><dd id="m-summary-fee" class="text-[--error-color]">+S/ 0.00</dd></div>
                                     <div class="summary-row summary-row--total"><dt>Total</dt><dd id="m-summary-total" aria-live="polite" aria-atomic="true">S/ 0.00</dd></div>
                                 </dl>
                             </div>
                         </details>

                     </div>

                     <!-- ===== Columna lateral (escritorio): resumen + pago + CTA ===== -->
                     <aside class="checkout-aside" aria-label="Resumen y pago">
                         <div class="checkout-aside__stack">
                             <section style="animation-delay: 0.4s" class="card ck-section" aria-labelledby="summary-title">
                                 <header class="ck-section__head">
                                     <span class="step-number" aria-hidden="true">3</span>
                                     <h2 id="summary-title" class="ck-section__title">Resumen</h2>
                                 </header>
                                 <div id="summary-items-container" class="summary-items"></div>

                                 <?php if ($mostrar_cupones): ?>
                                 <form class="coupon-form" data-coupon-form="desktop" novalidate>
                                     <label for="coupon-code" class="sr-only">Código de cupón</label>
                                     <input type="text" id="coupon-code" placeholder="Código de cupón" class="input-field" autocomplete="off" autocapitalize="characters" spellcheck="false">
                                     <button type="submit" id="apply-coupon-btn" class="cart-cta cart-cta--secondary cart-cta--compact">
                                         <span class="btn-text">Aplicar</span>
                                         <?php echo banano_icon('loader','btn-loader hidden w-5 h-5'); ?>
                                     </button>
                                     <div id="coupon-message" class="coupon-message" aria-live="polite"></div>
                                 </form>
                                 <?php endif; ?>

                                 <dl class="summary-totals">
                                     <div class="summary-row"><dt>Subtotal</dt><dd id="summary-subtotal">S/ 0.00</dd></div>
                                     <div id="summary-discount-row" class="summary-row hidden"><dt>Descuento</dt><dd id="summary-discount" class="text-[--success-color]">-S/ 0.00</dd></div>
                                     <div id="summary-fee-row" class="summary-row hidden"><dt id="summary-fee-label">Comisión</dt><dd id="summary-fee" class="text-[--error-color]">+S/ 0.00</dd></div>
                                     <div class="summary-row summary-row--total"><dt>Total</dt><dd id="summary-total" aria-live="polite" aria-atomic="true">S/ 0.00</dd></div>
                                 </dl>
                             </section>

                             <section style="animation-delay: 0.5s" class="card ck-section" aria-labelledby="payment-title">
                                 <header class="ck-section__head">
                                     <span class="step-number" aria-hidden="true">4</span>
                                     <h2 id="payment-title" class="ck-section__title">Método de pago</h2>
                                 </header>
                                 <div id="payment-methods-container" class="pm-list" role="radiogroup" aria-label="Método de pago"></div>
                             </section>

                             <button type="button" id="confirm-btn" class="cart-cta cart-cta--primary cart-cta--block" aria-busy="false">
                                 <span class="btn-text"><?php echo banano_icon('lock','w-4 h-4'); ?>Confirmar y pagar</span>
                                 <?php echo banano_icon('loader','btn-loader hidden w-5 h-5'); ?>
                             </button>

                             <p class="trust-row">
                                 <span><?php echo banano_icon('shield'); ?>Pago seguro</span>
                                 <span><?php echo banano_icon('whatsapp'); ?>Soporte</span>
                                 <span><?php echo banano_icon('bolt'); ?>Entrega rápida</span>
                             </p>
                         </div>
                     </aside>
                 </main>
             </div>

             <!-- Barra CTA móvil anclada -->
             <div id="mobile-cta-bar">
                 <div class="mobile-cta__inner">
                     <div class="min-w-0">
                         <span class="mobile-cta__label">Total</span>
                         <span id="mobile-cta-total" aria-live="polite" aria-atomic="true">S/ 0.00</span>
                     </div>
                     <button type="button" id="confirm-btn-mobile" class="cart-cta cart-cta--primary" aria-busy="false">
                         <span class="btn-text"><?php echo banano_icon('lock','w-4 h-4'); ?>Confirmar</span>
                         <?php echo banano_icon('loader','btn-loader hidden w-5 h-5'); ?>
                     </button>
                 </div>
             </div>

             <!-- Carrito vacío -->
             <div id="empty-cart-container" class="card empty-state">
                 <span class="empty-state__icon"><?php echo banano_icon('cart'); ?></span>
                 <h2>Tu carrito está vacío</h2>
                 <p>Parece que aún no has añadido productos. Visita nuestra tienda para ver las últimas ofertas.</p>
                 <a href="/" class="cart-cta cart-cta--primary">Volver a la tienda</a>
             </div>
         </div>

         <!-- Selector de destinatario (móvil) -->
         <div id="recipient-modal" class="ds-overlay" role="dialog" aria-modal="true" aria-labelledby="recipient-modal-title">
             <div class="ds-dialog">
                 <span class="ds-dialog__grip" aria-hidden="true"></span>
                 <header class="ds-dialog__head">
                     <h3 id="recipient-modal-title" class="ds-dialog__title">Seleccionar destinatario</h3>
                     <button type="button" id="close-recipient-modal" class="ds-dialog__close" aria-label="Cerrar"><?php echo banano_icon('close'); ?></button>
                 </header>
                 <div id="recipient-modal-list" class="ds-dialog__body"></div>
             </div>
         </div>

    <!-- Cuentas vía regalo -->
    <div id="accounts-modal" class="ds-overlay" role="dialog" aria-modal="true" aria-labelledby="modal-title" style="display: none;">
        <div class="ds-dialog">
            <span class="ds-dialog__grip" aria-hidden="true"></span>
            <header class="ds-dialog__head">
                <h3 id="modal-title" class="ds-dialog__title">Cuentas vía regalo</h3>
                <button type="button" id="modal-close-btn" class="ds-dialog__close" aria-label="Cerrar"><?php echo banano_icon('close'); ?></button>
            </header>
            <div class="ds-dialog__body">
                <p class="ds-dialog__lead">Agrega a todas estas cuentas y espera 48 horas desde la aceptación para poder recibir regalos.</p>
                <ul id="accounts-list" class="accounts-list"></ul>
            </div>
        </div>
    </div>

    <!-- Cola de modales genérica (tutoriales, advertencias, reservas). Contenido renderizado por JS. -->
    <div id="queue-modal-overlay" role="dialog" aria-modal="true" aria-live="polite"></div>

    <div id="toast-container" role="status" aria-live="polite"></div>

    <!-- Lightbox de producto -->
    <div id="product-lightbox" class="lightbox-overlay" role="dialog" aria-modal="true" aria-labelledby="lb-name">
        <article class="lightbox-card">
            <div class="lightbox-media">
                <div id="lightbox-img-wrap" class="lightbox-img-wrap"><img id="lightbox-img" src="" alt=""></div>
                <button type="button" id="close-lightbox" class="lightbox-close" aria-label="Cerrar"><?php echo banano_icon('close'); ?></button>
            </div>
            <div class="lightbox-body">
                <div class="lightbox-head">
                    <h3 id="lb-name" class="font-heading font-bold text-lg leading-tight">Producto</h3>
                    <div class="lightbox-price">
                        <p id="lb-price" class="num">S/ 0.00</p>
                        <p id="lb-base" class="num hidden">S/ 0.00</p>
                    </div>
                </div>
                <div class="lightbox-tags">
                    <span id="lb-type-wrap" class="category-badge lightbox-tag hidden"><?php echo banano_icon('tag'); ?><span id="lb-type"></span></span>
                    <span id="lb-vbucks-wrap" class="lightbox-tag num font-bold hidden"><span class="pavos-ico"><?php echo banano_icon('pavos'); ?></span><span id="lb-vbucks"></span></span>
                </div>
                <div id="lb-recipient-wrap" class="lightbox-recipient hidden">
                    <?php echo banano_icon('user'); ?>
                    <span>Para &rarr; <strong id="lb-recipient"></strong></span>
                </div>
            </div>
        </article>
    </div>

    <canvas id="confetti-canvas" aria-hidden="true"></canvas>

<script>
        // --- INTEGRACIÓN DE USUARIO ---
        window.USER_DATA = <?php 
            $user_data = null;
            if (isset($_SESSION['user_id']) && isset($_SESSION['logged_in_client'])) {
                // Obtener datos frescos del usuario para el checkout
                $uid = (int)$_SESSION['user_id'];

                $selectCols = "game_username, whatsapp_phone_e164, is_verified";
                $colCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'country_iso2'");
                if ($colCheck && $colCheck->num_rows > 0) {
                    $selectCols .= ", country_iso2";
                }

                $stmt_u = $conn->prepare("SELECT {$selectCols} FROM users WHERE id = ?");
                $stmt_u->bind_param('i', $uid);
                $stmt_u->execute();
                $res_u = $stmt_u->get_result();
                if ($row_u = $res_u->fetch_assoc()) {
                    $user_data = [
                        'is_logged_in' => true,
                        'nickname' => $row_u['game_username'],
                        'whatsapp' => $row_u['whatsapp_phone_e164'],
                        'is_verified' => (bool)$row_u['is_verified'],
                        'country_iso2' => $row_u['country_iso2'] ?? null
                    ];
                }
                $stmt_u->close();
            }
            echo json_encode($user_data); 
        ?>;
        // -----------------------------

        window.INITIAL_PRODUCTS = <?php echo $initial_products_json; ?>;
        window.PAYMENT_METHODS = <?php echo $payment_methods_json; ?>;
        window.ALERTA_SIN_REGALOS_ACTIVA = <?php echo $alerta_activa ? 'true' : 'false'; ?>;
        window.ALERTA_SIN_REGALOS_MENSAJE = <?php echo $alerta_mensaje_json; ?>;
        window.TUTORIALES_PENDIENTES = <?php echo $tutoriales_pendientes_json; ?>;
        window.USER_IS_LOGGED = <?php echo $user_is_logged ? 'true' : 'false'; ?>;
    </script>
  
 
     
     <script src="/avatar-fallback.js?v=20260914"></script>
     <script src="/snowfall.js" defer></script>
     <script src="/checkout/checkout.js?v=20260914"></script>
 </body>
 </html>
 <?php ob_end_flush(); ?>
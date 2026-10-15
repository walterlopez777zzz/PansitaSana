<?php
// 1. SEGURIDAD Y SESIÓN (Lo primero de todo)
// Sube un nivel desde 'checkout' a la raíz y entra a 'api'
require_once __DIR__ . '/../api/session_security.php';

// 2. Iniciar Buffer (Minificador)
ob_start("minify_html");

// Banano Store - Página de Confirmación de Pedido v2.4 (Solución Definitiva de Timezone)

// ------------------- CONFIGURACIÓN IMPORTANTE -------------------
// Define la zona horaria REAL de tu servidor donde corre MySQL.
// Para ET (Eastern Time), 'America/New_York' es la correcta.
// Lista de zonas horarias: https://www.php.net/manual/es/timezones.php
define('SERVER_TIMEZONE', 'America/New_York');
// ----------------------------------------------------------------

// Establecemos la zona horaria interna de PHP a UTC para que 'time()' sea consistente.
date_default_timezone_set('UTC');

// NOTA: session_start() ha sido eliminado aquí porque session_security.php ya lo inició.

require_once __DIR__ . '/../api/db_connect.php'; // Asegúrate que la ruta sea correcta

// UTC-FIRST: la sesión MySQL lee/escribe en UTC puro. Garantiza que fecha_creacion
// (incluso si la columna es TIMESTAMP) salga en UTC y que el timer/strtotime no se desfasen.
if (isset($conn) && $conn instanceof mysqli) { $conn->query("SET time_zone = '+00:00'"); }

$order_code = $_GET['order_code'] ?? null;
$pedido = null;
$config_tienda = [];
$timer_data = ['timeLeft' => 0, 'totalDuration' => 0]; // Datos para el timer
$expiry_label = '24 horas';   // Etiqueta legible de la ventana de pago para la pantalla de "Tiempo agotado".
$cliente_logueado = false;     // Si el cliente esta logueado.
$cliente_verificado = false;   // Si el cliente esta verificado.

// --- LÓGICA DE BÚSQUEDA Y CONFIGURACIÓN (VERSIÓN FINAL SIMPLIFICADA) ---
if ($conn) {
    $sql_config = "SELECT clave, valor FROM configuracion_tienda";
    $resultado_config = $conn->query($sql_config);
    if ($resultado_config && $resultado_config->num_rows > 0) {
        while ($fila = $resultado_config->fetch_assoc()) {
            $config_tienda[$fila['clave']] = $fila['valor'];
        }
    }

    $order_code = $_GET['order_code'] ?? $_GET['order_id'] ?? null;
    $query_sql = "";
    if ($order_code && $order_code !== 'undefined') {
        $query_sql = "SELECT * FROM pedidos WHERE codigo_pedido = ? LIMIT 1";
        $stmt = $conn->prepare($query_sql);
        $stmt->bind_param('s', $order_code);
    } else {
        $query_sql = "SELECT * FROM pedidos ORDER BY id DESC LIMIT 1";
        $stmt = $conn->prepare($query_sql);
    }

    if ($stmt) {
        $stmt->execute();
        $resultado = $stmt->get_result();
        if ($resultado->num_rows > 0) {
            $pedido = $resultado->fetch_assoc();
            
            
// --- Lógica del Timer Inteligente v3 (con Razón de Expiración) ---
$creation_timestamp = strtotime($pedido['fecha_creacion']);
$current_timestamp = time();

// 1. Calculamos el timestamp de expiración de la reserva de pago estándar.
$reservation_minutes = (int)($config_tienda['tiempo_reserva_normal_min'] ?? 1440);
$standard_expiry_timestamp = $creation_timestamp + ($reservation_minutes * 60);

// 2. Obtenemos el timestamp de expiración de la oferta, si existe en la BD.
$offer_expiry_timestamp = null;
if (!empty($pedido['fecha_expiracion_oferta'])) {
    $offer_expiry_timestamp = strtotime($pedido['fecha_expiracion_oferta']);
}

// 3. El timestamp de expiración final es el MÁS CERCANO.
$final_expiry_timestamp = $standard_expiry_timestamp;
$timer_reason = 'standard'; // Razón por defecto: reserva estándar
if ($offer_expiry_timestamp !== null && $offer_expiry_timestamp < $standard_expiry_timestamp) {
    $final_expiry_timestamp = $offer_expiry_timestamp;
    $timer_reason = 'offer_expiry'; // Razón de cambio: expiración de oferta
}

// 4. Calculamos el tiempo restante y la duración total.
$seconds_remaining = max(0, $final_expiry_timestamp - $current_timestamp);
$total_duration_seconds = max(0, $final_expiry_timestamp - $creation_timestamp);

$timer_data = [
    'timeLeft' => $seconds_remaining,
    'totalDuration' => $total_duration_seconds,
    'reason' => $timer_reason // <-- ¡NUEVA VARIABLE AÑADIDA!
];

// Etiqueta legible de la ventana de pago (p.ej. "2 minutos", "1 hora", "2 horas", "1 hora 30 minutos").
// Se deriva de la duración EFECTIVA real del backend (tiempo_reserva_normal_min u oferta), nunca de un texto fijo.
$expiry_total_min = max(1, (int)round($total_duration_seconds / 60));
if ($expiry_total_min < 60) {
    $expiry_label = $expiry_total_min . ($expiry_total_min === 1 ? ' minuto' : ' minutos');
} else {
    $expiry_h = intdiv($expiry_total_min, 60);
    $expiry_m = $expiry_total_min % 60;
    $expiry_label = $expiry_h . ($expiry_h === 1 ? ' hora' : ' horas');
    if ($expiry_m > 0) {
        $expiry_label .= ' ' . $expiry_m . ($expiry_m === 1 ? ' minuto' : ' minutos');
    }
}
$timer_data['expiryLabel'] = $expiry_label;

// --- CANCELACIÓN AUTOMÁTICA (lazy expiration on read, también al cargar la página) ---
// Si el pedido ya venció y sigue pendiente sin notificar, lo cancelamos aquí mismo
// para que el backend quede consistente (sin pedidos fantasma) y la UI muestre el
// estado real al instante. Las guardas del WHERE lo hacen seguro ante carreras
// (jamás toca pedidos pagados o ya notificados).
if ($pedido['estado'] === 'pendiente'
    && ($pedido['estado_pago_cliente'] ?? '') === 'no_notificado'
    && $current_timestamp > $final_expiry_timestamp) {
    $stmt_cancel = $conn->prepare(
        "UPDATE pedidos SET estado = 'cancelado'
         WHERE id = ? AND estado = 'pendiente' AND estado_pago_cliente = 'no_notificado'"
    );
    if ($stmt_cancel) {
        $stmt_cancel->bind_param('i', $pedido['id']);
        $stmt_cancel->execute();
        if ($stmt_cancel->affected_rows > 0) {
            $pedido['estado'] = 'cancelado';
        }
        $stmt_cancel->close();
    }
}

// --- Datos de usuario para la pantalla de "Tiempo agotado" ---
$cliente_logueado = isset($_SESSION['user_id']) && isset($_SESSION['logged_in_client']) && $_SESSION['logged_in_client'] === true;
if ($cliente_logueado) {
    $uid_conf = (int)$_SESSION['user_id'];
    $stmt_v = $conn->prepare("SELECT is_verified FROM users WHERE id = ? LIMIT 1");
    if ($stmt_v) {
        $stmt_v->bind_param('i', $uid_conf);
        $stmt_v->execute();
        $res_v = $stmt_v->get_result();
        if ($res_v && $row_v = $res_v->fetch_assoc()) {
            $cliente_verificado = (bool)$row_v['is_verified'];
        }
        $stmt_v->close();
    }
}

// Lógica para el color del texto del temporizador
$is_urgent = ($timer_data['timeLeft'] < 3 * 3600); // 3 horas en segundos
        }
        $stmt->close();
     // --- PASO 1: AÑADIR ESTE BLOQUE PARA OBTENER LOS PRODUCTOS DEL PEDIDO ---
        $detalles_pedido = [];
        if ($pedido) {
            // Al principio de confirmation.php
$sql_detalles = "SELECT dp.nombre_producto, dp.precio_base, dp.precio_final, dp.imagen_producto_url,
                        dp.v_bucks, dp.tipo_producto, dp.color_1, dp.color_2, dp.color_3,
                        dpt.nickname_destinatario 
                 FROM detalles_pedido dp
                 LEFT JOIN detalles_pedido_destinatarios dpt ON dp.id = dpt.detalle_pedido_id
                 WHERE dp.pedido_id = ?";
            $stmt_detalles = $conn->prepare($sql_detalles);
            $stmt_detalles->bind_param('i', $pedido['id']);
            $stmt_detalles->execute();
            $resultado_detalles = $stmt_detalles->get_result();
            if ($resultado_detalles->num_rows > 0) {
                while ($fila = $resultado_detalles->fetch_assoc()) {
                    $detalles_pedido[] = $fila;
                }
            }
            $stmt_detalles->close();
        }
        // --- FIN DEL BLOQUE A AÑADIR ---
// --- NUEVO (V2): RECUPERAR EFECTOS Y MENSAJES DEL CUPÓN (DOBLE ESTRATEGIA) ---
        $datos_cupon_fx = null;
        
        // ESTRATEGIA 1: Intentar buscar por el código de texto guardado en la tabla 'pedidos'
        if (!empty($pedido['codigo_cupon'])) {
            $sql_fx = "SELECT audio_url, imagen_url, modo_efecto, msg_confirmacion FROM cupones WHERE codigo = ? LIMIT 1";
            $stmt_fx = $conn->prepare($sql_fx);
            if ($stmt_fx) {
                $stmt_fx->bind_param('s', $pedido['codigo_cupon']);
                $stmt_fx->execute();
                $res_fx = $stmt_fx->get_result();
                if ($res_fx->num_rows > 0) {
                    $datos_cupon_fx = $res_fx->fetch_assoc();
                }
                $stmt_fx->close();
            }
        }
        
        // ESTRATEGIA 2 (FALLBACK): Si la 1 falló, buscar en 'cupones_usos_tracking' usando el ID del pedido.
        // Esto es vital si 'pedidos' no guardó el código, pero el tracking sí registró el uso.
        if ($datos_cupon_fx === null && isset($pedido['id'])) {
            // Asumimos que la columna que conecta con el pedido se llama 'pedido_id'.
            // Hacemos un JOIN para traer los datos del cupón directamente.
            $sql_track = "
                SELECT c.audio_url, c.imagen_url, c.modo_efecto, c.msg_confirmacion 
                FROM cupones_usos_tracking t
                JOIN cupones c ON t.cupon_id = c.id
                WHERE t.pedido_id = ? 
                LIMIT 1
            ";
            $stmt_track = $conn->prepare($sql_track);
            // Si tu PHP es muy viejo y falla el prepare, podría ser que la tabla no tenga 'pedido_id'. 
            // Pero por tus datos (57, 97, 230...) el 230 parece ser el ID del pedido.
            if ($stmt_track) {
                $stmt_track->bind_param('i', $pedido['id']);
                $stmt_track->execute();
                $res_track = $stmt_track->get_result();
                if ($res_track->num_rows > 0) {
                    $datos_cupon_fx = $res_track->fetch_assoc();
                }
                $stmt_track->close();
            }
        }
    }
    $conn->close();
}
// =================== INICIO BLOQUE DE DEBUG TEMPORAL ===================
if (!function_exists('conf_debug_log')) {
    function conf_debug_log($message) {
        error_log('[CONFIRMATION_PAGE] ' . (is_array($message) || is_object($message) ? print_r($message, true) : $message));
    }
}
$debug_info = [
    'fecha_expiracion_oferta_desde_BD' => $pedido['fecha_expiracion_oferta'] ?? 'NO PRESENTE EN EL ARRAY',
    'timestamp_oferta_calculado' => $offer_expiry_timestamp,
    'timestamp_reserva_estandar' => $standard_expiry_timestamp,
    'timestamp_final_elegido' => $final_expiry_timestamp,
    'decision' => ($offer_expiry_timestamp !== null && $offer_expiry_timestamp < $standard_expiry_timestamp) 
                  ? 'Se usó la fecha de la OFERTA' 
                  : 'Se usó la fecha de la RESERVA ESTÁNDAR'
];
conf_debug_log($debug_info);
// ==================== FIN BLOQUE DE DEBUG TEMPORAL =====================

// --- DATOS DE PAGO (sin cambios) ---
$datos_pago = [
    'yape' => [
        'qr_url' => 'https://res.cloudinary.com/dri9gvpwm/image/upload/v1755367435/539e27d5-a3e9-4b7c-a1d5-3c6070e_calidad_gy5bkh.jpg',
        'numero' => '924989167',
        'nombre' => 'Juan C. Machacca'
    ],
    'transferencia' => [
        'bcp' => ['cuenta' => '21592219205007', 'cci' => '00221519221920500722', 'nombre' => 'Juan C. Machacca'],
        'interbank' => ['cuenta' => '8983209099740', 'tipo' => 'Ahorro - Soles', 'nombre' => 'Juan C. Machacca'],
        'scotiabank' => ['cuenta' => '1290320678', 'nombre' => 'Juan C. Machacca']
    ],
    'whatsapp' => '51924989167'
];

function minify_html($buffer) {
    // 1. Proteger el contenido de las etiquetas <script>
    $scripts = [];
    $buffer = preg_replace_callback(
        '/<script\b[^>]*>([\s\S]*?)<\/script>/i',
        function($matches) use (&$scripts) {
            $scripts[] = $matches[0];
            return '<!-- SCRIPT_PLACEHOLDER_' . (count($scripts) - 1) . ' -->';
        },
        $buffer
    );

    // 2. Minificar el HTML (ahora seguro)
    $search = [
        '/\>[^\S ]+/s',
        '/[^\S ]+\</s',
        '/(\s)+/s',
        '/<!--(?! SCRIPT_PLACEHOLDER_)(.|\s)*?-->/' 
    ];
    $replace = [ '>', '<', '\\1', '' ];
    $buffer = preg_replace($search, $replace, $buffer);

    // 3. Restaurar el contenido de los scripts
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
    <title>Pedido <?php echo htmlspecialchars($pedido['codigo_pedido'] ?? 'no encontrado'); ?> - Banano Store</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" href="/assets/fonts/HeadingNowVariable.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow:ital,wght@0,400;0,700;1,700&display=swap">
    <link rel="stylesheet" href="/checkout/checkout.css">
    <link rel="stylesheet" href="/checkout/confirmation.css">
</head>
<body class="confirmation-page">

<div class="snowfall-layer" aria-hidden="true">
    <canvas id="snowfall-canvas"></canvas>
</div>

<svg width="0" height="0" class="hidden" aria-hidden="true" focusable="false">
    <defs>
        <symbol id="i-pavos" viewBox="0 0 24 24"><path fill-rule="evenodd" clip-rule="evenodd" fill="currentColor" d="M12 22.5C17.799 22.5 22.5 17.799 22.5 12C22.5 6.20101 17.799 1.5 12 1.5C6.20101 1.5 1.5 6.20101 1.5 12C1.5 17.799 6.20101 22.5 12 22.5ZM10.1051 7.5H6L9.31376 16.5H14.6306L18 7.5H13.8887L12.0896 13.7038L12.0031 14.0427H11.9784L11.9042 13.7038L10.1051 7.5Z"></path></symbol>
        <symbol id="i-bolt" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h9l-1 8 10-12h-9z"/></symbol>
        <symbol id="i-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
        <symbol id="i-info" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/></symbol>
        <symbol id="i-wallet" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></symbol>
        <symbol id="i-bank" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M3 10h18"/><path d="M5 6 12 3l7 3"/><path d="M4 10v11"/><path d="M20 10v11"/><path d="M8 14v3"/><path d="M12 14v3"/><path d="M16 14v3"/></symbol>
        <symbol id="i-phone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M11 18h2"/></symbol>
        <symbol id="i-trophy" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 21h8"/><path d="M12 17v4"/><path d="M7 4h10v5a5 5 0 0 1-10 0Z"/><path d="M17 5h3v2a3 3 0 0 1-3 3"/><path d="M7 5H4v2a3 3 0 0 0 3 3"/></symbol>
        <symbol id="i-card" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2.5"/><path d="M2 10h20"/><path d="M6 15h4"/></symbol>
        <symbol id="i-bell" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></symbol>
        <symbol id="i-copy" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect width="13" height="13" x="8" y="8" rx="2.5"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></symbol>
        <symbol id="i-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></symbol>
        <symbol id="i-qr" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 14h3v3"/><path d="M21 14v.01"/><path d="M14 21h.01"/><path d="M21 21v-4h-4"/></symbol>
        <symbol id="i-gift" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M5 12v9h14v-9"/><path d="M12 8C12 8 11 3 8.5 3a2 2 0 0 0 0 5z"/><path d="M12 8c0 0 1-5 3.5-5a2 2 0 0 1 0 5z"/></symbol>
        <symbol id="i-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></symbol>
        <symbol id="i-chevron-down" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></symbol>
        <symbol id="i-shield" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></symbol>
        <symbol id="i-user" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></symbol>
        <symbol id="i-tag" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8.5V4a1 1 0 0 1 1-1h4.5a1 1 0 0 1 .7.3l10 10a1 1 0 0 1 0 1.4l-4.8 4.8a1 1 0 0 1-1.4 0l-10-10a1 1 0 0 1-.3-.7Z"/><circle cx="7.5" cy="7.5" r="1.3"/></symbol>
        <symbol id="i-expand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6"/><path d="M9 21H3v-6"/><path d="M21 3l-7 7"/><path d="M3 21l7-7"/></symbol>
        <symbol id="i-loader" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></symbol>
        <symbol id="i-whatsapp" viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2m.01 1.67c4.54 0 8.23 3.7 8.23 8.24 0 4.54-3.69 8.23-8.23 8.23-1.48 0-2.93-.39-4.19-1.15l-.3-.17-3.12.82.83-3.04-.2-.32a8.19 8.19 0 0 1-1.26-4.38c.01-4.54 3.7-8.23 8.24-8.23M8.53 7.33c-.16 0-.43.06-.66.31-.22.25-.87.86-.87 2.07 0 1.22.89 2.39 1 2.56.14.17 1.76 2.67 4.25 3.73.59.27 1.05.42 1.41.53.59.19 1.13.16 1.56.1.48-.07 1.46-.6 1.67-1.18.21-.58.21-1.07.15-1.18-.07-.1-.23-.16-.48-.27-.25-.14-1.47-.74-1.69-.82-.23-.08-.37-.12-.56.12-.16.25-.64.81-.78.97-.15.17-.29.19-.53.07-.26-.13-1.06-.39-2-1.23-.74-.66-1.23-1.47-1.38-1.72-.12-.24-.01-.39.11-.5.11-.11.27-.29.37-.44.13-.14.17-.25.25-.41.08-.17 0-.32-.06-.44-.06-.11-.56-1.35-.77-1.84-.2-.48-.4-.42-.56-.43-.14 0-.3-.01-.47-.01"/></symbol>
        <symbol id="i-alert" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/></symbol>
        <symbol id="i-receipt" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 2v20l3-2 3 2 3-2 3 2 3-2 1 .7V2l-3 2-3-2-3 2-3-2-3 2z"/><path d="M8 8h8"/><path d="M8 12h8"/><path d="M8 16h5"/></symbol>
        <symbol id="i-store" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9 4.5 3h15L21 9"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M5 11v10h14V11"/><path d="M10 21v-6h4v6"/></symbol>
        <symbol id="i-hourglass" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h12"/><path d="M6 22h12"/><path d="M7 2v4.5a5 5 0 0 0 2 4l3 1.5-3 1.5a5 5 0 0 0-2 4V22"/><path d="M17 2v4.5a5 5 0 0 1-2 4l-3 1.5 3 1.5a5 5 0 0 1 2 4V22"/></symbol>
        <symbol id="i-coins" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="6"/><path d="M18.09 10.37A6 6 0 1 1 10.34 18"/><path d="M7 6h1v4"/><path d="m16.71 13.88.7.71-2.82 2.82"/></symbol>
    </defs>
</svg>

<?php if ($pedido): ?>
<?php
    if (!function_exists('banano_icon')) {
        function banano_icon($name, $class = '') {
            $cls = trim('bi ' . $class);
            return '<svg class="' . htmlspecialchars($cls) . '" aria-hidden="true"><use href="#i-' . $name . '" xlink:href="#i-' . $name . '"></use></svg>';
        }
    }

    // Degradado de tarjeta replicando /public_html/worker.js (orden color_1 -> color_3 -> color_2).
    if (!function_exists('banano_card_gradient')) {
        function banano_card_gradient($c1, $c2, $c3) {
            $clean = function ($c) {
                if (!is_string($c)) return null;
                $c = ltrim(strtolower(trim($c)), '#');
                return preg_match('/^[0-9a-f]{6,8}$/', $c) === 1 ? '#' . substr($c, 0, 6) : null;
            };
            $valid = array_values(array_filter([$clean($c1), $clean($c3), $clean($c2)]));
            if (count($valid) >= 2) {
                $v0 = $valid[0];
                $v1 = $valid[1] ?? $valid[0];
                $v2 = $valid[2] ?? $valid[1] ?? $valid[0];
                return 'background-image: linear-gradient(180deg, ' . $v0 . ' 0%, ' . $v1 . ' 65%, ' . $v2 . ' 100%);';
            }
            if (count($valid) === 1) return 'background-color: ' . $valid[0] . ';';
            return 'background-image: linear-gradient(160deg, #2a2a30 0%, #15151a 100%);';
        }
    }

    $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $money = static fn($v) => 'S/ ' . number_format((float)$v, 2);

    // Modo de visualización del destinatario: cabecera única o por ítem.
    $displayMode = 'per-item'; $headerRecipientName = null;
    if (!empty($detalles_pedido)) {
        if (empty($detalles_pedido[0]['nickname_destinatario'])) {
            $displayMode = 'header'; $headerRecipientName = $pedido['nickname_fortnite_cliente'] ?? null;
        } else {
            $firstRecipient = $detalles_pedido[0]['nickname_destinatario']; $allSame = true;
            foreach ($detalles_pedido as $detalle) { if ($detalle['nickname_destinatario'] !== $firstRecipient) { $allSame = false; break; } }
            if ($allSame) { $displayMode = 'header'; $headerRecipientName = $firstRecipient; }
        }
    }

    $num_productos = count($detalles_pedido);
    $estado_inicial = $pedido['estado'] ?? 'pendiente';
    $ya_notificado_inicial = (($pedido['estado_pago_cliente'] ?? '') === 'notificado_pendiente_revision') || in_array($estado_inicial, ['pagado', 'completado'], true);
    $es_final_inicial = in_array($estado_inicial, ['completado', 'cancelado'], true);
    $timer_es_oferta = (($timer_data['reason'] ?? '') === 'offer_expiry');

    // Miniatura compartida con el checkout: Vía Regalo (tile vertical 0.627) / Vía Cuenta (cuadrada).
    $render_media = function (array $d) use ($e, $displayMode) {
        $precio_final = (float)($d['precio_final'] ?? 0);
        $precio_base  = (float)($d['precio_base'] ?? $precio_final);
        $nombre = $d['nombre_producto'] ?? 'Producto';
        $img    = (string)($d['imagen_producto_url'] ?? '');
        $tipo   = trim((string)($d['tipo_producto'] ?? ''));
        $vbucks = (int)($d['v_bucks'] ?? 0);
        $recip  = ($displayMode === 'per-item') ? (string)($d['nickname_destinatario'] ?? '') : '';
        $is_static = ($tipo === '' && $vbucks === 0);
        $grad   = $is_static ? '' : banano_card_gradient($d['color_1'] ?? null, $d['color_2'] ?? null, $d['color_3'] ?? null);

        $html  = '<button type="button" class="cart-line__media item-tile' . ($is_static ? ' cart-line__media--square' : '') . '"'
               . ' data-lightbox-trigger'
               . ' data-name="' . $e($nombre) . '" data-img="' . $e($img) . '" data-grad="' . $e($grad) . '"'
               . ' data-tipo="' . $e($tipo) . '" data-vbucks="' . $vbucks . '"'
               . ' data-price="' . number_format($precio_final, 2, '.', '') . '" data-base="' . number_format($precio_base, 2, '.', '') . '"'
               . ' data-recipient="' . $e($recip) . '" aria-label="Ver ' . $e($nombre) . '">';
        $html .= '<span class="cart-line__tile item-image-container' . ($grad ? ' fortnite-gradient' : '') . '" style="' . $e($grad) . '">';
        $html .= $img !== ''
            ? '<img src="' . $e($img) . '" alt="" class="cart-line__image item-image" loading="lazy" decoding="async">'
            : '<span class="cart-line__placeholder">' . banano_icon('gift') . '</span>';
        $html .= '</span></button>';
        return $html;
    };

    // Lista de productos con la anatomía .sum-line del resumen del checkout.
    $render_items = function () use ($detalles_pedido, $displayMode, $headerRecipientName, $render_media, $e, $money) {
        if ($displayMode === 'header' && !empty($headerRecipientName)) {
            echo '<p class="conf-recipient"><span class="conf-recipient__ico">' . banano_icon('user') . '</span><span class="sum-recipient-label">Para &rarr;</span><span class="sum-recipient-name">' . $e($headerRecipientName) . '</span></p>';
        }
        echo '<ul class="conf-items" role="list">';
        foreach ($detalles_pedido as $d) {
            $precio_final = (float)($d['precio_final'] ?? 0);
            $precio_base  = (float)($d['precio_base'] ?? $precio_final);
            $tipo   = trim((string)($d['tipo_producto'] ?? ''));
            $vbucks = (int)($d['v_bucks'] ?? 0);
            $recip  = (string)($d['nickname_destinatario'] ?? '');
            $has_desc = $precio_base > $precio_final + 0.009;
            $has_surc = $precio_final > $precio_base + 0.009;
            $is_static = ($tipo === '' && $vbucks === 0);

            echo '<li class="sum-line">';
            echo $render_media($d);
            echo '<div class="sum-line__body">';
            echo '<p class="sum-line__name">' . $e($d['nombre_producto'] ?? 'Producto') . '</p>';
            echo '<div class="sum-line__meta">';
            echo '<span class="category-badge">' . ($tipo !== '' ? $e($tipo) : ($is_static ? 'Vía Cuenta' : 'Vía Regalo')) . '</span>';
            if ($vbucks > 0) echo '<span class="sum-vbucks num"><span class="pavos-ico">' . banano_icon('pavos') . '</span>' . number_format($vbucks) . '</span>';
            echo '</div></div>';
            echo '<div class="sum-price">';
            if ($has_desc || $has_surc) {
                echo '<span class="sum-price__base">' . $money($precio_base) . '</span>';
                echo '<span class="sum-price__final ' . ($has_desc ? 'is-discount' : 'is-surcharge') . '">' . $money($precio_final) . '</span>';
            } else {
                echo '<span class="sum-price__final">' . $money($precio_final) . '</span>';
            }
            echo '</div>';
            if ($displayMode === 'per-item' && $recip !== '') {
                echo '<div class="sum-recipient"><span class="sum-recipient-label">Para &rarr;</span><span class="sum-recipient-names"><span class="sum-recipient-name">' . $e($recip) . '</span></span></div>';
            }
            echo '</li>';
        }
        echo '</ul>';
    };

    // Totales: mismos hooks y clases que .summary-totals del checkout (el valor de descuento
    // ya viene resuelto por PHP; el JS no lo recalcula).
    $render_totals = function () use ($pedido, $money) {
        $descuento = (float)($pedido['monto_descuento'] ?? 0);
        $has_disc = abs($descuento) >= 0.009;
        $is_surcharge = $descuento < 0;
        ?>
        <dl class="summary-totals">
            <div class="summary-row"><dt>Subtotal</dt><dd><?php echo $money($pedido['subtotal'] ?? 0); ?></dd></div>
            <?php if (!empty($pedido['coins_redeemed']) && $pedido['coins_redeemed'] > 0): ?>
            <div class="summary-row summary-row--coins"><dt><?php echo banano_icon('coins'); ?> Bananos usados (<?php echo (int)$pedido['coins_redeemed']; ?>)</dt><dd>-<?php echo $money($pedido['coins_value_money'] ?? 0); ?></dd></div>
            <?php endif; ?>
            <div class="summary-row<?php echo $has_disc ? '' : ' hidden'; ?>" data-role="discount-row">
                <dt><?php echo $is_surcharge ? 'Recargo' : 'Descuento'; ?></dt>
                <dd class="<?php echo $is_surcharge ? 'text-[--error-color]' : 'text-[--success-color]'; ?>"><?php echo ($is_surcharge ? '+' : '-') . $money(abs($descuento)); ?></dd>
            </div>
            <div class="summary-row summary-row--total"><dt>Total</dt><dd><?php echo $money($pedido['total_pagar'] ?? 0); ?></dd></div>
        </dl>
        <?php
    };

    // Cuenta regresiva: un solo bloque reutilizable; JS actualiza por [data-timer-unit].
    $render_timer = function ($variant = '') {
        ?>
        <span class="conf-timer<?php echo $variant ? ' conf-timer--' . $variant : ''; ?> num" data-timer aria-live="off">
            <span class="conf-timer__unit hidden" data-timer-days-unit><span data-timer-unit="days">00</span><small>d</small></span>
            <span class="conf-timer__unit"><span data-timer-unit="hours">00</span><small>h</small></span>
            <span class="conf-timer__unit"><span data-timer-unit="minutes">00</span><small>m</small></span>
            <span class="conf-timer__unit"><span data-timer-unit="seconds">00</span><small>s</small></span>
        </span>
        <?php
    };

    $render_copy_field = function ($label, $value, $tooltip, $strong = true) use ($e) {
        $clean = str_replace(' ', '', (string)$value);
        ?>
        <div class="pm-field">
            <div class="pm-field__text"><span class="pm-field__label"><?php echo $label; ?></span><span class="pm-field__value num<?php echo $strong ? ' pm-field__value--strong' : ''; ?>"><?php echo $e($value); ?></span></div>
            <button type="button" class="copy-btn pm-field__copy" data-copy="<?php echo $e($clean); ?>" data-tooltip="<?php echo $e($tooltip); ?>" aria-label="<?php echo $e($tooltip); ?>"><?php echo banano_icon('copy'); ?></button>
        </div>
        <?php
    };
    $render_plain_field = function ($label, $value) use ($e) {
        ?>
        <div class="pm-field pm-field--plain"><div class="pm-field__text"><span class="pm-field__label"><?php echo $label; ?></span><span class="pm-field__value"><?php echo $e($value); ?></span></div></div>
        <?php
    };

    $steps = [
        ['key' => 'pending',   'icon' => 'check',  'title' => 'Pendiente',  'desc' => 'Pedido recibido',        'time_id' => 'pending-time'],
        ['key' => 'notified',  'icon' => 'bell',   'title' => 'Notificado', 'desc' => 'Esperando verificación', 'time_id' => 'notified-time'],
        ['key' => 'paid',      'icon' => 'card',   'title' => 'Pagado',     'desc' => 'Pago verificado',        'time_id' => 'paid-time'],
        ['key' => 'completed', 'icon' => 'trophy', 'title' => 'Completado', 'desc' => 'Ítems entregados',       'time_id' => 'completed-time'],
    ];
?>
<div class="checkout-shell conf-shell">

    <header class="checkout-header conf-header card">
        <div class="checkout-header__brand">
            <a href="/" class="checkout-header__back" aria-label="Volver a la tienda"><?php echo banano_icon('store'); ?></a>
            <h1 class="checkout-header__title">Pedido <em class="num">#<?php echo $e($pedido['codigo_pedido']); ?></em></h1>
        </div>
        <div class="checkout-header__aside">
            <button type="button" id="open-help-modal" class="conf-help-link"><?php echo banano_icon('info'); ?><span>¿Cómo completar tu compra?</span></button>
        </div>
    </header>

    <main class="checkout-layout conf-layout">

        <div class="checkout-flow conf-flow">

            <!-- ===== Tarjeta de estado del pedido ===== -->
            <section class="card ck-section conf-status" aria-labelledby="status-title" data-state="<?php echo $e($estado_inicial); ?>">
                <header class="conf-status__head">
                    <div class="conf-status__badge" data-role="status-badge">
                        <span class="conf-status__dot" aria-hidden="true"></span>
                        <h2 id="status-title" class="conf-status__title" data-role="status-title">Esperando tu pago</h2>
                    </div>
                    <div class="conf-status__timer<?php echo $is_urgent ? ' is-urgent' : ''; ?>" data-role="timer-block">
                        <span class="conf-status__timer-label"><?php echo banano_icon($is_urgent ? 'bolt' : 'clock'); ?><?php echo $timer_es_oferta ? 'Cambio de tienda en' : 'Reservado por'; ?></span>
                        <?php $render_timer(); ?>
                    </div>
                </header>

                <ol class="conf-steps" data-role="steps" aria-label="Progreso del pedido">
                    <?php foreach ($steps as $i => $s): ?>
                    <li class="conf-step" data-step="<?php echo $s['key']; ?>" data-state="<?php echo $i === 0 ? 'done' : ($i === 1 ? 'active' : 'todo'); ?>">
                        <span class="conf-step__icon" data-icon="<?php echo $s['icon']; ?>"><?php echo banano_icon($s['icon']); ?></span>
                        <span class="conf-step__text">
                            <span class="conf-step__title"><?php echo $s['title']; ?></span>
                            <span class="conf-step__desc"><?php echo $s['desc']; ?></span>
                            <time class="conf-step__time num" id="<?php echo $s['time_id']; ?>">--:--</time>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ol>
            </section>

            <!-- ===== Tarjeta de pago ===== -->
            <section class="card ck-section conf-pay" aria-labelledby="pay-title">
                <header class="ck-section__head">
                    <span class="step-number" aria-hidden="true"><?php echo banano_icon('wallet'); ?></span>
                    <h2 id="pay-title" class="ck-section__title">Realiza tu pago</h2>
                </header>

                <div class="conf-amount">
                    <span class="conf-amount__label">Monto a pagar</span>
                    <span class="conf-amount__value num"><small>S/</small><?php echo number_format((float)($pedido['total_pagar'] ?? 0), 2); ?></span>
                </div>

                <div class="conf-quick">
                    <button type="button" id="quick-qr-btn" class="conf-quick__qr" data-tooltip="Ampliar QR" aria-label="Ampliar QR de Yape">
                        <img id="quick-qr" src="<?php echo $e($datos_pago['yape']['qr_url']); ?>" alt="" width="56" height="56" loading="lazy" decoding="async">
                    </button>
                    <div class="conf-quick__text">
                        <span class="pm-field__label"><span id="quick-method-icon" class="conf-quick__ico"><?php echo banano_icon('phone'); ?></span><span id="quick-method-label">Número Yape</span></span>
                        <span id="quick-value" class="pm-field__value pm-field__value--strong num"><?php echo $e($datos_pago['yape']['numero']); ?></span>
                        <span id="quick-holder" class="conf-quick__holder"><?php echo $e($datos_pago['yape']['nombre']); ?></span>
                    </div>
                    <button type="button" id="quick-copy" class="copy-btn pm-field__copy pm-field__copy--accent" data-copy="<?php echo $e(str_replace(' ', '', $datos_pago['yape']['numero'])); ?>" data-tooltip="Copiar número" aria-label="Copiar número"><?php echo banano_icon('copy'); ?></button>
                </div>

                <div class="conf-pay__actions">
                    <button type="button" id="open-payment-modal" class="cart-cta cart-cta--secondary cart-cta--block"><?php echo banano_icon('qr'); ?> Ver todos los datos de pago</button>
                    <div class="conf-pay__row">
                        <button type="button" class="whatsapp-btn cart-cta cart-cta--secondary cart-cta--whatsapp"><?php echo banano_icon('whatsapp'); ?><span>WhatsApp</span></button>
                        <button type="button" class="notify-btn cart-cta cart-cta--primary" aria-busy="false"><span class="btn-text"><?php echo banano_icon('bell'); ?><span>Ya pagué</span></span></button>
                    </div>
                </div>
            </section>
        </div>

        <!-- ===== Resumen de compra ===== -->
        <aside class="checkout-aside conf-aside" aria-label="Resumen de compra">
            <details id="order-summary" class="card summary-sheet conf-summary" <?php echo ($num_productos <= 1) ? 'open' : ''; ?>>
                <summary class="summary-sheet__toggle">
                    <span class="step-number" aria-hidden="true"><?php echo banano_icon('receipt'); ?></span>
                    <h2 class="ck-section__title">Resumen de compra</h2>
                    <span class="summary-sheet__peek num" aria-hidden="true"><?php echo $money($pedido['total_pagar'] ?? 0); ?></span>
                    <span class="summary-sheet__chevron" aria-hidden="true"><?php echo banano_icon('chevron-down'); ?></span>
                </summary>
                <div class="summary-sheet__body">
                    <span class="ck-section__count"><?php echo $num_productos === 1 ? '1 artículo' : $num_productos . ' artículos'; ?></span>
                    <?php $render_items(); ?>
                    <?php $render_totals(); ?>
                </div>
            </details>
        </aside>
    </main>

    <!-- CTA fija móvil (se oculta al notificar / finalizar) -->
    <div id="mobile-cta-bar" class="conf-mobile-cta<?php echo ($ya_notificado_inicial || $es_final_inicial) ? ' hidden' : ''; ?>">
        <div class="mobile-cta__inner">
            <button type="button" class="whatsapp-btn cart-cta cart-cta--secondary cart-cta--whatsapp" aria-label="Enviar comprobante por WhatsApp"><?php echo banano_icon('whatsapp'); ?></button>
            <button type="button" class="notify-btn cart-cta cart-cta--primary" aria-busy="false"><span class="btn-text"><?php echo banano_icon('bell'); ?><span>Ya pagué</span></span></button>
        </div>
    </div>

    <!-- ===== Modal: datos de pago ===== -->
    <div id="payment-modal" class="ds-overlay modal" role="dialog" aria-modal="true" aria-labelledby="payment-modal-title" aria-hidden="true">
        <div class="ds-dialog conf-dialog">
            <div class="ds-dialog__grip" aria-hidden="true"></div>
            <header class="ds-dialog__head">
                <h3 id="payment-modal-title" class="ds-dialog__title">Datos de pago</h3>
                <button type="button" id="close-payment-modal" class="ds-dialog__close" aria-label="Cerrar"><?php echo banano_icon('close'); ?></button>
            </header>
            <div class="ds-dialog__body">
                <?php if ($timer_es_oferta): ?>
                <p class="conf-alert conf-alert--danger">
                    <span class="conf-alert__ico"><?php echo banano_icon('bolt'); ?></span>
                    <span class="conf-alert__text">Este producto se irá en el cambio de tienda dentro de <?php $render_timer('inline'); ?></span>
                </p>
                <?php endif; ?>

                <div class="conf-amount conf-amount--compact">
                    <span class="conf-amount__label">Total a pagar</span>
                    <span class="conf-amount__value num"><small>S/</small><?php echo number_format((float)($pedido['total_pagar'] ?? 0), 2); ?></span>
                </div>

                <div class="ck-segmented method-switch" role="tablist" aria-label="Método de pago">
                    <span id="method-switch-thumb" class="ck-segmented__thumb" aria-hidden="true"></span>
                    <button type="button" class="pm-method-btn ck-segmented__btn" role="tab" data-method="yape" aria-selected="true" aria-controls="pm-panel-yape"><?php echo banano_icon('phone'); ?> Yape</button>
                    <button type="button" class="pm-method-btn ck-segmented__btn" role="tab" data-method="transfer" aria-selected="false" aria-controls="pm-panel-transfer"><?php echo banano_icon('bank'); ?> Transferencia</button>
                </div>

                <section id="pm-panel-yape" class="pm-panel" role="tabpanel" data-method="yape">
                    <figure class="pm-qr">
                        <img src="<?php echo $e($datos_pago['yape']['qr_url']); ?>" alt="Código QR de Yape" width="176" height="176" decoding="async">
                        <figcaption>Escanea con Yape</figcaption>
                    </figure>
                    <div class="pm-fields">
                        <?php $render_copy_field('Número', $datos_pago['yape']['numero'], 'Copiar número'); ?>
                        <?php $render_plain_field('Titular', $datos_pago['yape']['nombre']); ?>
                    </div>
                </section>

                <section id="pm-panel-transfer" class="pm-panel hidden" role="tabpanel" data-method="transfer">
                    <p class="pm-panel__lead">Elige tu banco</p>
                    <div class="pm-banks" role="tablist" aria-label="Banco">
                        <button type="button" class="pm-bank-chip" role="tab" data-bank="bcp" aria-selected="true"><img src="https://res.cloudinary.com/dri9gvpwm/image/upload/v1754242866/image_2_f3hu0u.png" alt="" loading="lazy"><span>BCP</span></button>
                        <button type="button" class="pm-bank-chip" role="tab" data-bank="interbank" aria-selected="false"><img src="https://res.cloudinary.com/dri9gvpwm/image/upload/v1755285572/image_xcwrlm.png" alt="" loading="lazy"><span>Interbank</span></button>
                        <button type="button" class="pm-bank-chip" role="tab" data-bank="scotiabank" aria-selected="false"><img src="https://res.cloudinary.com/dri9gvpwm/image/upload/v1755285582/image_1_a7avae.png" alt="" loading="lazy"><span>Scotiabank</span></button>
                    </div>
                    <div class="pm-bank-info pm-fields" id="pm-bcp">
                        <?php $render_copy_field('N° de cuenta', $datos_pago['transferencia']['bcp']['cuenta'], 'Copiar cuenta'); ?>
                        <?php $render_copy_field('CCI', $datos_pago['transferencia']['bcp']['cci'], 'Copiar CCI', false); ?>
                        <?php $render_plain_field('Titular', $datos_pago['transferencia']['bcp']['nombre']); ?>
                    </div>
                    <div class="pm-bank-info pm-fields hidden" id="pm-interbank">
                        <?php $render_copy_field('N° de cuenta', $datos_pago['transferencia']['interbank']['cuenta'], 'Copiar cuenta'); ?>
                        <?php $render_plain_field('Tipo de cuenta', $datos_pago['transferencia']['interbank']['tipo']); ?>
                        <?php $render_plain_field('Titular', $datos_pago['transferencia']['interbank']['nombre']); ?>
                    </div>
                    <div class="pm-bank-info pm-fields hidden" id="pm-scotiabank">
                        <?php $render_copy_field('N° de cuenta', $datos_pago['transferencia']['scotiabank']['cuenta'], 'Copiar cuenta'); ?>
                        <?php $render_plain_field('Titular', $datos_pago['transferencia']['scotiabank']['nombre']); ?>
                    </div>
                </section>

                <div class="conf-pay__row">
                    <button type="button" class="whatsapp-btn cart-cta cart-cta--secondary cart-cta--whatsapp"><?php echo banano_icon('whatsapp'); ?><span>WhatsApp</span></button>
                    <button type="button" class="notify-btn cart-cta cart-cta--primary" aria-busy="false"><span class="btn-text"><?php echo banano_icon('bell'); ?><span>Ya pagué</span></span></button>
                </div>
                <p class="conf-footnote"><?php echo banano_icon('shield'); ?>Recuerda enviar tu comprobante por WhatsApp.</p>
            </div>
        </div>
    </div>

    <!-- ===== Modal: notificar pago ===== -->
    <div id="confirmation-modal" class="ds-overlay modal" role="dialog" aria-modal="true" aria-labelledby="confirmation-modal-title" aria-hidden="true">
        <div class="ds-dialog conf-dialog">
            <div class="ds-dialog__grip" aria-hidden="true"></div>
            <header class="ds-dialog__head">
                <h3 id="confirmation-modal-title" class="ds-dialog__title">Notificar pago</h3>
                <button type="button" id="close-confirmation-modal" class="ds-dialog__close" aria-label="Cerrar"><?php echo banano_icon('close'); ?></button>
            </header>
            <div class="ds-dialog__body">
                <ul class="conf-notes" role="list">
                    <li><span class="conf-notes__ico"><?php echo banano_icon('bell'); ?></span><span>Este botón solo <strong>notifica</strong> que ya pagaste para revisarlo. No envía el producto automáticamente.</span></li>
                    <li><span class="conf-notes__ico"><?php echo banano_icon('alert'); ?></span><span><strong>Debes enviar la captura o comprobante del pago por WhatsApp.</strong></span></li>
                    <li><span class="conf-notes__ico conf-notes__ico--danger"><?php echo banano_icon('close'); ?></span><span>Notificaciones falsas o sin comprobante pueden generar bloqueos temporales.</span></li>
                </ul>
                <div class="conf-pay__row">
                    <button type="button" id="cancel-btn" class="cart-cta cart-cta--secondary">Cancelar</button>
                    <button type="button" id="confirm-btn" class="cart-cta cart-cta--primary" aria-busy="false">
                        <span class="btn-text">Sí, ya pagué</span>
                        <span class="btn-loader hidden"><?php echo banano_icon('loader'); ?></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== Modal: cómo completar tu compra ===== -->
    <div id="help-modal" class="ds-overlay modal" role="dialog" aria-modal="true" aria-labelledby="help-modal-title" aria-hidden="true">
        <div class="ds-dialog conf-dialog">
            <div class="ds-dialog__grip" aria-hidden="true"></div>
            <header class="ds-dialog__head">
                <h3 id="help-modal-title" class="ds-dialog__title">¿Cómo completar tu compra?</h3>
                <button type="button" id="close-help-modal" class="ds-dialog__close" aria-label="Cerrar"><?php echo banano_icon('close'); ?></button>
            </header>
            <div class="ds-dialog__body">
                <ol class="conf-howto">
                    <li><span class="step-number" aria-hidden="true">1</span><span>Realiza el pago usando el método seleccionado.</span></li>
                    <li><span class="step-number" aria-hidden="true">2</span><span>Envía el comprobante por <strong class="conf-howto__wa">WhatsApp</strong>.</span></li>
                    <li><span class="step-number" aria-hidden="true">3</span><span>Pulsa <strong>"Ya pagué"</strong> para una revisión más rápida (opcional).</span></li>
                    <li><span class="step-number" aria-hidden="true">4</span><span>Recibe tu compra y disfruta.</span></li>
                </ol>
            </div>
        </div>
    </div>

    <!-- ===== Modal: pedido completado ===== -->
    <div id="completion-modal" class="ds-overlay modal" role="dialog" aria-modal="true" aria-labelledby="completion-modal-title" aria-hidden="true">
        <div class="ds-dialog conf-dialog conf-dialog--center">
            <div class="ds-dialog__body">
                <span class="conf-hero-icon conf-hero-icon--success"><?php echo banano_icon('trophy'); ?></span>
                <h2 id="completion-modal-title" class="conf-dialog__headline">¡Pedido completado!</h2>
                <p class="ds-dialog__lead">Tus ítems ya fueron entregados. Gracias por comprar en Banano Store.</p>
                <div class="conf-dialog__stack">
                    <a href="/" class="cart-cta cart-cta--primary cart-cta--block"><?php echo banano_icon('store'); ?> Volver a la tienda</a>
                    <button type="button" id="close-completion-modal" class="cart-cta cart-cta--secondary cart-cta--block">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== Lightbox de producto ===== -->
    <div id="product-lightbox" class="ds-overlay modal" role="dialog" aria-modal="true" aria-labelledby="lightbox-name" aria-hidden="true">
        <div class="ds-dialog conf-dialog conf-lightbox">
            <div id="lightbox-img-wrap" class="conf-lightbox__media">
                <img id="lightbox-img" src="" alt="">
                <button type="button" id="close-lightbox" class="ds-dialog__close conf-lightbox__close" aria-label="Cerrar"><?php echo banano_icon('close'); ?></button>
            </div>
            <div class="ds-dialog__body conf-lightbox__body">
                <div class="conf-lightbox__head">
                    <h3 id="lightbox-name" class="conf-lightbox__name">Producto</h3>
                    <div class="sum-price">
                        <span id="lightbox-base" class="sum-price__base hidden">S/ 0.00</span>
                        <span id="lightbox-price" class="sum-price__final conf-lightbox__price">S/ 0.00</span>
                    </div>
                </div>
                <div class="sum-line__meta">
                    <span id="lightbox-type-wrap" class="category-badge hidden"><span id="lightbox-type"></span></span>
                    <span id="lightbox-vbucks-wrap" class="sum-vbucks num hidden"><span class="pavos-ico"><?php echo banano_icon('pavos'); ?></span><span id="lightbox-vbucks"></span></span>
                </div>
                <p id="lightbox-recipient-wrap" class="sum-recipient hidden"><span class="sum-recipient-label">Para &rarr;</span><span id="lightbox-recipient" class="sum-recipient-name"></span></p>
            </div>
        </div>
    </div>

    <!-- ===== Overlay: reserva expirada ===== -->
    <div id="cancelled-overlay" class="ds-overlay modal hidden" role="dialog" aria-modal="true" aria-labelledby="cancelled-title">
        <div class="ds-dialog conf-dialog conf-dialog--center">
            <header class="ds-dialog__head">
                <button type="button" id="close-cancelled-overlay" class="ds-dialog__close" aria-label="Cerrar"><?php echo banano_icon('close'); ?></button>
            </header>
            <div class="ds-dialog__body">
                <span class="conf-hero-icon conf-hero-icon--danger"><?php echo banano_icon('hourglass'); ?></span>
                <h2 id="cancelled-title" class="conf-dialog__headline">Tiempo agotado</h2>
                <p class="ds-dialog__lead">El plazo de <strong id="cancel-expiry-label"><?php echo $e($expiry_label); ?></strong> para completar el pago venció y el pedido se canceló automáticamente. Puedes volver a realizar tu compra cuando quieras.</p>
                <div class="conf-dialog__stack">
                    <a href="/" class="cart-cta cart-cta--primary cart-cta--block"><?php echo banano_icon('store'); ?> Volver a la tienda</a>
                    <?php if ($cliente_logueado): ?>
                    <a href="/cuenta/ordenes" class="cart-cta cart-cta--secondary cart-cta--block"><?php echo banano_icon('receipt'); ?> Ir a mis pedidos</a>
                    <?php endif; ?>
                </div>
                <?php if ($cliente_logueado && !$cliente_verificado): ?>
                <p class="conf-footnote">¿Ya habías pagado? <a href="https://wa.me/51924989167?text=<?php echo rawurlencode('Hola, mi pedido ' . ($pedido['codigo_pedido'] ?? '') . ' se cancelo por tiempo, pero ya realice el pago. Pueden ayudarme a actualizarlo?'); ?>" target="_blank" rel="noopener" class="conf-link">Escríbenos por WhatsApp</a> y lo actualizamos.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>
<?php else: ?>
    <div class="checkout-shell conf-shell conf-shell--empty">
        <section class="card ck-section conf-notfound" aria-labelledby="notfound-title">
            <span class="conf-hero-icon conf-hero-icon--danger"><?php echo banano_icon('alert'); ?></span>
            <h1 id="notfound-title" class="conf-dialog__headline">Pedido no encontrado</h1>
            <p class="ds-dialog__lead">No pudimos encontrar el pedido que buscas. Es posible que el código sea incorrecto o el pedido no exista.</p>
            <a href="/" class="cart-cta cart-cta--primary cart-cta--block"><?php echo banano_icon('store'); ?> Volver al inicio</a>
        </section>
    </div>
<?php endif; ?>

<script>
    window.PHP_DATA = {
        pedido: <?php echo json_encode($pedido); ?>,
        timerData: <?php echo json_encode($timer_data); ?>,
        datosPago: <?php echo json_encode($datos_pago); ?>,
        couponFx: <?php echo json_encode($datos_cupon_fx); ?>
    };
</script>
<script src="/snowfall.js" defer></script>
<script src="/checkout/confirmation.js"></script>
</body>
</html>
<?php ob_end_flush(); ?>

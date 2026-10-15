<?php
// Archivo: /api/coupon_validation_logic.php
// Versión 3.2 - Añadida la devolución de productos afectados para la UI

if (!function_exists('validarCuponCompleto')) {
    /**
     * Valida un cupón de forma exhaustiva contra un carrito y un contexto de cliente.
     *
     * @param mysqli $conn La conexión activa a la base de datos.
     * @param string $codigo_cupon El código del cupón a validar.
     * @param array $productos_carrito Array de productos en el carrito.
     * @param string $whatsapp_cliente El número de WhatsApp del cliente.
     * @param array $destinatarios_input Array con los nombres de los destinatarios.
     * @param string $ip_cliente La dirección IP del cliente.
     * @param int|null $user_id El ID del usuario logueado, o null si es visitante.
     * @return array Un array asociativo con el resultado.
     */
    function validarCuponCompleto($conn, $codigo_cupon, $productos_carrito, $whatsapp_cliente, $destinatarios_input, $ip_cliente, $user_id = null) {

        // --- PASO A: Búsqueda y Validez Básica del Cupón ---
        $stmt = $conn->prepare("
            SELECT * FROM cupones 
            WHERE codigo = ? AND activo = 1 
            AND (fecha_expiracion IS NULL OR fecha_expiracion > NOW())
            AND (fecha_inicio IS NULL OR fecha_inicio <= NOW())
        ");
        if (!$stmt) { return ['status' => 'error', 'message' => 'Error interno del servidor (PREPARE_A).']; }
        $stmt->bind_param("s", $codigo_cupon);
        $stmt->execute();
        $cupon = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$cupon) {
            return ['status' => 'error', 'message' => 'El código del cupón no es válido o ha expirado.'];
        }

        // --- PASO A.2: VALIDACIÓN DE REQUISITOS DE SESIÓN (Libre -> Logeado -> Verificado) ---
        if (!empty($cupon['requiere_verificacion'])) {
            if (!$user_id) {
                return ['status' => 'error', 'message' => 'Debes iniciar sesión para usar este cupón.'];
            }
            $stmt_ver = $conn->prepare("SELECT is_verified FROM users WHERE id = ?");
            $stmt_ver->bind_param("i", $user_id);
            $stmt_ver->execute();
            $row_ver = $stmt_ver->get_result()->fetch_assoc();
            $stmt_ver->close();
            if (!$row_ver || !$row_ver['is_verified']) {
                return ['status' => 'error', 'message' => 'Este cupón es exclusivo para cuentas verificadas.'];
            }
        } elseif (!empty($cupon['requiere_login'])) {
            if (!$user_id) {
                return ['status' => 'error', 'message' => 'Debes iniciar sesión para usar este cupón.'];
            }
        }

        // --- PASO B: VALIDACIÓN DE LÍMITES Y REQUISITOS DE CLIENTE ---
        
        // 1. Límite de Usos Globales (AQUÍ ES DONDE QUEREMOS EL MENSAJE TROLL)
        if ($cupon['limite_usos'] !== null && $cupon['usos_actuales'] >= $cupon['limite_usos']) {
            return [
                'status' => 'error', 
                'message' => 'Este cupón ha alcanzado su límite de usos.', // Mensaje técnico por defecto
                // Enviamos 'data' SOLO aquí para que get_checkout_state detecte el msg_error
                'data' => ['msg_error' => $cupon['msg_error']] 
            ];
        }

        // 2. Resto de validaciones (NO envían 'data', así que mostrarán el error normal)
        if ($cupon['limite_por_ip'] !== null) {
            $stmt_ip = $conn->prepare("SELECT COUNT(*) FROM cupones_usos_tracking WHERE cupon_id = ? AND ip_usuario = ?");
            $stmt_ip->bind_param("is", $cupon['id'], $ip_cliente); $stmt_ip->execute();
            $usos_ip = $stmt_ip->get_result()->fetch_row()[0]; $stmt_ip->close();
            if ($usos_ip >= $cupon['limite_por_ip']) {
                return ['status' => 'error', 'message' => 'Has alcanzado el límite de uso para este cupón desde tu conexión.'];
            }
        }
        if ($cupon['limite_por_whatsapp'] !== null && !empty($whatsapp_cliente)) {
            $stmt_wha = $conn->prepare("SELECT COUNT(*) FROM cupones_usos_tracking WHERE cupon_id = ? AND whatsapp_usuario = ?");
            $stmt_wha->bind_param("is", $cupon['id'], $whatsapp_cliente); $stmt_wha->execute();
            $usos_whatsapp = $stmt_wha->get_result()->fetch_row()[0]; $stmt_wha->close();
            if ($usos_whatsapp >= $cupon['limite_por_whatsapp']) {
                return ['status' => 'error', 'message' => 'Este número de WhatsApp ya ha alcanzado el límite de uso para este cupón.'];
            }
        }
        if ($cupon['limite_por_usuario'] !== null && $cupon['limite_por_usuario'] > 0) {
            // Validación por cuenta de usuario logeado
            if ($user_id) {
                $stmt_user_limit = $conn->prepare("SELECT COUNT(*) FROM cupones_usos_tracking WHERE cupon_id = ? AND user_id = ?");
                $stmt_user_limit->bind_param("ii", $cupon['id'], $user_id);
                $stmt_user_limit->execute();
                $usos_user = $stmt_user_limit->get_result()->fetch_row()[0];
                $stmt_user_limit->close();
                if ($usos_user >= $cupon['limite_por_usuario']) {
                    return ['status' => 'error', 'message' => 'Ya alcanzaste el límite de usos de este cupón en tu cuenta.'];
                }
            }

            // Validación por destinatarios (fallback / complemento)
            $destinatarios_unicos = array_unique(array_filter($destinatarios_input));
            if (!empty($destinatarios_unicos)) {
                $destinatarios_lower = array_map('strtolower', $destinatarios_unicos);
                $placeholders = implode(',', array_fill(0, count($destinatarios_lower), '?'));
                $stmt_dest = $conn->prepare("SELECT destinatario_epic_id, COUNT(*) as total_usos FROM cupones_usos_destinatario WHERE cupon_id = ? AND destinatario_epic_id IN ($placeholders) GROUP BY destinatario_epic_id");
                $types = 'i' . str_repeat('s', count($destinatarios_lower));
                $params = array_merge([$cupon['id']], $destinatarios_lower);
                $stmt_dest->bind_param($types, ...$params);
                $stmt_dest->execute();
                $usos_por_destinatario = $stmt_dest->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt_dest->close();
                $usos_map = array_column($usos_por_destinatario, 'total_usos', 'destinatario_epic_id');
                foreach ($destinatarios_unicos as $destinatario) {
                    $usos_actuales = $usos_map[strtolower($destinatario)] ?? 0;
                    if ($usos_actuales >= $cupon['limite_por_usuario']) {
                        return ['status' => 'error', 'message' => "El destinatario '{$destinatario}' ya ha alcanzado el límite de regalos con este cupón."];
                    }
                }
            }
        }
        if ($cupon['compras_minimas_requeridas'] > 0) {
            if (empty($whatsapp_cliente)) {
                return ['status' => 'error', 'message' => 'Este cupón requiere un historial de compras. Por favor, ingresa tu número de WhatsApp.'];
            }
            $stmt_historial = $conn->prepare("SELECT COUNT(*) FROM pedidos WHERE whatsapp_cliente = ? AND estado_pago = 'completado'");
            $stmt_historial->bind_param("s", $whatsapp_cliente);
            $stmt_historial->execute();
            $compras_previas = $stmt_historial->get_result()->fetch_row()[0];
            $stmt_historial->close();
            if ($compras_previas < $cupon['compras_minimas_requeridas']) {
                $faltantes = $cupon['compras_minimas_requeridas'] - $compras_previas;
                return ['status' => 'error', 'message' => "Este cupón es para clientes frecuentes. Te falta(n) {$faltantes} compra(s) para poder usarlo."];
            }
        }

// ==============================================================================
// === INICIO: REEMPLAZAR DESDE "PASO C" HASTA EL FINAL DE LA FUNCIÓN ===
// ==============================================================================

// ==============================================================================
// === INICIO DEL BLOQUE FINAL Y COMPLETO PARA REEMPLAZAR (con toda la lógica restaurada) ===
// ==============================================================================

// ==============================================================================
// === INICIO DEL BLOQUE A REEMPLAZAR ===
// ==============================================================================

// ==============================================================================
// === INICIO DEL BLOQUE ÚNICO Y COMPLETO PARA REEMPLAZAR ===
// ==============================================================================

// --- PASO C: VALIDACIÓN DE REGLAS DE APLICABILIDAD ---
$stmt_reglas = $conn->prepare("SELECT * FROM cupones_reglas_aplicabilidad WHERE cupon_id = ?");
$stmt_reglas->bind_param("i", $cupon['id']); $stmt_reglas->execute();
$reglas = $stmt_reglas->get_result()->fetch_all(MYSQLI_ASSOC); $stmt_reglas->close();
if (empty($reglas)) $reglas[] = ['tipo_regla' => 'global', 'valor_regla' => 'global'];

$productos_aplicables = [];
$subtotal_carrito = array_reduce($productos_carrito, function($sum, $item) {
    return $sum + (($item['precio'] ?? 0) * ($item['cantidad'] ?? 1));
}, 0);

if ($cupon['gasto_minimo'] && $subtotal_carrito < $cupon['gasto_minimo']) {
    return ['status' => 'error', 'message' => 'El total de tu compra no alcanza el mínimo requerido de S/ ' . number_format($cupon['gasto_minimo'], 2)];
}

// NUEVA FUNCIÓN DE AYUDA DENTRO DEL ALCANCE
if (!function_exists('itemCumpleReglaLocal')) {
    function itemCumpleReglaLocal($producto, $regla) {
        $tipo_producto_carrito = $producto['tipo'] ?? null;
        $es_estatico = isset($producto['is_static']) && $producto['is_static'] === true;
        $id_producto_carrito = $producto['offerId'] ?? null;
        $seccion_producto_carrito = $producto['section'] ?? null;
        switch ($regla['tipo_regla']) {
            case 'global': return true;
            case 'global_sin_estaticos': return !$es_estatico;
            case 'producto_especifico': case 'producto_estatico': return ($id_producto_carrito !== null && $id_producto_carrito == $regla['valor_regla']);
            case 'seccion_tienda': return ($seccion_producto_carrito !== null && strtolower($seccion_producto_carrito) === strtolower($regla['valor_regla']));
            case 'categoria_api': case 'categoria_estatica':
                if ($tipo_producto_carrito !== null && strtolower($tipo_producto_carrito) === strtolower($regla['valor_regla'])) return true;
                if ($tipo_producto_carrito !== null && strtolower($regla['valor_regla']) === 'lote' && str_contains(strtolower($tipo_producto_carrito), 'lote')) return true;
                return false;
            default: return false;
        }
    }
}

// --- LÓGICA DE VALIDACIÓN CENTRAL (COMBO vs NORMAL) ---
if (!empty($cupon['requiere_todos_los_productos']) && $cupon['requiere_todos_los_productos'] == 1) {
    // MODO COMBO (AND): TODAS las reglas deben cumplirse
    $reglas_cumplidas_check = [];
    foreach ($reglas as $index => $regla) {
        $reglas_cumplidas_check[$index] = false;
        foreach ($productos_carrito as $producto) {
            if (itemCumpleReglaLocal($producto, $regla)) {
                $reglas_cumplidas_check[$index] = true;
                break; 
            }
        }
    }
    if (in_array(false, $reglas_cumplidas_check, true)) {
        return ['status' => 'error', 'message' => 'Este cupón requiere una combinación específica de productos que no se encuentra en tu carrito.'];
    }
    foreach($productos_carrito as $producto) {
        foreach($reglas as $regla) {
            if(itemCumpleReglaLocal($producto, $regla)) {
                if(!isset($productos_aplicables[$producto['offerId']])) { 
                     $productos_aplicables[$producto['offerId']] = $producto;
                }
            }
        }
    }
} else {
    // MODO NORMAL (OR): CUALQUIER producto puede cumplir CUALQUIER regla
    foreach ($productos_carrito as $producto) {
        foreach ($reglas as $regla) {
            if (itemCumpleReglaLocal($producto, $regla)) {
                if(!isset($productos_aplicables[$producto['offerId']])) { 
                    $productos_aplicables[$producto['offerId']] = $producto;
                }
            }
        }
    }
}
$productos_aplicables = array_values($productos_aplicables);

if (empty($productos_aplicables)) {
    return ['status' => 'error', 'message' => 'El cupón no es válido para los productos en tu carrito.'];
}

// --- VALIDACIÓN DE CANTIDAD MÍNIMA ---
$cantidad_requerida = (int)($cupon['cantidad_minima_compra'] ?? 1);
if ($cantidad_requerida > 1) {
    $cumple_cantidad = false;
    foreach ($productos_aplicables as $p) {
        if ((int)($p['cantidad'] ?? 1) >= $cantidad_requerida) {
            $cumple_cantidad = true;
            break;
        }
    }
    if (!$cumple_cantidad) {
        return ['status' => 'error', 'message' => "Debes llevar al menos {$cantidad_requerida} unidades de un producto en promoción para usar este cupón."];
    }
}

// --- PASO D: CÁLCULO FINAL DEL DESCUENTO ---
$subtotal_base_aplicable = 0;
$es_descuento_por_item_unico = !empty($cupon['aplicar_a_un_solo_item']) && $cupon['aplicar_a_un_solo_item'] == 1;

if ($es_descuento_por_item_unico) {
    if(!empty($productos_aplicables)){
        $primer_producto = $productos_aplicables[0];
        $precio_base_producto = (float)($primer_producto['precio_base'] ?? $primer_producto['precio']);
        $subtotal_base_aplicable = $precio_base_producto;
    }
} else {
    foreach ($productos_aplicables as $producto) {
        $precio_base_producto = (float)($producto['precio_base'] ?? $producto['precio']);
        $cantidad_producto = (int)($producto['cantidad'] ?? 1);
        $subtotal_base_aplicable += $precio_base_producto * $cantidad_producto;
    }
}

$descuento_calculado = 0;
if ($cupon['tipo_descuento'] === 'porcentaje') {
    $descuento_calculado = $subtotal_base_aplicable * ((float)$cupon['valor_descuento'] / 100);
} else {
    $descuento_calculado = (float)$cupon['valor_descuento'];
}
if ($cupon['descuento_maximo'] !== null && $descuento_calculado > (float)$cupon['descuento_maximo']) {
    $descuento_calculado = (float)$cupon['descuento_maximo'];
}
/* COMENTADO PARA PERMITIR TOTALES NEGATIVOS (OVER-DISCOUNT)
if ($descuento_calculado > $subtotal_base_aplicable) {
    $descuento_calculado = $subtotal_base_aplicable;
}
*/
$descuento_final = round($descuento_calculado, 2);
$nuevo_total = $subtotal_carrito - $descuento_final;

// ==============================================================================
// === INICIO DEL BLOQUE FINAL Y CORREGIDO PARA REEMPLAZAR ===
// ==============================================================================

// --- GENERAR DATOS PARA LA INTERFAZ DE USUARIO ---
$productos_afectados_data = [];
if (!empty($productos_aplicables) && $subtotal_base_aplicable > 0) {
    
    // Si el descuento es para un solo item, la lógica es simple.
    if ($es_descuento_por_item_unico) {
        $producto = $productos_aplicables[0];
        $precio_base_real = (float)($producto['precio_base'] ?? $producto['precio']);
        $precio_final_item = $precio_base_real - $descuento_final;

        $productos_afectados_data[] = [
            'id' => $producto['offerId'],
            'precio_original' => round($precio_base_real, 2),
            'precio_final' => round(max(0, $precio_final_item), 2)
        ];
    } else {
        // Si el descuento se aplica a múltiples items, lo distribuimos proporcionalmente.
        foreach ($productos_aplicables as $producto) {
            $precio_base_real = (float)($producto['precio_base'] ?? $producto['precio']);
            $cantidad = (int)($producto['cantidad'] ?? 1);
            $valor_base_total_item = $precio_base_real * $cantidad;
            
            // Calcular qué porción del total aplicable representa este item.
            $proporcion_del_total = $valor_base_total_item / $subtotal_base_aplicable;
            
            // Aplicar esa misma proporción al descuento total final.
            $descuento_para_este_item = $descuento_final * $proporcion_del_total;
            
            // El precio final es el precio original menos su parte del descuento.
            $precio_final_con_descuento = $valor_base_total_item - $descuento_para_este_item;

            $productos_afectados_data[] = [
                'id' => $producto['offerId'],
                // El precio original que mostramos es por unidad
                'precio_original' => round($precio_base_real, 2), 
                // El precio final lo calculamos por unidad también
                'precio_final' => round(max(0, $precio_final_con_descuento / $cantidad), 2)
            ];
        }
    }
}

// --- RESPUESTA DE ÉXITO ---
return [
    'status' => 'success',
    'message' => '¡Cupón aplicado con éxito!',
    'data' => [
        'cupon_id'             => $cupon['id'],
        'codigo'               => $cupon['codigo'],
        'descripcion'          => $cupon['descripcion'],
        'descuento_aplicado'   => $descuento_final,
        'nuevo_total'          => $nuevo_total,
        'productos_afectados'  => $productos_afectados_data,
        // Indica si el cupón solo descuenta UNA unidad del producto afectado.
        // El frontend lo usa para pintar el total de la fila sin prorratear.
        'aplicar_a_un_solo_item' => $es_descuento_por_item_unico,
        
        // Datos extra
        'msg_exito'            => $cupon['msg_exito'] ?? null,
        'msg_error'            => $cupon['msg_error'] ?? null,
        'msg_confirmacion'     => $cupon['msg_confirmacion'] ?? null,
        'audio_url'            => $cupon['audio_url'] ?? null,
        'imagen_url'           => $cupon['imagen_url'] ?? null,
        'modo_efecto'          => $cupon['modo_efecto'] ?? null,

        // Advertencia configurable que muestra el checkout en su cola de modales
        'advertencia_activa'   => !empty($cupon['advertencia_activa']),
        'advertencia_texto'    => $cupon['advertencia_texto'] ?? null,
        
        // --- NUEVO: CONFIGURACIÓN DEL QUIZ ---
        // Decodificamos el JSON aquí para enviarlo como objeto limpio a JS
        'quiz_config'          => !empty($cupon['quiz_config']) ? json_decode($cupon['quiz_config']) : null
    ]
];

// ==============================================================================
// === FIN DEL BLOQUE FINAL Y CORREGIDO ===
// ==============================================================================

// ==============================================================================
// === FIN DEL BLOQUE ÚNICO Y COMPLETO PARA REEMPLAZAR ===
// ==============================================================================
// ==============================================================================
// === FIN DEL BLOQUE FINAL Y COMPLETO PARA REEMPLAZAR ==========================
// ==============================================================================
    }
}
?>
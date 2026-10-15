<?php
// Archivo: api_productos.php (v5.6 - CORRECCIÓN CRÍTICA de bind_param)
require_once __DIR__ . '/../../api/session_security.php';
require_once __DIR__ . '/../core/db_connect.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Content-Type: application/json'); http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso no autorizado.']);
    exit;
}
header('Content-Type: application/json; charset=utf-8');

function format_date_for_mysql($date_string) {
    if (empty($date_string) || $date_string === 'null' || $date_string === 'undefined') return null;
    try { return (new DateTime($date_string))->format('Y-m-d H:i:s'); } catch (Exception $e) { return null; }
}

$accion   = $_POST['accion'] ?? '';
$response = [];

try {
    $conn->begin_transaction();
    switch ($accion) {
        case 'add':
        case 'edit':
            $is_edit = ($accion === 'edit');
            $id = $is_edit ? (int)($_POST['id'] ?? 0) : 0;
            
            // Recopilar TODOS los datos del formulario
            $nombre = trim($_POST['nombre'] ?? '');
            $descripcion = $_POST['descripcion'] ?? '';
            $precio_base = (float)($_POST['precio_base'] ?? 0);
            $stock_cantidad = (int)($_POST['stock_cantidad'] ?? -1);
            $categoria = trim($_POST['categoria'] ?? '');
            $imagen_url = !empty(trim($_POST['imagen_url'])) ? trim($_POST['imagen_url']) : NULL;
            $fecha_expiracion = format_date_for_mysql($_POST['fecha_expiracion'] ?? null);
            $tipo_oferta = $_POST['tipo_oferta'] ?? 'precio_fijo';
            $monedas_otorgadas = (int)($_POST['monedas_otorgadas'] ?? 0);
            $permitir_canje = isset($_POST['permitir_canje_monedas']) ? 1 : 0;
            $requiere_nick = isset($_POST['requiere_nickname']) ? 1 : 0;
            $dar_cashback = isset($_POST['dar_cashback']) ? 1 : 0;
            $activar_tutorial = isset($_POST['activar_tutorial']) ? 1 : 0;
            $mensaje_tutorial = trim($_POST['mensaje_tutorial'] ?? '');
            $modo_reserva = isset($_POST['modo_reserva']) ? 1 : 0;
            $mensaje_reserva = trim($_POST['mensaje_reserva'] ?? '');
            $valor_oferta = (!empty($_POST['valor_oferta']) && $_POST['valor_oferta'] !== '') ? (float)$_POST['valor_oferta'] : null;
            $oferta_fecha_expiracion = format_date_for_mysql($_POST['oferta_fecha_expiracion'] ?? null);
            $precio_oferta_final = null;
            if ($valor_oferta !== null) {
                if ($tipo_oferta === 'porcentaje' && $valor_oferta <= 100) { $precio_oferta_final = $precio_base * (1 - ($valor_oferta / 100)); } 
                elseif ($tipo_oferta === 'precio_fijo') { $precio_oferta_final = $valor_oferta; }
            }
                                    $urgencia_horas = (int)($_POST['urgencia_horas'] ?? 24);
            // === CORRECCIÓN DEFINITIVA ETIQUETA NULA ===
            // Se toma el valor enviado. trim() elimina espacios al inicio/final.
            // Si no se envía 'etiqueta_texto', se establece como una cadena vacía ''.
            // De esta forma, si el usuario borra el texto, se guardará como vacío.
            $etiqueta_texto = trim($_POST['etiqueta_texto'] ?? '');
            $etiqueta_posicion = (int)($_POST['etiqueta_posicion'] ?? 1);
            $etiqueta_color_fondo = $_POST['etiqueta_color_fondo'] ?? '#22c55e';
            $etiqueta_color_texto = $_POST['etiqueta_color_texto'] ?? '#ffffff';
            $precio_oferta_color = $_POST['precio_oferta_color'] ?? '#22c55e';
            $timer_color_fondo = $_POST['timer_color_fondo'] ?? '#18181b';
            $timer_color_texto = $_POST['timer_color_texto'] ?? '#ffffff';

            if (($is_edit && $id <= 0) || empty($nombre) || $precio_base <= 0 || empty($categoria)) {
                throw new Exception('Datos insuficientes para la operación.');
            }

            if ($is_edit) {
                $stmt = $conn->prepare("UPDATE productos_estaticos SET 
                    nombre = ?, descripcion = ?, stock_cantidad = ?, precio_base = ?, precio_oferta = ?, tipo_oferta = ?, oferta_fecha_expiracion = ?, 
                    imagen_url = ?, categoria = ?, fecha_expiracion = ?, urgencia_horas = ?, etiqueta_texto = ?, etiqueta_posicion = ?, 
                    etiqueta_color_fondo = ?, etiqueta_color_texto = ?, precio_oferta_color = ?, timer_color_fondo = ?, timer_color_texto = ?,
                    monedas_otorgadas = ?, permitir_canje_monedas = ?, requiere_nickname = ?, dar_cashback = ?,
                    activar_tutorial = ?, mensaje_tutorial = ?, modo_reserva = ?, mensaje_reserva = ? WHERE id = ?");
                $stmt->bind_param("ssiddsssssisssssssiiiiisisi", 
                    $nombre,              // s  1
                    $descripcion,         // s  2
                    $stock_cantidad,      // i  3
                    $precio_base,         // d  4
                    $precio_oferta_final, // d  5
                    $tipo_oferta,         // s  6
                    $oferta_fecha_expiracion, // s  7
                    $imagen_url,          // s  8
                    $categoria,           // s  9
                    $fecha_expiracion,    // s  10
                    $urgencia_horas,      // i  11
                    $etiqueta_texto,      // s  12
                    $etiqueta_posicion,   // s  13
                    $etiqueta_color_fondo,// s  14
                    $etiqueta_color_texto,// s  15
                    $precio_oferta_color, // s  16
                    $timer_color_fondo,   // s  17
                    $timer_color_texto,   // s  18
                    $monedas_otorgadas,   // i  19
                    $permitir_canje,      // i  20
                    $requiere_nick,       // i  21
                    $dar_cashback,        // i  22
                    $activar_tutorial,    // i  23
                    $mensaje_tutorial,    // s  24
                    $modo_reserva,        // i  25
                    $mensaje_reserva,     // s  26
                    $id                   // i  27
                );
            } else {
                $orden_cat_res = $conn->query("SELECT orden_categoria FROM productos_estaticos WHERE categoria = '{$conn->real_escape_string($categoria)}' LIMIT 1");
                $orden_categoria = $orden_cat_res && $orden_cat_res->num_rows > 0 ? $orden_cat_res->fetch_assoc()['orden_categoria'] : 99;
                $orden_prod_res = $conn->query("SELECT MAX(orden) as max_orden FROM productos_estaticos WHERE categoria = '{$conn->real_escape_string($categoria)}'");
                $orden = $orden_prod_res ? ($orden_prod_res->fetch_assoc()['max_orden'] ?? 0) + 1 : 1;
                $stmt = $conn->prepare("INSERT INTO productos_estaticos 
                    (nombre, descripcion, stock_cantidad, precio_base, precio_oferta, tipo_oferta, oferta_fecha_expiracion, 
                     imagen_url, categoria, orden, orden_categoria, fecha_expiracion, activo, 
                     urgencia_horas, etiqueta_texto, etiqueta_posicion, etiqueta_color_fondo, etiqueta_color_texto, 
                     precio_oferta_color, timer_color_fondo, timer_color_texto, 
                     monedas_otorgadas, permitir_canje_monedas, requiere_nickname, dar_cashback,
                     activar_tutorial, mensaje_tutorial, modo_reserva, mensaje_reserva) 
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param("ssiddssssiisisssssssiiiiisis", 
                    $nombre, $descripcion, $stock_cantidad, $precio_base, $precio_oferta_final, 
                    $tipo_oferta, $oferta_fecha_expiracion, $imagen_url, $categoria, 
                    $orden, $orden_categoria, $fecha_expiracion, 
                    $urgencia_horas, $etiqueta_texto, $etiqueta_posicion, 
                    $etiqueta_color_fondo, $etiqueta_color_texto, $precio_oferta_color, 
                    $timer_color_fondo, $timer_color_texto, 
                    $monedas_otorgadas, $permitir_canje, $requiere_nick, $dar_cashback,
                    $activar_tutorial, $mensaje_tutorial, $modo_reserva, $mensaje_reserva);
            }
            if (!$stmt->execute()) { throw new Exception('Error al guardar: ' . $stmt->error); }
            $stmt->close();
            $response = ['status' => 'success', 'message' => 'Producto ' . ($is_edit ? 'actualizado' : 'añadido') . '.'];
            break;

        case 'toggle_status':
            $id = (int)($_POST['id'] ?? 0); $status = (int)($_POST['status'] ?? 0); if ($id <= 0) { throw new Exception('ID de producto no válido.'); } if ($status == 1) { $cat_stmt = $conn->prepare("SELECT categoria FROM productos_estaticos WHERE id = ?"); if (!$cat_stmt) throw new Exception("Error preparando consulta: " . $conn->error); $cat_stmt->bind_param("i", $id); $cat_stmt->execute(); $cat_result = $cat_stmt->get_result(); if ($cat_result->num_rows === 0) throw new Exception("Producto no encontrado."); $categoria = $cat_result->fetch_assoc()['categoria']; $cat_stmt->close(); $order_stmt = $conn->prepare("SELECT MAX(orden) as max_orden FROM productos_estaticos WHERE categoria = ? AND activo = 1"); if (!$order_stmt) throw new Exception("Error preparando consulta: " . $conn->error); $order_stmt->bind_param("s", $categoria); $order_stmt->execute(); $order_result = $order_stmt->get_result()->fetch_assoc(); $nuevo_orden = ($order_result['max_orden'] ?? 0) + 1; $order_stmt->close(); $stmt = $conn->prepare("UPDATE productos_estaticos SET activo = 1, orden = ?, fecha_expiracion = NULL WHERE id = ?"); if (!$stmt) throw new Exception("Error preparando consulta de actualización: " . $conn->error); $stmt->bind_param("ii", $nuevo_orden, $id); } else { $stmt = $conn->prepare("UPDATE productos_estaticos SET activo = 0 WHERE id = ?"); if (!$stmt) throw new Exception("Error preparando consulta de desactivación: " . $conn->error); $stmt->bind_param("i", $id); } if (!$stmt->execute()) { throw new Exception('Error al cambiar estado: ' . $stmt->error); } $response = ['status' => 'success', 'message' => 'Estado actualizado.']; $stmt->close();
            break;

        case 'set_stock':
            $id = (int)($_POST['id'] ?? 0); $stock = (int)($_POST['stock']); if ($id <= 0) throw new Exception("ID de producto no válido."); $stmt = $conn->prepare("UPDATE productos_estaticos SET stock_cantidad = ? WHERE id = ?"); $stmt->bind_param("ii", $stock, $id); if (!$stmt->execute()) throw new Exception("Error al actualizar el stock."); $response = ['status' => 'success', 'message' => 'Stock del producto actualizado.'];
            break;

        case 'set_stock_by_category':
            $category = trim($_POST['category'] ?? '');
            $stock = (int)($_POST['stock']);
            if (empty($category)) throw new Exception("Categoría no válida.");
            
            $stmt = $conn->prepare("UPDATE productos_estaticos SET stock_cantidad = ? WHERE categoria = ? AND activo = 1");
            $stmt->bind_param("is", $stock, $category);
            if (!$stmt->execute()) throw new Exception("Error al actualizar stock de la categoría.");
            
            $message = $stock == 0 ? "Stock de la categoría '$category' agotado." : "Stock de la categoría '$category' restaurado a infinito.";
            $response = ['status' => 'success', 'message' => $message];
            break;

        case 'set_all_stock':
            $stock = (int)($_POST['stock']); $conn->query("UPDATE productos_estaticos SET stock_cantidad = $stock WHERE activo = 1"); $message = $stock == 0 ? 'Todos los productos activos han sido agotados.' : 'El stock de todos los productos activos ha sido restaurado a infinito.'; $response = ['status' => 'success', 'message' => $message];
            break;

        case 'reorder_product':
            $id = (int)($_POST['id'] ?? 0);
            $direction = $_POST['direction'] ?? '';

            if ($id <= 0 || !in_array($direction, ['up', 'down'])) {
                throw new Exception('Datos para reordenar no válidos.');
            }

            $stmt_current = $conn->prepare("SELECT orden, categoria FROM productos_estaticos WHERE id = ?");
            $stmt_current->bind_param("i", $id);
            $stmt_current->execute();
            $current_product = $stmt_current->get_result()->fetch_assoc();
            $stmt_current->close();

            if (!$current_product) throw new Exception('Producto a mover no encontrado.');
            $current_orden = $current_product['orden']; $categoria = $current_product['categoria'];
            
            $op = $direction === 'up' ? '<' : '>';
            $order_by = $direction === 'up' ? 'DESC' : 'ASC';
            $stmt_other = $conn->prepare("SELECT id, orden FROM productos_estaticos WHERE categoria = ? AND activo = 1 AND orden $op ? ORDER BY orden $order_by LIMIT 1");
            $stmt_other->bind_param("si", $categoria, $current_orden);
            $stmt_other->execute();
            $other_product = $stmt_other->get_result()->fetch_assoc();
            $stmt_other->close();
            
            if ($other_product) {
                $other_id = $other_product['id']; $other_orden = $other_product['orden'];
                $stmt_update1 = $conn->prepare("UPDATE productos_estaticos SET orden = ? WHERE id = ?");
                $stmt_update1->bind_param("ii", $other_orden, $id); $stmt_update1->execute(); $stmt_update1->close();
                $stmt_update2 = $conn->prepare("UPDATE productos_estaticos SET orden = ? WHERE id = ?");
                $stmt_update2->bind_param("ii", $current_orden, $other_id); $stmt_update2->execute(); $stmt_update2->close();
            }
            
            $response = ['status' => 'success', 'message' => 'Producto reordenado.'];
            break;
        
        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { throw new Exception('ID no válido para eliminar.'); }
            $stmt = $conn->prepare("DELETE FROM productos_estaticos WHERE id = ?");
            $stmt->bind_param("i", $id);
            if (!$stmt->execute()) { throw new Exception('Error al eliminar producto: ' . $stmt->error); }
            $stmt->close();
            $response = ['status' => 'success', 'message' => 'Producto eliminado correctamente.'];
            break;

        default:
             throw new Exception('Acción no reconocida.');
    }
    
    $conn->commit();
} catch (Exception $e) {
    if ($conn->in_transaction) $conn->rollback();
    http_response_code(400); 
    $response = ['status' => 'error', 'message' => $e->getMessage()];
}
echo json_encode($response, JSON_NUMERIC_CHECK);
$conn->close();
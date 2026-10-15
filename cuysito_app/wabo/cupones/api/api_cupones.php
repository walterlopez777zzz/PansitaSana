<?php
// Archivo: wabo/cupones/api/api_cupones.php (Versión 2.7 - Funcionalidad Total)

ob_start(); ini_set('display_errors', 1); error_reporting(E_ALL);
require_once __DIR__ . '/../../../api/session_security.php';
// --- AÑADE ESTA LÍNEA AQUÍ ---
date_default_timezone_set('America/Lima');
require_once __DIR__ . '/../../core/db_connect.php'; 
require_once __DIR__ . '/../../core/shop_helper.php';
require_once __DIR__ . '/../../../core/AvatarCatalog.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    ob_end_clean(); header('Content-Type: application/json'); http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso no autorizado.']); exit;
}

ob_end_clean(); header('Content-Type: application/json; charset=utf-8');

$response = []; $method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';
        switch($action) {
case 'get_initial_data':
            error_log("[DEBUG-LOG] Entrando en get_initial_data...");

            // 1. Obtener Cupones
            $cupones_sql = "
                SELECT c.*, 
                       (SELECT JSON_ARRAYAGG(JSON_OBJECT('tipo', ra.tipo_regla, 'valor', ra.valor_regla)) 
                        FROM cupones_reglas_aplicabilidad ra 
                        WHERE ra.cupon_id = c.id) as reglas_aplicabilidad
                FROM cupones c 
                ORDER BY c.id DESC
            ";
            $cupones = $conn->query($cupones_sql)->fetch_all(MYSQLI_ASSOC);
            
            // 2. Obtener Productos Estáticos
            $productos_estaticos = $conn->query("SELECT id, nombre as name, categoria FROM productos_estaticos WHERE activo = 1 ORDER BY orden_categoria, orden ASC")->fetch_all(MYSQLI_ASSOC);
            $categorias_estaticas = $conn->query("SELECT DISTINCT categoria FROM productos_estaticos WHERE activo = 1 AND categoria IS NOT NULL AND categoria != '' ORDER BY categoria ASC")->fetch_all(MYSQLI_ASSOC);
            
            // 3. ESCANEO DE CARPETAS FX
            // La raíz pública se resuelve dinámicamente (DOCUMENT_ROOT o carpeta
            // hermana con web.php), sin asumir el nombre de la carpeta pública.
            
            $base_fx_path = '/assets/fx'; // Para la URL (frontend)
            
            $server_fx_path = AvatarCatalog::publicRoot() . $base_fx_path;
            
            // LOG DE DEBUG: Para ver en el archivo error_log si la ruta es correcta
            error_log("[DEBUG-FX] Buscando archivos en: " . $server_fx_path);
            
            $audios_files = [];
            $stickers_files = [];

// Escanear carpeta de Audio
            if (is_dir($server_fx_path . '/audio')) {
                $files = scandir($server_fx_path . '/audio');
                $found_count = 0;
                foreach ($files as $file) {
                    if ($file !== '.' && $file !== '..') {
                        $audios_files[] = $base_fx_path . '/audio/' . $file;
                        $found_count++;
                    }
                }
                // LOG EXTRA: Nos dirá cuántos archivos encontró
                error_log("[DEBUG-FX] Escaneo de audio completado. Encontrados: " . $found_count . " archivos. (Ruta: " . $server_fx_path . '/audio' . ")");
            } else {
                error_log("[DEBUG-FX] ERROR: No se encuentra la carpeta de audios en " . $server_fx_path . '/audio');
            }

            // Escanear carpeta de Stickers
            if (is_dir($server_fx_path . '/stickers')) {
                $files = scandir($server_fx_path . '/stickers');
                foreach ($files as $file) {
                    if ($file !== '.' && $file !== '..') {
                        $stickers_files[] = $base_fx_path . '/stickers/' . $file;
                    }
                }
            } else {
                error_log("[DEBUG-FX] ERROR: No se encuentra la carpeta de stickers en " . $server_fx_path . '/stickers');
            }

            // 4. Obtener datos de Fortnite API
            $shopHelperResult = get_fortnite_shop_data();
            
            // 5. Armar respuesta
            $response = ['status' => 'success', 'data' => [
                'cupones' => $cupones, 
                'shopData' => [
                    'result' => true, 
                    'sections' => $shopHelperResult['sections'] ?? []
                ], 
                'productosEstaticos' => $productos_estaticos, 
                'categoriasEstaticas' => array_column($categorias_estaticas, 'categoria'), 
                'apiCategories' => $shopHelperResult['apiCategories'] ?? [],
                // Enviamos los archivos encontrados al JS
                'fxFiles' => [
                    'audios' => $audios_files,
                    'stickers' => $stickers_files
                ]
            ]];
            break;
            default: throw new Exception("Acción GET no reconocida.");
        }
    } elseif ($method === 'POST') {
        $conn->begin_transaction();
        $action = $_POST['action'] ?? '';
        switch($action) {
// REEMPLAZA ESTE BLOQUE ENTERO EN api_cupones.php
// REEMPLAZA ESTE BLOQUE ENTERO EN api_cupones.php
case 'save_coupon':
            $id = (int)($_POST['id'] ?? 0);
            $codigo = strtoupper(trim($_POST['codigo']));
            $descripcion = trim($_POST['descripcion']);
            $tipo_descuento = $_POST['tipo_descuento'];
            $valor_descuento = (float)$_POST['valor_descuento'];
            $gasto_minimo = !empty($_POST['gasto_minimo']) ? (float)$_POST['gasto_minimo'] : null;
            $descuento_maximo = !empty($_POST['descuento_maximo']) ? (float)$_POST['descuento_maximo'] : null;
            $limite_usos = !empty($_POST['limite_usos']) ? (int)$_POST['limite_usos'] : null;
            $limite_por_usuario = !empty($_POST['limite_por_usuario']) ? (int)$_POST['limite_por_usuario'] : 1;
            $limite_por_ip = !empty($_POST['limite_por_ip']) ? (int)$_POST['limite_por_ip'] : null;
            $limite_por_whatsapp = !empty($_POST['limite_por_whatsapp']) ? (int)$_POST['limite_por_whatsapp'] : null;
            $compras_minimas_requeridas = !empty($_POST['compras_minimas_requeridas']) ? (int)$_POST['compras_minimas_requeridas'] : 0;
            $cantidad_minima_compra = !empty($_POST['cantidad_minima_compra']) ? (int)$_POST['cantidad_minima_compra'] : 1;

            // --- LÓGICA DE FECHAS ---
            $fecha_inicio = null;
            if ($_POST['tipo_inicio'] === 'especifico' && !empty($_POST['fecha_inicio'])) { $fecha_inicio = $_POST['fecha_inicio']; }
            
            $fecha_expiracion = null;
            switch ($_POST['tipo_expiracion']) {
                case 'especifica': if (!empty($_POST['fecha_expiracion_especifica'])) { $fecha_expiracion = $_POST['fecha_expiracion_especifica'] . ' 23:59:59'; } break;
                case 'temporizador':
                    $horas = (int)($_POST['temporizador_horas'] ?? 0); $minutos = (int)($_POST['temporizador_minutos'] ?? 0);
                    $total_minutos = ($horas * 60) + $minutos;
                    if ($total_minutos > 0) {
                        $start_date = $fecha_inicio ? new DateTime($fecha_inicio) : new DateTime();
                        $fecha_expiracion = $start_date->add(new DateInterval("PT{$total_minutos}M"))->format('Y-m-d H:i:s');
                    }
                    break;
                case 'exacta': if (!empty($_POST['fecha_expiracion_exacta'])) { $fecha_expiracion = $_POST['fecha_expiracion_exacta']; } break;
            }

            $permite_pago_automatico = isset($_POST['permite_pago_automatico']) ? 1 : 0;
            $tiempo_limite_pago = !empty($_POST['tiempo_limite_pago_minutos']) ? (int)$_POST['tiempo_limite_pago_minutos'] : null;
            $aplicar_a_un_solo_item = isset($_POST['aplicar_a_un_solo_item']) ? 1 : 0;
            $requiere_todos_los_productos = isset($_POST['requiere_todos_los_productos']) ? 1 : 0;

            // --- CAMPOS DE CONTROL DE ACCESO ---
            $requiere_login = isset($_POST['requiere_login']) ? 1 : 0;
            $requiere_verificacion = isset($_POST['requiere_verificacion']) ? 1 : 0;

            // --- CAMPOS MULTIMEDIA, MENSAJES Y QUIZ ---
            $audio_url = !empty($_POST['audio_url']) ? trim($_POST['audio_url']) : null;
            $imagen_url = !empty($_POST['imagen_url']) ? trim($_POST['imagen_url']) : null;
            $modo_efecto = !empty($_POST['modo_efecto']) ? trim($_POST['modo_efecto']) : 'ninguno';
            $msg_exito = !empty($_POST['msg_exito']) ? trim($_POST['msg_exito']) : null;
            $msg_error = !empty($_POST['msg_error']) ? trim($_POST['msg_error']) : null;
            $msg_confirmacion = !empty($_POST['msg_confirmacion']) ? trim($_POST['msg_confirmacion']) : null;
            
            // NUEVO: Capturar configuración del Quiz
            $quiz_config = !empty($_POST['quiz_config']) ? $_POST['quiz_config'] : null;

            if ($id > 0) {
                // UPDATE
                // Añadimos quiz_config al final. 
                // Tipos: 18 base + 7 extra (audio,img,fx,msg1,msg2,msg3,quiz) + 1 id = 26 variables
                // String type: sssdddiiiissiiiiiis sssssss i
                $stmt = $conn->prepare("UPDATE cupones SET codigo=?, descripcion=?, tipo_descuento=?, valor_descuento=?, gasto_minimo=?, descuento_maximo=?, limite_usos=?, limite_por_usuario=?, limite_por_ip=?, limite_por_whatsapp=?, fecha_inicio=?, fecha_expiracion=?, permite_pago_automatico=?, compras_minimas_requeridas=?, cantidad_minima_compra=?, tiempo_limite_pago_minutos=?, aplicar_a_un_solo_item=?, requiere_todos_los_productos=?, requiere_login=?, requiere_verificacion=?, audio_url=?, imagen_url=?, modo_efecto=?, msg_exito=?, msg_error=?, msg_confirmacion=?, quiz_config=? WHERE id=?");
                
                $stmt->bind_param("sssdddiiiissiiiiiiiisssssssi", $codigo, $descripcion, $tipo_descuento, $valor_descuento, $gasto_minimo, $descuento_maximo, $limite_usos, $limite_por_usuario, $limite_por_ip, $limite_por_whatsapp, $fecha_inicio, $fecha_expiracion, $permite_pago_automatico, $compras_minimas_requeridas, $cantidad_minima_compra, $tiempo_limite_pago, $aplicar_a_un_solo_item, $requiere_todos_los_productos, $requiere_login, $requiere_verificacion, $audio_url, $imagen_url, $modo_efecto, $msg_exito, $msg_error, $msg_confirmacion, $quiz_config, $id);
            } else {
                // INSERT
                // Añadimos quiz_config al final.
                // Tipos: 18 base + 7 extra = 25 variables
                // String type: sssdddiiiissiiiiiis sssssss
                $stmt = $conn->prepare("INSERT INTO cupones (codigo, descripcion, tipo_descuento, valor_descuento, gasto_minimo, descuento_maximo, limite_usos, limite_por_usuario, limite_por_ip, limite_por_whatsapp, fecha_inicio, fecha_expiracion, permite_pago_automatico, compras_minimas_requeridas, cantidad_minima_compra, tiempo_limite_pago_minutos, aplicar_a_un_solo_item, requiere_todos_los_productos, requiere_login, requiere_verificacion, audio_url, imagen_url, modo_efecto, msg_exito, msg_error, msg_confirmacion, quiz_config, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                
                $stmt->bind_param("sssdddiiiissiiiiiiiisssssss", $codigo, $descripcion, $tipo_descuento, $valor_descuento, $gasto_minimo, $descuento_maximo, $limite_usos, $limite_por_usuario, $limite_por_ip, $limite_por_whatsapp, $fecha_inicio, $fecha_expiracion, $permite_pago_automatico, $compras_minimas_requeridas, $cantidad_minima_compra, $tiempo_limite_pago, $aplicar_a_un_solo_item, $requiere_todos_los_productos, $requiere_login, $requiere_verificacion, $audio_url, $imagen_url, $modo_efecto, $msg_exito, $msg_error, $msg_confirmacion, $quiz_config);
            }
            
            if (!$stmt->execute()) { throw new Exception($conn->errno == 1062 ? "El código '{$codigo}' ya existe." : "Error al guardar el cupón: " . $stmt->error); }
            $cupon_id = ($id > 0) ? $id : $conn->insert_id;
            $stmt->close();
            
            // Guardar reglas (Se mantiene igual)
            $conn->query("DELETE FROM cupones_reglas_aplicabilidad WHERE cupon_id = $cupon_id");
            $reglas = json_decode($_POST['reglas_aplicabilidad'], true);
            if (json_last_error() === JSON_ERROR_NONE && !empty($reglas)) {
                $stmt_reglas = $conn->prepare("INSERT INTO cupones_reglas_aplicabilidad (cupon_id, tipo_regla, valor_regla) VALUES (?, ?, ?)");
                foreach ($reglas as $regla) {
                    if (empty($regla['valor'])) continue;
                    $stmt_reglas->bind_param("iss", $cupon_id, $regla['tipo'], $regla['valor']);
                    $stmt_reglas->execute();
                }
                $stmt_reglas->close();
            }
            $response = ['status' => 'success', 'message' => 'Cupón guardado.'];
            break;

            case 'delete_coupon': // <<< NUEVO
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    $conn->query("DELETE FROM cupones_reglas_aplicabilidad WHERE cupon_id = $id");
                    $stmt = $conn->prepare("DELETE FROM cupones WHERE id = ?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $stmt->close();
                    $response = ['status' => 'success', 'message' => 'Cupón eliminado.'];
                } else {
                    throw new Exception("ID de cupón no válido para eliminar.");
                }
                break;
                // --- AÑADE ESTE NUEVO BLOQUE 'CASE' ---
            case 'toggle_status':
                $id = (int)($_POST['id'] ?? 0);
                $status = (int)($_POST['status'] ?? 0); // 1 para activo, 0 para inactivo

                if ($id > 0) {
                    $stmt = $conn->prepare("UPDATE cupones SET activo = ? WHERE id = ?");
                    $stmt->bind_param("ii", $status, $id);
                    if ($stmt->execute()) {
                        $response = ['status' => 'success', 'message' => 'Estado del cupón actualizado.'];
                    } else {
                        throw new Exception("Error al actualizar el estado del cupón.");
                    }
                    $stmt->close();
                } else {
                    throw new Exception("ID de cupón no válido.");
                }
                break;
            // --- FIN DEL NUEVO BLOQUE 'CASE' ---
            default: throw new Exception("Acción POST no reconocida.");
        }
        $conn->commit();
    }
} catch (Exception $e) {
    $conn->rollback(); 
    http_response_code(500);
    $response = ['status' => 'error', 'message' => $e->getMessage()];
}
// --- BLOQUE DE SALIDA JSON ROBUSTO ---

// JSON_INVALID_UTF8_SUBSTITUTE es la clave. Reemplazará cualquier carácter UTF-8 inválido
// en lugar de fallar silenciosamente. Esto asegura que SIEMPRE obtengamos un JSON válido.
$json_output = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

// Verificamos si json_encode falló por alguna otra razón
if ($json_output === false) {
    // Si falla, registramos el error específico de JSON para saber por qué
    $json_error = json_last_error_msg();
    error_log("[JSON_ENCODE_ERROR] Falló la codificación JSON: " . $json_error);
    
    // Y enviamos una respuesta de error JSON válida al frontend
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error interno del servidor al generar la respuesta JSON: ' . $json_error]);
} else {
    // Si todo está bien, enviamos la respuesta JSON correcta.
    echo $json_output;
}
// --- FIN DEL BLOQUE DE SALIDA ---
if (isset($conn)) $conn->close();
?>
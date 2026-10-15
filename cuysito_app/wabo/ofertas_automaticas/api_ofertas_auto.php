<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
// Archivo: api_ofertas_auto.php (v4.7 - CORRECCIÓN FINAL REORDER)
ini_set('display_errors', 1); error_reporting(E_ALL);

require_once __DIR__ . '/../../api/session_security.php';
require_once __DIR__ . '/../core/db_connect.php';
require_once __DIR__ . '/../../config/shop_config.php';
require_once __DIR__ . '/../../core/ShopRepository.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Content-Type: application/json'); http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso no autorizado.']);
    exit;
}
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$action = ($method === 'GET') ? ($_GET['accion'] ?? '') : ($_POST['accion'] ?? '');
$response = [];

try {
    if ($method === 'GET') {
        switch ($action) {
            case 'get_config_data':
                $reglas_sql = "SELECT * FROM ofertas_reglas ORDER BY orden ASC";
                $reglas = $conn->query($reglas_sql)->fetch_all(MYSQLI_ASSOC);
                $tipos_sql = "SELECT * FROM ofertas_tipos_articulos ORDER BY nombre_tipo ASC";
                $tipos = $conn->query($tipos_sql)->fetch_all(MYSQLI_ASSOC);
                $response = ['status' => 'success', 'data' => ['reglas' => $reglas, 'tipos' => $tipos]];
                break;
            case 'get_shop_entries':
                // Sirve la estructura de la tienda desde el snapshot interno
                // (banano_primordial.json), NO desde la API externa. Mantiene
                // la forma {data:{entries:[...]}} que el panel ya consume.
                $response = ['status' => 'success', 'data' => ['entries' => ShopRepository::items()]];
                break;
            case 'get_ofertas_hoy':
                $sql = "SELECT d.offer_id, r.* FROM descuentos_automaticos d JOIN ofertas_reglas r ON d.id_regla = r.id ORDER BY r.orden ASC, d.id ASC";
                $ofertas = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
                $response = ['status' => 'success', 'data' => $ofertas];
                break;
            default: throw new Exception("Acción GET no reconocida.");
        }
    } elseif ($method === 'POST') {
        $conn->begin_transaction();
        switch ($action) {
            case 'limpiar_ofertas': // <--- NUEVO CASO AÑADIDO
                $script_path = realpath(__DIR__ . '/../../api/limpiar_ofertas.php');
                if ($script_path) {
                    shell_exec("php " . escapeshellarg($script_path));
                }
                $response = ['status' => 'success', 'message' => 'Ofertas automáticas limpiadas.'];
                break;
                
            case 'forzar_generacion':
    $script_path = realpath(__DIR__ . '/../../api/generar_oferta_diaria.php');
    if ($script_path === false) {
        throw new Exception("No se pudo encontrar el script 'generar_oferta_diaria.php'. Verifica la ruta.");
    }
    $output = shell_exec("php " . escapeshellarg($script_path)); // ESTO ES LO CORRECTO
    $response = ['status' => 'success', 'message' => 'Proceso de generación ejecutado.', 'log' => $output ?? 'El script no produjo salida.'];
    break;
            case 'save_regla':
                $id = (int)($_POST['id'] ?? 0);
                $nombre_regla = trim($_POST['nombre_regla']);
                $estrategia = trim($_POST['estrategia_seleccion']);
                $tipo_articulo = ($estrategia === 'palabra_clave') ? trim($_POST['tipo_articulo']) : '';
                $cantidad = (int)$_POST['cantidad_a_elegir'];
                $descuento = (int)$_POST['porcentaje_descuento'];
                $etiqueta_texto = trim($_POST['etiqueta_texto']);
                $etiqueta_color_fondo = trim($_POST['etiqueta_color_fondo']);
                $etiqueta_color_texto = trim($_POST['etiqueta_color_texto']);
                $precio_oferta_color = trim($_POST['precio_oferta_color']);
                $etiqueta_posicion = (int)$_POST['etiqueta_posicion'];
                if (empty($nombre_regla) || empty($estrategia) || $cantidad <= 0) throw new Exception("Datos incompletos.");
                if ($id > 0) {
                    $stmt = $conn->prepare("UPDATE ofertas_reglas SET nombre_regla=?, estrategia_seleccion=?, tipo_articulo=?, cantidad_a_elegir=?, porcentaje_descuento=?, etiqueta_texto=?, etiqueta_color_fondo=?, etiqueta_color_texto=?, precio_oferta_color=?, etiqueta_posicion=? WHERE id=?");
                    $stmt->bind_param("sssiissssii", $nombre_regla, $estrategia, $tipo_articulo, $cantidad, $descuento, $etiqueta_texto, $etiqueta_color_fondo, $etiqueta_color_texto, $precio_oferta_color, $etiqueta_posicion, $id);
                } else {
                    $orden_result = $conn->query("SELECT MAX(orden) as max_orden FROM ofertas_reglas");
                    $nuevo_orden = ($orden_result->fetch_assoc()['max_orden'] ?? 0) + 1;
                    $stmt = $conn->prepare("INSERT INTO ofertas_reglas (nombre_regla, estrategia_seleccion, tipo_articulo, cantidad_a_elegir, porcentaje_descuento, etiqueta_texto, etiqueta_color_fondo, etiqueta_color_texto, precio_oferta_color, etiqueta_posicion, orden, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                    $stmt->bind_param("sssiissssii", $nombre_regla, $estrategia, $tipo_articulo, $cantidad, $descuento, $etiqueta_texto, $etiqueta_color_fondo, $etiqueta_color_texto, $precio_oferta_color, $etiqueta_posicion, $nuevo_orden);
                }
                if (!$stmt) throw new Exception("Error al preparar la consulta: " . $conn->error);
                if (!$stmt->execute()) throw new Exception("Error al ejecutar la consulta: " . $stmt->error);
                $stmt->close();
                $response = ['status' => 'success', 'message' => 'Regla guardada.'];
                break;
            case 'delete_regla':
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $conn->prepare("DELETE FROM ofertas_reglas WHERE id = ?");
                $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();
                $response = ['status' => 'success', 'message' => 'Regla eliminada.'];
                break;
            case 'toggle_status':
                $id = (int)($_POST['id'] ?? 0); $status = (int)$_POST['status'];
                $stmt = $conn->prepare("UPDATE ofertas_reglas SET activo = ? WHERE id = ?");
                $stmt->bind_param("ii", $status, $id); $stmt->execute(); $stmt->close();
                $response = ['status' => 'success', 'message' => 'Estado actualizado.'];
                break;
            case 'reorder_reglas':
                $order_data = json_decode($_POST['order'], true);
                if (json_last_error() !== JSON_ERROR_NONE) { throw new Exception("Datos de orden no son un JSON válido."); }
                
                $stmt = $conn->prepare("UPDATE ofertas_reglas SET orden = ? WHERE id = ?");
                if (!$stmt) { throw new Exception("Error al preparar la consulta de reordenamiento: " . $conn->error); }
                
                // Vinculamos las variables, no los valores directamente
                $order_val = 0;
                $id_val = 0;
                $stmt->bind_param("ii", $order_val, $id_val);

                foreach ($order_data as $item) {
                    $order_val = (int)$item['order'];
                    $id_val = (int)$item['id'];
                    if (!$stmt->execute()) {
                        throw new Exception("Falló la ejecución para el ID " . $id_val);
                    }
                }
                $stmt->close();
                $response = ['status' => 'success', 'message' => 'Orden actualizado.'];
                break;
            case 'save_tipo':
                $nombre_tipo = trim($_POST['nombre_tipo']);
                $stmt = $conn->prepare("INSERT INTO ofertas_tipos_articulos (nombre_tipo) VALUES (?) ON DUPLICATE KEY UPDATE nombre_tipo=nombre_tipo");
                $stmt->bind_param("s", $nombre_tipo); $stmt->execute(); $stmt->close();
                $response = ['status' => 'success', 'message' => 'Tipo guardado.'];
                break;
            case 'delete_tipo':
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $conn->prepare("DELETE FROM ofertas_tipos_articulos WHERE id = ?");
                $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();
                $response = ['status' => 'success', 'message' => 'Tipo eliminado.'];
                break;
            default: throw new Exception("Acción POST no reconocida.");
        }
        $conn->commit();
    } else {
        throw new Exception("Método HTTP no soportado.");
    }
} catch (Exception $e) {
    if (isset($conn) && $conn->in_transaction) $conn->rollback();
    http_response_code(400);
    $response = ['status' => 'error', 'message' => 'Error del servidor: ' . $e->getMessage()];
}
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
if (isset($conn)) $conn->close();
?>
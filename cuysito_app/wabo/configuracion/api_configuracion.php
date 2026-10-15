<?php
// Archivo: wabo/configuracion/api_configuracion.php (Versión 3.0 - Lógica de Acción Mejorada)

try {
    // Configuración inicial
    ini_set('display_errors', 0);
    error_reporting(E_ALL);
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../../api/session_security.php';
    
    // 1. AUTENTICACIÓN
    if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
        throw new Exception('Acceso no autorizado.', 403);
    }

    // 2. CONEXIÓN SEGURA A LA BASE DE DATOS
    ob_start();
    require_once __DIR__ . '/../core/db_connect.php';
    $output = ob_get_clean();
    if (!empty($output)) {
        throw new Exception('Error fatal en db_connect.php: ' . trim($output));
    }
    if (!isset($conn) || !$conn || $conn->connect_error) {
        throw new Exception('Fallo en la conexión a la base de datos. Verifica db_connect.php. Error: ' . (isset($conn) ? $conn->connect_error : 'Variable $conn no definida.'));
    }

    // 3. LÓGICA DEL CONTROLADOR MEJORADA
    $method = $_SERVER['REQUEST_METHOD'];
    $action = '';
    $data = [];

    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';
    } elseif ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON inválido recibido.', 400);
        }
        // La acción ahora viene dentro del payload JSON para peticiones POST.
        $action = $data['action'] ?? '';
    }
    
    $response = [];

    switch ($action) {
        case 'get_all':
            if ($method !== 'GET') throw new Exception("Método no permitido para 'get_all'.", 405);
            $settings = [];
            $result = $conn->query("SELECT clave, valor, descripcion FROM configuracion_tienda");
            if (!$result) throw new Exception('Error en la consulta SQL: ' . $conn->error);
            while ($row = $result->fetch_assoc()) {
                $settings[$row['clave']] = ['valor' => $row['valor'], 'descripcion' => $row['descripcion']];
            }
            $response = ['status' => 'success', 'settings' => $settings];
            break;

        case 'save':
            if ($method !== 'POST') throw new Exception("Método no permitido para 'save'.", 405);
            
            // Eliminamos 'action' del array de datos para no intentar guardarlo en la DB.
            unset($data['action']);
            
            $conn->begin_transaction();
            $stmt = $conn->prepare("UPDATE configuracion_tienda SET valor = ? WHERE clave = ?");
            if (!$stmt) throw new Exception('Error al preparar la consulta: ' . $conn->error);
            
            foreach ($data as $clave => $valor) {
                $stmt->bind_param("ss", $valor, $clave);
                if (!$stmt->execute()) {
                    $conn->rollback();
                    throw new Exception("Error al guardar la clave: {$clave} - " . $stmt->error);
                }
            }
            $stmt->close();
            $conn->commit();
            $response = ['status' => 'success', 'message' => '¡Configuración guardada correctamente!'];
            break;

        default:
            throw new Exception("Acción '{$action}' no reconocida.", 404);
    }
    $conn->close();
    echo json_encode($response);
    exit;

} catch (Throwable $e) {
    $httpCode = method_exists($e, 'getCode') && $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($httpCode);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
?>
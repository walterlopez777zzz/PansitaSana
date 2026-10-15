<?php

try {
    ini_set('display_errors', 0);
    error_reporting(E_ALL);
    header('Content-Type: application/json; charset=utf-8');

    require_once __DIR__ . '/../../api/session_security.php';

    if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
        throw new Exception('Acceso no autorizado.', 403);
    }

    ob_start();
    require_once __DIR__ . '/../core/db_connect.php';
    $output = ob_get_clean();

    if (!empty($output)) {
        throw new Exception('Error fatal en db_connect.php: ' . trim($output));
    }
    if (!isset($conn) || !$conn || $conn->connect_error) {
        throw new Exception('Fallo en la conexión a la base de datos. Verifica db_connect.php. Error: ' . (isset($conn) ? $conn->connect_error : 'Variable $conn no definida.'));
    }

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
        $action = $data['action'] ?? '';
    }

    $response = [];

    switch ($action) {
        case 'get_all':
        case 'list':
            if ($method !== 'GET') throw new Exception("Método no permitido para 'list'.", 405);

            $settings = [];
            $result = $conn->query("SELECT key_name, value, updated_at FROM site_settings ORDER BY key_name ASC");
            if (!$result) throw new Exception('Error en la consulta SQL: ' . $conn->error);

            while ($row = $result->fetch_assoc()) {
                $settings[] = [
                    'key_name' => $row['key_name'],
                    'value' => $row['value'],
                    'updated_at' => $row['updated_at']
                ];
            }

            $response = ['status' => 'success', 'settings' => $settings];
            break;

        case 'save':
        case 'update':
            if ($method !== 'POST') throw new Exception("Método no permitido para 'update'.", 405);

            $key_name = isset($data['key_name']) ? trim((string)$data['key_name']) : '';
            $value = isset($data['value']) ? (string)$data['value'] : null;

            if ($key_name === '' || strlen($key_name) > 50) {
                throw new Exception('key_name inválido.', 400);
            }
            if (!preg_match('/^[A-Za-z0-9_\-\.]+$/', $key_name)) {
                throw new Exception('key_name contiene caracteres no permitidos.', 400);
            }

            if ($value === null) {
                unset($data['action']);
                $keys = array_keys($data);
                if (!empty($keys)) {
                    $key_name = (string)$keys[0];
                    $value = (string)$data[$key_name];
                }
            }

            if ($value === null) {
                throw new Exception('value es requerido.', 400);
            }

            $conn->begin_transaction();

            $stmt_check = $conn->prepare('SELECT 1 FROM site_settings WHERE key_name = ? LIMIT 1');
            if (!$stmt_check) throw new Exception('Error al preparar la verificación: ' . $conn->error);
            $stmt_check->bind_param('s', $key_name);
            $stmt_check->execute();
            $exists = $stmt_check->get_result()->num_rows > 0;
            $stmt_check->close();

            if (!$exists) {
                $conn->rollback();
                throw new Exception('La clave no existe.', 404);
            }

            $stmt = $conn->prepare('UPDATE site_settings SET value = ?, updated_at = NOW() WHERE key_name = ?');
            if (!$stmt) throw new Exception('Error al preparar la actualización: ' . $conn->error);
            $stmt->bind_param('ss', $value, $key_name);

            if (!$stmt->execute()) {
                $conn->rollback();
                throw new Exception('Error al actualizar: ' . ($stmt->error ?: 'desconocido'));
            }

            $stmt->close();

            $stmt_get = $conn->prepare('SELECT key_name, value, updated_at FROM site_settings WHERE key_name = ? LIMIT 1');
            if (!$stmt_get) throw new Exception('Error al preparar la lectura: ' . $conn->error);
            $stmt_get->bind_param('s', $key_name);
            $stmt_get->execute();
            $updated = $stmt_get->get_result()->fetch_assoc();
            $stmt_get->close();

            $conn->commit();
            $response = ['status' => 'success', 'message' => 'Configuración actualizada.', 'setting' => $updated];
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

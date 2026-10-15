<?php
// Archivo: api_tienda.php (v8.0 - Fusión con Lógica de Reglas)
ini_set('display_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('America/Lima'); // Importante para las fechas

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

function get_fortnite_shop() {
    // --- FUENTE ÚNICA: snapshot canónico interno (banano_primordial.json) ---
    // Ya NO se contacta la API externa (ni clave ni SSL inseguro). El DAL
    // preserva los campos crudos (layout, brItems, bundle, colors, offerId,
    // finalPrice, sortPriority) que el armado de secciones de abajo consume.
    $entries = ShopRepository::items();

    // --- PASO 2: PROCESAR LOS DATOS Y CONSTRUIR LA ESTRUCTURA DE SECCIONES ---
    $sectionMap = [];

    foreach ($entries as $entry) {
        // Ignoramos si la entrada no tiene un layout o un item para mostrar
        if (!isset($entry['layout']['name']) || (empty($entry['brItems']) && empty($entry['tracks']))) {
            continue;
        }

        $sectionName = $entry['layout']['name'];
        $sectionRank = $entry['layout']['rank'] ?? 999;

        if (!isset($sectionMap[$sectionName])) {
            $sectionMap[$sectionName] = ['name' => $sectionName, 'rank' => $sectionRank, 'items' => []];
        }

        // --- Extracción de datos del cosmético (adaptado a la estructura de .com) ---
        $mainItem = $entry['brItems'][0] ?? null; // Usamos el primer item para info general
        
        // Lógica para determinar el nombre y tipo (maneja lotes correctamente)
        $display_name = $entry['bundle']['name'] ?? $mainItem['name'] ?? 'Artículo Desconocido';
        $display_type = $entry['bundle']['info'] ?? $mainItem['type']['displayValue'] ?? 'Desconocido';
        
        // Lógica para obtener las imágenes
        // Prioridad: Imagen de la tarjeta de la tienda, luego la del cosmético
        $featured_image = $entry['newDisplayAsset']['renderImages'][0]['image'] ?? $mainItem['images']['featured'] ?? $mainItem['images']['icon'] ?? null;
        $icon_image = $mainItem['images']['icon'] ?? $mainItem['images']['smallIcon'] ?? $featured_image;

        if (!$icon_image) { continue; } // Si no podemos obtener una imagen, lo saltamos

        // Lógica para los colores de la tarjeta (convierte de RRGGBBAA a #RRGGBB)
        $cardColors = null;
        if (isset($entry['colors']['color1'], $entry['colors']['color2'])) {
            $color1 = '#' . substr($entry['colors']['color1'], 0, -2);
            $color2 = '#' . substr($entry['colors']['color2'], 0, -2);
            $cardColors = [$color1, $color2];
        }

        // Construimos el objeto final con la estructura que tu frontend espera
        $sectionMap[$sectionName]['items'][] = [
            'offerId' => $entry['offerId'],
            'finalPrice' => $entry['finalPrice'],
            'sortPriority' => $entry['sortPriority'] ?? 99,
            'items' => [[
                'name' => $display_name,
                'type' => ['displayValue' => $display_type],
                'images' => ['icon' => $icon_image, 'featured' => $featured_image],
                'cardColors' => $cardColors
            ]]
        ];
    }

    // --- PASO 3: ORDENAMIENTO FINAL (se mantiene igual) ---
    // Ordena las secciones por su 'rank' (de mayor a menor)
    uasort($sectionMap, function ($a, $b) { 
        return $b['rank'] <=> $a['rank']; 
    });

    $final_sections = [];
    foreach ($sectionMap as $sectionData) {
        // Ordena los ítems dentro de cada sección por su 'sortPriority' (de menor a mayor)
        usort($sectionData['items'], function($a, $b) {
            $priorityA = $a['sortPriority'] ?? 999;
            $priorityB = $b['sortPriority'] ?? 999;
            return $priorityA <=> $priorityB;
        });

        $final_sections[] = [
            'id' => str_replace(' ', '', $sectionData['name']),
            'name' => $sectionData['name'],
            'entries' => $sectionData['items']
        ];
    }
    
    return ['result' => true, 'sections' => $final_sections];
}

function get_manual_discounts($conn) {
    $discounts = []; $result = $conn->query("SELECT * FROM descuentos_manuales ORDER BY fecha_creacion DESC");
    if ($result) { while ($row = $result->fetch_assoc()) { $discounts[] = $row; } } return $discounts;
}

// --- CONTROLADOR PRINCIPAL ---

$response = [];
$method = $_SERVER['REQUEST_METHOD'];
$action = ($method === 'GET') ? ($_GET['action'] ?? '') : ($_POST['action'] ?? '');

try {
    if ($method === 'GET' && $action === 'get_all_data') {
        // Obtenemos los descuentos junto con sus reglas usando la técnica de la API de cupones
        $discounts_sql = "
            SELECT d.*, 
                   (SELECT JSON_ARRAYAGG(JSON_OBJECT('tipo', ra.tipo_regla, 'valor', ra.valor_regla)) 
                    FROM descuentos_reglas_aplicabilidad ra 
                    WHERE ra.descuento_id = d.id) as reglas_aplicabilidad
            FROM descuentos_manuales d
            ORDER BY d.id DESC
        ";
        $discounts = $conn->query($discounts_sql)->fetch_all(MYSQLI_ASSOC);

        $shopData = get_fortnite_shop();
        
        // Extraer categorías de la API, igual que en la API de cupones
        $raw_api_categories = [];
        if (isset($shopData['sections']) && is_array($shopData['sections'])) {
            foreach ($shopData['sections'] as $section) {
                foreach ($section['entries'] as $entry) {
                    $type = $entry['items'][0]['type']['displayValue'] ?? null;
                    if ($type) { $raw_api_categories[] = $type; }
                }
            }
        }
        $processed_categories = [];
        foreach ($raw_api_categories as $category) {
            $processed_categories[] = str_contains(strtolower($category), 'lote') ? 'Lote' : $category;
        }
        $api_categories = array_values(array_unique($processed_categories));
        sort($api_categories);

        $response = ['status' => 'success', 'data' => [
            'discounts' => $discounts, 
            'shopData' => $shopData, 
            'apiCategories' => $api_categories,
        ]];

    } elseif ($method === 'POST') {
        $conn->begin_transaction();
        
        switch ($action) {
            case 'save_discount':
                $id = (int)($_POST['id'] ?? 0);
                $nombre_articulo = trim($_POST['nombre_articulo']);
                $tipo_descuento = $_POST['tipo_descuento'];
                $valor = (float)$_POST['valor'];
                
                // Lógica de fechas avanzada
                $fecha_inicio = null;
                if ($_POST['tipo_inicio'] === 'especifico' && !empty($_POST['fecha_inicio'])) { 
                    $fecha_inicio = $_POST['fecha_inicio']; 
                }
                $fecha_expiracion = null;
                switch ($_POST['tipo_expiracion']) {
                    case 'especifica': 
                        if (!empty($_POST['fecha_expiracion_especifica'])) { 
                            $fecha_expiracion = $_POST['fecha_expiracion_especifica'] . ' 23:59:59'; 
                        } 
                        break;
                    case 'temporizador':
                        $horas = (int)($_POST['temporizador_horas'] ?? 0); 
                        $minutos = (int)($_POST['temporizador_minutos'] ?? 0);
                        $total_minutos = ($horas * 60) + $minutos;
                        if ($total_minutos > 0) { 
                            $fecha_expiracion = (new DateTime())->add(new DateInterval("PT{$total_minutos}M"))->format('Y-m-d H:i:s'); 
                        }
                        break;
                    case 'exacta': 
                        if (!empty($_POST['fecha_expiracion_exacta'])) { 
                            $fecha_expiracion = $_POST['fecha_expiracion_exacta']; 
                        } 
                        break;
                }

                if ($id > 0) {
                    $stmt = $conn->prepare("UPDATE descuentos_manuales SET nombre_articulo=?, tipo_descuento=?, valor=?, fecha_inicio=?, fecha_expiracion=? WHERE id=?");
                    $stmt->bind_param("ssdssi", $nombre_articulo, $tipo_descuento, $valor, $fecha_inicio, $fecha_expiracion, $id);
                } else {
                    $stmt = $conn->prepare("INSERT INTO descuentos_manuales (nombre_articulo, tipo_descuento, valor, fecha_inicio, fecha_expiracion, activo) VALUES (?, ?, ?, ?, ?, 1)");
                    $stmt->bind_param("ssdss", $nombre_articulo, $tipo_descuento, $valor, $fecha_inicio, $fecha_expiracion);
                }
                
                if (!$stmt->execute()) { throw new Exception("Error al guardar el descuento."); }
                
                $descuento_id = ($id > 0) ? $id : $conn->insert_id;
                $stmt->close();
                
                // Gestión de reglas
                $conn->query("DELETE FROM descuentos_reglas_aplicabilidad WHERE descuento_id = $descuento_id");
                $reglas = json_decode($_POST['reglas_aplicabilidad'] ?? '[]', true);
                if (json_last_error() === JSON_ERROR_NONE && !empty($reglas)) {
                    $stmt_reglas = $conn->prepare("INSERT INTO descuentos_reglas_aplicabilidad (descuento_id, tipo_regla, valor_regla) VALUES (?, ?, ?)");
                    foreach ($reglas as $regla) {
                        $stmt_reglas->bind_param("iss", $descuento_id, $regla['tipo'], $regla['valor']);
                        $stmt_reglas->execute();
                    }
                    $stmt_reglas->close();
                }
                
                $response = ['status' => 'success', 'message' => 'Descuento guardado correctamente.'];
                break;

            case 'delete_discount':
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) throw new Exception("ID de descuento no válido.");
                
                // Borrar primero las reglas y luego el descuento
                $conn->query("DELETE FROM descuentos_reglas_aplicabilidad WHERE descuento_id = $id");
                $stmt = $conn->prepare("DELETE FROM descuentos_manuales WHERE id = ?");
                $stmt->bind_param("i", $id);
                if (!$stmt->execute()) throw new Exception('Error al eliminar el descuento.');
                $stmt->close();
                
                $response = ['status' => 'success', 'message' => 'Descuento eliminado.'];
                break;

            default:
                throw new Exception("Acción POST no reconocida: '{$action}'");
        }
        
        $conn->commit();

    } else {
        throw new Exception("Acción o método no permitido.");
    }

} catch (Exception $e) {
    $conn->rollback(); 
    http_response_code(500);
    $response = ['status' => 'error', 'message' => $e->getMessage()];
    error_log("ERROR en api_tienda.php: " . $e->getMessage());
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
if (isset($conn)) $conn->close();
?>
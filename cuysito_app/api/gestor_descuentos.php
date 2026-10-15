<?php
// Archivo: /api/gestor_descuentos.php (Versión 8.0 - Alineado con Frontend Moderno)

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
ini_set('display_errors', 0); // Ocultar errores en producción
error_reporting(E_ALL);
date_default_timezone_set('America/Lima');

require_once 'db_connect.php';
require_once __DIR__ . '/../core/ShopRepository.php';
require_once __DIR__ . '/../core/SectionBackgroundsRepository.php';
require_once __DIR__ . '/../core/JamTrackVideoIndex.php';

function _outputJson($data) {
    $raw = json_encode($data);
    if (defined('MODO_DEBUG') && MODO_DEBUG) {
        echo $raw;
    } else {
        $sn = openssl_random_pseudo_bytes(16);
        $sd = openssl_encrypt($raw, 'aes-256-cbc', CART_SYNC_TOKEN, OPENSSL_RAW_DATA, $sn);
        echo json_encode(['_sd' => base64_encode($sd), '_sn' => base64_encode($sn)]);
    }
}

// --- FUNCIONES HELPER ---

function getConversionRate($db_connection) {
    $sql = "SELECT valor FROM configuracion_tienda WHERE clave = 'precio_por_100_pavos' LIMIT 1";
    $result = $db_connection->query($sql);
    return ($result && $result->num_rows > 0) ? (float)$result->fetch_assoc()['valor'] : 1.50;
}

// --- LÓGICA PRINCIPAL ---

try {
    // 1. OBTENER REGLAS DE DESCUENTO DE LA BASE DE DATOS
    $now_string = (new DateTime())->format('Y-m-d H:i:s');
    $allActiveDiscounts = [];

    // Descuentos manuales
    $sql_manual = "SELECT d.*, 
                   (SELECT JSON_ARRAYAGG(JSON_OBJECT('tipo', ra.tipo_regla, 'valor', ra.valor_regla)) 
                    FROM descuentos_reglas_aplicabilidad ra WHERE ra.descuento_id = d.id) as reglas_aplicabilidad
                   FROM descuentos_manuales d
                   WHERE d.activo = 1 AND (d.fecha_inicio IS NULL OR d.fecha_inicio <= '{$now_string}')
                   AND (d.fecha_expiracion IS NULL OR d.fecha_expiracion >= '{$now_string}')";
    
    $result_manual = $conn->query($sql_manual);
    if ($result_manual) {
        $manualDiscounts = $result_manual->fetch_all(MYSQLI_ASSOC);
        foreach ($manualDiscounts as $discount) {
            $discount['reglas_aplicabilidad'] = json_decode($discount['reglas_aplicabilidad'] ?? '[]', true);
            $allActiveDiscounts[] = $discount;
        }
    }

    // Descuentos automáticos (sistema antiguo)
    $sql_auto = "SELECT da.offer_id, ore.* FROM descuentos_automaticos da 
                 JOIN ofertas_reglas ore ON da.id_regla = ore.id WHERE ore.activo = 1";
    $result_auto = $conn->query($sql_auto);
    if ($result_auto) {
        while ($row = $result_auto->fetch_assoc()) {
            $allActiveDiscounts[] = [
                'id' => 'legacy_' . $row['id'],
                'nombre_descuento' => $row['nombre_regla'],
                'tipo_descuento' => 'porcentaje',
                'valor' => $row['porcentaje_descuento'],
                'reglas_aplicabilidad' => [['tipo' => 'producto_especifico', 'valor' => $row['offer_id']]]
            ];
        }
    }

    // 2. OBTENER LA TIENDA DESDE EL SNAPSHOT CANÓNICO INTERNO (DAL)
    //    Única fuente para el frontend: banano_primordial.json (con failover
    //    automático al fallback diario según el modo de fuente). El frontend
    //    deja de depender de la disponibilidad de la API pública.
    $entries = ShopRepository::items();
    if (empty($entries)) {
        throw new Exception("No hay snapshot de tienda disponible (banano_primordial.json).");
    }

    // 3. PROCESAR CADA ARTÍCULO PARA APLICAR DESCUENTOS (en tiempo de servicio)
    $conversion_rate = getConversionRate($conn);
    $jamTrackVideoIndex = JamTrackVideoIndex::load($conn);
    $processedEntries = [];

    foreach ($entries as $entry) {
        $entry = JamTrackVideoIndex::annotate($entry, $jamTrackVideoIndex);
        $vbucks = $entry['finalPrice'] ?? 0;
        // Precio base unificado: usa el precio canónico en soles del snapshot
        // (que YA respeta los overrides del Gestor de Capa de Datos). Si por
        // alguna razón no existe, recurre al cálculo desde V-Bucks.
        $regularPriceSoles = (isset($entry['precio_base_soles']) && is_numeric($entry['precio_base_soles']))
            ? (float) $entry['precio_base_soles']
            : ($vbucks / 100) * $conversion_rate;
        $finalPriceSoles = $regularPriceSoles;
        $appliedDiscount = null;

        // Lógica de búsqueda de descuento (se mantiene, pero ahora con datos de una sola API)
        $offerId = $entry['offerId'];
        $isBundle = isset($entry['bundle']);
        $mainItemType = $entry['brItems'][0]['type']['displayValue'] ?? 'Artículo';
        $sectionName = $entry['layout']['name'] ?? null;
        
        $itemCategory = $isBundle ? 'Lote' : $mainItemType;

        $checkFunctions = [
            'producto_especifico' => fn($r) => $r['tipo'] === 'producto_especifico' && $r['valor'] === $offerId,
            'seccion_tienda' => fn($r) => $sectionName && $r['tipo'] === 'seccion_tienda' && strcasecmp($r['valor'], $sectionName) === 0,
            'categoria_api' => fn($r) => $itemCategory && $r['tipo'] === 'categoria_api' && strcasecmp($r['valor'], $itemCategory) === 0,
            'global' => fn($r) => $r['tipo'] === 'global' || $r['tipo'] === 'global_sin_estaticos'
        ];

        foreach ($checkFunctions as $check) {
            foreach ($allActiveDiscounts as $discount) {
                foreach ($discount['reglas_aplicabilidad'] as $rule) {
                    if ($check($rule)) {
                        $appliedDiscount = $discount;
                        break 3; // Salimos de todos los bucles al encontrar la primera coincidencia
                    }
                }
            }
        }
        
        if ($appliedDiscount) {
            if ($appliedDiscount['tipo_descuento'] === 'porcentaje') {
                $finalPriceSoles = $regularPriceSoles * (1 - (float)$appliedDiscount['valor'] / 100);
            } else { // 'precio_fijo'
                $finalPriceSoles = (float)$appliedDiscount['valor'];
            }
        }

        // Añadimos los nuevos campos al objeto 'entry' original
        $entry['regularPrice'] = round($regularPriceSoles, 2);
        $entry['finalPriceWithDiscount'] = round($finalPriceSoles, 2);
        $entry['discountApplied'] = !!$appliedDiscount;

        $processedEntries[] = $entry;
    }

    // 4. DEVOLVER LA TIENDA PROCESADA EN EL FORMATO CORRECTO PARA EL WORKER
    // El worker ahora recibirá la misma estructura que si hubiera llamado a fortnite-api.com,
    // pero con los campos de descuento ya añadidos.
    $finalResponse = [
        'status' => 200,
        'data' => [
            'date' => ShopRepository::shopDate(),
            'entries' => $processedEntries,
            'sectionBackgrounds' => SectionBackgroundsRepository::activeFromCache()
        ]
    ];

    _outputJson($finalResponse);

} catch (Exception $e) {
    http_response_code(500);
    _outputJson(['status' => 'error', 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}

if (isset($conn)) {
    $conn->close();
}
?>
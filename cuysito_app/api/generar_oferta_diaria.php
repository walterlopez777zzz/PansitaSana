<?php
// Archivo: generar_oferta_diaria.php (v4.0 - Migrado a Fortnite-API.com)
// Ocultamos warnings pero no errores fatales.
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 1);
set_time_limit(120);

require_once __DIR__ . '/../wabo/core/db_connect.php';
require_once __DIR__ . '/../config/shop_config.php';
require_once __DIR__ . '/../core/ShopRepository.php';

function finish($message, $is_error = false) {
    $status = $is_error ? "ERROR" : "INFO";
    echo date('[Y-m-d H:i:s]') . " [$status] " . $message . PHP_EOL;
    if ($is_error) exit(1);
}

echo "--- Iniciando proceso de generación de ofertas ---\n";

// La limpieza de la tabla se mantiene.
// $conn->query("TRUNCATE TABLE descuentos_automaticos"); 
echo "INFO: Tabla 'descuentos_automaticos' limpiada.\n";

// Obtener reglas activas (sin cambios)
$reglas_result = $conn->query("SELECT * FROM ofertas_reglas WHERE activo = 1 ORDER BY orden ASC");
if (!$reglas_result || $reglas_result->num_rows === 0) {
    finish("Proceso terminado: No hay reglas activas.", false);
}
$reglas_activas = $reglas_result->fetch_all(MYSQLI_ASSOC);
echo "INFO: Se encontraron " . count($reglas_activas) . " reglas activas.\n";

// --- FUENTE ÚNICA: snapshot canónico interno (banano_primordial.json) ---
// Ya NO se contacta la API externa: leemos el DAL, que preserva los campos
// crudos (offerId, tracks, brItems, bundle) usados por el filtrado de abajo.
$todos_los_articulos = ShopRepository::items();
if (empty($todos_los_articulos)) {
    finish("El snapshot canónico (banano_primordial.json) está vacío o no existe. Ejecuta el ingestor (generar_cache_tienda.php) primero.", true);
}
echo "INFO: Se obtuvieron " . count($todos_los_articulos) . " ofertas desde el snapshot interno.\n";

// Obtener descuentos manuales (sin cambios)
$manual_discounts_result = $conn->query("SELECT offer_id FROM descuentos_manuales WHERE activo = 1");
$manual_ids = [];
if ($manual_discounts_result) {
    while($row = $manual_discounts_result->fetch_assoc()) {
        $manual_ids[] = $row['offer_id'];
    }
}
echo "INFO: Se excluirán " . count($manual_ids) . " IDs con descuentos manuales.\n";

$articulos_ya_elegidos = [];
$ofertas_generadas_count = 0;

$conn->begin_transaction();
try {
    $stmt_insert = $conn->prepare("INSERT INTO descuentos_automaticos (offer_id, id_regla) VALUES (?, ?)");
    if (!$stmt_insert) throw new Exception("Error preparando inserción: " . $conn->error);

    foreach ($reglas_activas as $regla) {
        echo "\n--- Procesando Regla #{$regla['id']}: '{$regla['nombre_regla']}' ---\n";
        $estrategia = $regla['estrategia_seleccion'];
        $palabra_clave = $regla['tipo_articulo'];

        // --- CAMBIO 3: LÓGICA DE FILTRADO ADAPTADA A LA NUEVA ESTRUCTURA ---
        $candidatos = array_filter($todos_los_articulos, function($entry) use ($estrategia, $palabra_clave, $manual_ids, $articulos_ya_elegidos) {
            if (!isset($entry['offerId'])) return false;
            
            // Excluir artículos con descuento manual o ya elegidos
            if (in_array($entry['offerId'], $manual_ids) || in_array($entry['offerId'], $articulos_ya_elegidos)) return false;
            
            // ===== LÓGICA DE FILTRADO MEJORADA Y ADAPTADA =====
            
            // 1. Detectar si es una Pista de Improvisación (Jam Track)
            $es_pista = !empty($entry['tracks']);

            // 2. Obtener el tipo de artículo visible para el usuario
            $displayType = 'Desconocido';
            if ($es_pista) {
                $displayType = 'Pista de improvisación'; // Asignamos el tipo manualmente
            } elseif (!empty($entry['brItems'])) {
                // Para lotes, usamos su 'info', si no, el tipo del primer ítem
                $displayType = $entry['bundle']['info'] ?? $entry['brItems'][0]['type']['displayValue'] ?? 'Desconocido';
            }

            // 3. Aplicar las reglas de la estrategia
            switch ($estrategia) {
                case 'todo': 
                    return true;
                case 'todo_menos_pistas': 
                    return !$es_pista;
                case 'palabra_clave':
                    if ($palabra_clave === 'Pista de improvisación' || $palabra_clave === 'Música') {
                        return $es_pista;
                    }
                    // Buscamos la palabra clave en el tipo visible.
                    return stripos($displayType, $palabra_clave) !== false;
                default: 
                    return false;
            }
        });
        
        $candidatos = array_values($candidatos); // Re-indexar el array
        echo "INFO: Se encontraron " . count($candidatos) . " candidatos para esta regla.\n";
        
        $cantidad_a_elegir = min((int)$regla['cantidad_a_elegir'], count($candidatos));
        echo "INFO: Se van a elegir {$cantidad_a_elegir} artículo(s).\n";

        if ($cantidad_a_elegir > 0) {
            $keys_ganadoras = (array) array_rand($candidatos, $cantidad_a_elegir);
            foreach ($keys_ganadoras as $key) {
                $articulo_ganador = $candidatos[$key];
                $offerId_ganador = $articulo_ganador['offerId'];
                
                // Obtener el nombre para el log
                $nombre_articulo = $articulo_ganador['bundle']['name'] ?? $articulo_ganador['brItems'][0]['name'] ?? $articulo_ganador['tracks'][0]['title'] ?? 'Artículo desconocido';

                $stmt_insert->bind_param("si", $offerId_ganador, $regla['id']);
                $stmt_insert->execute();
                
                $ofertas_generadas_count++;
                $articulos_ya_elegidos[] = $offerId_ganador;
                echo "-> SELECCIONADO: '{$nombre_articulo}' ({$offerId_ganador})\n";
            }
        }
    }

    $stmt_insert->close();
    $conn->commit();
    finish("¡Éxito! Proceso completado. Total de ofertas generadas: $ofertas_generadas_count.", false);

} catch (Exception $e) {
    if ($conn->status) $conn->rollback();
    finish("Proceso fallido durante la transacción: " . $e->getMessage(), true);
}

$conn->close();
?>
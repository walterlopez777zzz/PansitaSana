<?php
// cron/test_bsc.php
// DIAGNÓSTICO V3: MIGRACIÓN A ETHERSCAN V2 (UNIFIED)
header('Content-Type: text/plain');
date_default_timezone_set('America/Lima');

// --- ¡¡¡PEGA TU NUEVA KEY DE ETHERSCAN AQUÍ!!! ---
$apiKey = 'YY1P74RTQVVT2N4P1VKIN5BXG1WE9ZZRVQ'; 
// ----------------------------------------------------

$timestamp = time();

echo "--- DIAGNÓSTICO ETHERSCAN V2 (BSC CHAIN 56) ---\n";
echo "API Key: $apiKey\n";
echo "Endpoint: api.etherscan.io/v2/api\n\n";

// NUEVA URL V2 UNIFICADA
// chainid=56 es lo que le dice "Dame datos de Binance Smart Chain"
$url = "https://api.etherscan.io/v2/api?chainid=56&module=block&action=getblocknobytime&timestamp=$timestamp&closest=before&apikey=$apiKey";

echo "1. CONSULTANDO...\n";
echo "URL: $url\n\n";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');

$result = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo "ERROR CONEXIÓN: $error\n";
} else {
    echo "RESPUESTA RAW:\n$result\n\n";
    
    $json = json_decode($result, true);
    
    if(isset($json['status']) && $json['status'] == '1') {
        $blockNumber = $json['result'];
        echo "✅ ¡ÉXITO! Bloque V2 encontrado: #$blockNumber\n";
        
        // 2. OBTENER HASH (Endpoint V2 Proxy)
        echo "\n2. OBTENIENDO HASH...\n";
        $tagHex = '0x' . dechex($blockNumber);
        $urlHash = "https://api.etherscan.io/v2/api?chainid=56&module=proxy&action=eth_getBlockByNumber&tag=$tagHex&boolean=true&apikey=$apiKey";
        
        $ch2 = curl_init($urlHash);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch2, CURLOPT_USERAGENT, 'Mozilla/5.0');
        $resHash = curl_exec($ch2);
        curl_close($ch2);
        
        $jsonHash = json_decode($resHash, true);
        
        if(isset($jsonHash['result']['hash'])) {
            echo "✅ HASH REAL: " . $jsonHash['result']['hash'] . "\n";
            echo "CONCLUSIÓN: API Key y URL V2 funcionan correctamente.\n";
        } else {
            echo "⚠️ Bloque OK, pero falló el Hash.\nRespuesta: $resHash\n";
        }
        
    } else {
        echo "❌ ERROR API: " . ($json['message'] ?? 'Desconocido') . "\n";
        echo "Causa probable: API Key inválida (Asegúrate de usar una de Etherscan.io, no de BscScan).\n";
    }
}
?>
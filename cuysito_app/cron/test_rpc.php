<?php
// cron/test_rpc.php
// DIAGNÓSTICO: CONEXIÓN DIRECTA A NODO BINANCE (RPC)
// Sin intermediarios (BscScan/Etherscan) - 100% Gratis

header('Content-Type: text/plain');
date_default_timezone_set('America/Lima');

// URL OFICIAL PÚBLICA DE BINANCE SMART CHAIN
$rpcUrl = 'https://bsc-dataseed.binance.org/';

echo "--- DIAGNÓSTICO RPC (DIRECTO A BLOCKCHAIN) ---\n";
echo "Nodo: $rpcUrl\n\n";

// FUNCIÓN PARA HACER PETICIONES POST (JSON-RPC)
function rpcRequest($method, $params = []) {
    global $rpcUrl;
    $data = [
        'jsonrpc' => '2.0',
        'method' => $method,
        'params' => $params,
        'id' => 1
    ];
    
    $ch = curl_init($rpcUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $result = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) return ['error' => $error];
    return json_decode($result, true);
}

// 1. OBTENER ÚLTIMO BLOQUE (eth_blockNumber)
echo "1. CONSULTANDO ÚLTIMO BLOQUE...\n";
$respBlock = rpcRequest('eth_blockNumber');

if (isset($respBlock['result'])) {
    $blockHex = $respBlock['result'];
    $blockDec = hexdec($blockHex);
    echo "✅ ÉXITO. Bloque Actual: #$blockDec ($blockHex)\n\n";
    
    // 2. OBTENER HASH DE ESE BLOQUE (eth_getBlockByNumber)
    echo "2. OBTENIENDO HASH DEL BLOQUE...\n";
    $respHash = rpcRequest('eth_getBlockByNumber', [$blockHex, true]); // true = full transactions (no necesario pero estandar)
    
    if (isset($respHash['result']['hash'])) {
        echo "✅ HASH REAL: " . $respHash['result']['hash'] . "\n";
        echo "CONCLUSIÓN: El método RPC funciona perfecto y es gratis.\n";
    } else {
        echo "⚠️ Falló al obtener detalle del bloque.\n";
        print_r($respHash);
    }
    
} elseif (isset($respBlock['error'])) {
    echo "❌ ERROR RPC: " . print_r($respBlock['error'], true) . "\n";
} else {
    echo "❌ ERROR CONEXIÓN: " . print_r($respBlock, true) . "\n";
}
?>
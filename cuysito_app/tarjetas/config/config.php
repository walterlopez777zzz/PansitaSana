<?php

declare(strict_types=1);

$shared = require __DIR__ . '/../../tareas/config/config.php';
$db = $shared['db'];
$pin = getenv('TARJETAS_PIN') ?: (string) $shared['pin'];
$dbConfig = [
    'host' => getenv('TARJETAS_DB_HOST') ?: (string) $db['host'],
    'name' => getenv('TARJETAS_DB_NAME') ?: (string) $db['name'],
    'user' => getenv('TARJETAS_DB_USER') ?: (string) $db['user'],
    'pass' => getenv('TARJETAS_DB_PASS') ?: (string) $db['pass'],
    'charset' => 'utf8mb4',
];
$keyMaterial = getenv('TARJETAS_ENCRYPTION_KEY')
    ?: implode('|', [$dbConfig['host'], $dbConfig['name'], $dbConfig['user'], 'tarjetas-v2']);

return [
    'pin' => $pin,
    'pin_length' => strlen($pin),
    'max_attempts' => 5,
    'lockout_seconds' => 60,
    'session_name' => 'tarjetas_session',
    'session_cookie_path' => '/tarjetas',
    'session_lifetime' => 28800,
    'db' => $dbConfig,
    'table_prefix' => 'tarjetas_v2_',
    'encryption_key' => hash('sha256', $keyMaterial),
];

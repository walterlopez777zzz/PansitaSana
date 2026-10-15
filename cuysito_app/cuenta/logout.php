<?php
// /logout.php

// Cargamos la configuración de seguridad para enviar cabeceras No-Cache
require_once __DIR__ . '/../api/session_security.php'; 

$db_path_primary = __DIR__ . '/../wabo/core/db_connect.php';
$db_path_fallback = __DIR__ . '/../api/db_connect.php';

if (file_exists($db_path_primary)) {
    require_once $db_path_primary;
} else {
    require_once $db_path_fallback;
}

require_once __DIR__ . '/../api/remember_me.php';
if (isset($conn) && $conn instanceof mysqli) {
    banano_remember_forget_token($conn);
}

// Borramos todas las variables
session_unset();

// Destruimos la sesión del servidor
session_destroy();

// Redirigimos al home
header('Location: /'); 
exit;
?>
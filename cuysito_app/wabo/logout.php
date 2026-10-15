<?php
// /wabo/logout.php

// 1. Iniciar con seguridad (necesario para poder destruir la sesi車n correcta)
require_once __DIR__ . '/../api/session_security.php';

// Destruir todas las variables de sesi車n.
$_SESSION = array();

// Borrar la cookie de sesi車n (Tu c車digo original, muy correcto).
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Finalmente, destruir la sesi車n.
session_destroy();

// Redirigir a la p芍gina de login.
header("Location: login.php");
exit;
?>
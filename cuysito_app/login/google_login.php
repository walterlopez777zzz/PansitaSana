<?php
// /wabo/login/google_login.php

// 1. SEGURIDAD (Reemplaza a session_start)
// Sube dos niveles (../..) para salir de 'login' y de 'wabo', y entrar a 'api'
require_once __DIR__ . '/../api/session_security.php';

$redirect_to = null;
if (isset($_GET['redirect_to'])) {
    $redirect_to = banano_sanitize_internal_return_url($_GET['redirect_to']);
} elseif (!isset($_SESSION['return_url']) && isset($_SERVER['HTTP_REFERER'])) {
    $redirect_to = banano_sanitize_internal_return_url($_SERVER['HTTP_REFERER']);
}

if ($redirect_to) {
    $_SESSION['return_url'] = $redirect_to;
}

// --- 1. DETECCIÓN DE ENTORNO ---
$host = $_SERVER['HTTP_HOST'];
$base_config_path = '/home/zkckhehj/secure_config/';

if (strpos($host, 'cuysito.com') !== false) {
    $config_file = $base_config_path . 'google_staging.php';
} elseif (strpos($host, 'banano.pe') !== false) {
    $config_file = $base_config_path . 'google_prod.php';
} else {
    die('Error: Dominio no autorizado para OAuth.');
}

if (!file_exists($config_file)) {
    die('Error cr��tico: Archivo de configuraci��n no encontrado.');
}
$google_conf = require $config_file;

// --- 2. ESTADO DE SEGURIDAD (CSRF) ---
$_SESSION['oauth_state'] = bin2hex(random_bytes(16));

// --- 3. CONSTRUIR URL (SCOPES MÍNIMOS) ---
$params = [
    'response_type' => 'code',
    'client_id'     => $google_conf['client_id'],
    'redirect_uri'  => $google_conf['redirect_uri'],
    'scope'         => 'openid email', // SOLO LO NECESARIO
    'state'         => $_SESSION['oauth_state'],
    'access_type'   => 'online',
    'prompt'        => 'select_account'
];

$auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);

header('Location: ' . $auth_url);
exit;
?>
<?php
// /wabo/login/google_callback.php

// 1. SEGURIDAD Y SESIÓN (Reemplaza a session_start)
// Configura la sesión de 15 días y bloquea el caché antes de procesar el login
require_once __DIR__ . '/../api/session_security.php';

// AJUSTA ESTA RUTA (Desde /public_html/login/ a /public_html/wabo/core/)
require_once __DIR__ . '/../wabo/core/db_connect.php';
require_once __DIR__ . '/../core/AvatarCatalog.php';

// --- HELPER: Petición segura cURL (Reemplaza file_get_contents) ---
function curl_get_contents($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($http_code === 200) ? $data : false;
}

// --- 1. DETECCIÓN DE ENTORNO ---
$host = $_SERVER['HTTP_HOST'];
$base_config_path = '/home/zkckhehj/secure_config/';

if (strpos($host, 'cuysito.com') !== false) {
    $config_file = $base_config_path . 'google_staging.php';
} elseif (strpos($host, 'banano.pe') !== false) {
    $config_file = $base_config_path . 'google_prod.php';
} else {
    die('Error: Dominio no autorizado.');
}

if (!file_exists($config_file)) die('Error: Config no hallada.');
$google_conf = require $config_file;

// --- 2. VALIDACIONES PREVIAS ---
if (isset($_GET['error'])) die('Acceso denegado: ' . htmlspecialchars($_GET['error']));
if (!isset($_GET['code']) || !isset($_GET['state'])) {
    // Si entran directo al callback sin venir de Google, los mandamos al inicio del login
    header('Location: /login/'); 
    exit;
}

// Validar CSRF State
if (!isset($_SESSION['oauth_state']) || $_GET['state'] !== $_SESSION['oauth_state']) {
    die('Error de seguridad: Estado CSRF inválido.');
}
unset($_SESSION['oauth_state']);

// --- 3. INTERCAMBIO DE CODE POR TOKENS (cURL) ---
$token_url = 'https://oauth2.googleapis.com/token';
$post_data = [
    'code'          => $_GET['code'],
    'client_id'     => $google_conf['client_id'],
    'client_secret' => $google_conf['client_secret'],
    'redirect_uri'  => $google_conf['redirect_uri'],
    'grant_type'    => 'authorization_code'
];

$ch = curl_init($token_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) die('Error Google Token: ' . $response);

$token_data = json_decode($response, true);
if (!isset($token_data['id_token'])) die('Error: No id_token.');

// --- 4. VALIDACIÓN DEL ID_TOKEN (TOKENINFO ROBUSTO) ---
// Usamos cURL al endpoint de Google para validar firma, issuer, exp y audience.
$verify_url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . $token_data['id_token'];
$json_info = curl_get_contents($verify_url);

if (!$json_info) die('Error conectando con validación de Google.');
$payload = json_decode($json_info, true);

if (isset($payload['error'])) die('Token inválido: ' . $payload['error_description']);

// Validaciones Críticas
$issuers = ['accounts.google.com', 'https://accounts.google.com'];
if (!in_array($payload['iss'], $issuers)) die('Error: Issuer fraudulento.');
if ($payload['aud'] !== $google_conf['client_id']) die('Error: Audience mismatch.');
if ($payload['exp'] < time()) die('Error: Token expirado.');
if (!isset($payload['email_verified']) || $payload['email_verified'] !== 'true') {
    die('Error: Email Google no verificado.');
}

// Datos sanitizados
$google_sub = $payload['sub'];
$email = filter_var($payload['email'], FILTER_SANITIZE_EMAIL);
// Como quitamos scope 'profile', 'name' podría no venir. Usamos parte del email como fallback.
$raw_name = isset($payload['name']) ? $payload['name'] : explode('@', $email)[0];

// --- 5. LÓGICA DE BASE DE DATOS ---
try {
    $conn->begin_transaction();

    // A) Buscar identidad existente
    $stmt = $conn->prepare("SELECT user_id FROM auth_identities WHERE provider = 'google' AND provider_user_id = ?");
    $stmt->bind_param('s', $google_sub);
    $stmt->execute();
    $stmt->store_result(); // FIX: Store result para usar num_rows
    
    if ($stmt->num_rows > 0) {
        // --- LOGIN EXISTENTE ---
        $stmt->bind_result($user_id);
        $stmt->fetch();
        $stmt->close();

        // Update Logins
        $conn->query("UPDATE auth_identities SET last_login_at = NOW() WHERE provider = 'google' AND provider_user_id = '$google_sub'");
        
        $stmt_u = $conn->prepare("UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?");
        $ip = $_SERVER['REMOTE_ADDR'];
        $stmt_u->bind_param('si', $ip, $user_id);
        $stmt_u->execute();
        $stmt_u->close();

    } else {
        $stmt->close();
        
        // --- NUEVO USUARIO / VINCULACIÓN ---
        
        // B) Chequear si el email ya existe en users
        $stmt_email = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt_email->bind_param('s', $email);
        $stmt_email->execute();
        $stmt_email->store_result();

        if ($stmt_email->num_rows > 0) {
            // Vincular a cuenta existente
            $stmt_email->bind_result($user_id);
            $stmt_email->fetch();
            $stmt_email->close();
        } else {
            $stmt_email->close();
            
            // Crear nuevo usuario
            // 1. Generar Código Público
            $public_code = 'BN-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            
            // 2. Normalizar Username
            // ANTES: $game_username = $raw_name;
            // AHORA: Usamos un temporal genérico que el usuario DEBE cambiar
            $game_username = "Usuario_" . substr(bin2hex(random_bytes(3)), 0, 5); 
            $game_username_norm = strtolower($game_username);
            
            // Loop simple para garantizar unicidad del nick
            $suffix_count = 0;
            do {
                $temp_norm = ($suffix_count == 0) ? $game_username_norm : $game_username_norm . $suffix_count;
                
                $chk = $conn->prepare("SELECT id FROM users WHERE game_username_norm = ?");
                $chk->bind_param('s', $temp_norm);
                $chk->execute();
                $chk->store_result();
                $exists = $chk->num_rows > 0;
                $chk->close();
                
                if (!$exists) {
                    $game_username_norm = $temp_norm;
                    if ($suffix_count > 0) $game_username .= $suffix_count;
                } else {
                    $suffix_count = ($suffix_count == 0) ? rand(100, 999) : $suffix_count + 1;
                }
            } while ($exists);

            // 3. Insertar (con todas las columnas requeridas)
            $ip = $_SERVER['REMOTE_ADDR'];
            $initial_avatar = AvatarCatalog::random($conn);
            // '0' si el catálogo está vacío: nunca NULL (la columna puede ser NOT NULL); se repara en el primer get_session.
            $initial_avatar_id = $initial_avatar ? (string)$initial_avatar['id'] : '0';
            $sql_ins = "INSERT INTO users (
                public_code, email, 
                game_username, game_username_norm, 
                public_display_name, public_display_name_norm, 
                created_at, last_login_at, last_login_ip,
                avatar_id
            ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?)";
            
            $stmt_new = $conn->prepare($sql_ins);
            // public_display_name inicial igual al game_username; avatar aleatorio del catálogo válido
            $stmt_new->bind_param('ssssssss', 
                $public_code, $email, 
                $game_username, $game_username_norm, 
                $game_username, $game_username_norm, 
                $ip, $initial_avatar_id
            );
            
            if (!$stmt_new->execute()) throw new Exception("Error insert user: " . $stmt_new->error);
            $user_id = $stmt_new->insert_id;
            $stmt_new->close();

            // 4. Crear Coins (INSERT IGNORE para evitar crash si duplicado raro)
            $conn->query("INSERT IGNORE INTO coins (user_id, balance) VALUES ($user_id, 0.00)");
        }

        // C) Crear Identidad Auth
        $stmt_auth = $conn->prepare("INSERT INTO auth_identities (user_id, provider, provider_user_id, provider_email, created_at, last_login_at) VALUES (?, 'google', ?, ?, NOW(), NOW())");
        $stmt_auth->bind_param('iss', $user_id, $google_sub, $email);
        $stmt_auth->execute();
        $stmt_auth->close();
    }

    $conn->commit();

    // --- 6. SESIÓN Y REDIRECT ---
    $_SESSION['user_id'] = $user_id;
    $_SESSION['email'] = $email;
    $_SESSION['logged_in_client'] = true;

    require_once __DIR__ . '/../api/remember_me.php';
    if (isset($conn) && $conn instanceof mysqli) {
        banano_remember_issue_token($conn, (int)$user_id);
    }

    $redirect_target = banano_consume_return_url('/cuenta/');
    header('Location: ' . $redirect_target);
 
    exit;

} catch (Exception $e) {
    if ($conn->in_transaction) $conn->rollback();
    error_log("Login Error: " . $e->getMessage()); // Loguear error interno
    die('Error en el sistema de login. Por favor intenta más tarde.');
}
?>
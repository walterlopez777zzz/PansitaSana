<?php
// Archivo: wabo/visual_juegos/api_visual_juegos.php (v3.0 - media de tarjetas: imagen / SVG / ambos)
//
// Persiste lo que consume cuysito_app/juegos/index.php para el `.gh-card__media`
// de cada tarjeta y su título:
//   background_image_url, bg_position_x, bg_position_y  → capa imagen
//   media_config (JSON, ver juegos/gh_media.php)          → modo + icono SVG
//   texts_config.title.text                               → título de la tarjeta
// El SVG se sanea en servidor antes de guardarse (lista blanca).

try {
    ini_set('display_errors', 0);
    error_reporting(E_ALL);
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../../api/session_security.php';
    require_once __DIR__ . '/../../juegos/gh_media.php';

    // 1. AUTENTICACIÓN
    if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
        throw new Exception('Acceso no autorizado.', 403);
    }

    // 2. CONEXIÓN SEGURA A LA BASE DE DATOS
    ob_start();
    require_once __DIR__ . '/../core/db_connect.php';
    $output = ob_get_clean();
    if (!empty($output)) {
        throw new Exception('Error fatal en db_connect.php: ' . trim($output));
    }
    if (!isset($conn) || !$conn || $conn->connect_error) {
        throw new Exception('Fallo en la conexión a la base de datos. Verifica db_connect.php. Error: ' . (isset($conn) ? $conn->connect_error : 'Variable $conn no definida.'));
    }

    // 3. CONSTANTES
    $GALLERY_DIR = __DIR__ . '/../../assets/game_cards';
    if (is_dir(__DIR__ . '/../../assets')) {
        $GALLERY_DIR = realpath(__DIR__ . '/../../assets') . DIRECTORY_SEPARATOR . 'game_cards';
    }
    $GALLERY_URL_BASE = '/assets/game_cards/';
    $ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
    $CARD_KEYS = ['daily', 'flash'];
    $MIGRATION_HINT = " Ejecuta la migración cuysito_app/update_visuals_media_json_db.php.";

    // 4. LÓGICA DEL CONTROLADOR
    $method = $_SERVER['REQUEST_METHOD'];
    $action = '';
    $data = [];

    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';
    } elseif ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON inválido recibido.', 400);
        }
        $action = $data['action'] ?? '';
    }

    $requireCardKey = static function (array $data) use ($CARD_KEYS): string {
        $card_key = $data['card_key'] ?? '';
        if (!in_array($card_key, $CARD_KEYS, true)) {
            throw new Exception("card_key inválido. Debe ser 'daily' o 'flash'.", 400);
        }
        return $card_key;
    };

    $response = [];

    switch ($action) {
        case 'get_all':
            if ($method !== 'GET') throw new Exception("Método no permitido para 'get_all'.", 405);
            $cards = [];
            try {
                $result = $conn->query("SELECT card_key, background_image_url, bg_position_x, bg_position_y, texts_config, media_config FROM game_card_visuals ORDER BY id ASC");
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() === 1054) throw new Exception("Falta la columna media_config." . $MIGRATION_HINT, 500);
                throw $e;
            }
            if (!$result) {
                throw new Exception(($conn->errno === 1054 ? "Falta la columna media_config." . $MIGRATION_HINT : 'Error en la consulta SQL: ' . $conn->error));
            }
            while ($row = $result->fetch_assoc()) {
                $texts = $row['texts_config'] ? json_decode($row['texts_config'], true) : null;
                $media = $row['media_config'] ? json_decode($row['media_config'], true) : null;
                $cards[$row['card_key']] = [
                    'background_image_url' => $row['background_image_url'],
                    'bg_position_x'        => (int) $row['bg_position_x'],
                    'bg_position_y'        => (int) $row['bg_position_y'],
                    'title'                => is_array($texts) ? (string) ($texts['title']['text'] ?? '') : '',
                    'media_config'         => gh_media_legacy_mode(gh_media_normalize($media)['config'], $media, $row['background_image_url']),
                ];
            }
            $response = [
                'status' => 'success',
                'cards' => $cards,
                'defaults' => ['media_config' => gh_media_defaults(), 'svg' => GH_DEFAULT_SVG],
            ];
            break;

        case 'save':
            if ($method !== 'POST') throw new Exception("Método no permitido para 'save'.", 405);
            $card_key = $requireCardKey($data);

            $bg_url = isset($data['background_image_url']) ? trim((string) $data['background_image_url']) : '';
            if ($bg_url !== '') {
                $is_local = $bg_url[0] === '/' && substr($bg_url, 0, 2) !== '//';
                $is_https = strncasecmp($bg_url, 'https://', 8) === 0 && filter_var($bg_url, FILTER_VALIDATE_URL) !== false;
                if (!$is_local && !$is_https) {
                    throw new Exception('La URL de imagen debe ser una ruta local (/assets/…) o https://.', 400);
                }
                if (mb_strlen($bg_url) > 500) {
                    throw new Exception('La URL de imagen es demasiado larga (máx. 500).', 400);
                }
            } else {
                $bg_url = null;
            }

            $bg_pos_x = max(0, min(100, (int) ($data['bg_position_x'] ?? 50)));
            $bg_pos_y = max(0, min(100, (int) ($data['bg_position_y'] ?? 50)));

            $media = gh_media_normalize($data['media_config'] ?? null);
            if ($media['error'] !== null) {
                throw new Exception($media['error'], 400);
            }
            $media_json = json_encode($media['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $title = isset($data['title']) && is_string($data['title']) ? mb_substr(trim($data['title']), 0, 200) : '';
            $texts_json = $title !== '' ? json_encode(['title' => ['text' => $title]], JSON_UNESCAPED_UNICODE) : null;

            try {
                $stmt = $conn->prepare("UPDATE game_card_visuals SET background_image_url = ?, bg_position_x = ?, bg_position_y = ?, media_config = ?, texts_config = ? WHERE card_key = ?");
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() === 1054) throw new Exception("Falta la columna media_config." . $MIGRATION_HINT, 500);
                throw $e;
            }
            if (!$stmt) throw new Exception('Error al preparar la consulta: ' . $conn->error);
            $stmt->bind_param("siisss", $bg_url, $bg_pos_x, $bg_pos_y, $media_json, $texts_json, $card_key);
            if (!$stmt->execute()) {
                throw new Exception('Error al guardar: ' . $stmt->error);
            }
            if ($stmt->affected_rows === 0) {
                $check = $conn->prepare("SELECT id FROM game_card_visuals WHERE card_key = ?");
                $check->bind_param("s", $card_key);
                $check->execute();
                $check->store_result();
                $missing = $check->num_rows === 0;
                $check->close();
                if ($missing) {
                    throw new Exception("No se encontró la fila para card_key '{$card_key}'. Ejecuta la migración primero.", 404);
                }
            }
            $stmt->close();
            $response = [
                'status' => 'success',
                'message' => '¡Tarjeta actualizada correctamente!',
                'media_config' => $media['config'],
            ];
            break;

        case 'reset':
            if ($method !== 'POST') throw new Exception("Método no permitido para 'reset'.", 405);
            $card_key = $requireCardKey($data);

            $stmt = $conn->prepare("UPDATE game_card_visuals SET background_image_url = NULL, background_opacity = 1.00, background_color = NULL, bg_position_x = 50, bg_position_y = 50, bg_scale = 100, texts_config = NULL, media_config = NULL WHERE card_key = ?");
            if (!$stmt) throw new Exception('Error al preparar la consulta: ' . $conn->error);
            $stmt->bind_param("s", $card_key);
            if (!$stmt->execute()) {
                throw new Exception('Error al resetear: ' . $stmt->error);
            }
            $stmt->close();
            $response = ['status' => 'success', 'message' => "Tarjeta '{$card_key}' restaurada a valores por defecto."];
            break;

        case 'get_gallery':
            if ($method !== 'GET') throw new Exception("Método no permitido para 'get_gallery'.", 405);

            if (!is_dir($GALLERY_DIR)) {
                if (!mkdir($GALLERY_DIR, 0755, true)) {
                    throw new Exception('No se pudo crear la carpeta de galería: ' . $GALLERY_DIR);
                }
            }

            $images = [];
            $files = scandir($GALLERY_DIR);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, $ALLOWED_EXTENSIONS, true)) {
                    $images[] = [
                        'filename' => $file,
                        'url'      => $GALLERY_URL_BASE . rawurlencode($file),
                    ];
                }
            }

            usort($images, fn($a, $b) => strcasecmp($a['filename'], $b['filename']));
            $response = ['status' => 'success', 'images' => $images];
            break;

        default:
            throw new Exception("Acción '{$action}' no reconocida.", 404);
    }

    $conn->close();
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;

} catch (Throwable $e) {
    $httpCode = method_exists($e, 'getCode') && $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($httpCode);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}

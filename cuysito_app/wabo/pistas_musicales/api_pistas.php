<?php
// Archivo: api_pistas.php (Backend para la nueva interfaz de gestión de pistas)
require_once __DIR__ . '/../../api/session_security.php';
require_once __DIR__ . '/../core/db_connect.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso no autorizado.']);
    exit;
}

header('Content-Type: application/json');

function get_youtube_id_from_url($url) {
    preg_match('/(?:https?:\/\/)?(?:www\.)?(?:(?:youtu\.be\/)|(?:youtube\.com\/(?:(?:watch\?v=)|(?:embed\/)|(?:v\/))))([a-zA-Z0-9_-]{11})/', $url, $matches);
    return (isset($matches[1])) ? $matches[1] : $url;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$response = [];

try {
    switch ($action) {
        case 'get_all_tracks':
            // 1. Obtener pistas desde la API de Fortnite
            $apiUrl = 'https://fortnite-api.com/v2/shop?language=es-419';
            $api_tracks_raw = @file_get_contents($apiUrl);
            if ($api_tracks_raw === false) {
                throw new Exception("No se pudo conectar a la API de Fortnite.");
            }
            $api_data = json_decode($api_tracks_raw, true);
            $shop_tracks_map = [];
            if (isset($api_data['data']['entries'])) {
                foreach ($api_data['data']['entries'] as $entry) {
                    if (isset($entry['tracks'][0])) {
                        $track = $entry['tracks'][0];
                        $shop_tracks_map[$track['title']] = [
                            'name' => $track['title'],
                            'artist' => $track['artist'] ?? 'Artista Desconocido',
                            'artwork' => $track['albumArt'] ?? 'https://via.placeholder.com/100'
                        ];
                    }
                }
            }

            // 2. Obtener videos desde nuestra base de datos
            $db_videos_map = [];
            $result = $conn->query("SELECT nombre_pista, youtube_id, start_time FROM pistas_musicales WHERE youtube_id IS NOT NULL AND youtube_id != ''");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $db_videos_map[$row['nombre_pista']] = $row;
                }
            }

            // 3. Clasificar las pistas
            $inShopNoVideo = [];
            $inShopWithVideo = [];
            $outOfShopWithVideo = [];

            // Pistas en la tienda
            foreach ($shop_tracks_map as $name => $track_data) {
                if (isset($db_videos_map[$name])) {
                    // Está en la tienda Y tiene video
                    $inShopWithVideo[] = array_merge($track_data, $db_videos_map[$name]);
                } else {
                    // Está en la tienda PERO no tiene video
                    $inShopNoVideo[] = $track_data;
                }
            }

            // Pistas fuera de la tienda (históricas)
            foreach ($db_videos_map as $name => $video_data) {
                if (!isset($shop_tracks_map[$name])) {
                    // Tiene video PERO no está en la tienda hoy
                    $outOfShopWithVideo[] = [
                        'name' => $name,
                        'youtube_id' => $video_data['youtube_id'],
                        'start_time' => $video_data['start_time']
                    ];
                }
            }

            $response = [
                'status' => 'success',
                'inShopNoVideo' => $inShopNoVideo,
                'inShopWithVideo' => $inShopWithVideo,
                'outOfShopWithVideo' => $outOfShopWithVideo
            ];
            break;

        case 'save_video':
            $track_name = $_POST['track_name'] ?? '';
            $youtube_id_raw = $_POST['youtube_id'] ?? '';
            $start_time = (int)($_POST['start_time'] ?? 0);

            if (empty($track_name) || empty($youtube_id_raw)) {
                throw new Exception("Faltan datos para guardar el video.");
            }
            
            $youtube_id = get_youtube_id_from_url($youtube_id_raw);

            // Verificar si ya existe para decidir entre INSERT y UPDATE
            $stmt_check = $conn->prepare("SELECT id FROM pistas_musicales WHERE nombre_pista = ?");
            $stmt_check->bind_param("s", $track_name);
            $stmt_check->execute();
            $stmt_check->store_result();
            
            if ($stmt_check->num_rows > 0) {
                // Existe, hacer UPDATE
                $stmt = $conn->prepare("UPDATE pistas_musicales SET youtube_id = ?, start_time = ? WHERE nombre_pista = ?");
                $stmt->bind_param("sis", $youtube_id, $start_time, $track_name);
            } else {
                // No existe, hacer INSERT
                $stmt = $conn->prepare("INSERT INTO pistas_musicales (nombre_pista, youtube_id, start_time) VALUES (?, ?, ?)");
                $stmt->bind_param("ssi", $track_name, $youtube_id, $start_time);
            }
            $stmt_check->close();

            if ($stmt->execute()) {
                $response = ['status' => 'success', 'message' => 'Video guardado correctamente.'];
            } else {
                throw new Exception('Error al guardar en la base de datos: ' . $stmt->error);
            }
            $stmt->close();
            break;

        case 'remove_video':
            $track_name = $_POST['track_name'] ?? '';
            if (empty($track_name)) {
                throw new Exception("Falta el nombre de la pista para quitar el video.");
            }
            
            // Hacemos un UPDATE para limpiar los campos, no borramos la fila
            $stmt = $conn->prepare("UPDATE pistas_musicales SET youtube_id = '', start_time = 0 WHERE nombre_pista = ?");
            $stmt->bind_param("s", $track_name);

            if ($stmt->execute()) {
                $response = ['status' => 'success', 'message' => 'Video quitado correctamente.'];
            } else {
                throw new Exception('Error al quitar el video de la base de datos: ' . $stmt->error);
            }
            $stmt->close();
            break;

        default:
            throw new Exception('Acción no reconocida.');
    }
} catch (Exception $e) {
    http_response_code(400);
    $response = ['status' => 'error', 'message' => $e->getMessage()];
}

echo json_encode($response);
$conn->close();
?>
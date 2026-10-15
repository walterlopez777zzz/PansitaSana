<?php
// Archivo: api_videos_objetos.php (v1.0 - Videos manuales de objetos)
// API de la sección "Videos de Objetos": administra la tabla videos_objetos.
// Independiente del Gestor de Capa de Datos y del sistema legacy de pistas.
ini_set('display_errors', 0);
error_reporting(E_ALL);
date_default_timezone_set('America/Lima');

require_once __DIR__ . '/../../api/session_security.php';
require_once __DIR__ . '/../core/db_connect.php';
require_once __DIR__ . '/../../config/shop_config.php';
require_once __DIR__ . '/../../core/CosmeticsRepository.php';
require_once __DIR__ . '/../../core/ItemVideosRepository.php';
require_once __DIR__ . '/../../core/ShopRepository.php';
require_once __DIR__ . '/../../core/ShopNormalizer.php';

/** Clave estable de un estilo: "canal:tag" en minúsculas (misma que app.js). */
function vo_style_key($channel, $tag): string
{
    $c = preg_replace('/[^a-z0-9_.-]/', '', strtolower((string) $channel));
    $t = preg_replace('/[^a-z0-9_.-]/', '', strtolower((string) $tag));
    return ($c !== '' && $t !== '') ? $c . ':' . $t : '';
}

/** Snapshot entry whose lead cosmetic is $cosmeticId (visible ones first). */
function vo_find_entry(array $entries, string $cosmeticId): ?array
{
    $visible = null;
    $any     = null;
    foreach ($entries as $entry) {
        if (!is_array($entry) || ShopNormalizer::entryMainItemId($entry) !== $cosmeticId) {
            continue;
        }
        $isVisible = !empty($entry['layout']['name']);
        $isBundle  = isset($entry['bundle']) && is_array($entry['bundle']);
        if ($isVisible && !$isBundle) {
            return $entry;
        }
        if ($isVisible && $visible === null) {
            $visible = $entry;
        }
        if ($any === null) {
            $any = $entry;
        }
    }
    return $visible ?? $any;
}

/**
 * Selectable styles of a cosmetic, mirroring the item page's style selector:
 * numbered render styles for lotes, first variant channel otherwise.
 * @return array<int,array{key:string,name:string,image:?string}>
 */
function vo_item_styles(string $cosmeticId): array
{
    $snap    = ShopRepository::snapshot();
    $entries = isset($snap['entries']) && is_array($snap['entries']) ? $snap['entries'] : [];
    $entry   = vo_find_entry($entries, $cosmeticId);
    if ($entry === null) {
        return [];
    }
    $isBundle = isset($entry['bundle']) && is_array($entry['bundle']);
    $styles = [];
    if ($isBundle) {
        $renders = $entry['newDisplayAsset']['renderImages'] ?? [];
        $nonLego = [];
        foreach ((is_array($renders) ? $renders : []) as $r) {
            if (is_array($r) && (($r['productTag'] ?? '') !== 'Product.Juno')) {
                $nonLego[] = $r;
            }
        }
        if (count($nonLego) > 1) {
            foreach ($nonLego as $i => $r) {
                $styles[] = ['key' => 'render:' . $i, 'name' => 'Estilo ' . ($i + 1), 'image' => $r['image'] ?? null];
            }
        }
        return $styles;
    }
    $mainItem = null;
    foreach (['brItems', 'instruments', 'cars'] as $k) {
        if (isset($entry[$k][0]) && is_array($entry[$k][0])) {
            $mainItem = $entry[$k][0];
            break;
        }
    }
    $variants = isset($mainItem['variants']) && is_array($mainItem['variants']) ? $mainItem['variants'] : [];
    foreach ($variants as $channel) {
        if (!is_array($channel) || !isset($channel['options']) || !is_array($channel['options']) || count($channel['options']) <= 1) {
            continue;
        }
        foreach ($channel['options'] as $option) {
            if (!is_array($option)) {
                continue;
            }
            $key = vo_style_key($channel['channel'] ?? '', $option['tag'] ?? '');
            if ($key === '') {
                continue;
            }
            $styles[] = ['key' => $key, 'name' => (string) ($option['name'] ?? $key), 'image' => $option['image'] ?? null];
        }
        break; // Solo el primer canal con opciones (igual que la página del objeto).
    }
    return $styles;
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Acceso no autorizado.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Sin conexión a la base de datos.']);
    exit;
}

$action = $_REQUEST['action'] ?? 'list';

switch ($action) {

    case 'list': {
        $rows = ItemVideosRepository::all($conn);

        // Enriquecimiento para el panel: imagen del objeto, si el objeto está
        // hoy en la tienda (lead de alguna sección visible) y peso del archivo.
        $snap    = ShopRepository::snapshot();
        $entries = isset($snap['entries']) && is_array($snap['entries']) ? $snap['entries'] : [];
        $inShop  = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && !empty($entry['layout']['name'])) {
                $mainId = ShopNormalizer::entryMainItemId($entry);
                if ($mainId !== null) {
                    $inShop[$mainId] = true;
                }
            }
        }
        $index = CosmeticsRepository::index();
        foreach ($rows as &$row) {
            $cid = strtolower(trim((string) ($row['cosmetic_id'] ?? '')));
            $record = $index[$cid] ?? null;
            $row['image']   = is_array($record)
                ? ($record['images']['icon'] ?? ($record['images']['smallIcon'] ?? ($record['images']['albumArt'] ?? null)))
                : null;
            $row['in_shop'] = isset($inShop[$cid]);
            $row['file_size'] = null;
            $url = (string) ($row['video_url'] ?? '');
            $prefix = ItemVideosRepository::mediaUrlPrefix();
            if (strpos($url, $prefix) === 0) {
                $path = ItemVideosRepository::mediaDirPath() . '/' . basename($url);
                if (is_file($path)) {
                    $row['file_size'] = filesize($path);
                }
            }
        }
        unset($row);
        echo json_encode(['ok' => true, 'videos' => $rows], JSON_UNESCAPED_UNICODE);
        break;
    }

    case 'item_styles': {
        // Estilos seleccionables de un objeto (para asignar videos por estilo).
        $cid = strtolower(trim((string) ($_GET['id'] ?? '')));
        if ($cid === '' || strlen($cid) > 191) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'ID inválido.']);
            break;
        }
        echo json_encode(['ok' => true, 'styles' => vo_item_styles($cid)], JSON_UNESCAPED_UNICODE);
        break;
    }

    case 'search': {
        // Busca objetos por nombre o id en el índice local de cosméticos.
        $q = mb_strtolower(trim((string) ($_GET['q'] ?? '')));
        if (mb_strlen($q) < 2) {
            echo json_encode(['ok' => true, 'results' => []]);
            break;
        }
        $results = [];
        foreach (CosmeticsRepository::index() as $id => $record) {
            $name = mb_strtolower((string) ($record['name'] ?? ''));
            if (strpos($name, $q) === false && strpos($id, $q) === false) {
                continue;
            }
            $results[] = [
                'id'    => $id,
                'name'  => $record['name'] ?? $id,
                'type'  => $record['type']['displayValue'] ?? ($record['group'] ?? ''),
                'image' => $record['images']['icon'] ?? ($record['images']['smallIcon'] ?? ($record['images']['albumArt'] ?? null)),
            ];
            if (count($results) >= 20) {
                break;
            }
        }
        echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
        break;
    }

    case 'save': {
        $cosmeticId = strtolower(trim((string) ($_POST['cosmetic_id'] ?? '')));
        $nombre     = trim((string) ($_POST['nombre_objeto'] ?? ''));
        $videoUrl   = trim((string) ($_POST['video_url'] ?? ''));
        $styleKey   = strtolower(trim((string) ($_POST['style_key'] ?? '')));
        $styleName  = trim((string) ($_POST['style_name'] ?? ''));

        if ($cosmeticId === '' || strlen($cosmeticId) > 191) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'ID de objeto inválido.']);
            break;
        }
        if (strlen($styleKey) > 64 || ($styleKey !== '' && !preg_match('/^[a-z0-9_.:-]+$/', $styleKey))) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Clave de estilo inválida.']);
            break;
        }

        if (isset($_FILES['video_file']) && ($_FILES['video_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            // Subida directa de archivo (.webm / .mp4).
            $stored = ItemVideosRepository::storeUploadedFile($cosmeticId, $_FILES['video_file'], $styleKey);
            if (!$stored['ok']) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => $stored['message']]);
                break;
            }
            $finalUrl = $stored['url'];
            $origen   = 'archivo';
        } elseif ($videoUrl !== '') {
            // URL externa: se re-hospeda SIEMPRE (las URLs firmadas caducan).
            $rehosted = ItemVideosRepository::rehostFromUrl($cosmeticId, $videoUrl, $styleKey);
            if (!$rehosted['ok']) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'message' => $rehosted['message']]);
                break;
            }
            $finalUrl = $rehosted['url'];
            $origen   = 'archivo';
        } else {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Proporciona una URL o un archivo de video.']);
            break;
        }

        $saved = ItemVideosRepository::save($conn, $cosmeticId, $nombre !== '' ? $nombre : null, $finalUrl, $origen, $styleKey, $styleName !== '' ? $styleName : null);
        if ($saved['ok']) {
            ItemVideosRepository::resyncSnapshots($conn);
        }
        echo json_encode($saved + ['url' => $finalUrl], JSON_UNESCAPED_UNICODE);
        break;
    }

    case 'toggle': {
        $id     = (int) ($_POST['id'] ?? 0);
        $activo = (int) ($_POST['activo'] ?? 0) === 1;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'ID inválido.']);
            break;
        }
        $ok = ItemVideosRepository::setActive($conn, $id, $activo);
        if ($ok) {
            ItemVideosRepository::resyncSnapshots($conn);
        }
        echo json_encode(['ok' => $ok, 'message' => $ok ? 'Estado actualizado.' : 'No se pudo actualizar.']);
        break;
    }

    case 'delete': {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'ID inválido.']);
            break;
        }
        $ok = ItemVideosRepository::delete($conn, $id);
        if ($ok) {
            ItemVideosRepository::resyncSnapshots($conn);
        }
        echo json_encode(['ok' => $ok, 'message' => $ok ? 'Video eliminado.' : 'No se pudo eliminar.']);
        break;
    }

    default: {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Acción desconocida.']);
    }
}

$conn->close();

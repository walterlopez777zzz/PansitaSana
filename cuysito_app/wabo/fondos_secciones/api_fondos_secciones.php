<?php
// Archivo: api_fondos_secciones.php (v1.0 - Fondos manuales de secciones)
// API de la sección "Fondos de Secciones": administra la tabla secciones_fondos.
// Los fondos son las imágenes "billboard" que Epic muestra detrás de cada
// sección de la tienda en fortnite.com y que la API pública no expone.
ini_set('display_errors', 0);
error_reporting(E_ALL);
date_default_timezone_set('America/Lima');

require_once __DIR__ . '/../../api/session_security.php';
require_once __DIR__ . '/../core/db_connect.php';
require_once __DIR__ . '/../../config/shop_config.php';
require_once __DIR__ . '/../../core/ShopRepository.php';
require_once __DIR__ . '/../../core/SectionBackgroundsRepository.php';

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

/**
 * Secciones visibles de la tienda actual (una por layout), con un ítem
 * representativo para la vista previa del panel.
 * @return array<int,array{key:string,layoutId:string,name:string,rank:int,items:int,previewImage:?string}>
 */
function fs_current_sections(): array
{
    $snap    = ShopRepository::snapshot();
    $entries = isset($snap['entries']) && is_array($snap['entries']) ? $snap['entries'] : [];
    $sections = [];
    foreach ($entries as $entry) {
        if (!is_array($entry) || empty($entry['layout']['name'])) {
            continue;
        }
        $layoutId = (string) ($entry['layout']['id'] ?? $entry['layout']['name']);
        $key = SectionBackgroundsRepository::normalizeKey($layoutId);
        if ($key === '') {
            continue;
        }
        if (!isset($sections[$key])) {
            $preview = $entry['newDisplayAsset']['renderImages'][0]['image']
                ?? ($entry['brItems'][0]['images']['smallIcon'] ?? null);
            $sections[$key] = [
                'key'          => $key,
                'layoutId'     => $layoutId,
                'name'         => (string) $entry['layout']['name'],
                'rank'         => (int) ($entry['layout']['rank'] ?? 0),
                'items'        => 0,
                'previewImage' => is_string($preview) ? $preview : null,
            ];
        }
        $sections[$key]['items']++;
    }
    $list = array_values($sections);
    usort($list, static fn($a, $b) => $b['rank'] <=> $a['rank']);

    // Secciones construidas por el frontend (no vienen en las entries de la
    // API) pero que también pueden tener fondo manual: claves virtuales fijas.
    $virtual = [];
    foreach (SectionBackgroundsRepository::VIRTUAL_SECTIONS as $vKey => $vName) {
        if (isset($sections[$vKey])) {
            continue;
        }
        $virtual[] = [
            'key'          => $vKey,
            'layoutId'     => $vKey,
            'name'         => $vName,
            'rank'         => 0,
            'items'        => 0,
            'previewImage' => null,
            'virtual'      => true,
        ];
    }
    return array_merge($virtual, $list);
}

$action = $_REQUEST['action'] ?? 'list';

switch ($action) {

    case 'list': {
        // Fondos guardados + secciones de la tienda actual, con el estado de
        // vínculo resuelto (misma regla de matching que el frontend).
        $sections = fs_current_sections();

        // Limpieza automática: los fondos NO persistentes de secciones que ya
        // no están en la tienda se eliminan (las virtuales nunca se podan).
        $activeKeys = [];
        $activeNameKeys = [];
        foreach ($sections as $s) {
            if (!empty($s['virtual'])) {
                continue;
            }
            $activeKeys[] = $s['key'];
            $activeNameKeys[] = SectionBackgroundsRepository::normalizeKey($s['name']);
        }
        $pruned = SectionBackgroundsRepository::pruneEphemeral($conn, $activeKeys, $activeNameKeys);
        if ($pruned > 0) {
            SectionBackgroundsRepository::recompileCache($conn);
        }

        $rows = SectionBackgroundsRepository::all($conn);

        // El fondo por defecto global vive como fila reservada: se expone
        // aparte y no participa del matching por sección.
        $defaultBg = null;
        foreach ($rows as $i => $row) {
            if (SectionBackgroundsRepository::normalizeKey((string) $row['section_key'])
                === SectionBackgroundsRepository::DEFAULT_KEY) {
                $defaultBg = [
                    'id'        => (int) $row['id'],
                    'image_url' => (string) $row['image_url'],
                    'activo'    => (int) $row['activo'],
                ];
                unset($rows[$i]);
            }
        }
        $rows = array_values($rows);

        $normalizedRows = [];
        foreach ($rows as $row) {
            $normalizedRows[] = [
                'row'      => $row,
                'key'      => SectionBackgroundsRepository::normalizeKey((string) $row['section_key']),
                'nameKey'  => SectionBackgroundsRepository::normalizeKey((string) $row['section_name']),
            ];
        }
        // Incluye también fondos ocultos (activo = 0): el panel los muestra
        // con el ojo tachado para poder re-mostrarlos desde la propia fila.
        foreach ($sections as &$section) {
            $section['background'] = null;
            $sectionNameKey = SectionBackgroundsRepository::normalizeKey($section['name']);
            foreach ($normalizedRows as $nr) {
                if ($nr['key'] === $section['key']
                    || ($sectionNameKey !== '' && $nr['nameKey'] === $sectionNameKey)) {
                    $section['background'] = [
                        'id'        => (int) $nr['row']['id'],
                        'image_url' => $nr['row']['image_url'],
                        'activo'    => (int) $nr['row']['activo'],
                    ];
                    break;
                }
            }
        }
        unset($section);

        echo json_encode([
            'ok'        => true,
            'sections'  => $sections,
            'fondos'    => $rows,
            'default_background' => $defaultBg,
            'pruned'    => $pruned,
            'shop_date' => ShopRepository::shopDate(),
        ], JSON_UNESCAPED_UNICODE);
        break;
    }

    case 'save': {
        $sectionKey  = SectionBackgroundsRepository::normalizeKey((string) ($_POST['section_key'] ?? ''));
        $sectionName = trim((string) ($_POST['section_name'] ?? ''));
        $imageUrl    = trim((string) ($_POST['image_url'] ?? ''));

        if ($sectionKey === '' || strlen($sectionKey) > 160) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Clave de sección inválida.']);
            break;
        }
        if (mb_strlen($sectionName) > 190) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Nombre de sección demasiado largo.']);
            break;
        }
        if ($imageUrl === '' || strlen($imageUrl) > 2000
            || !preg_match('#^https://[^\s"\'<>]+$#i', $imageUrl)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'La URL de la imagen debe ser https:// válida.']);
            break;
        }

        $saved = SectionBackgroundsRepository::save($conn, $sectionKey, $sectionName, $imageUrl);
        if ($saved['ok']) {
            SectionBackgroundsRepository::recompileCache($conn);
        }
        echo json_encode($saved, JSON_UNESCAPED_UNICODE);
        break;
    }

    case 'save_default': {
        $imageUrl = trim((string) ($_POST['image_url'] ?? ''));
        if ($imageUrl === '' || strlen($imageUrl) > 2000
            || !preg_match('#^https://[^\s"\'<>]+$#i', $imageUrl)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'La URL de la imagen debe ser https:// válida.']);
            break;
        }
        $saved = SectionBackgroundsRepository::save(
            $conn,
            SectionBackgroundsRepository::DEFAULT_KEY,
            '',
            $imageUrl
        );
        if ($saved['ok']) {
            SectionBackgroundsRepository::recompileCache($conn);
            $saved['message'] = 'Fondo por defecto guardado.';
        }
        echo json_encode($saved, JSON_UNESCAPED_UNICODE);
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
        $ok = SectionBackgroundsRepository::setActive($conn, $id, $activo);
        if ($ok) {
            SectionBackgroundsRepository::recompileCache($conn);
        }
        echo json_encode(['ok' => $ok, 'message' => $ok ? 'Estado actualizado.' : 'No se pudo actualizar.']);
        break;
    }

    case 'persist': {
        $id          = (int) ($_POST['id'] ?? 0);
        $persistente = (int) ($_POST['persistente'] ?? 0) === 1;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'ID inválido.']);
            break;
        }
        $ok = SectionBackgroundsRepository::setPersistent($conn, $id, $persistente);
        echo json_encode(['ok' => $ok, 'message' => $ok ? 'Preferencia actualizada.' : 'No se pudo actualizar.']);
        break;
    }

    case 'delete': {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'ID inválido.']);
            break;
        }
        $ok = SectionBackgroundsRepository::delete($conn, $id);
        if ($ok) {
            SectionBackgroundsRepository::recompileCache($conn);
        }
        echo json_encode(['ok' => $ok, 'message' => $ok ? 'Fondo eliminado.' : 'No se pudo eliminar.']);
        break;
    }

    default: {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Acción desconocida.']);
    }
}

$conn->close();

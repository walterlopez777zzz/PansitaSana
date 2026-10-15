<?php
// /wabo/avatars/api_avatares.php
// API del panel de avatares: catálogo, creadores/packs, métricas y herramientas
// administrativas (inspección por avatar, asignación manual a usuarios).
require_once __DIR__ . '/../../api/session_security.php';
require_once __DIR__ . '/../core/db_connect.php';
require_once __DIR__ . '/../../core/AvatarCatalog.php';

// Seguridad Admin
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(403); die(json_encode(['status'=>'error', 'message'=>'Acceso denegado']));
}

header('Content-Type: application/json');
$action = $_POST['action'] ?? '';
$upload_dir = AvatarCatalog::uploadDir();
AvatarCatalog::ensureSchema($conn);

const AVATAR_MAX_BYTES = 2 * 1024 * 1024;
const AVATAR_MIME_EXT  = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
const AVATAR_DETAIL_USERS_LIMIT = 500;
const AVATAR_USER_SEARCH_LIMIT  = 25;

/** Valida el archivo subido (tamaño, tipo real) y lo mueve a la carpeta pública. Devuelve el nuevo filename. */
function avatar_store_upload(array $file, string $upload_dir): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new Exception($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
            ? 'La imagen supera el tamaño permitido por el servidor' : 'No se recibió el archivo');
    }
    if ((int)$file['size'] > AVATAR_MAX_BYTES) throw new Exception('La imagen es muy pesada (máx. 2 MB)');
    $info = @getimagesize($file['tmp_name']);
    $mime = $info['mime'] ?? '';
    if (!isset(AVATAR_MIME_EXT[$mime])) throw new Exception('Solo PNG, JPG o WEBP');
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) throw new Exception('No se pudo crear la carpeta de avatares');
    if (!is_writable($upload_dir)) throw new Exception('La carpeta de avatares no tiene permisos de escritura');
    $filename = uniqid('avt_') . '.' . AVATAR_MIME_EXT[$mime];
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . '/' . $filename)) {
        throw new Exception('Error al mover archivo al servidor');
    }
    return $filename;
}

function avatar_pct(int $part, int $total): float {
    return $total > 0 ? round($part * 100 / $total, 1) : 0.0;
}

/** Fila enriquecida para el panel. */
function avatar_row(array $row, array $usage, int $users_total): array {
    $id = (int)$row['id'];
    $row['id']          = $id;
    $row['is_active']   = (int)$row['is_active'];
    $row['creator']     = AvatarCatalog::normalizeCreator($row['creator'] ?? null);
    $row['url']         = AvatarCatalog::url($row['filename']);
    $row['file_exists'] = AvatarCatalog::fileExists($row['filename']);
    $row['users_count'] = $usage[$id] ?? 0;
    $row['users_pct']   = avatar_pct($row['users_count'], $users_total);
    $path = AvatarCatalog::filePath($row['filename']);
    $row['file_size']   = $path !== null ? (int)filesize($path) : null;
    $row['file_dir']    = $path !== null ? (dirname($path) === AvatarCatalog::uploadDir() ? 'public' : 'legacy') : null;
    if (empty($row['created_at']) && $path !== null) {
        // Filas anteriores a la columna created_at: la fecha del archivo es la mejor aproximación.
        $row['created_at'] = date('Y-m-d H:i:s', (int)filemtime($path));
        $row['created_at_source'] = 'file';
    } else {
        $row['created_at_source'] = empty($row['created_at']) ? null : 'db';
    }
    return $row;
}

function avatar_fetch(mysqli $conn, int $id): ?array {
    $stmt = $conn->prepare("SELECT * FROM avatars WHERE id = ?");
    $stmt->bind_param("i", $id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function avatar_users_total(mysqli $conn): int {
    $res = $conn->query("SELECT COUNT(*) AS n FROM users");
    if (!$res) return 0;
    $n = (int)$res->fetch_assoc()['n']; $res->free();
    return $n;
}

function avatar_stats(mysqli $conn, array $rows, int $users_total): array {
    $stats = ['total'=>count($rows), 'active'=>0, 'inactive'=>0, 'missing'=>0, 'orphan_avatars'=>0,
              'users_total'=>$users_total, 'orphan_users'=>0, 'users_with_avatar'=>0, 'users_without_avatar'=>0, 'creators'=>0];
    $creators = [];
    foreach ($rows as $r) {
        $r['is_active'] ? $stats['active']++ : $stats['inactive']++;
        if (!$r['file_exists']) $stats['missing']++;
        if ($r['users_count'] === 0) $stats['orphan_avatars']++;
        $creators[$r['creator']] = true;
    }
    $stats['creators']             = count($creators);
    $stats['orphan_users']         = AvatarCatalog::orphanUsersCount($conn);
    $stats['users_without_avatar'] = $stats['orphan_users'];
    $stats['users_with_avatar']    = max(0, $users_total - $stats['orphan_users']);
    $stats['users_with_avatar_pct'] = avatar_pct($stats['users_with_avatar'], $users_total);
    return $stats;
}

/** Creadores/packs con conteos: [{name, avatars, active, users, pct}] ordenados por usuarios. */
function avatar_creators(array $rows, int $users_total): array {
    $map = [];
    foreach ($rows as $r) {
        $c = $r['creator'];
        if (!isset($map[$c])) $map[$c] = ['name'=>$c, 'avatars'=>0, 'active'=>0, 'users'=>0, 'pct'=>0.0, 'is_default'=>$c === AvatarCatalog::DEFAULT_CREATOR];
        $map[$c]['avatars']++;
        if ($r['is_active']) $map[$c]['active']++;
        $map[$c]['users'] += $r['users_count'];
    }
    $assigned = array_sum(array_column($map, 'users'));
    foreach ($map as &$c) {
        $c['pct']       = avatar_pct($c['users'], $users_total);
        $c['share_pct'] = avatar_pct($c['users'], $assigned);
    }
    unset($c);
    $list = array_values($map);
    usort($list, fn($a, $b) => [$b['users'], $b['avatars'], $a['name']] <=> [$a['users'], $a['avatars'], $b['name']]);
    return $list;
}

/** Métricas para la pestaña "Visual Avatars". */
function avatar_analytics(array $rows, array $creators, array $stats): array {
    $users_total = $stats['users_total'];
    $byUsers = $rows;
    usort($byUsers, fn($a, $b) => [$b['users_count'], $a['id']] <=> [$a['users_count'], $b['id']]);

    $top = [];
    foreach (array_slice($byUsers, 0, 10) as $i => $r) {
        if ($r['users_count'] === 0) break;
        $top[] = ['rank'=>$i + 1, 'id'=>$r['id'], 'name'=>$r['name'], 'url'=>$r['url'], 'creator'=>$r['creator'],
                  'is_active'=>$r['is_active'], 'users'=>$r['users_count'], 'pct'=>$r['users_pct'],
                  'share_pct'=>avatar_pct($r['users_count'], $stats['users_with_avatar'])];
    }

    $orphans = array_values(array_map(fn($r) => ['id'=>$r['id'], 'name'=>$r['name'], 'url'=>$r['url'], 'creator'=>$r['creator'], 'is_active'=>$r['is_active'], 'file_exists'=>$r['file_exists']],
        array_filter($rows, fn($r) => $r['users_count'] === 0)));

    $recent = $rows;
    usort($recent, fn($a, $b) => [(string)$b['created_at'], $b['id']] <=> [(string)$a['created_at'], $a['id']]);
    $recent = array_values(array_map(fn($r) => ['id'=>$r['id'], 'name'=>$r['name'], 'url'=>$r['url'], 'creator'=>$r['creator'], 'created_at'=>$r['created_at'], 'users'=>$r['users_count']],
        array_slice($recent, 0, 6)));

    $active_in_use = count(array_filter($rows, fn($r) => $r['is_active'] && $r['users_count'] > 0));
    $topCreator = $creators[0] ?? null;

    // Concentración: cuántos avatares acumulan el 50 % de los usuarios con avatar.
    $half = $stats['users_with_avatar'] / 2; $acc = 0; $needed = 0;
    foreach ($byUsers as $r) { if ($acc >= $half || $r['users_count'] === 0) break; $acc += $r['users_count']; $needed++; }

    return [
        'top'               => $top,
        'orphans'           => $orphans,
        'recent'            => $recent,
        'creators'          => $creators,
        'top_creator'       => $topCreator,
        'users_total'       => $users_total,
        'users_with_avatar' => $stats['users_with_avatar'],
        'users_without_avatar' => $stats['users_without_avatar'],
        'users_with_avatar_pct' => $stats['users_with_avatar_pct'],
        'active_in_use'     => $active_in_use,
        'active_total'      => $stats['active'],
        'coverage_pct'      => avatar_pct($active_in_use, $stats['active']),
        'half_users_avatars' => $needed,
        'avg_users_per_avatar' => $stats['active'] > 0 ? round($stats['users_with_avatar'] / $stats['active'], 1) : 0,
    ];
}

function avatar_list_payload(mysqli $conn, array $extra = []): array {
    AvatarCatalog::refresh();
    $usage = AvatarCatalog::usageCounts($conn);
    $users_total = avatar_users_total($conn);
    $res   = $conn->query("SELECT * FROM avatars ORDER BY id DESC");
    $rows  = array_map(fn($r) => avatar_row($r, $usage, $users_total), $res->fetch_all(MYSQLI_ASSOC));
    $stats = avatar_stats($conn, $rows, $users_total);
    $creators = avatar_creators($rows, $users_total);
    return [
        'status'          => 'success',
        'data'            => $rows,
        'stats'           => $stats,
        'creators'        => $creators,
        'analytics'       => avatar_analytics($rows, $creators, $stats),
        'upload_dir'      => AvatarCatalog::uploadDir(),
        'default_creator' => AvatarCatalog::DEFAULT_CREATOR,
    ] + $extra;
}

const AVATAR_USER_COLS = "u.id, u.public_code, u.email, u.game_username, u.public_display_name, u.is_verified, u.account_status,
                          u.avatar_id, u.avatar_updated_at, u.created_at, u.last_login_at";

/** Fila de usuario para listas del panel (con su avatar efectivo resuelto, sin persistir). */
function avatar_user_row(mysqli $conn, array $u): array {
    $av = AvatarCatalog::find($conn, $u['avatar_id'] ?? null);
    return [
        'id'                  => (int)$u['id'],
        'public_code'         => $u['public_code'] ?? null,
        'email'               => $u['email'] ?? null,
        'game_username'       => $u['game_username'] ?? null,
        'public_display_name' => $u['public_display_name'] ?? null,
        'is_verified'         => (int)($u['is_verified'] ?? 0),
        'account_status'      => $u['account_status'] ?? null,
        'avatar_id'           => $av ? $av['id'] : (is_numeric($u['avatar_id'] ?? null) ? (int)$u['avatar_id'] : null),
        'avatar_valid'        => $av !== null,
        'avatar_name'         => $av ? $av['name'] : null,
        'avatar_url'          => $av ? AvatarCatalog::url($av['filename']) : null,
        'avatar_updated_at'   => $u['avatar_updated_at'] ?? null,
        'created_at'          => $u['created_at'] ?? null,
        'last_login_at'       => $u['last_login_at'] ?? null,
    ];
}

function avatar_user_fetch(mysqli $conn, int $user_id): ?array {
    $stmt = $conn->prepare("SELECT " . AVATAR_USER_COLS . " FROM users u WHERE u.id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id); $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $u ? avatar_user_row($conn, $u) : null;
}

/** Sustituye el creador de un conjunto de avatares. Devuelve filas afectadas. */
function avatar_set_creator(mysqli $conn, array $ids, string $creator): int {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
    if (!$ids) throw new Exception("Indica al menos un avatar");
    $in = implode(',', $ids);
    $stmt = $conn->prepare("UPDATE avatars SET creator = ? WHERE id IN ({$in})");
    $stmt->bind_param("s", $creator);
    $stmt->execute();
    $n = $stmt->affected_rows;
    $stmt->close();
    return max(0, (int)$n);
}

try {
    switch ($action) {
        case 'list':
            echo json_encode(avatar_list_payload($conn));
            break;

        case 'upload':
            if (!isset($_FILES['file'])) throw new Exception("No se envió ningún archivo");
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                // Nombre descriptivo a partir del archivo original: "mi_avatar-epico.png" → "Mi avatar epico"
                $base = pathinfo((string)($_FILES['file']['name'] ?? ''), PATHINFO_FILENAME);
                $name = trim(preg_replace('/[\s_\-]+/u', ' ', $base));
                $name = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1) : 'Avatar';
            }
            if (mb_strlen($name) > 60) $name = mb_substr($name, 0, 60);
            $is_active = isset($_POST['is_active']) ? ((int)$_POST['is_active'] === 1 ? 1 : 0) : 1;
            $creator = AvatarCatalog::normalizeCreator($_POST['creator'] ?? null);

            $filename = avatar_store_upload($_FILES['file'], $upload_dir);
            $new_id = 0;
            try {
                $stmt = $conn->prepare("INSERT INTO avatars (filename, name, creator, is_active, created_at) VALUES (?, ?, ?, ?, NOW())");
                if ($stmt) {
                    $stmt->bind_param("sssi", $filename, $name, $creator, $is_active);
                    if ($stmt->execute()) $new_id = (int)$stmt->insert_id;
                    $stmt->close();
                }
            } catch (mysqli_sql_exception $e) {
                $new_id = 0;
            }
            if ($new_id <= 0) {
                // Tabla legacy sin creator/created_at (sin permisos para ALTER).
                $stmt = $conn->prepare("INSERT INTO avatars (filename, name, is_active) VALUES (?, ?, ?)");
                if (!$stmt) throw new Exception('No se pudo registrar el avatar');
                $stmt->bind_param("ssi", $filename, $name, $is_active);
                if (!$stmt->execute()) { $stmt->close(); @unlink($upload_dir . $filename); throw new Exception('No se pudo registrar el avatar'); }
                $new_id = (int)$stmt->insert_id;
                $stmt->close();
            }
            echo json_encode(avatar_list_payload($conn, ['message'=>'Avatar subido', 'id'=>$new_id]));
            break;

        case 'replace_file':
            // Sustituye la imagen conservando id/nombre/estado: los usuarios que lo tienen no cambian.
            $id = (int)($_POST['id'] ?? 0);
            $row = avatar_fetch($conn, $id);
            if (!$row) throw new Exception("Avatar no encontrado");
            if (!isset($_FILES['file'])) throw new Exception("No se envió ningún archivo");

            $filename = avatar_store_upload($_FILES['file'], $upload_dir);
            $stmt = $conn->prepare("UPDATE avatars SET filename = ? WHERE id = ?");
            $stmt->bind_param("si", $filename, $id);
            $stmt->execute();
            $stmt->close();
            if ($row['filename'] !== $filename) AvatarCatalog::unlinkAll($row['filename']);
            echo json_encode(avatar_list_payload($conn, ['message'=>'Imagen reemplazada', 'id'=>$id]));
            break;

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            $row = avatar_fetch($conn, $id);
            if (!$row) throw new Exception("Avatar no encontrado");
            AvatarCatalog::unlinkAll($row['filename']);
            $stmt = $conn->prepare("DELETE FROM avatars WHERE id = ?");
            $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();
            AvatarCatalog::refresh();
            $moved = AvatarCatalog::reassignOrphans($conn);
            echo json_encode(avatar_list_payload($conn, ['message'=>'Avatar eliminado', 'users_reassigned'=>$moved]));
            break;

        case 'toggle_status':
            $id = (int)($_POST['id'] ?? 0);
            $status = (int)($_POST['status'] ?? 0) === 1 ? 1 : 0;
            $stmt = $conn->prepare("UPDATE avatars SET is_active = ? WHERE id = ?");
            $stmt->bind_param("ii", $status, $id);
            $stmt->execute();
            $stmt->close();
            AvatarCatalog::refresh();
            $moved = $status === 0 ? AvatarCatalog::reassignOrphans($conn) : 0;
            echo json_encode(avatar_list_payload($conn, ['users_reassigned'=>$moved, 'id'=>$id]));
            break;

        case 'update_name':
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new Exception("El nombre no puede estar vacío");
            if (mb_strlen($name) > 60) throw new Exception("El nombre es demasiado largo (máx. 60)");
            $stmt = $conn->prepare("UPDATE avatars SET name = ? WHERE id = ?");
            $stmt->bind_param("si", $name, $id);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['status'=>'success']);
            break;

        case 'set_creator':
            // Cambia el creador/pack de uno o varios avatares (ids separados por coma).
            $ids = preg_split('/[,\s]+/', (string)($_POST['ids'] ?? $_POST['id'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            $creator = AvatarCatalog::normalizeCreator($_POST['creator'] ?? null);
            $n = avatar_set_creator($conn, $ids, $creator);
            echo json_encode(avatar_list_payload($conn, ['message'=>"Creador \"{$creator}\" aplicado a {$n} avatar" . ($n === 1 ? '' : 'es'), 'updated'=>$n, 'creator'=>$creator]));
            break;

        case 'rename_creator':
            // Renombra (o fusiona) un creador/pack completo.
            $from = AvatarCatalog::normalizeCreator($_POST['from'] ?? null);
            $to   = AvatarCatalog::normalizeCreator($_POST['to'] ?? null);
            if ($from === $to) throw new Exception("El nombre nuevo es igual al actual");
            $stmt = $conn->prepare("UPDATE avatars SET creator = ? WHERE creator = ?");
            $stmt->bind_param("ss", $to, $from);
            $stmt->execute();
            $n = max(0, (int)$stmt->affected_rows);
            $stmt->close();
            echo json_encode(avatar_list_payload($conn, ['message'=>"\"{$from}\" → \"{$to}\": {$n} avatar" . ($n === 1 ? '' : 'es'), 'updated'=>$n, 'creator'=>$to]));
            break;

        case 'detail':
            // Ficha completa de un avatar + usuarios que lo tienen equipado.
            $id = (int)($_POST['id'] ?? 0);
            $row = avatar_fetch($conn, $id);
            if (!$row) throw new Exception("Avatar no encontrado");
            AvatarCatalog::refresh();
            $usage = AvatarCatalog::usageCounts($conn);
            $users_total = avatar_users_total($conn);
            $av = avatar_row($row, $usage, $users_total);
            $path = AvatarCatalog::filePath($row['filename']);
            $dim = $path !== null ? @getimagesize($path) : false;
            $av['width']  = $dim ? (int)$dim[0] : null;
            $av['height'] = $dim ? (int)$dim[1] : null;
            $av['mime']   = $dim ? ($dim['mime'] ?? null) : null;
            $av['is_valid'] = AvatarCatalog::find($conn, $id) !== null;

            $sid = (string)$id;
            $limit = AVATAR_DETAIL_USERS_LIMIT;
            $stmt = $conn->prepare("SELECT " . AVATAR_USER_COLS . " FROM users u WHERE u.avatar_id = ?
                                    ORDER BY u.avatar_updated_at DESC, u.id DESC LIMIT {$limit}");
            $stmt->bind_param("s", $sid); $stmt->execute();
            $users = array_map(fn($u) => avatar_user_row($conn, $u), $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
            $stmt->close();

            echo json_encode(['status'=>'success', 'avatar'=>$av, 'users'=>$users, 'users_truncated'=>count($users) >= $limit]);
            break;

        case 'search_users':
            // Busca usuarios por id, código público, nombre público, usuario del juego o email.
            $q = trim((string)($_POST['q'] ?? ''));
            if (mb_strlen($q) < 1) throw new Exception("Escribe algo para buscar");
            $like = '%' . $q . '%';
            $limit = AVATAR_USER_SEARCH_LIMIT;
            if (ctype_digit($q)) {
                $qid = (int)$q;
                $stmt = $conn->prepare("SELECT " . AVATAR_USER_COLS . " FROM users u
                                        WHERE u.id = ? OR u.public_code LIKE ? OR u.public_display_name LIKE ? OR u.game_username LIKE ?
                                        ORDER BY (u.id = ?) DESC, u.last_login_at DESC LIMIT {$limit}");
                $stmt->bind_param("isssi", $qid, $like, $like, $like, $qid);
            } else {
                $stmt = $conn->prepare("SELECT " . AVATAR_USER_COLS . " FROM users u
                                        WHERE u.public_display_name LIKE ? OR u.game_username LIKE ? OR u.public_code LIKE ? OR u.email LIKE ?
                                        ORDER BY (u.public_display_name = ? OR u.game_username = ?) DESC, u.last_login_at DESC LIMIT {$limit}");
                $stmt->bind_param("ssssss", $like, $like, $like, $like, $q, $q);
            }
            $stmt->execute();
            $users = array_map(fn($u) => avatar_user_row($conn, $u), $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
            $stmt->close();
            echo json_encode(['status'=>'success', 'users'=>$users, 'q'=>$q]);
            break;

        case 'assign_user_avatar':
            // Cambia manualmente el avatar de un usuario (sólo a avatares válidos: activos y con archivo).
            $user_id   = (int)($_POST['user_id'] ?? 0);
            $avatar_id = (int)($_POST['avatar_id'] ?? 0);
            if ($user_id <= 0) throw new Exception("Usuario inválido");
            $before = avatar_user_fetch($conn, $user_id);
            if (!$before) throw new Exception("Usuario no encontrado");
            $target = $avatar_id === 0 ? AvatarCatalog::random($conn) : AvatarCatalog::find($conn, $avatar_id);
            if (!$target) throw new Exception($avatar_id === 0 ? "No hay avatares válidos en el catálogo" : "Ese avatar no está disponible (inactivo o sin archivo)");
            $sid = (string)$target['id'];
            $stmt = $conn->prepare("UPDATE users SET avatar_id = ?, avatar_updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("si", $sid, $user_id);
            $stmt->execute();
            $stmt->close();
            $after = avatar_user_fetch($conn, $user_id);
            $label = $after['public_display_name'] ?: ($after['game_username'] ?: "#{$user_id}");
            echo json_encode(avatar_list_payload($conn, [
                'message' => "Avatar de {$label} → \"{$target['name']}\"",
                'user'    => $after,
                'previous_avatar_id' => $before['avatar_id'],
                'id'      => $target['id'],
            ]));
            break;

        case 'repair_orphans':
            // Reasigna a mano los usuarios con avatar inválido (borrado, inactivo, sin archivo o legacy).
            AvatarCatalog::refresh();
            $moved = AvatarCatalog::reassignOrphans($conn);
            echo json_encode(avatar_list_payload($conn, ['message'=>"Usuarios reasignados: {$moved}", 'users_reassigned'=>$moved]));
            break;

        default: throw new Exception("Acción inválida");
    }
} catch (mysqli_sql_exception $e) {
    error_log('[avatars] ' . $e->getMessage());
    http_response_code(500);
    $msg = stripos($e->getMessage(), "Unknown column 'creator'") !== false
        ? 'La tabla avatars aún no tiene la columna creator y el usuario de BD no puede crearla (ALTER TABLE).'
        : 'Error de base de datos';
    echo json_encode(['status'=>'error', 'message'=>$msg]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status'=>'error', 'message'=>$e->getMessage()]);
}

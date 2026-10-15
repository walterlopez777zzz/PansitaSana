<?php
/**
 * ============================================================
 * Tareas — API de Adjuntos de misión
 * ============================================================
 * Endpoints (?action=...):
 *   GET  list?mision_id= → adjuntos de una misión
 *   POST upload          → subir un archivo (multipart/form-data)
 *   GET  download?id=    → descargar/servir un adjunto
 *   POST delete          → eliminar un adjunto (DB + disco)
 *
 * Seguridad:
 *   - Allowlist de extensiones; nombre en disco aleatorio.
 *   - uploads/ tiene .htaccess que impide ejecutar scripts.
 *   - La descarga se sirve como adjunto (Content-Disposition) con
 *     X-Content-Type-Options: nosniff para evitar XSS por sniffing.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/domain.php';

const TAREAS_ATTACH_MAX_BYTES = 10485760; // 10 MiB

/** Extensiones permitidas → tipo MIME canónico. */
const TAREAS_ATTACH_TYPES = [
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'pdf'  => 'application/pdf',
    'txt'  => 'text/plain',
    'md'   => 'text/plain',
    'csv'  => 'text/csv',
    'json' => 'application/json',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'zip'  => 'application/zip',
];

$pdo       = TareasDB::connect();
$prefix    = tareas_prefix();
$method    = $_SERVER['REQUEST_METHOD'];
$action    = $_GET['action'] ?? '';
$uploadDir = __DIR__ . '/../uploads';

try {
    switch ($action) {

        // ─── LISTAR ──────────────────────────────────────────
        case 'list':
            if ($method !== 'GET') {
                tareas_error("Usa GET para 'list'.", 405);
            }

            $misionId = tareas_int($_GET['mision_id'] ?? 0);
            if ($misionId === 0) tareas_error('mision_id inválido.', 400);

            tareas_ok(['attachments' => tareas_list_attachments($pdo, $prefix, $misionId)]);

        // ─── SUBIR ───────────────────────────────────────────
        case 'upload':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'upload'.", 405);
            }

            $misionId = tareas_int($_POST['mision_id'] ?? 0);
            if ($misionId === 0) tareas_error('mision_id inválido.', 400);
            if (!tareas_attach_mision_exists($pdo, $prefix, $misionId)) {
                tareas_error('Misión no encontrada.', 404);
            }

            $file = $_FILES['file'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                tareas_error('No se recibió ningún archivo.', 400);
            }
            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                tareas_error('La subida falló (código ' . (int) $file['error'] . ').', 400);
            }
            if (!is_uploaded_file($file['tmp_name'])) {
                tareas_error('Subida inválida.', 400);
            }
            if ((int) $file['size'] <= 0 || (int) $file['size'] > TAREAS_ATTACH_MAX_BYTES) {
                tareas_error('El archivo supera el máximo de 10 MB o está vacío.', 400);
            }

            $original = tareas_clean_text($file['name'] ?? '', 180);
            $ext      = strtolower(pathinfo($original, PATHINFO_EXTENSION));
            if (!isset(TAREAS_ATTACH_TYPES[$ext])) {
                tareas_error('Tipo de archivo no permitido.', 415);
            }

            if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true)) {
                tareas_error('No se pudo preparar el almacenamiento.', 500);
            }

            $mime       = tareas_detect_mime($file['tmp_name'], $ext);
            $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
            $target     = $uploadDir . '/' . $storedName;

            if (!move_uploaded_file($file['tmp_name'], $target)) {
                tareas_error('No se pudo guardar el archivo.', 500);
            }
            @chmod($target, 0644);

            $stmt = $pdo->prepare(
                "INSERT INTO `{$prefix}attachments` (mision_id, stored_name, original_name, mime, size)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$misionId, $storedName, $original !== '' ? $original : $storedName, $mime, (int) $file['size']]);
            $id = (int) $pdo->lastInsertId();

            tareas_emit($pdo, 'misiones');
            tareas_ok([
                'message'    => 'Adjunto subido.',
                'attachment' => tareas_fetch_attachment($pdo, $prefix, $id),
            ], 201);

        // ─── DESCARGAR / SERVIR ──────────────────────────────
        case 'download':
            if ($method !== 'GET') {
                tareas_error("Usa GET para 'download'.", 405);
            }

            $id = tareas_int($_GET['id'] ?? 0);
            if ($id === 0) tareas_error('ID inválido.', 400);

            $attachment = tareas_fetch_attachment($pdo, $prefix, $id);
            if (!$attachment) {
                tareas_error('Adjunto no encontrado.', 404);
            }

            $path = $uploadDir . '/' . $attachment['stored_name'];
            if (!is_file($path)) {
                tareas_error('El archivo ya no está disponible.', 410);
            }

            tareas_stream_attachment($path, $attachment);

        // ─── ELIMINAR ────────────────────────────────────────
        case 'delete':
            if ($method !== 'POST') {
                tareas_error("Usa POST para 'delete'.", 405);
            }

            $body = tareas_read_json_body();
            $id   = tareas_int($body['id'] ?? 0);
            if ($id === 0) tareas_error('ID inválido.', 400);

            $attachment = tareas_fetch_attachment($pdo, $prefix, $id);
            if (!$attachment) {
                tareas_error('Adjunto no encontrado.', 404);
            }

            $pdo->prepare("DELETE FROM `{$prefix}attachments` WHERE id = ?")->execute([$id]);

            $path = $uploadDir . '/' . $attachment['stored_name'];
            if (is_file($path)) {
                @unlink($path);
            }

            tareas_emit($pdo, 'misiones');
            tareas_ok(['message' => 'Adjunto eliminado.']);

        default:
            tareas_error('Acción no reconocida.', 404);
    }
} catch (PDOException $e) {
    error_log('[tareas/attachments] ' . $e->getMessage());
    tareas_error('Error de base de datos.', 500);
}

// ── Helpers ──────────────────────────────────────────────────

/** ¿Existe la misión? */
function tareas_attach_mision_exists(PDO $pdo, string $prefix, int $misionId): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM `{$prefix}misiones` WHERE id = ?");
    $stmt->execute([$misionId]);
    return (bool) $stmt->fetchColumn();
}

/** Detecta el MIME real del archivo; cae al canónico de la extensión. */
function tareas_detect_mime(string $tmpPath, string $ext): string
{
    $fallback = TAREAS_ATTACH_TYPES[$ext] ?? 'application/octet-stream';
    if (!function_exists('finfo_open')) {
        return $fallback;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        return $fallback;
    }
    $detected = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);
    return is_string($detected) && $detected !== '' ? $detected : $fallback;
}

/** Adjuntos de una misión (metadatos, sin contenido binario). */
function tareas_list_attachments(PDO $pdo, string $prefix, int $misionId): array
{
    $stmt = $pdo->prepare(
        "SELECT id, mision_id, stored_name, original_name, mime, size, created_at
           FROM `{$prefix}attachments`
          WHERE mision_id = ?
          ORDER BY created_at DESC, id DESC"
    );
    $stmt->execute([$misionId]);

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row = tareas_shape_attachment($row);
    }
    unset($row);

    return $rows;
}

/** Un adjunto por id o null. */
function tareas_fetch_attachment(PDO $pdo, string $prefix, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id, mision_id, stored_name, original_name, mime, size, created_at
           FROM `{$prefix}attachments` WHERE id = ?"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? tareas_shape_attachment($row) : null;
}

/** Normaliza tipos escalares de un adjunto. */
function tareas_shape_attachment(array $row): array
{
    $row['id']        = (int) $row['id'];
    $row['mision_id'] = (int) $row['mision_id'];
    $row['size']      = (int) $row['size'];
    return $row;
}

/** Envía el archivo al cliente como descarga segura y corta la ejecución. */
function tareas_stream_attachment(string $path, array $attachment): never
{
    $size = filesize($path);

    header('Content-Type: ' . ($attachment['mime'] !== '' ? $attachment['mime'] : 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . tareas_safe_filename($attachment['original_name']) . '"');
    header('Content-Length: ' . ($size !== false ? $size : 0));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');

    readfile($path);
    exit;
}

/** Sanea el nombre de archivo para la cabecera Content-Disposition. */
function tareas_safe_filename(string $name): string
{
    $name = str_replace(['"', "\\", "\r", "\n"], '', $name);
    return $name !== '' ? $name : 'adjunto';
}

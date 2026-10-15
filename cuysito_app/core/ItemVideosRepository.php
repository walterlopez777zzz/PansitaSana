<?php
/**
 * ============================================================================
 * BANANO STORE — ITEM VIDEOS REPOSITORY (videos manuales de objetos)
 * ============================================================================
 * Persistence for manually-managed item videos shown on the item pages
 * (/objeto/...). Independent of the legacy jam-track video system and of the
 * Data Layer Manager: videos are administered ONLY from the dedicated
 * "Videos de Objetos" admin section.
 *
 * Storage: `videos_objetos` table keyed by the cosmetic id. External URLs
 * (often short-lived signed S3 links) are re-hosted at save time into
 * cuysito_app/videos_objetos_media/ so pages never depend on expiring links.
 * ============================================================================
 */

require_once __DIR__ . '/../config/shop_config.php';

if (!class_exists('ItemVideosRepository')) {

final class ItemVideosRepository
{
    const TABLE = 'videos_objetos';

    /** Directory (inside cuysito_app) where re-hosted video files live. */
    const MEDIA_DIR = 'videos_objetos_media';

    public static function mediaDirPath(): string
    {
        return ShopSourceConfig::appRoot() . '/' . self::MEDIA_DIR;
    }

    /** Public URL prefix for re-hosted files (served by the router). */
    public static function mediaUrlPrefix(): string
    {
        return '/' . self::MEDIA_DIR . '/';
    }

    public static function ensureTable(mysqli $conn): void
    {
        $conn->query(
            'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `cosmetic_id` VARCHAR(191) NOT NULL,
                `style_key` VARCHAR(64) NOT NULL DEFAULT "",
                `style_name` VARCHAR(120) NULL,
                `nombre_objeto` VARCHAR(255) NULL,
                `video_url` TEXT NOT NULL,
                `origen` ENUM("url","archivo") NOT NULL DEFAULT "url",
                `activo` TINYINT(1) NOT NULL DEFAULT 1,
                `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_cosmetic_style` (`cosmetic_id`, `style_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::upgradeSchema($conn);
    }

    /**
     * In-place upgrade for pre-styles installs: adds style_key/style_name and
     * swaps the per-cosmetic unique for a per-(cosmetic, style) unique.
     * style_key = "" is the main (default) video of the item.
     */
    private static function upgradeSchema(mysqli $conn): void
    {
        $res = $conn->query('SHOW COLUMNS FROM `' . self::TABLE . '` LIKE "style_key"');
        if ($res && $res->num_rows > 0) {
            return;
        }
        $conn->query(
            'ALTER TABLE `' . self::TABLE . '`
                ADD COLUMN `style_key` VARCHAR(64) NOT NULL DEFAULT "" AFTER `cosmetic_id`,
                ADD COLUMN `style_name` VARCHAR(120) NULL AFTER `style_key`'
        );
        $idx = $conn->query('SHOW INDEX FROM `' . self::TABLE . '` WHERE Key_name = "uniq_cosmetic"');
        if ($idx && $idx->num_rows > 0) {
            $conn->query('ALTER TABLE `' . self::TABLE . '` DROP INDEX `uniq_cosmetic`');
        }
        $idx = $conn->query('SHOW INDEX FROM `' . self::TABLE . '` WHERE Key_name = "uniq_cosmetic_style"');
        if (!$idx || $idx->num_rows === 0) {
            $conn->query('ALTER TABLE `' . self::TABLE . '` ADD UNIQUE KEY `uniq_cosmetic_style` (`cosmetic_id`, `style_key`)');
        }
    }

    /**
     * Active videos per cosmetic, main video + per-style videos.
     * @return array<string,array{url:?string,styles:array<string,string>}>
     *         cosmetic_id (lowercased) => ['url' => main video or null,
     *                                      'styles' => style_key => video_url]
     */
    public static function activeVideoMap(?mysqli $conn): array
    {
        if (!($conn instanceof mysqli)) {
            return [];
        }
        self::ensureTable($conn);
        $map = [];
        $res = $conn->query('SELECT cosmetic_id, style_key, video_url FROM `' . self::TABLE . '` WHERE activo = 1');
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $cid = strtolower(trim((string) $row['cosmetic_id']));
                $url = trim((string) $row['video_url']);
                if ($cid === '' || $url === '') {
                    continue;
                }
                if (!isset($map[$cid])) {
                    $map[$cid] = ['url' => null, 'styles' => []];
                }
                $styleKey = strtolower(trim((string) ($row['style_key'] ?? '')));
                if ($styleKey === '') {
                    $map[$cid]['url'] = $url;
                } else {
                    $map[$cid]['styles'][$styleKey] = $url;
                }
            }
        }
        return $map;
    }

    /** Active video URL for one cosmetic id (main video only), or null. */
    public static function findActiveVideo(?mysqli $conn, string $cosmeticId): ?string
    {
        $videos = self::findActiveVideos($conn, $cosmeticId);
        return $videos !== null ? $videos['url'] : null;
    }

    /**
     * All active videos of one cosmetic (main + styles), or null when none.
     * @return array{url:?string,styles:array<string,string>}|null
     */
    public static function findActiveVideos(?mysqli $conn, string $cosmeticId): ?array
    {
        if (!($conn instanceof mysqli) || $cosmeticId === '') {
            return null;
        }
        self::ensureTable($conn);
        $stmt = $conn->prepare('SELECT style_key, video_url FROM `' . self::TABLE . '` WHERE activo = 1 AND cosmetic_id = ?');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $cosmeticId);
        $stmt->execute();
        $result = $stmt->get_result();
        $videos = ['url' => null, 'styles' => []];
        $found = false;
        while ($result && ($row = $result->fetch_assoc())) {
            $url = trim((string) ($row['video_url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $found = true;
            $styleKey = strtolower(trim((string) ($row['style_key'] ?? '')));
            if ($styleKey === '') {
                $videos['url'] = $url;
            } else {
                $videos['styles'][$styleKey] = $url;
            }
        }
        $stmt->close();
        return $found ? $videos : null;
    }

    /**
     * Active videos for a set of cosmetic ids (main + styles per id), in ONE
     * IN() query. Ids are matched case-insensitively.
     *
     * @param string[] $ids
     * @return array<string,array{url:?string,styles:array<string,string>}>
     *         cosmetic_id (lowercased) => ['url' => ..., 'styles' => [...]]
     */
    public static function findActiveVideosForIds(?mysqli $conn, array $ids): array
    {
        if (!($conn instanceof mysqli)) {
            return [];
        }
        $normalized = [];
        foreach ($ids as $id) {
            $id = strtolower(trim((string) $id));
            if ($id !== '') {
                $normalized[$id] = true;
            }
        }
        $normalized = array_keys($normalized);
        if (empty($normalized)) {
            return [];
        }
        self::ensureTable($conn);
        $placeholders = implode(',', array_fill(0, count($normalized), '?'));
        $stmt = $conn->prepare(
            'SELECT cosmetic_id, style_key, video_url FROM `' . self::TABLE . '`
             WHERE activo = 1 AND LOWER(cosmetic_id) IN (' . $placeholders . ')'
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param(str_repeat('s', count($normalized)), ...$normalized);
        $stmt->execute();
        $result = $stmt->get_result();
        $map = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $cid = strtolower(trim((string) $row['cosmetic_id']));
            $url = trim((string) $row['video_url']);
            if ($cid === '' || $url === '') {
                continue;
            }
            if (!isset($map[$cid])) {
                $map[$cid] = ['url' => null, 'styles' => []];
            }
            $styleKey = strtolower(trim((string) ($row['style_key'] ?? '')));
            if ($styleKey === '') {
                $map[$cid]['url'] = $url;
            } else {
                $map[$cid]['styles'][$styleKey] = $url;
            }
        }
        $stmt->close();
        return $map;
    }

    /**
     * Re-attach the manual-video map onto the existing shop snapshots (live +
     * fallback) so admin changes are visible immediately — including in the
     * instant client-side render — without re-running the full ingestor
     * (no external API call). Atomic write; a broken snapshot is never left.
     */
    public static function resyncSnapshots(?mysqli $conn): void
    {
        require_once __DIR__ . '/ShopNormalizer.php';
        $map = self::activeVideoMap($conn);
        $paths = [ShopSourceConfig::liveSnapshotPath(), ShopSourceConfig::fallbackSnapshotPath()];
        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $snapshot = json_decode((string) @file_get_contents($path), true);
            if (!is_array($snapshot) || !isset($snapshot['entries']) || !is_array($snapshot['entries'])) {
                continue;
            }
            foreach ($snapshot['entries'] as $i => $entry) {
                if (is_array($entry)) {
                    $snapshot['entries'][$i] = ShopNormalizer::attachManualVideo($entry, $map);
                }
            }
            $tmp = $path . '.tmp.' . getmypid();
            if (@file_put_contents($tmp, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) {
                @rename($tmp, $path);
            } else {
                @unlink($tmp);
            }
        }
    }

    /** All rows (for the admin listing). */
    public static function all(mysqli $conn): array
    {
        self::ensureTable($conn);
        $rows = [];
        $res = $conn->query('SELECT * FROM `' . self::TABLE . '` ORDER BY actualizado_en DESC');
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Insert or replace the video for a cosmetic id.
     * @return array{ok:bool,message:string}
     */
    public static function save(mysqli $conn, string $cosmeticId, ?string $nombre, string $videoUrl, string $origen, string $styleKey = '', ?string $styleName = null): array
    {
        self::ensureTable($conn);
        if ($cosmeticId === '' || $videoUrl === '') {
            return ['ok' => false, 'message' => 'Datos incompletos.'];
        }
        $origen = $origen === 'archivo' ? 'archivo' : 'url';
        $styleKey = strtolower(trim($styleKey));
        $stmt = $conn->prepare(
            'INSERT INTO `' . self::TABLE . '` (cosmetic_id, style_key, style_name, nombre_objeto, video_url, origen, activo)
             VALUES (?, ?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE style_name = VALUES(style_name),
                                     nombre_objeto = VALUES(nombre_objeto),
                                     video_url = VALUES(video_url),
                                     origen = VALUES(origen),
                                     activo = 1'
        );
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Error de base de datos.'];
        }
        $stmt->bind_param('ssssss', $cosmeticId, $styleKey, $styleName, $nombre, $videoUrl, $origen);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok
            ? ['ok' => true, 'message' => 'Video guardado.']
            : ['ok' => false, 'message' => 'No se pudo guardar el video.'];
    }

    public static function setActive(mysqli $conn, int $id, bool $active): bool
    {
        self::ensureTable($conn);
        $stmt = $conn->prepare('UPDATE `' . self::TABLE . '` SET activo = ? WHERE id = ?');
        if (!$stmt) {
            return false;
        }
        $flag = $active ? 1 : 0;
        $stmt->bind_param('ii', $flag, $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /** Delete a row (and its re-hosted file when applicable). */
    public static function delete(mysqli $conn, int $id): bool
    {
        self::ensureTable($conn);
        $stmt = $conn->prepare('SELECT video_url, origen FROM `' . self::TABLE . '` WHERE id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (is_array($row) && ($row['origen'] ?? '') === 'archivo') {
                $url = (string) ($row['video_url'] ?? '');
                $prefix = self::mediaUrlPrefix();
                if (strpos($url, $prefix) === 0) {
                    $file = basename($url);
                    // basename() confines deletion to the media dir (no traversal).
                    $path = self::mediaDirPath() . '/' . $file;
                    if (is_file($path)) {
                        @unlink($path);
                    }
                }
            }
        }
        $stmt = $conn->prepare('DELETE FROM `' . self::TABLE . '` WHERE id = ?');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /**
     * Filesystem-safe base name for a cosmetic (+ optional style) video file.
     */
    private static function fileBase(string $cosmeticId, string $styleKey = ''): string
    {
        $base = preg_replace('/[^a-zA-Z0-9_.-]/', '_', strtolower($cosmeticId));
        if ($styleKey !== '') {
            $base .= '__' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', strtolower($styleKey));
        }
        return $base;
    }

    /**
     * Re-host a remote video URL into the local media dir so the stored URL
     * never expires (external links are often signed S3 URLs valid ~1 day).
     *
     * @return array{ok:bool,message:string,url:?string}
     */
    public static function rehostFromUrl(string $cosmeticId, string $remoteUrl, string $styleKey = ''): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message' => 'cURL no disponible en el servidor.', 'url' => null];
        }
        $parsed = parse_url($remoteUrl);
        if (!is_array($parsed) || (($parsed['scheme'] ?? '') !== 'https' && ($parsed['scheme'] ?? '') !== 'http')) {
            return ['ok' => false, 'message' => 'URL de video inválida.', 'url' => null];
        }

        $dir = self::mediaDirPath();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return ['ok' => false, 'message' => 'El directorio de videos no es escribible.', 'url' => null];
        }

        $safeId = self::fileBase($cosmeticId, $styleKey);
        $tmpPath = $dir . '/.download.' . $safeId . '.' . getmypid() . '.tmp';

        $fh = @fopen($tmpPath, 'wb');
        if ($fh === false) {
            return ['ok' => false, 'message' => 'No se pudo crear el archivo temporal.', 'url' => null];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $remoteUrl,
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_USERAGENT      => 'BananoStore/VideoRehost/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $ok = curl_exec($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
        $err         = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);
        fclose($fh);

        if ($ok === false || $err !== null || $httpCode !== 200 || filesize($tmpPath) < 1024) {
            @unlink($tmpPath);
            return ['ok' => false, 'message' => 'No se pudo descargar el video (' . ($err ?: 'HTTP ' . $httpCode) . ').', 'url' => null];
        }

        $ext = self::extensionFor($contentType, $remoteUrl);
        if ($ext === null) {
            @unlink($tmpPath);
            return ['ok' => false, 'message' => 'Formato de video no soportado (' . ($contentType ?: 'desconocido') . ').', 'url' => null];
        }

        $finalName = $safeId . '.' . $ext;
        $finalPath = $dir . '/' . $finalName;
        if (!@rename($tmpPath, $finalPath)) {
            @unlink($tmpPath);
            return ['ok' => false, 'message' => 'No se pudo promover el archivo descargado.', 'url' => null];
        }
        @chmod($finalPath, 0644);
        self::removeSiblingExtension($dir, $safeId, $ext);

        return ['ok' => true, 'message' => 'Video re-hospedado.', 'url' => self::mediaUrlPrefix() . $finalName];
    }

    /**
     * Persist an uploaded video file (from the admin form) into the media dir.
     * @param array $file One entry of $_FILES.
     * @return array{ok:bool,message:string,url:?string}
     */
    public static function storeUploadedFile(string $cosmeticId, array $file, string $styleKey = ''): array
    {
        if (!isset($file['tmp_name'], $file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Error al subir el archivo.', 'url' => null];
        }
        $ext = self::extensionFor('', (string) ($file['name'] ?? ''));
        if ($ext === null) {
            return ['ok' => false, 'message' => 'Formato no soportado (usa .webm o .mp4).', 'url' => null];
        }
        $dir = self::mediaDirPath();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $safeId = self::fileBase($cosmeticId, $styleKey);
        $finalName = $safeId . '.' . $ext;
        $finalPath = $dir . '/' . $finalName;
        if (!move_uploaded_file($file['tmp_name'], $finalPath)) {
            return ['ok' => false, 'message' => 'No se pudo guardar el archivo subido.', 'url' => null];
        }
        @chmod($finalPath, 0644);
        self::removeSiblingExtension($dir, $safeId, $ext);
        return ['ok' => true, 'message' => 'Archivo guardado.', 'url' => self::mediaUrlPrefix() . $finalName];
    }

    /**
     * When a video is replaced with a different container (.webm ↔ .mp4),
     * drop the now-orphaned file of the other extension for the same slot.
     */
    private static function removeSiblingExtension(string $dir, string $safeId, string $keptExt): void
    {
        foreach (['webm', 'mp4'] as $ext) {
            if ($ext === $keptExt) {
                continue;
            }
            $path = $dir . '/' . $safeId . '.' . $ext;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private static function extensionFor(string $contentType, string $urlOrName): ?string
    {
        if (strpos($contentType, 'webm') !== false) {
            return 'webm';
        }
        if (strpos($contentType, 'mp4') !== false) {
            return 'mp4';
        }
        $path = (string) parse_url($urlOrName, PHP_URL_PATH);
        $ext = strtolower(pathinfo($path !== '' ? $path : $urlOrName, PATHINFO_EXTENSION));
        if (in_array($ext, ['webm', 'mp4'], true)) {
            return $ext;
        }
        // Signed URLs often hide the name inside response-content-disposition.
        if (stripos($urlOrName, '.webm') !== false) {
            return 'webm';
        }
        if (stripos($urlOrName, '.mp4') !== false) {
            return 'mp4';
        }
        return null;
    }
}

}

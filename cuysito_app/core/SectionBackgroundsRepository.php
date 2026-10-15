<?php
/**
 * ============================================================================
 * BANANO STORE — SECTION BACKGROUNDS REPOSITORY (fondos de secciones)
 * ============================================================================
 * Persistence for manually-managed section background images (the billboard
 * artwork Epic shows behind each item-shop section on fortnite.com, which the
 * public API does NOT expose). Administered ONLY from the dedicated
 * "Fondos de Secciones" admin section.
 *
 * Storage: `secciones_fondos` table keyed by the normalized section key
 * (layout.id). Reads on the hot paths (shop payload, item pages) come from a
 * compiled JSON cache (cache/section_backgrounds.json) so they never require
 * a DB connection; the cache is recompiled on every admin write.
 *
 * Matching: a background applies to a shop section when its `section_key`
 * equals the normalized layout.id OR its normalized `section_name` equals the
 * normalized layout.name. Matching by name keeps a background attached to
 * recurring sections whose layout.id rotates daily.
 *
 * Lifecycle: by default (`persistente` = 0) a background is deleted
 * automatically as soon as its section is no longer part of the current shop
 * snapshot. Marking it as persistent (`persistente` = 1) keeps it forever so
 * it re-activates when the section returns. The frontend-built sections
 * ("Ofertas random del día", "Lo más vendido") are managed here too via fixed
 * virtual keys and are never auto-pruned.
 * ============================================================================
 */

require_once __DIR__ . '/../config/shop_config.php';

if (!class_exists('SectionBackgroundsRepository')) {

final class SectionBackgroundsRepository
{
    const TABLE = 'secciones_fondos';

    const FILE_CACHE = 'section_backgrounds.json';

    /**
     * Frontend-built sections that never come from the API entries but can
     * still have a manual background. key => visible name.
     */
    const VIRTUAL_SECTIONS = [
        'section-random-offer'         => 'Ofertas random del día',
        'section-bestsellers-carousel' => 'Lo más vendido',
    ];

    /**
     * Reserved key for the global default background: applied to every
     * section that has no background of its own. Never auto-pruned and
     * never matched by section name.
     */
    const DEFAULT_KEY = 'section-default-background';

    public static function cachePath(): string
    {
        return ShopSourceConfig::cacheDir() . '/' . self::FILE_CACHE;
    }

    /**
     * Normalized section key. Mirrors the worker's safeId derivation
     * (`layout.id.replace(/\W+/g,'-').toLowerCase()`) so both sides agree.
     */
    public static function normalizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        return trim((string) $value, '-');
    }

    public static function ensureTable(mysqli $conn): void
    {
        $conn->query(
            'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `section_key` VARCHAR(160) NOT NULL,
                `section_name` VARCHAR(190) NOT NULL DEFAULT "",
                `image_url` TEXT NOT NULL,
                `activo` TINYINT(1) NOT NULL DEFAULT 1,
                `persistente` TINYINT(1) NOT NULL DEFAULT 0,
                `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_section_key` (`section_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $res = $conn->query('SHOW COLUMNS FROM `' . self::TABLE . '` LIKE "persistente"');
        if ($res && $res->num_rows === 0) {
            $conn->query('ALTER TABLE `' . self::TABLE . '` ADD COLUMN `persistente` TINYINT(1) NOT NULL DEFAULT 0 AFTER `activo`');
        }
    }

    /** @return array<int,array> all rows, newest first */
    public static function all(mysqli $conn): array
    {
        self::ensureTable($conn);
        $rows = [];
        $res = $conn->query('SELECT * FROM `' . self::TABLE . '` ORDER BY actualizado_en DESC, id DESC');
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** Upsert by section_key. @return array{ok:bool,message:string} */
    public static function save(mysqli $conn, string $sectionKey, string $sectionName, string $imageUrl): array
    {
        self::ensureTable($conn);
        $stmt = $conn->prepare(
            'INSERT INTO `' . self::TABLE . '` (section_key, section_name, image_url, activo)
             VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE section_name = VALUES(section_name),
                                     image_url = VALUES(image_url),
                                     activo = 1'
        );
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Error interno preparando la consulta.'];
        }
        $stmt->bind_param('sss', $sectionKey, $sectionName, $imageUrl);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok
            ? ['ok' => true, 'message' => 'Fondo guardado.']
            : ['ok' => false, 'message' => 'No se pudo guardar el fondo.'];
    }

    public static function setActive(mysqli $conn, int $id, bool $active): bool
    {
        self::ensureTable($conn);
        $stmt = $conn->prepare('UPDATE `' . self::TABLE . '` SET activo = ? WHERE id = ?');
        if (!$stmt) {
            return false;
        }
        $activeInt = $active ? 1 : 0;
        $stmt->bind_param('ii', $activeInt, $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public static function setPersistent(mysqli $conn, int $id, bool $persistent): bool
    {
        self::ensureTable($conn);
        $stmt = $conn->prepare('UPDATE `' . self::TABLE . '` SET persistente = ? WHERE id = ?');
        if (!$stmt) {
            return false;
        }
        $persistentInt = $persistent ? 1 : 0;
        $stmt->bind_param('ii', $persistentInt, $id);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /**
     * Deletes non-persistent backgrounds whose section left the current shop.
     * Virtual sections are always kept. No-op when the snapshot has no
     * sections (avoids wiping everything on a missing/failed snapshot).
     *
     * @param array<int,string> $activeKeys      normalized layout.id keys in today's shop
     * @param array<int,string> $activeNameKeys  normalized layout.name keys in today's shop
     * @return int number of rows deleted
     */
    public static function pruneEphemeral(mysqli $conn, array $activeKeys, array $activeNameKeys): int
    {
        if (!$activeKeys && !$activeNameKeys) {
            return 0;
        }
        self::ensureTable($conn);
        $keys     = array_flip($activeKeys);
        $nameKeys = array_flip(array_filter($activeNameKeys, static fn($k) => $k !== ''));
        $toDelete = [];
        $res = $conn->query('SELECT id, section_key, section_name FROM `' . self::TABLE . '` WHERE persistente = 0');
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $key     = self::normalizeKey((string) $row['section_key']);
                $nameKey = self::normalizeKey((string) $row['section_name']);
                if ($key === self::DEFAULT_KEY || isset(self::VIRTUAL_SECTIONS[$key]) || isset($keys[$key])) {
                    continue;
                }
                if ($nameKey !== '' && isset($nameKeys[$nameKey])) {
                    continue;
                }
                $toDelete[] = (int) $row['id'];
            }
        }
        if (!$toDelete) {
            return 0;
        }
        $conn->query('DELETE FROM `' . self::TABLE . '` WHERE id IN (' . implode(',', $toDelete) . ')');
        return count($toDelete);
    }

    public static function delete(mysqli $conn, int $id): bool
    {
        self::ensureTable($conn);
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
     * Recompile cache/section_backgrounds.json from the active DB rows.
     * Atomic write (tmp + rename). Call after every admin write.
     */
    public static function recompileCache(mysqli $conn): bool
    {
        self::ensureTable($conn);
        $items = [];
        $res = $conn->query('SELECT section_key, section_name, image_url FROM `' . self::TABLE . '` WHERE activo = 1');
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $key  = self::normalizeKey((string) $row['section_key']);
                $name = trim((string) $row['section_name']);
                $url  = trim((string) $row['image_url']);
                if ($key === '' || $url === '') {
                    continue;
                }
                $items[] = ['key' => $key, 'name' => $name, 'image' => $url];
            }
        }
        $payload = [
            'schema_version' => 1,
            'generated_at'   => gmdate('Y-m-d\TH:i:s\Z'),
            'items'          => $items,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        $path = self::cachePath();
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0755, true);
        }
        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        @chmod($path, 0644);
        return true;
    }

    /**
     * Active backgrounds from the compiled cache (no DB required).
     * @return array<int,array{key:string,name:string,image:string}>
     */
    public static function activeFromCache(): array
    {
        static $memo = null;
        if ($memo !== null) {
            return $memo;
        }
        $memo = [];
        $path = self::cachePath();
        if (!is_file($path)) {
            return $memo;
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (is_array($decoded) && isset($decoded['items']) && is_array($decoded['items'])) {
            foreach ($decoded['items'] as $item) {
                if (is_array($item) && !empty($item['key']) && !empty($item['image'])) {
                    $memo[] = [
                        'key'   => (string) $item['key'],
                        'name'  => (string) ($item['name'] ?? ''),
                        'image' => (string) $item['image'],
                    ];
                }
            }
        }
        return $memo;
    }

    /**
     * Background URL for a shop entry layout (['id' => ..., 'name' => ...]),
     * matching by normalized layout.id first, then by normalized name.
     */
    public static function findForLayout(?array $layout): ?string
    {
        if (!is_array($layout)) {
            return null;
        }
        $layoutKey  = self::normalizeKey((string) ($layout['id'] ?? ''));
        $layoutName = self::normalizeKey((string) ($layout['name'] ?? ''));
        $byName = null;
        foreach (self::activeFromCache() as $bg) {
            if ($bg['key'] === self::DEFAULT_KEY) {
                continue;
            }
            if ($layoutKey !== '' && $bg['key'] === $layoutKey) {
                return $bg['image'];
            }
            if ($byName === null && $layoutName !== '' && self::normalizeKey($bg['name']) === $layoutName) {
                $byName = $bg['image'];
            }
        }
        return $byName;
    }

    /**
     * URL of the active global default background (applied to sections
     * without a background of their own), or null when not configured.
     */
    public static function defaultBackground(): ?string
    {
        foreach (self::activeFromCache() as $bg) {
            if ($bg['key'] === self::DEFAULT_KEY && $bg['image'] !== '') {
                return $bg['image'];
            }
        }
        return null;
    }
}

}

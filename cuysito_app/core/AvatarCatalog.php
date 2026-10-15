<?php
/**
 * ============================================================================
 * BANANO STORE — AVATAR CATALOG (server side)
 * ============================================================================
 * Único punto de verdad para avatares de usuario:
 *
 *   - `avatars(id, filename, name, is_active, creator, created_at)` guarda SOLO
 *     el nombre de archivo; `users.avatar_id` apunta a esa tabla. Ninguna ruta se
 *     persiste en BD. `creator` agrupa avatares por creador/pack ("General" por
 *     defecto); `ensureSchema()` añade ambas columnas si la tabla es antigua.
 *   - Las subidas nuevas van a la raíz web pública (`<docroot>/assets/avatars`,
 *     servida directamente por Apache). La raíz web se toma de
 *     `$_SERVER['DOCUMENT_ROOT']` o se deduce buscando la carpeta hermana de la
 *     app que contiene `web.php`; nunca de un nombre de carpeta fijo, así que
 *     renombrar `cuysito_app` → `banano_app` no rompe nada.
 *   - Los archivos antiguos en `<app>/assets/avatars` siguen resolviéndose
 *     (el router busca en ambas carpetas para `/assets/avatars/<file>`).
 *   - Un avatar es "válido" sólo si su fila está activa Y el archivo existe en
 *     disco. Todo lo demás cae a un avatar aleatorio del catálogo válido y,
 *     cuando se conoce el usuario, la elección se persiste en `users.avatar_id`
 *     para que sea estable entre peticiones.
 * ============================================================================
 */

if (!class_exists('AvatarCatalog')) {

final class AvatarCatalog
{
    public const URL_BASE = '/assets/avatars/';
    public const DEFAULT_CREATOR = 'General';
    public const CREATOR_MAX_LEN = 80;

    private static bool $schemaChecked = false;

    /** @var array<string,bool> cache por petición: filename => existe en disco */
    private static array $existsCache = [];
    /** @var array<int,array{id:int,filename:string,name:string,creator:string}>|null catálogo válido por petición */
    private static ?array $catalogCache = null;

    private function __construct() {}

    // ----------------------------------------------------------------- schema

    /** Añade `creator` y `created_at` a `avatars` si faltan (idempotente, una vez por petición). */
    public static function ensureSchema(mysqli $conn): void
    {
        if (self::$schemaChecked) return;
        self::$schemaChecked = true;
        if (!self::hasColumn($conn, 'avatars', 'creator')) {
            try {
                $conn->query("ALTER TABLE avatars ADD COLUMN creator VARCHAR(" . self::CREATOR_MAX_LEN . ") NOT NULL DEFAULT '" . self::DEFAULT_CREATOR . "' AFTER name");
                $conn->query("ALTER TABLE avatars ADD INDEX idx_avatars_creator (creator)");
            } catch (Throwable $e) {}
        }
        if (!self::hasColumn($conn, 'avatars', 'created_at')) {
            try {
                $conn->query("ALTER TABLE avatars ADD COLUMN created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP");
            } catch (Throwable $e) {}
        }
    }

    private static function hasColumn(mysqli $conn, string $table, string $column): bool
    {
        try {
            $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '" . $conn->real_escape_string($column) . "'");
        } catch (Throwable $e) {
            $res = false;
        }
        if (!$res) return true; // sin permisos para inspeccionar: no intentar ALTER
        $has = $res->num_rows > 0;
        $res->free();
        return $has;
    }

    /** Normaliza un nombre de creador/pack: recorta, colapsa espacios y cae a DEFAULT_CREATOR si queda vacío. */
    public static function normalizeCreator($raw): string
    {
        $name = is_string($raw) ? trim(preg_replace('/\s+/u', ' ', $raw)) : '';
        if ($name === '') return self::DEFAULT_CREATOR;
        if (mb_strlen($name) > self::CREATOR_MAX_LEN) $name = mb_substr($name, 0, self::CREATOR_MAX_LEN);
        return $name;
    }

    // ------------------------------------------------------------------ rutas

    /** Raíz de la app (carpeta que contiene core/, api/, wabo/…), sin asumir su nombre. */
    public static function appRoot(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Raíz web pública (donde viven index.php/web.php/router.php).
     * 1) `$_SERVER['DOCUMENT_ROOT']` si apunta a una carpeta real (Apache/LiteSpeed).
     * 2) Si no (CLI, cron), la carpeta hermana de la app que contenga `web.php`.
     * 3) Último recurso: `<padre de la app>/public_html`.
     */
    public static function publicRoot(): string
    {
        $docroot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/\\') : '';
        if ($docroot !== '' && is_dir($docroot)) {
            $real = realpath($docroot);
            return $real !== false ? $real : $docroot;
        }
        $parent = dirname(self::appRoot());
        foreach (glob($parent . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if ($dir !== self::appRoot() && is_file($dir . '/web.php')) return $dir;
        }
        return $parent . '/public_html';
    }

    /** Carpeta pública donde el panel admin guarda los archivos subidos. */
    public static function uploadDir(): string
    {
        return self::publicRoot() . '/assets/avatars';
    }

    /** Carpeta histórica dentro de la app; sólo se lee, ya no se escribe. */
    public static function legacyDir(): string
    {
        return self::appRoot() . '/assets/avatars';
    }

    /**
     * Carpetas físicas donde puede vivir un avatar: primero la pública (subidas
     * nuevas), luego la histórica dentro de la app.
     * @return string[]
     */
    public static function storageDirs(): array
    {
        $dirs = [self::uploadDir()];
        $legacy = self::legacyDir();
        if (!in_array($legacy, $dirs, true)) $dirs[] = $legacy;
        return $dirs;
    }

    /** Borra el archivo en todas las carpetas conocidas. Devuelve cuántos borró. */
    public static function unlinkAll(?string $filename): int
    {
        $name = self::normalizeFilename($filename);
        if ($name === null) return 0;
        $n = 0;
        foreach (self::storageDirs() as $dir) {
            $path = $dir . '/' . $name;
            if (is_file($path) && @unlink($path)) $n++;
        }
        unset(self::$existsCache[$name]);
        return $n;
    }

    // -------------------------------------------------------------- filenames

    /**
     * Normaliza lo guardado en BD a un nombre de archivo plano. Tolera valores
     * antiguos con ruta (`/cuysito_app/assets/avatars/x.png` → `x.png`).
     */
    public static function normalizeFilename($stored): ?string
    {
        if (!is_string($stored)) return null;
        $name = basename(str_replace('\\', '/', trim($stored)));
        if ($name === '' || $name === '.' || $name === '..') return null;
        return $name;
    }

    /** Ruta absoluta del archivo si existe en alguna carpeta conocida; null si no. */
    public static function filePath(?string $filename): ?string
    {
        $name = self::normalizeFilename($filename);
        if ($name === null) return null;
        foreach (self::storageDirs() as $dir) {
            $path = $dir . '/' . $name;
            if (is_file($path)) return $path;
        }
        return null;
    }

    public static function fileExists(?string $filename): bool
    {
        $name = self::normalizeFilename($filename);
        if ($name === null) return false;
        if (!array_key_exists($name, self::$existsCache)) {
            self::$existsCache[$name] = self::filePath($name) !== null;
        }
        return self::$existsCache[$name];
    }

    /** URL pública de un archivo (sin comprobar existencia); null si no hay nombre. */
    public static function url(?string $filename): ?string
    {
        $name = self::normalizeFilename($filename);
        return $name === null ? null : self::URL_BASE . rawurlencode($name);
    }

    // --------------------------------------------------------------- catálogo

    /**
     * Avatares activos cuyo archivo existe en disco (cacheado por petición).
     * @return array<int,array{id:int,filename:string,name:string,creator:string}> indexado por id
     */
    public static function validCatalog(mysqli $conn): array
    {
        if (self::$catalogCache !== null) return self::$catalogCache;
        $catalog = [];
        try {
            $res = $conn->query("SELECT id, filename, name, creator FROM avatars WHERE is_active = 1 ORDER BY id DESC");
        } catch (Throwable $e) {
            $res = false;
        }
        if (!$res) {
            // Tabla antigua sin columna `creator` (el panel admin la crea al abrirse).
            $res = $conn->query("SELECT id, filename, name, '" . self::DEFAULT_CREATOR . "' AS creator FROM avatars WHERE is_active = 1 ORDER BY id DESC");
        }
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $name = self::normalizeFilename($row['filename']);
                if ($name === null || !self::fileExists($name)) continue;
                $catalog[(int)$row['id']] = [
                    'id'       => (int)$row['id'],
                    'filename' => $name,
                    'name'     => (string)($row['name'] ?? ''),
                    'creator'  => self::normalizeCreator($row['creator'] ?? null),
                ];
            }
            $res->free();
        }
        return self::$catalogCache = $catalog;
    }

    /** Lista pública para selectores (id, filename, name, url), sólo válidos. */
    public static function publicList(mysqli $conn): array
    {
        $list = [];
        foreach (self::validCatalog($conn) as $a) {
            $list[] = $a + ['url' => self::url($a['filename'])];
        }
        return $list;
    }

    /** Un avatar válido al azar, o null si el catálogo está vacío. */
    public static function random(mysqli $conn): ?array
    {
        $catalog = self::validCatalog($conn);
        if (!$catalog) return null;
        return $catalog[array_rand($catalog)];
    }

    /** Entrada del catálogo válido para un id, o null. */
    public static function find(mysqli $conn, $avatarId): ?array
    {
        $id = is_numeric($avatarId) ? (int)$avatarId : 0;
        if ($id <= 0) return null;
        return self::validCatalog($conn)[$id] ?? null;
    }

    // ---------------------------------------------------------------- fallback

    /**
     * Nombre de archivo válido para mostrar: el recibido si existe en disco, si
     * no uno aleatorio del catálogo válido; null sólo si no hay ningún avatar.
     */
    public static function filename(mysqli $conn, $stored): ?string
    {
        $name = self::normalizeFilename($stored);
        if ($name !== null && self::fileExists($name)) return $name;
        $pick = self::random($conn);
        return $pick ? $pick['filename'] : null;
    }

    /** Igual que filename() pero devuelve la URL pública (o null). */
    public static function urlFor(mysqli $conn, $stored): ?string
    {
        return self::url(self::filename($conn, $stored));
    }

    /**
     * Avatar efectivo de un usuario. Si `avatar_id` no apunta a un avatar válido
     * se elige uno al azar y, con $persist, se guarda en `users.avatar_id`.
     * @return array{id:int,filename:string,name:string,url:string,repaired:bool}|null
     */
    public static function resolveUser(mysqli $conn, int $userId, $avatarId, bool $persist = true): ?array
    {
        $current = self::find($conn, $avatarId);
        if ($current !== null) {
            return $current + ['url' => self::url($current['filename']), 'repaired' => false];
        }
        $pick = self::random($conn);
        if ($pick === null) return null;
        if ($persist && $userId > 0) {
            $stmt = $conn->prepare("UPDATE users SET avatar_id = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $pick['id'], $userId);
                $stmt->execute();
                $stmt->close();
            }
        }
        return $pick + ['url' => self::url($pick['filename']), 'repaired' => true];
    }

    /**
     * Reasigna un avatar válido aleatorio a todos los usuarios cuyo `avatar_id`
     * ya no apunta al catálogo válido (avatar borrado, desactivado o sin archivo).
     * Devuelve filas afectadas; 0 si el catálogo está vacío.
     */
    public static function reassignOrphans(mysqli $conn): int
    {
        $ids = array_keys(self::validCatalog($conn));
        if (!$ids) return 0;
        $list  = implode(',', $ids);
        $count = count($ids);
        $sql   = "UPDATE users SET avatar_id = ELT(FLOOR(1 + RAND() * {$count}), {$list})
                  WHERE avatar_id IS NULL OR avatar_id NOT IN ({$list})";
        if (!$conn->query($sql)) return 0;
        return max(0, (int)$conn->affected_rows);
    }

    /**
     * Usuarios por avatar: [avatar_id => n]. Sólo cuenta ids numéricos; los
     * valores legacy (p.ej. 'default_1') quedan fuera y cuentan como huérfanos.
     * @return array<int,int>
     */
    public static function usageCounts(mysqli $conn): array
    {
        $counts = [];
        $res = $conn->query("SELECT avatar_id, COUNT(*) AS n FROM users GROUP BY avatar_id");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                if (is_numeric($row['avatar_id'])) $counts[(int)$row['avatar_id']] = (int)$row['n'];
            }
            $res->free();
        }
        return $counts;
    }

    /** Usuarios cuyo avatar_id no apunta al catálogo válido (se repararían con reassignOrphans). */
    public static function orphanUsersCount(mysqli $conn): int
    {
        $valid = self::validCatalog($conn);
        $n = 0;
        foreach (self::usageCounts($conn) as $id => $c) {
            if (!isset($valid[$id])) $n += $c;
        }
        $res = $conn->query("SELECT COUNT(*) AS n FROM users WHERE avatar_id IS NULL OR avatar_id NOT REGEXP '^[0-9]+$'");
        if ($res) {
            $row = $res->fetch_assoc();
            $n += (int)($row['n'] ?? 0);
            $res->free();
        }
        return $n;
    }

    /** Invalida las cachés por petición (tras subir/borrar/activar avatares). */
    public static function refresh(): void
    {
        self::$existsCache  = [];
        self::$catalogCache = null;
    }
}

}

<?php
/**
 * ============================================================
 * Tareas — Setup & migraciones de base de datos
 * ============================================================
 * Crea (idempotente) todas las tablas y columnas que necesita el
 * módulo, incluidas las incorporaciones de productividad: acento
 * y vencimiento de misiones, recurrencia, etiquetas, plantillas,
 * comentarios y adjuntos.
 *
 * Es seguro ejecutarlo cuantas veces se quiera: sólo crea lo que
 * falta (CREATE TABLE IF NOT EXISTS + ALTER condicionado por
 * information_schema, compatible con MySQL 5.7/8 y MariaDB).
 *
 * Ejecutar una sola vez desde el navegador:
 *     https://tu-dominio.com/tareas/setup_database.php
 *
 * Por seguridad, elimina este archivo después de ejecutarlo.
 * ============================================================
 */

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/api/db.php';

$prefix = (require __DIR__ . '/config/config.php')['table_prefix'];

echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">';
echo '<title>Tareas — Setup de base de datos</title>';
echo '<style>body{font-family:system-ui,sans-serif;max-width:760px;margin:2rem auto;padding:0 1rem;line-height:1.5;color:#1a1a24}'
   . 'h1{font-size:1.4rem}code{background:#f0f0f5;padding:.1rem .35rem;border-radius:.25rem}'
   . '.ok{color:#15803d;font-weight:600}.err{color:#b91c1c;font-weight:600}.muted{color:#666}</style></head><body>';
echo '<h1>Tareas — Setup de base de datos</h1>';

/** Imprime una línea de estado. */
function step(string $msg, string $class = 'ok'): void
{
    echo '<p class="' . $class . '">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>';
    if (function_exists('ob_flush')) { @ob_flush(); }
    @flush();
}

/** ¿Existe la columna `$column` en la tabla `$table`? (esquema actual). */
function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

/** ¿Existe el índice `$index` en la tabla `$table`? */
function index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $index]);
    return (bool) $stmt->fetchColumn();
}

/** Agrega una columna sólo si todavía no existe (idempotente). */
function add_column(PDO $pdo, string $table, string $column, string $definition): bool
{
    if (column_exists($pdo, $table, $column)) {
        return false;
    }
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    return true;
}

/** Agrega un índice sólo si todavía no existe (idempotente). */
function add_index(PDO $pdo, string $table, string $index, string $definition): bool
{
    if (index_exists($pdo, $table, $index)) {
        return false;
    }
    $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$index}` {$definition}");
    return true;
}

try {
    $pdo = TareasDB::connect();

    // ── Colaboradores ────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$prefix}colaboradores` (
            `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`        VARCHAR(60)     NOT NULL COMMENT 'Nombre visible del colaborador',
            `color`       VARCHAR(7)      NOT NULL DEFAULT '#6c63ff' COMMENT 'Color hex del avatar',
            `is_archived` TINYINT(1)      NOT NULL DEFAULT 0,
            `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_col_archived` (`is_archived`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    step('✓ Tabla `' . $prefix . 'colaboradores` lista.');

    // ── Ideas (captura rápida) ───────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$prefix}ideas` (
            `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `content`             TEXT            NOT NULL COMMENT 'Texto libre de la idea',
            `accent`              VARCHAR(20)     NOT NULL DEFAULT 'violet' COMMENT 'Tema de color de la tarjeta',
            `is_pinned`           TINYINT(1)      NOT NULL DEFAULT 0,
            `status`              ENUM('active','converted','archived') NOT NULL DEFAULT 'active',
            `converted_mision_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Misión generada al convertir',
            `sort_order`          INT             NOT NULL DEFAULT 0,
            `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_ideas_status` (`status`),
            INDEX `idx_ideas_pinned` (`is_pinned`),
            INDEX `idx_ideas_sort`   (`sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    add_index($pdo, "{$prefix}ideas", 'idx_ideas_sort', '(`sort_order`)');
    step('✓ Tabla `' . $prefix . 'ideas` lista.');

    // ── Misiones (tareas) ────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$prefix}misiones` (
            `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `title`          VARCHAR(160)    NOT NULL,
            `description`    TEXT            DEFAULT NULL,
            `status`         ENUM('pendiente','en_progreso','completada','archivada') NOT NULL DEFAULT 'pendiente',
            `priority`       ENUM('baja','media','alta') NOT NULL DEFAULT 'media',
            `accent`         VARCHAR(20)     NOT NULL DEFAULT 'violet' COMMENT 'Tema de color (heredable de la idea de origen)',
            `due_date`       DATE            DEFAULT NULL COMMENT 'Fecha de vencimiento (opcional)',
            `recurrence`     ENUM('none','daily','weekly','monthly') NOT NULL DEFAULT 'none' COMMENT 'Recurrencia al completar',
            `colaborador_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Colaborador asignado (NULL = sin asignar)',
            `origin_idea_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Idea de origen si nació de una conversión',
            `sort_order`     INT             NOT NULL DEFAULT 0,
            `completed_at`   DATETIME        DEFAULT NULL,
            `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_mis_status`   (`status`),
            INDEX `idx_mis_priority` (`priority`),
            INDEX `idx_mis_colab`    (`colaborador_id`),
            INDEX `idx_mis_due`      (`due_date`),
            INDEX `idx_mis_sort`     (`sort_order`),
            CONSTRAINT `fk_mis_colaborador` FOREIGN KEY (`colaborador_id`)
                REFERENCES `{$prefix}colaboradores` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    // Migración de instalaciones previas: columnas e índices nuevos.
    $added = [];
    if (add_column($pdo, "{$prefix}misiones", 'accent', "VARCHAR(20) NOT NULL DEFAULT 'violet' AFTER `priority`")) { $added[] = 'accent'; }
    if (add_column($pdo, "{$prefix}misiones", 'due_date', "DATE DEFAULT NULL AFTER `accent`")) { $added[] = 'due_date'; }
    if (add_column($pdo, "{$prefix}misiones", 'recurrence', "ENUM('none','daily','weekly','monthly') NOT NULL DEFAULT 'none' AFTER `due_date`")) { $added[] = 'recurrence'; }
    if (add_column($pdo, "{$prefix}misiones", 'origin_idea_id', "BIGINT UNSIGNED DEFAULT NULL AFTER `colaborador_id`")) { $added[] = 'origin_idea_id'; }
    add_index($pdo, "{$prefix}misiones", 'idx_mis_due', '(`due_date`)');
    add_index($pdo, "{$prefix}misiones", 'idx_mis_sort', '(`sort_order`)');
    step('✓ Tabla `' . $prefix . 'misiones` lista.' . ($added ? ' (+ ' . implode(', ', $added) . ')' : ''));

    // ── Ítems de checklist (anidados en cada misión) ─────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$prefix}checklist_items` (
            `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `mision_id`  BIGINT UNSIGNED NOT NULL,
            `content`    VARCHAR(255)    NOT NULL,
            `is_done`    TINYINT(1)      NOT NULL DEFAULT 0,
            `sort_order` INT             NOT NULL DEFAULT 0,
            `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_chk_mision` (`mision_id`),
            CONSTRAINT `fk_chk_mision` FOREIGN KEY (`mision_id`)
                REFERENCES `{$prefix}misiones` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    step('✓ Tabla `' . $prefix . 'checklist_items` lista.');

    // ── Etiquetas (tags) y sus pivotes ───────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$prefix}tags` (
            `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`       VARCHAR(40)     NOT NULL,
            `color`      VARCHAR(20)     NOT NULL DEFAULT 'violet' COMMENT 'Token de acento o color hex',
            `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_tag_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$prefix}idea_tags` (
            `idea_id` BIGINT UNSIGNED NOT NULL,
            `tag_id`  BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (`idea_id`, `tag_id`),
            INDEX `idx_idea_tags_tag` (`tag_id`),
            CONSTRAINT `fk_idea_tags_idea` FOREIGN KEY (`idea_id`)
                REFERENCES `{$prefix}ideas` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_idea_tags_tag` FOREIGN KEY (`tag_id`)
                REFERENCES `{$prefix}tags` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$prefix}mision_tags` (
            `mision_id` BIGINT UNSIGNED NOT NULL,
            `tag_id`    BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (`mision_id`, `tag_id`),
            INDEX `idx_mision_tags_tag` (`tag_id`),
            CONSTRAINT `fk_mision_tags_mision` FOREIGN KEY (`mision_id`)
                REFERENCES `{$prefix}misiones` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_mision_tags_tag` FOREIGN KEY (`tag_id`)
                REFERENCES `{$prefix}tags` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    step('✓ Tablas de etiquetas (`' . $prefix . 'tags`, pivotes) listas.');

    // ── Plantillas de misión ─────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$prefix}templates` (
            `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`        VARCHAR(80)     NOT NULL COMMENT 'Nombre de la plantilla',
            `title`       VARCHAR(160)    NOT NULL DEFAULT '',
            `description` TEXT            DEFAULT NULL,
            `priority`    ENUM('baja','media','alta') NOT NULL DEFAULT 'media',
            `accent`      VARCHAR(20)     NOT NULL DEFAULT 'violet',
            `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$prefix}template_items` (
            `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `template_id` BIGINT UNSIGNED NOT NULL,
            `content`     VARCHAR(255)    NOT NULL,
            `sort_order`  INT             NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            INDEX `idx_tpl_items_tpl` (`template_id`),
            CONSTRAINT `fk_tpl_items_tpl` FOREIGN KEY (`template_id`)
                REFERENCES `{$prefix}templates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    step('✓ Tablas de plantillas (`' . $prefix . 'templates`, ítems) listas.');

    // ── Comentarios de misión ────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$prefix}comments` (
            `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `mision_id`  BIGINT UNSIGNED NOT NULL,
            `body`       TEXT            NOT NULL,
            `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_comments_mision` (`mision_id`),
            CONSTRAINT `fk_comments_mision` FOREIGN KEY (`mision_id`)
                REFERENCES `{$prefix}misiones` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    step('✓ Tabla `' . $prefix . 'comments` lista.');

    // ── Adjuntos de misión ───────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$prefix}attachments` (
            `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `mision_id`     BIGINT UNSIGNED NOT NULL,
            `stored_name`   VARCHAR(120)    NOT NULL COMMENT 'Nombre en disco (uploads/)',
            `original_name` VARCHAR(180)    NOT NULL,
            `mime`          VARCHAR(100)    NOT NULL DEFAULT '',
            `size`          INT UNSIGNED    NOT NULL DEFAULT 0,
            `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_attach_mision` (`mision_id`),
            CONSTRAINT `fk_attach_mision` FOREIGN KEY (`mision_id`)
                REFERENCES `{$prefix}misiones` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    step('✓ Tabla `' . $prefix . 'attachments` lista.');

    // ── Bitácora de cambios (tiempo real / SSE) ──────────────
    // Registro monotónico y efímero de "qué colección cambió y quién lo
    // provocó". Lo consume `api/events.php` (Server-Sent Events) para avisar
    // al resto de pestañas/colaboradores que deben resincronizar esa vista.
    // El `id` AUTO_INCREMENT actúa como secuencia y como Last-Event-ID del
    // stream SSE. Las filas se podan solas (ver api/changes.php); esta tabla
    // nunca crece de forma ilimitada.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$prefix}changes` (
            `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `scope`      VARCHAR(20)     NOT NULL COMMENT 'Colección afectada: ideas/misiones/colaboradores/tags/all',
            `actor`      VARCHAR(64)     NOT NULL DEFAULT '' COMMENT 'Client-Id que originó el cambio (para suprimir el eco)',
            `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_changes_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    step('✓ Tabla `' . $prefix . 'changes` (tiempo real) lista.');

    // ── Carpeta de adjuntos (fuera del alcance público de scripts) ──
    $uploadsDir = __DIR__ . '/uploads';
    if (!is_dir($uploadsDir)) {
        @mkdir($uploadsDir, 0775, true);
    }
    if (is_dir($uploadsDir)) {
        // Endurecer: impedir ejecución de scripts servidos desde uploads.
        $htaccess = $uploadsDir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "php_flag engine off\nOptions -ExecCGI\n<FilesMatch \"\\.(php|phtml|php3|php4|php5|pl|py|cgi|sh)$\">\n  Require all denied\n</FilesMatch>\n");
        }
        step('✓ Carpeta de adjuntos `uploads/` lista.');
    } else {
        step('⚠ No se pudo crear `uploads/` automáticamente. Creala con permisos de escritura para usar adjuntos.', 'muted');
    }

    echo '<p class="ok" style="margin-top:1.5rem;font-size:1.1rem">¡Listo! El módulo de Tareas ya tiene todas sus tablas.</p>';
    echo '<p class="muted">Recuerda eliminar este archivo (<code>setup_database.php</code>) por seguridad.</p>';

} catch (Throwable $e) {
    http_response_code(500);
    step('✗ Error: ' . $e->getMessage(), 'err');
}

echo '</body></html>';

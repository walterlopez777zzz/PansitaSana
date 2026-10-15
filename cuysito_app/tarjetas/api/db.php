<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

final class TarjetasDB
{
    private static ?PDO $connection = null;
    private static bool $migrated = false;

    private function __construct() {}
    private function __clone() {}

    public static function connect(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $db = tarjetas_config()['db'];
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $db['host'],
            $db['name'],
            $db['charset']
        );
        self::$connection = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$connection->exec("SET time_zone = '+00:00'");
        self::migrate(self::$connection);
        return self::$connection;
    }

    public static function migrate(PDO $pdo): void
    {
        if (self::$migrated) {
            return;
        }

        $p = tarjetas_prefix();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$p}emitters` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(80) NOT NULL,
                `color` CHAR(7) NOT NULL DEFAULT '#6c63ff',
                `sort_order` INT NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_emitter_name` (`name`),
                INDEX `idx_emitter_order` (`sort_order`, `name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$p}profiles` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `emitter_id` BIGINT UNSIGNED NOT NULL,
                `bank` VARCHAR(100) NOT NULL,
                `device` VARCHAR(100) NOT NULL,
                `holder` VARCHAR(120) NOT NULL,
                `cvu` VARCHAR(34) NOT NULL,
                `email` VARCHAR(190) DEFAULT NULL,
                `account_password` TEXT DEFAULT NULL,
                `location` VARCHAR(190) DEFAULT NULL,
                `account_number` VARCHAR(100) DEFAULT NULL,
                `phone` VARCHAR(60) DEFAULT NULL,
                `color` CHAR(7) NOT NULL DEFAULT '#6c63ff',
                `sort_order` INT NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_profile_emitter` (`emitter_id`),
                INDEX `idx_profile_order` (`sort_order`, `holder`),
                CONSTRAINT `fk_profile_emitter` FOREIGN KEY (`emitter_id`)
                    REFERENCES `{$p}emitters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$p}cards` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `profile_id` BIGINT UNSIGNED NOT NULL,
                `number_encrypted` TEXT NOT NULL,
                `expiry` VARCHAR(7) NOT NULL,
                `cvv_encrypted` TEXT NOT NULL,
                `uses_remaining` INT NOT NULL DEFAULT 5,
                `cooldown_until` DATETIME DEFAULT NULL,
                `sort_order` INT NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_card_profile` (`profile_id`),
                INDEX `idx_card_order` (`sort_order`, `created_at`),
                INDEX `idx_card_cooldown` (`cooldown_until`),
                CONSTRAINT `fk_card_profile` FOREIGN KEY (`profile_id`)
                    REFERENCES `{$p}profiles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$p}preferences` (
                `id` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `selected_preset` VARCHAR(80) NOT NULL DEFAULT 'split',
                `custom_presets` JSON DEFAULT NULL,
                `theme` ENUM('system','dark','light') NOT NULL DEFAULT 'system',
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
            INSERT IGNORE INTO `{$p}preferences` (`id`, `selected_preset`, `custom_presets`, `theme`)
            VALUES (1, 'split', JSON_ARRAY(), 'system')
        ");

        $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$p}emitters`")->fetchColumn();
        if ($count === 0) {
            $stmt = $pdo->prepare("
                INSERT INTO `{$p}emitters` (`name`, `color`, `sort_order`)
                VALUES (?, ?, ?)
            ");
            foreach ([
                ['AstroPay', '#7c5cff', 0],
                ['Belo', '#15b8a6', 1],
                ['Lemon', '#d4f238', 2],
            ] as $emitter) {
                $stmt->execute($emitter);
            }
        }

        self::$migrated = true;
    }
}

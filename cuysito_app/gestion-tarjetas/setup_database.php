<?php
/**
 * ============================================================
 * Card Manager — Database Setup & Migrations
 * ============================================================
 * Run via browser to create / migrate all required tables.
 * URL: https://yourdomain.com/gestion-tarjetas/setup_database.php
 * Delete this file after running it for security.
 */

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

$DB_HOST = 'localhost';
$DB_USER = 'zkckhehj_pistas';
$DB_PASS = 'Pistas2628';
$DB_NAME = 'zkckhehj_pistas';

echo '<h2>Card Manager — Database Setup</h2>';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    // ── Base table ────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `card_manager_cards` (
            `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `card_number`     VARCHAR(16)      NOT NULL COMMENT 'Full card number (13-16 digits)',
            `cvu`             VARCHAR(22)      NOT NULL DEFAULT '' COMMENT 'CVU / CBU (6-22 digits, optional)',
            `cvc`             VARCHAR(4)       NOT NULL DEFAULT '' COMMENT 'CVC / CVV security code (3-4 digits)',
            `expiration_date` VARCHAR(5)       NOT NULL DEFAULT '' COMMENT 'Expiration date (MM/YY)',
            `card_type`       ENUM('dollarapp','astropay') NOT NULL DEFAULT 'dollarapp' COMMENT 'Card provider type',
            `cardholder_name` VARCHAR(100)     NOT NULL COMMENT 'Cardholder full name (titular)',
            `uses_remaining` TINYINT UNSIGNED NOT NULL DEFAULT 5,
            `last_used_at`   DATETIME        DEFAULT NULL COMMENT 'UTC timestamp of last use',
            `is_archived`    TINYINT(1)      NOT NULL DEFAULT 0,
            `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_archived`  (`is_archived`),
            INDEX `idx_last_used` (`last_used_at`),
            INDEX `idx_card_type` (`card_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo '<p style="color:green;font-weight:bold;">&#10003; Table `card_manager_cards` created (or already exists).</p>';

    // ── Migration: last_four → card_number ────────────────────
    $lfCol = $pdo->query("SHOW COLUMNS FROM `card_manager_cards` LIKE 'last_four'")->fetchAll();
    if (count($lfCol) > 0) {
        $pdo->exec("ALTER TABLE `card_manager_cards` CHANGE COLUMN `last_four` `card_number` VARCHAR(16) NOT NULL COMMENT 'Full card number (13-16 digits)'");
        $idxRows = $pdo->query("SHOW INDEX FROM `card_manager_cards` WHERE Key_name = 'uq_card_number'")->fetchAll();
        if (count($idxRows) > 0) {
            $pdo->exec("ALTER TABLE `card_manager_cards` DROP INDEX `uq_card_number`");
        }
        echo '<p style="color:green;font-weight:bold;">&#10003; Migration: `last_four` → `card_number`.</p>';
    } else {
        echo '<p style="color:#888;">&#8212; Column `card_number` already exists.</p>';
    }

    // ── Migration: Add card_type ───────────────────────────────
    if (count($pdo->query("SHOW COLUMNS FROM `card_manager_cards` LIKE 'card_type'")->fetchAll()) === 0) {
        $pdo->exec("ALTER TABLE `card_manager_cards` ADD COLUMN `card_type` ENUM('dollarapp','astropay') NOT NULL DEFAULT 'dollarapp' COMMENT 'Card provider type' AFTER `card_number`, ADD INDEX `idx_card_type` (`card_type`)");
        echo '<p style="color:green;font-weight:bold;">&#10003; Migration: `card_type` column added.</p>';
    } else {
        echo '<p style="color:#888;">&#8212; Column `card_type` already exists.</p>';
    }

    // ── Migration: label → cardholder_name ────────────────────
    if (count($pdo->query("SHOW COLUMNS FROM `card_manager_cards` LIKE 'label'")->fetchAll()) > 0) {
        $pdo->exec("ALTER TABLE `card_manager_cards` CHANGE COLUMN `label` `cardholder_name` VARCHAR(100) NOT NULL COMMENT 'Cardholder full name (titular)'");
        $pdo->exec("UPDATE `card_manager_cards` SET `cardholder_name` = 'Sin titular' WHERE `cardholder_name` = '' OR `cardholder_name` IS NULL");
        echo '<p style="color:green;font-weight:bold;">&#10003; Migration: `label` → `cardholder_name`.</p>';
    } else {
        echo '<p style="color:#888;">&#8212; Column `cardholder_name` already exists.</p>';
    }

    // ── Migration: Add cvu ─────────────────────────────────────
    if (count($pdo->query("SHOW COLUMNS FROM `card_manager_cards` LIKE 'cvu'")->fetchAll()) === 0) {
        $pdo->exec("ALTER TABLE `card_manager_cards` ADD COLUMN `cvu` VARCHAR(22) NOT NULL DEFAULT '' COMMENT 'CVU / CBU of the card' AFTER `card_number`");
        echo '<p style="color:green;font-weight:bold;">&#10003; Migration: `cvu` column added.</p>';
    } else {
        echo '<p style="color:#888;">&#8212; Column `cvu` already exists.</p>';
    }

    // ── Migration: cvv → cvc (only if cvv exists and cvc does NOT) ──
    $cvvExists = count($pdo->query("SHOW COLUMNS FROM `card_manager_cards` LIKE 'cvv'")->fetchAll()) > 0;
    $cvcExists = count($pdo->query("SHOW COLUMNS FROM `card_manager_cards` LIKE 'cvc'")->fetchAll()) > 0;

    if ($cvvExists && !$cvcExists) {
        $pdo->exec("ALTER TABLE `card_manager_cards` CHANGE COLUMN `cvv` `cvc` VARCHAR(4) NOT NULL DEFAULT '' COMMENT 'CVC / CVV security code (3-4 digits)'");
        echo '<p style="color:green;font-weight:bold;">&#10003; Migration: `cvv` renamed to `cvc`.</p>';
    } elseif ($cvvExists && $cvcExists) {
        // Both exist — drop the old cvv column
        $pdo->exec("ALTER TABLE `card_manager_cards` DROP COLUMN `cvv`");
        echo '<p style="color:green;font-weight:bold;">&#10003; Migration: Duplicate `cvv` column dropped (keeping `cvc`).</p>';
    } else {
        echo '<p style="color:#888;">&#8212; Column `cvc` already exists.</p>';
    }

    // ── Migration: Add expiration_date ─────────────────────────
    if (count($pdo->query("SHOW COLUMNS FROM `card_manager_cards` LIKE 'expiration_date'")->fetchAll()) === 0) {
        $pdo->exec("ALTER TABLE `card_manager_cards` ADD COLUMN `expiration_date` VARCHAR(5) NOT NULL DEFAULT '' COMMENT 'Expiration date (MM/YY)' AFTER `cvc`");
        echo '<p style="color:green;font-weight:bold;">&#10003; Migration: `expiration_date` column added.</p>';
    } else {
        echo '<p style="color:#888;">&#8212; Column `expiration_date` already exists.</p>';
    }

    // ── Migration: Create card_manager_groups ─────────────────
    if (count($pdo->query("SHOW TABLES LIKE 'card_manager_groups'")->fetchAll()) === 0) {
        $pdo->exec("
            CREATE TABLE `card_manager_groups` (
                `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
                `name`            VARCHAR(80)      NOT NULL COMMENT 'Nombre visible del grupo',
                `card_type`       ENUM('dollarapp','astropay') NOT NULL DEFAULT 'dollarapp',
                `cardholder_name` VARCHAR(100)     NOT NULL DEFAULT '',
                `cvu`             VARCHAR(22)      NOT NULL DEFAULT '',
                `sort_order`      INT UNSIGNED     NOT NULL DEFAULT 0 COMMENT 'Orden en el dropdown/drawer',
                `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_groups_sort` (`sort_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        echo '<p style="color:green;font-weight:bold;">&#10003; Table `card_manager_groups` created.</p>';
    } else {
        echo '<p style="color:#888;">&#8212; Table `card_manager_groups` already exists.</p>';
    }

    // ── Migration: Add group_id to card_manager_cards ─────────
    if (count($pdo->query("SHOW COLUMNS FROM `card_manager_cards` LIKE 'group_id'")->fetchAll()) === 0) {
        $pdo->exec("
            ALTER TABLE `card_manager_cards`
            ADD COLUMN `group_id` INT UNSIGNED DEFAULT NULL
                COMMENT 'FK opcional → card_manager_groups.id'
            AFTER `cardholder_name`,
            ADD INDEX `idx_cards_group` (`group_id`)
        ");
        echo '<p style="color:green;font-weight:bold;">&#10003; Column `group_id` added to `card_manager_cards`.</p>';
    } else {
        echo '<p style="color:#888;">&#8212; Column `group_id` already exists.</p>';
    }

    // ── Migration: Add card_sort_order to card_manager_cards ──
    if (count($pdo->query("SHOW COLUMNS FROM `card_manager_cards` LIKE 'card_sort_order'")->fetchAll()) === 0) {
        $pdo->exec("
            ALTER TABLE `card_manager_cards`
            ADD COLUMN `card_sort_order` INT UNSIGNED NOT NULL DEFAULT 0
                COMMENT 'Orden manual dentro de su grupo'
            AFTER `group_id`
        ");
        echo '<p style="color:green;font-weight:bold;">&#10003; Column `card_sort_order` added to `card_manager_cards`.</p>';
    } else {
        echo '<p style="color:#888;">&#8212; Column `card_sort_order` already exists.</p>';
    }

    // ── Migration: Add bg_color to card_manager_groups ───────
    if (count($pdo->query("SHOW COLUMNS FROM `card_manager_groups` LIKE 'bg_color'")->fetchAll()) === 0) {
        $pdo->exec("ALTER TABLE `card_manager_groups` ADD COLUMN `bg_color` VARCHAR(7) DEFAULT NULL COMMENT 'Hex background color for group section' AFTER `cvu`");
        echo '<p style="color:green;font-weight:bold;">&#10003; Column `bg_color` added to `card_manager_groups`.</p>';
    } else {
        echo '<p style="color:#888;">&#8212; Column `bg_color` already exists.</p>';
    }

    // ── Migration: Add card_color to card_manager_groups ─────
    if (count($pdo->query("SHOW COLUMNS FROM `card_manager_groups` LIKE 'card_color'")->fetchAll()) === 0) {
        $pdo->exec("ALTER TABLE `card_manager_groups` ADD COLUMN `card_color` VARCHAR(7) DEFAULT NULL COMMENT 'Optional hex color for cards in this group' AFTER `bg_color`");
        echo '<p style="color:green;font-weight:bold;">&#10003; Column `card_color` added to `card_manager_groups`.</p>';
    } else {
        echo '<p style="color:#888;">&#8212; Column `card_color` already exists.</p>';
    }

    // ── Migration: Add resets_remaining to card_manager_groups ─
    if (count($pdo->query("SHOW COLUMNS FROM `card_manager_groups` LIKE 'resets_remaining'")->fetchAll()) === 0) {
        $pdo->exec("ALTER TABLE `card_manager_groups` ADD COLUMN `resets_remaining` SMALLINT UNSIGNED NOT NULL DEFAULT 30 COMMENT 'Remaining group resets (DollarApp only, starts counting at 3 cards)' AFTER `card_color`");
        echo '<p style="color:green;font-weight:bold;">&#10003; Column `resets_remaining` added to `card_manager_groups`.</p>';
    } else {
        echo '<p style="color:#888;">&#8212; Column `resets_remaining` already exists.</p>';
    }

    // ── Migration: Add monthly_resets_used to card_manager_groups ──
    if (count($pdo->query("SHOW COLUMNS FROM `card_manager_groups` LIKE 'monthly_resets_used'")->fetchAll()) === 0) {
        $pdo->exec("ALTER TABLE `card_manager_groups` ADD COLUMN `monthly_resets_used` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Resets used in the current 30-day window (max 10)' AFTER `resets_remaining`");
        echo '<p style="color:green;font-weight:bold;">&#10003; Column `monthly_resets_used` added to `card_manager_groups`.</p>';
    } else {
        echo '<p style="color:#888;">&#8212; Column `monthly_resets_used` already exists.</p>';
    }

    // ── Migration: Add window_started_at to card_manager_groups ───
    if (count($pdo->query("SHOW COLUMNS FROM `card_manager_groups` LIKE 'window_started_at'")->fetchAll()) === 0) {
        $pdo->exec("ALTER TABLE `card_manager_groups` ADD COLUMN `window_started_at` DATETIME DEFAULT NULL COMMENT 'UTC timestamp when the current 30-day reset window started (NULL = not yet started)' AFTER `monthly_resets_used`");
        echo '<p style="color:green;font-weight:bold;">&#10003; Column `window_started_at` added to `card_manager_groups`.</p>';
    } else {
        echo '<p style="color:#888;">&#8212; Column `window_started_at` already exists.</p>';
    }

    echo '<hr><p style="color:green;font-weight:bold;">&#10003; All migrations complete. You may now delete this file for security.</p>';

} catch (PDOException $e) {
    echo '<p style="color:red;font-weight:bold;">&#10007; Database error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
}

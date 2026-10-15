<?php
/**
 * Migration: Add texts_config JSON column to game_card_visuals table.
 *
 * Run once via browser or CLI:
 *   php update_visuals_text_json_db.php
 *
 * Safe to re-run — checks for column existence before altering.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/wabo/core/db_connect.php';

if (!isset($conn) || !$conn || $conn->connect_error) {
    die('❌ Database connection failed: ' . ($conn->connect_error ?? 'Unknown error'));
}

$table = 'game_card_visuals';

// Check if column already exists
$check = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE 'texts_config'");
if ($check && $check->num_rows > 0) {
    echo "✅ Column 'texts_config' already exists in '{$table}'. No changes needed.\n";
} else {
    $sql = "ALTER TABLE `{$table}` ADD COLUMN `texts_config` JSON DEFAULT NULL AFTER `bg_scale`";
    if ($conn->query($sql)) {
        echo "✅ Column 'texts_config' added successfully to '{$table}'.\n";
    } else {
        die("❌ Failed to add column: " . $conn->error . "\n");
    }
}

$conn->close();
echo "Done.\n";

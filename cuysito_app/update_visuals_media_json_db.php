<?php
/**
 * Migration: Add media_config JSON column to game_card_visuals table
 * (Admin → Visual Juegos: modo imagen / SVG / ambos, SVG y color del icono).
 *
 * Run once via browser or CLI:
 *   php update_visuals_media_json_db.php
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

$check = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE 'media_config'");
if ($check && $check->num_rows > 0) {
    echo "✅ Column 'media_config' already exists in '{$table}'. No changes needed.\n";
} else {
    $sql = "ALTER TABLE `{$table}` ADD COLUMN `media_config` JSON DEFAULT NULL AFTER `texts_config`";
    if ($conn->query($sql)) {
        echo "✅ Column 'media_config' added successfully to '{$table}'.\n";
    } else {
        die("❌ Failed to add column: " . $conn->error . "\n");
    }
}

$conn->close();
echo "Done.\n";

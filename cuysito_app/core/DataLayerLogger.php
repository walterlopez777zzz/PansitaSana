<?php
/**
 * ============================================================================
 * BANANO STORE — DATA LAYER LOGGER (Phase 5)
 * ============================================================================
 * Lightweight, dependency-free observability sink for the Data Layer Manager.
 * Records anomalies and lifecycle events from the Ingestor (missing/renamed
 * API fields, connection errors, snapshot writes, mode changes, overrides).
 *
 * Design:
 *   - Accepts an optional mysqli connection. When present, rows go to
 *     `dl_event_log` (the panel reads from there). The INSERT is best-effort:
 *     if the table does not exist yet, it silently degrades.
 *   - ALWAYS mirrors to a rotating-ish file (cache/data_layer.log) so events
 *     are never lost even without a DB. The file is gitignored.
 *   - Never throws; logging must never break the Ingestor or any consumer.
 * ============================================================================
 */

require_once __DIR__ . '/../config/shop_config.php';

if (!class_exists('DataLayerLogger')) {

final class DataLayerLogger
{
    const SEVERITIES = ['info', 'warning', 'error', 'critical'];

    /**
     * Record a single event.
     *
     * @param mysqli|null $conn    Optional DB connection (best-effort).
     * @param string      $severity info|warning|error|critical
     * @param string      $type    short machine code (e.g. 'missing_field')
     * @param string      $message human-readable message
     * @param array       $context arbitrary JSON-serializable context
     */
    public static function log($conn, string $severity, string $type, string $message, array $context = []): void
    {
        $severity = in_array($severity, self::SEVERITIES, true) ? $severity : 'info';
        $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($contextJson === false) {
            $contextJson = '{}';
        }

        self::toFile($severity, $type, $message, $contextJson);
        self::toDb($conn, $severity, $type, $message, $contextJson);
    }

    /** Convenience batch logger for the anomaly arrays from ShopNormalizer. */
    public static function logAnomalies($conn, array $anomalies, array $context = []): void
    {
        foreach ($anomalies as $a) {
            self::log(
                $conn,
                (string) ($a['severity'] ?? 'warning'),
                (string) ($a['type'] ?? 'anomaly'),
                (string) ($a['message'] ?? ''),
                $context
            );
        }
    }

    private static function toFile(string $severity, string $type, string $message, string $contextJson): void
    {
        $dir = ShopSourceConfig::cacheDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = sprintf(
            "[%s] %-8s %-16s %s %s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            strtoupper($severity),
            $type,
            $message,
            $contextJson === '{}' || $contextJson === '[]' ? '' : $contextJson
        );
        @file_put_contents($dir . '/data_layer.log', $line, FILE_APPEND | LOCK_EX);
    }

    private static function toDb($conn, string $severity, string $type, string $message, string $contextJson): void
    {
        if (!($conn instanceof mysqli)) {
            return;
        }
        // Best-effort: a missing table (e.g. before the SQL is applied) must
        // NEVER break the Ingestor or any consumer. PHP 8.1 mysqli throws by
        // default, so we swallow everything here.
        try {
            $stmt = $conn->prepare(
                'INSERT INTO dl_event_log (severity, event_type, message, context) VALUES (?, ?, ?, ?)'
            );
            if ($stmt === false) {
                return;
            }
            $stmt->bind_param('ssss', $severity, $type, $message, $contextJson);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            // Already mirrored to file; ignore DB failures.
        }
    }
}

}

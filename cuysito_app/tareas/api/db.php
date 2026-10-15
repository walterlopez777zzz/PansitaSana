<?php
/**
 * ============================================================
 * Tareas — Conexión PDO (Singleton)
 * ============================================================
 * Capa de acceso a datos autocontenida. Replica el patrón de
 * conexión usado por el resto de la plataforma pero sin depender
 * de ninguno de sus archivos.
 * ============================================================
 */

declare(strict_types=1);

final class TareasDB
{
    private static ?PDO $instance = null;

    private function __construct() {}
    private function __clone() {}

    public static function connect(): PDO
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../config/config.php';
            $db     = $config['db'];

            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                $db['host'],
                $db['name'],
                $db['charset']
            );

            self::$instance = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            self::$instance->exec("SET time_zone = '+00:00'");
        }

        return self::$instance;
    }
}

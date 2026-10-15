<?php
/**
 * ============================================================
 * Card Manager — PDO Database Connection (Singleton)
 * ============================================================
 */

declare(strict_types=1);

final class Database
{
    private static ?PDO $instance = null;

    private const HOST = 'localhost';
    private const USER = 'zkckhehj_pistas';
    private const PASS = 'Pistas2628';
    private const NAME = 'zkckhehj_pistas';

    private function __construct() {}
    private function __clone() {}

    public static function connect(): PDO
    {
        if (self::$instance === null) {
            $dsn = 'mysql:host=' . self::HOST . ';dbname=' . self::NAME . ';charset=utf8mb4';

            self::$instance = new PDO($dsn, self::USER, self::PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            self::$instance->exec("SET time_zone = '+00:00'");
        }

        return self::$instance;
    }
}

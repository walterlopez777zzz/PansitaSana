<?php
/**
 * ============================================================================
 * BANANO STORE — UTC TIME (server side)
 * ============================================================================
 * Small, dependency-free helpers for the "UTC-First" convention:
 *
 *   - MySQL DATETIME columns hold UTC wall-clock (`Y-m-d H:i:s`) and are
 *     compared with `UTC_TIMESTAMP()`, never `NOW()` (session-zone dependent).
 *   - Anything that leaves the server for a browser is ISO-8601 with a `Z`
 *     suffix so `Date.parse()` cannot mistake it for local time.
 *
 * Every function is pure and zone-explicit: the result never depends on
 * `date_default_timezone_set()` nor on the MySQL session `time_zone`, so the
 * public API (UTC session) and the admin panel (server-local session) agree.
 * ============================================================================
 */

if (!class_exists('UtcTime')) {

final class UtcTime
{
    public const SQL_FORMAT = 'Y-m-d H:i:s';
    public const ISO_FORMAT = 'Y-m-d\TH:i:s\Z';

    private static ?DateTimeZone $zone = null;

    private function __construct() {}

    public static function zone(): DateTimeZone
    {
        return self::$zone ??= new DateTimeZone('UTC');
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::zone());
    }

    public static function fromTimestamp(int $unixTs): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $unixTs))->setTimezone(self::zone());
    }

    /** UTC `Y-m-d H:i:s` (as read from MySQL) → instant; null when empty/invalid. */
    public static function fromSql(?string $sqlUtc): ?DateTimeImmutable
    {
        if ($sqlUtc === null || $sqlUtc === '' || strncmp($sqlUtc, '0000-00-00', 10) === 0) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!' . self::SQL_FORMAT, $sqlUtc, self::zone());
        return $parsed === false ? null : $parsed;
    }

    public static function toSql(DateTimeInterface $instant): string
    {
        return DateTimeImmutable::createFromInterface($instant)->setTimezone(self::zone())->format(self::SQL_FORMAT);
    }

    public static function toIso(DateTimeInterface $instant): string
    {
        return DateTimeImmutable::createFromInterface($instant)->setTimezone(self::zone())->format(self::ISO_FORMAT);
    }

    /** UTC SQL string → ISO-8601 `Z` for clients; null so callers never emit a bogus date. */
    public static function sqlToIso(?string $sqlUtc): ?string
    {
        $instant = self::fromSql($sqlUtc);
        return $instant === null ? null : $instant->format(self::ISO_FORMAT);
    }

    /** Whole seconds from `$now` until a UTC SQL string, clamped at 0; null when unparseable. */
    public static function secondsUntil(?string $sqlUtc, ?DateTimeInterface $now = null): ?int
    {
        $target = self::fromSql($sqlUtc);
        if ($target === null) {
            return null;
        }
        $from = $now === null ? self::now() : $now;
        return max(0, $target->getTimestamp() - $from->getTimestamp());
    }
}

}

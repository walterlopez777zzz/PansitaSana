<?php
/**
 * ============================================================================
 * BANANO STORE — DAILY SPIN SCHEDULE (server side)
 * ============================================================================
 * Single source of truth for the Giro Diario calendar.
 *
 * Business rules live in America/Lima: the free spin resets at 19:00 local and
 * `daily_spin_config.event_date` is keyed by the local calendar date. Every
 * value that touches MySQL or leaves the server is UTC (see UtcTime):
 *
 *   - `daily_discounts.created_at` / `expires_at` are stored as UTC
 *     `Y-m-d H:i:s` strings and compared against `UTC_TIMESTAMP()`.
 *   - Clients receive ISO-8601 UTC (`...Z`) and render in their own zone.
 *
 * Instances are immutable snapshots of "now"; build one per request and pass
 * it around so every query in that request agrees on the same instant.
 * ============================================================================
 */

require_once __DIR__ . '/UtcTime.php';

if (!class_exists('DailySpinSchedule')) {

final class DailySpinSchedule
{
    public const BUSINESS_TIMEZONE = 'America/Lima';
    public const RESET_HOUR        = 19;

    private DateTimeImmutable $nowUtc;
    private DateTimeImmutable $windowStartUtc;
    private DateTimeImmutable $windowEndUtc;
    private string $businessDate;

    private function __construct(DateTimeImmutable $now)
    {
        $local       = $now->setTimezone(new DateTimeZone(self::BUSINESS_TIMEZONE));
        $resetToday  = $local->setTime(self::RESET_HOUR, 0, 0);
        $beforeReset = $local < $resetToday;

        $this->nowUtc         = $now->setTimezone(UtcTime::zone());
        $this->windowStartUtc = ($beforeReset ? $resetToday->modify('-1 day') : $resetToday)->setTimezone(UtcTime::zone());
        $this->windowEndUtc   = ($beforeReset ? $resetToday : $resetToday->modify('+1 day'))->setTimezone(UtcTime::zone());
        $this->businessDate   = $local->format('Y-m-d');
    }

    /** Snapshot of the schedule at `$now` (defaults to the current instant). */
    public static function at(?DateTimeInterface $now = null): self
    {
        return new self($now === null ? UtcTime::now() : DateTimeImmutable::createFromInterface($now));
    }

    /** Local calendar date (`Y-m-d`) that keys `daily_spin_config.event_date`. */
    public function businessDate(): string
    {
        return $this->businessDate;
    }

    public function nowUtc(): DateTimeImmutable
    {
        return $this->nowUtc;
    }

    /** Most recent 19:00 Lima reset, as UTC. Spins on/after it belong to the current window. */
    public function windowStartUtc(): DateTimeImmutable
    {
        return $this->windowStartUtc;
    }

    /** Next 19:00 Lima reset, as UTC. Discounts won in this window expire here. */
    public function windowEndUtc(): DateTimeImmutable
    {
        return $this->windowEndUtc;
    }

    public function nowSql(): string
    {
        return UtcTime::toSql($this->nowUtc);
    }

    public function windowStartSql(): string
    {
        return UtcTime::toSql($this->windowStartUtc);
    }

    public function windowEndSql(): string
    {
        return UtcTime::toSql($this->windowEndUtc);
    }

    public function nowIso(): string
    {
        return UtcTime::toIso($this->nowUtc);
    }

    public function windowEndIso(): string
    {
        return UtcTime::toIso($this->windowEndUtc);
    }
}

}

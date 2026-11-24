<?php
declare(strict_types=1);

namespace Borealis\Services;

use Borealis\Core\Database;

/**
 * Time zone and quiet hours of each person (1.9).
 *
 * A person may choose their own zone in Perfil › Notificaciones; otherwise the installation's zone
 * (app.timezone) is used, exactly as before. Reminders, quiet hours and the hours written in e-mails
 * and in Google Calendar use that zone. Everything stored in the database stays in UTC.
 */
final class UserTime
{
    /** @var array<int, \DateTimeZone> */
    private static array $cache = [];

    public static function installZone(): string
    {
        $z = (string)config('app.timezone', 'UTC');
        return in_array($z, \DateTimeZone::listIdentifiers(), true) ? $z : 'UTC';
    }

    public static function valid(?string $zone): bool
    {
        return is_string($zone) && $zone !== '' && in_array($zone, \DateTimeZone::listIdentifiers(), true);
    }

    public static function zone(int $userId): \DateTimeZone
    {
        if (!isset(self::$cache[$userId])) {
            $z = Notifications::prefs($userId)['timezone'] ?? null;
            self::$cache[$userId] = new \DateTimeZone(self::valid($z) ? $z : self::installZone());
        }
        return self::$cache[$userId];
    }

    public static function forget(int $userId): void
    {
        unset(self::$cache[$userId]);
    }

    /** «Now» in the person's zone. $nowUtc lets the tests and the cron pin the clock. */
    public static function now(int $userId, ?int $ts = null): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . ($ts ?? time())))->setTimezone(self::zone($userId));
    }

    /**
     * The due moment of a task, shown in the person's zone; date-only tasks have none (null).
     * The hour of a task is written in the installation's zone (it is the same instant for the whole
     * team); a person in another zone sees and is reminded at their own local hour.
     */
    public static function dueAt(int $userId, ?string $date, ?string $time): ?\DateTimeImmutable
    {
        if (!$date || !$time) return null;
        return (new \DateTimeImmutable($date . ' ' . substr($time, 0, 5) . ':00', new \DateTimeZone(self::installZone())))->setTimezone(self::zone($userId));
    }

    /** True while the person's quiet hours are on (push and e-mail wait; Borealis' bell does not). */
    public static function isQuiet(int $userId, ?int $ts = null): bool
    {
        $q = Notifications::prefs($userId)['quiet'];
        if (!$q['on']) return false;
        $hm = self::now($userId, $ts)->format('H:i');
        [$from, $to] = [$q['from'], $q['to']];
        if ($from === $to) return false;
        return $from < $to ? ($hm >= $from && $hm < $to) : ($hm >= $from || $hm < $to);
    }

    /** Short «17:30» in the person's zone for a UTC moment. */
    public static function hm(int $userId, string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(self::zone($userId))->format('H:i');
    }

    /** Zones offered in the profile: the common ones of Latin America and Spain first, then all. */
    public static function choices(): array
    {
        $common = ['America/Santiago', 'America/Mexico_City', 'America/Bogota', 'America/Lima', 'America/Argentina/Buenos_Aires', 'America/Caracas', 'America/Montevideo',
            'America/Guayaquil', 'America/La_Paz', 'America/Asuncion', 'America/Costa_Rica', 'America/Panama', 'America/New_York', 'Europe/Madrid', 'UTC'];
        return array_values(array_unique(array_merge($common, \DateTimeZone::listIdentifiers())));
    }

    public static function emailOf(int $userId): ?string
    {
        $e = (string)Database::get()->value('SELECT email FROM users WHERE id = ? AND is_active = 1', [$userId]);
        return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null;
    }
}

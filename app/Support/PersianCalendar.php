<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/** Display-only Persian dates; never change persisted UTC timestamps or API values. */
final class PersianCalendar
{
    /** Convert a civil Gregorian YYYY-MM-DD without moving UTC reporting buckets. */
    public static function date(string $value): string
    {
        if (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D', $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return $value;
        }

        [$year, $month, $day] = self::jalali((int) $parts[1], (int) $parts[2], (int) $parts[3]);

        return self::digits(sprintf('%04d/%02d/%02d', $year, $month, $day));
    }

    /** Application reports already express this civil time in Tehran. */
    public static function localDateTime(string $value): string
    {
        if (preg_match('/\A([0-9]{4}-[0-9]{2}-[0-9]{2}) ([0-9]{2}:[0-9]{2})(?::[0-9]{2})?\z/D', $value, $parts) !== 1) {
            return $value;
        }

        return self::date($parts[1]).' '.self::digits($parts[2]);
    }

    /** UTC database timestamp converted to Tehran local date and clock. */
    public static function utcDateTime(string $value): string
    {
        try {
            $tehran = (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('Asia/Tehran'));
        } catch (Throwable) {
            return $value;
        }

        return self::date($tehran->format('Y-m-d')).' '.self::digits($tehran->format('H:i'));
    }

    /** Current Persian month boundary, retaining original Tehran civil-day semantics. */
    public static function dayOfMonth(DateTimeInterface $tehranDate): int
    {
        return self::jalali(
            (int) $tehranDate->format('Y'), (int) $tehranDate->format('n'), (int) $tehranDate->format('j'),
        )[2];
    }

    /** @return array{int,int,int} */
    private static function jalali(int $year, int $month, int $day): array
    {
        $offsets = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $adjustedYear = $month > 2 ? $year + 1 : $year;
        $days = 355666 + 365 * $year
            + intdiv($adjustedYear + 3, 4) - intdiv($adjustedYear + 99, 100)
            + intdiv($adjustedYear + 399, 400) + $day + $offsets[$month - 1];
        $jy = -1595 + 33 * intdiv($days, 12053);
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            return [$jy, 1 + intdiv($days, 31), 1 + $days % 31];
        }

        return [$jy, 7 + intdiv($days - 186, 30), 1 + ($days - 186) % 30];
    }

    public static function digits(string $value): string
    {
        return strtr($value, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
    }
}

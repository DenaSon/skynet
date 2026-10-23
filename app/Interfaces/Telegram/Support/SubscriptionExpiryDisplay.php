<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Present a Remnawave UTC expiry in the Persian calendar.
 * Dates and remaining time are presentation-only; provider data stays UTC.
 */
final readonly class SubscriptionExpiryDisplay
{
    /** @return array{string, string} */
    public function lines(DateTimeImmutable $expiresAt, DateTimeImmutable $now): array
    {
        $tehran = $expiresAt->setTimezone(new DateTimeZone('Asia/Tehran'));
        [$year, $month, $day] = $this->toJalali(
            (int) $tehran->format('Y'), (int) $tehran->format('n'), (int) $tehran->format('j'),
        );
        $date = self::digits(sprintf('%04d/%02d/%02d', $year, $month, $day));
        $clock = self::digits($tehran->format('H:i'));

        $seconds = max(0, $expiresAt->getTimestamp() - $now->getTimestamp());
        $days = intdiv($seconds, 86400);
        $remaining = match (true) {
            $seconds === 0 => '۰ روز (منقضی شده)',
            $days === 0 => '۰ روز (کمتر از ۲۴ ساعت)',
            default => self::digits((string) $days).' روز',
        };

        return [
            '📅 تاریخ انقضا: <b>'.$date.'</b>، ساعت <b>'.$clock.'</b> (ایران)',
            '⏳ روزهای باقی‌مانده: <b>'.$remaining.'</b>',
        ];
    }

    /**
     * Gregorian to Solar Hijri for modern service dates, without extensions
     * or a new Composer dependency.
     *
     * @return array{int, int, int}
     */
    private function toJalali(int $year, int $month, int $day): array
    {
        $monthOffsets = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $adjustedYear = $month > 2 ? $year + 1 : $year;
        $days = 355666 + 365 * $year
            + intdiv($adjustedYear + 3, 4)
            - intdiv($adjustedYear + 99, 100)
            + intdiv($adjustedYear + 399, 400)
            + $day + $monthOffsets[$month - 1];

        $jalaliYear = -1595 + 33 * intdiv($days, 12053);
        $days %= 12053;
        $jalaliYear += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jalaliYear += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            return [$jalaliYear, 1 + intdiv($days, 31), 1 + $days % 31];
        }

        return [$jalaliYear, 7 + intdiv($days - 186, 30), 1 + ($days - 186) % 30];
    }

    private static function digits(string $value): string
    {
        return strtr($value, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
    }
}

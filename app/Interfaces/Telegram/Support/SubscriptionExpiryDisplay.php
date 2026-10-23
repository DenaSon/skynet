<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Support\PersianCalendar;
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
        $date = PersianCalendar::date($tehran->format('Y-m-d'));
        $clock = PersianCalendar::digits($tehran->format('H:i'));

        $seconds = max(0, $expiresAt->getTimestamp() - $now->getTimestamp());
        $days = intdiv($seconds, 86400);
        $remaining = match (true) {
            $seconds === 0 => '۰ روز (منقضی شده)',
            $days === 0 => '۰ روز (کمتر از ۲۴ ساعت)',
            default => PersianCalendar::digits((string) $days).' روز',
        };

        return [
            '📅 تاریخ انقضا: <b>'.$date.'</b>، ساعت <b>'.$clock.'</b> (ایران)',
            '⏳ روزهای باقی‌مانده: <b>'.$remaining.'</b>',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\Provisioning\Application\SubscriptionUsage;
use DateTimeImmutable;

/** Plain customer-facing copy from an authorized usage snapshot; never sends messages. */
final class SubscriptionRenewalReminder
{
    public static function text(?string $name, SubscriptionUsage $usage): ?string
    {
        if (! in_array($usage->status, ['ACTIVE', 'LIMITED', 'EXPIRED'], true)) {
            return null;
        }
        $name = trim(preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $name ?? '') ?? '');
        $service = $name === '' ? 'سرویس شما' : 'سرویس '.$name;
        $ended = (new DateTimeImmutable($usage->expireAt))->getTimestamp() <= time();

        if ($usage->status === 'EXPIRED' && ! $ended) {
            return null;
        }

        $expiry = PersianCalendar::utcDateTime($usage->expireAt);
        $separator = strrpos($expiry, ' ');
        if ($separator !== false) {
            $expiry = substr($expiry, 0, $separator).' ساعت '.substr($expiry, $separator + 1);
        }

        return "سلام، وقتتون بخیر 🌱\n\n"
            .'اعتبار '.$service.' در تاریخ '.$expiry.' '.($ended ? 'به پایان رسیده.' : 'تموم می‌شه.')
            ."\n\nاگر قصد تمدید دارید، پیام بدید تا راهنمایی‌تون کنم.";
    }
}

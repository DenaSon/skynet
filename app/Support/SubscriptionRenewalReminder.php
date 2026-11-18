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
        $service = $name === '' ? 'سرویس شما' : 'سرویس «'.mb_substr($name, 0, 60).'»';
        $ended = (new DateTimeImmutable($usage->expireAt))->getTimestamp() <= time();

        if ($usage->status === 'EXPIRED' && ! $ended) {
            return null;
        }

        return 'سلام، زمان اعتبار '.$service.' در '.PersianCalendar::utcDateTime($usage->expireAt)
            .' (تهران) '.($ended ? 'به پایان رسیده است.' : 'به پایان می‌رسد.')
            .' برای بررسی و تمدید، لطفاً پیام بدهید.';
    }
}

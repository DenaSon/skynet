<?php

declare(strict_types=1);

namespace App\Modules\Filtering\Application;

final class FilteringGroupDestination
{
    /** A private Telegram group/supergroup must use a negative chat ID. */
    public static function chatId(): ?int
    {
        $configured = config('filtering.notification_group_chat_id');
        if (! is_string($configured) && ! is_int($configured)) {
            return null;
        }

        $value = (string) $configured;
        if (preg_match('/\A-[1-9][0-9]{1,18}\z/D', $value) !== 1) {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($id) && $id < 0 ? $id : null;
    }

    public static function eventKey(int $incidentId, string $kind, int $chatId): string
    {
        return "flt:{$incidentId}:{$kind}:group:{$chatId}";
    }
}

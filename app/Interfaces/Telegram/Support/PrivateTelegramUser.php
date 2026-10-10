<?php

namespace App\Interfaces\Telegram\Support;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;

final class PrivateTelegramUser
{
    public function id(Nutgram $bot): ?int
    {
        $chat = $bot->chat();
        $user = $bot->user();

        if ($chat === null || $user === null
            || ! in_array($chat->type, [ChatType::PRIVATE, 'private'], true)
            || $chat->id !== $user->id) {
            return null;
        }

        return $user->id;
    }
}

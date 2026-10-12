<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;

final class TelegramRecipient
{
    public function forActiveUser(int $userId): ?int
    {
        $user = User::query()->with('telegramAccount')->find($userId);

        return $user !== null && $user->is_active
            ? $user->telegramAccount?->telegram_user_id
            : null;
    }
}

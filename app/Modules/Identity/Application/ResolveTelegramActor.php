<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;

final class ResolveTelegramActor
{
    public function handle(int $telegramUserId): ?TelegramActor
    {
        $account = TelegramAccount::query()->with('user')
            ->where('telegram_user_id', $telegramUserId)->first();
        $user = $account?->user;

        if ($user === null || ! $user->is_active) {
            return null;
        }

        return new TelegramActor(
            userId: (int) $user->getKey(),
            name: $user->name,
            role: $user->role,
        );
    }
}

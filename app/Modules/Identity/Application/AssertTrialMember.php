<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/** Internal ordinary-member check; callers hold a transaction for admission. */
final class AssertTrialMember
{
    public function handle(int $userId, int $telegramId): void
    {
        $user = User::query()->lockForUpdate()->find($userId);
        if ($user === null || ! $user->is_active || $telegramId < 1
            || $user->getRoleNames()->all() !== ['member']
            || $user->getAllPermissions()->isNotEmpty()
            || ! $user->telegramAccount()->where('telegram_user_id', $telegramId)->exists()) {
            throw new AuthorizationException;
        }
    }
}

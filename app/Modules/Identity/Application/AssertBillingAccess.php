<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class AssertBillingAccess
{
    public function reseller(int $userId): void
    {
        $user = User::query()->find($userId);

        if ($user === null || ! $user->is_active || ! $user->hasRole('reseller')) {
            throw new AuthorizationException;
        }
    }

    public function reviewer(int $userId): void
    {
        $user = User::query()->with('telegramAccount')->find($userId);

        if ($user === null || ! app(SuperAdminTrust::class)->eligible($user)) {
            throw new AuthorizationException;
        }
    }
}

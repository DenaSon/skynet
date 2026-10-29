<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class ResolveSalesBuyer
{
    /** Only an active regular member can apply for a Sales Panel. */
    public function isMember(int $userId): bool
    {
        $user = User::query()->find($userId);
        if ($user === null || ! $user->is_active) {
            throw new AuthorizationException;
        }

        return $user->hasRole('member') && ! $user->hasRole('reseller')
            && ! $user->hasRole('admin') && ! $user->hasRole('super-admin')
            && ! app(AdminCapabilityAccess::class)->isOperator($user);
    }

    public function isReseller(int $userId): bool
    {
        $user = User::query()->find($userId);

        if ($user === null || ! $user->is_active) {
            throw new AuthorizationException;
        }

        return $user->hasRole('reseller');
    }
}

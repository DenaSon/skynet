<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class ResolveSalesBuyer
{
    public function isReseller(int $userId): bool
    {
        $user = User::query()->find($userId);

        if ($user === null || ! $user->is_active) {
            throw new AuthorizationException;
        }

        return $user->hasRole('reseller');
    }
}

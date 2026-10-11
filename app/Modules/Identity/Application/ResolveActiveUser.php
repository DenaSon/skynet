<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class ResolveActiveUser
{
    public function handle(int $userId, bool $lock = false): int
    {
        $query = User::query();

        if ($lock) {
            $query->lockForUpdate();
        }

        $user = $query->find($userId);

        if ($user === null || ! $user->is_active) {
            throw new AuthorizationException;
        }

        return (int) $user->getKey();
    }
}

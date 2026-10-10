<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;

final class SuperAdminTrust
{
    /**
     * @return list<string>
     */
    public function allowedTelegramIds(): array
    {
        $ids = config('super_admin.telegram_ids', []);

        if (! is_array($ids)) {
            return [];
        }

        $valid = array_filter($ids, static fn (mixed $id): bool => is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id) === 1);

        return array_values(array_unique($valid));
    }

    public function eligible(User $user): bool
    {
        if (! $user->is_active || ! $user->hasRole('super-admin')) {
            return false;
        }

        $allowed = $this->allowedTelegramIds();

        // Never permit an environment with only one designated root.
        if (count($allowed) < 2) {
            return false;
        }

        $telegramId = $user->telegramAccount?->telegram_user_id;

        return $telegramId !== null && in_array((string) $telegramId, $allowed, true);
    }

    public function canManageUsers(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->hasRole('super-admin')) {
            return $this->eligible($user);
        }

        return $user->hasPermissionTo('users.manage', 'web');
    }
}

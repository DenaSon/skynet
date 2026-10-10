<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\UserRole;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateUserAccess
{
    public function handle(User $actor, User $subject, ?UserRole $role, ?bool $active): User
    {
        return DB::transaction(function () use ($actor, $subject, $role, $active): User {
            $user = User::query()->lockForUpdate()->findOrFail($subject->getKey());

            $newRole = $role ?? $user->role;
            $newActive = $active ?? $user->is_active;

            if ($actor->is($user) && ($newRole !== UserRole::Admin || ! $newActive)) {
                throw ValidationException::withMessages([
                    'role' => 'You cannot revoke your own administrator access.',
                ]);
            }

            if ($user->isAdmin() && $user->is_active && ($newRole !== UserRole::Admin || ! $newActive)) {
                $otherActiveAdminExists = User::query()
                    ->whereKeyNot($user->getKey())
                    ->where('role', UserRole::Admin->value)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->exists();

                if (! $otherActiveAdminExists) {
                    throw ValidationException::withMessages([
                        'role' => 'At least one active administrator is required.',
                    ]);
                }
            }

            $user->forceFill([
                'role' => $newRole,
                'is_active' => $newActive,
            ])->save();

            return $user->refresh();
        });
    }
}

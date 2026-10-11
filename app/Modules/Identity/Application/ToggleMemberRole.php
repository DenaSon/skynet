<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

final class ToggleMemberRole
{
    public function handle(int $actorId, int $userId, int $roleId, bool $expectedAssigned): UserDirectoryEntry
    {
        return DB::transaction(function () use ($actorId, $userId, $roleId, $expectedAssigned): UserDirectoryEntry {
            $actor = app(ManageRoles::class)->authorize($actorId);
            $subject = User::query()->lockForUpdate()->findOrFail($userId);
            $role = Role::query()->where('guard_name', 'web')->findOrFail($roleId);

            if ($subject->hasRole('super-admin') || $role->name === 'super-admin') {
                throw ValidationException::withMessages(['role' => 'Super Admin enrollment is CLI only.']);
            }

            $current = $subject->getRoleNames()->all();
            $assigned = in_array($role->name, $current, true);

            if ($assigned !== $expectedAssigned) {
                throw ValidationException::withMessages(['role' => 'Role assignment changed. Refresh the user.']);
            }

            $next = $assigned ? array_values(array_diff($current, [$role->name])) : [...$current, $role->name];

            if ($next === []) {
                throw ValidationException::withMessages(['role' => 'At least one role is required.']);
            }

            app(AssignUserRoles::class)->handle($actor, $subject, $next);

            $result = app(BrowseUsers::class)->find($actorId, $userId);

            if ($result === null) {
                throw ValidationException::withMessages(['user' => 'User no longer exists.']);
            }

            return $result;
        });
    }
}

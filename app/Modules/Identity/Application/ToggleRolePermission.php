<?php

namespace App\Modules\Identity\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class ToggleRolePermission
{
    public function handle(int $actorId, int $roleId, int $permissionId, bool $expectedAssigned): RoleEntry
    {
        return DB::transaction(function () use ($actorId, $roleId, $permissionId, $expectedAssigned): RoleEntry {
            $actor = app(ManageRoles::class)->authorize($actorId);
            $role = Role::query()->where('guard_name', 'web')->lockForUpdate()->findOrFail($roleId);

            if (in_array($role->name, ['member', 'admin', 'super-admin'], true)) {
                throw ValidationException::withMessages(['role' => 'Built-in roles are protected.']);
            }

            $permission = Permission::query()->where('guard_name', 'web')->findOrFail($permissionId);
            $assigned = $role->hasPermissionTo($permission);

            if ($assigned !== $expectedAssigned) {
                throw ValidationException::withMessages(['permission' => 'Permissions changed. Refresh the role.']);
            }

            $pairs = ['purchases.review' => 'purchases.view', 'topups.review' => 'topups.view'];
            if (! $assigned && isset($pairs[$permission->name])
                && ! $role->hasPermissionTo($pairs[$permission->name])) {
                throw ValidationException::withMessages(['permission' => 'Grant receipt view permission first.']);
            }
            if ($assigned && in_array($permission->name, $pairs, true)
                && $role->hasPermissionTo($permission->name === 'purchases.view' ? 'purchases.review' : 'topups.review')) {
                throw ValidationException::withMessages(['permission' => 'Revoke receipt review permission first.']);
            }

            if ($assigned) {
                $role->revokePermissionTo($permission);
            } else {
                $role->givePermissionTo($permission);
            }

            app(RecordIdentityAudit::class)->handle($actor, 'identity.role.permission_changed', 'role', $roleId, [
                'permission' => $permission->name,
                'before' => $assigned,
                'after' => ! $assigned,
            ]);

            $result = app(ManageRoles::class)->find($actorId, $roleId);

            if ($result === null) {
                throw ValidationException::withMessages(['role' => 'Role no longer exists.']);
            }

            return $result;
        });
    }
}

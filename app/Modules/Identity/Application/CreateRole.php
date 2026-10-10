<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class CreateRole
{
    /**
     * @param  list<string>  $permissions
     */
    public function handle(User $actor, string $name, array $permissions = []): Role
    {
        if (! app(SuperAdminTrust::class)->eligible($actor)) {
            throw ValidationException::withMessages(['role' => 'Only a trusted Super Admin can manage roles.']);
        }

        $name = mb_strtolower(trim($name));

        if (in_array($name, ['member', 'admin', 'super-admin'], true)) {
            throw ValidationException::withMessages(['name' => 'System role names are protected.']);
        }

        if (preg_match('/^[a-z][a-z0-9-]{2,49}$/D', $name) !== 1) {
            throw ValidationException::withMessages(['name' => 'Invalid role name.']);
        }

        return DB::transaction(function () use ($actor, $name, $permissions): Role {
            if (Role::query()->where('name', $name)->where('guard_name', 'web')->exists()) {
                throw ValidationException::withMessages(['name' => 'Role already exists.']);
            }

            $role = Role::create(['name' => $name, 'guard_name' => 'web']);
            $approved = Permission::query()->where('guard_name', 'web')->whereIn('name', $permissions)->pluck('name')->all();

            if (count(array_unique($permissions)) !== count($approved)) {
                throw ValidationException::withMessages(['permissions' => 'Unknown permission requested.']);
            }

            $role->syncPermissions($approved);
            app(RecordIdentityAudit::class)->handle($actor, 'identity.role.created', 'role', (int) $role->getKey(), [
                'name' => $name,
                'permissions' => $approved,
            ]);

            return $role;
        });
    }
}

<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class ManageRoles
{
    public function authorize(int $actorId): User
    {
        $actor = User::query()->find($actorId);

        if ($actor === null || ! app(SuperAdminTrust::class)->eligible($actor)) {
            throw new AuthorizationException;
        }

        return $actor;
    }

    public function page(int $actorId, int $page = 1): RoleDirectoryPage
    {
        $this->authorize($actorId);

        $roles = Role::query()->where('guard_name', 'web')
            ->with('permissions')->withCount('users')->orderBy('id')
            ->paginate(perPage: 8, page: max(1, min(100000, $page)));

        return new RoleDirectoryPage(
            roles: $roles->getCollection()->map(fn (Role $role): RoleEntry => $this->entry($role))->all(),
            permissions: $this->permissions(),
            page: $roles->currentPage(),
            lastPage: $roles->lastPage(),
            total: $roles->total(),
        );
    }

    public function find(int $actorId, int $roleId): ?RoleEntry
    {
        $this->authorize($actorId);

        $role = Role::query()->where('guard_name', 'web')
            ->with('permissions')->withCount('users')->find($roleId);

        return $role === null ? null : $this->entry($role);
    }

    /**
     * @return list<PermissionEntry>
     */
    public function permissions(): array
    {
        return Permission::query()->where('guard_name', 'web')->orderBy('name')->get()
            ->map(fn (Permission $permission): PermissionEntry => new PermissionEntry((int) $permission->getKey(), $permission->name))
            ->all();
    }

    private function entry(Role $role): RoleEntry
    {
        return new RoleEntry(
            id: (int) $role->getKey(),
            name: $role->name,
            permissions: $role->permissions->pluck('name')->sort()->values()->all(),
            userCount: $role->users_count,
            protected: in_array($role->name, ['member', 'admin', 'super-admin'], true),
        );
    }
}

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['users.view', 'purchases.view', 'purchases.review', 'topups.view', 'topups.review'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Backward compatibility: the built-in admin keeps its existing
        // users.manage and user-directory read rights; do not grant finance.
        $admin = Role::findByName('admin', 'web');
        $admin->givePermissionTo('users.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Existing assigned role/permission records may already be in use:
        // do not destroy business authorization evidence during rollback.
        if (DB::table('model_has_permissions')->exists()
            || DB::table('role_has_permissions')->whereIn('permission_id',
                DB::table('permissions')->whereIn('name',
                    ['users.view', 'purchases.view', 'purchases.review', 'topups.view', 'topups.review'])
                    ->pluck('id'))->whereNotIn('role_id',
                        DB::table('roles')->where('name', 'admin')->pluck('id'))->exists()) {
            throw new RuntimeException('Delegated permissions exist; reviewed forward fix required.');
        }

        Role::findByName('admin', 'web')->revokePermissionTo('users.view');
        DB::table('permissions')->whereIn('name',
            ['users.view', 'purchases.view', 'purchases.review', 'topups.view', 'topups.review'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

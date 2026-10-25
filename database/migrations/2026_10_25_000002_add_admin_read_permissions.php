<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const GRANTS = ['support.view', 'reports.view', 'finance.view', 'servers.view'];

    public function up(): void
    {
        foreach (self::GRANTS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->where('guard_name', 'web')
            ->whereIn('name', self::GRANTS)->pluck('id');

        if (DB::table('role_has_permissions')->whereIn('permission_id', $ids)->exists()
            || DB::table('model_has_permissions')->whereIn('permission_id', $ids)->exists()) {
            throw new RuntimeException('Delegated grants exist; revoke through reviewed RBAC workflow before rollback.');
        }

        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

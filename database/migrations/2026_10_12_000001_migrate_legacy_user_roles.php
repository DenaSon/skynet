<?php

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['member', 'admin', 'super-admin'] as $name) {
            DB::table('roles')->insert([
                'name' => $name,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('permissions')->insert([
            'name' => 'users.manage',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roleIds = DB::table('roles')->pluck('id', 'name');
        $permissionId = DB::table('permissions')->where('name', 'users.manage')->value('id');

        DB::table('role_has_permissions')->insert([
            'role_id' => $roleIds['admin'],
            'permission_id' => $permissionId,
        ]);

        DB::table('users')->orderBy('id')->chunkById(200, function ($users) use ($roleIds): void {
            foreach ($users as $user) {
                DB::table('model_has_roles')->insert([
                    'role_id' => $roleIds[$user->role === 'admin' ? 'admin' : 'member'],
                    'model_type' => User::class,
                    'model_id' => $user->id,
                ]);
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 32)->default('member');
        });

        // Only legacy roles can be represented in the former single-column schema.
        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');
        $admins = DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->where('role_id', $adminRoleId)
            ->pluck('model_id');
        DB::table('users')->whereIn('id', $admins)->update(['role' => 'admin']);
    }
};

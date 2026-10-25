<?php

declare(strict_types=1);

use App\Modules\Identity\Application\AdminCapabilityAccess;
use App\Modules\Identity\Application\ToggleRolePermission;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(DatabaseMigrations::class);

it('invalidates a shared permission snapshot repopulated before the role edit commits', function (): void {
    config()->set('super_admin.telegram_ids', ['997011', '997012']);
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::create(['user_id' => $root->id, 'telegram_user_id' => 997011]);
    $role = Role::findOrCreate('commit-reviewer', 'web');
    $role->givePermissionTo(['topups.view', 'topups.review']);
    $operator = User::factory()->create();
    $operator->syncRoles([$role]);
    $access = app(AdminCapabilityAccess::class);
    expect($access->allowed($operator->fresh(), 'topups.review'))->toBeTrue();
    $key = config('permission.cache.key');
    $previousSnapshot = Cache::get($key);
    expect($previousSnapshot)->not->toBeNull();
    $permission = Permission::findByName('topups.review', 'web');

    DB::transaction(function () use ($root, $role, $permission, $key, $previousSnapshot): void {
        app(ToggleRolePermission::class)->handle($root->id, $role->id, $permission->id, true);
        // A concurrent reader can still see pre-commit grants and repopulate
        // this shared snapshot after Spatie's immediate cache invalidation.
        Cache::put($key, $previousSnapshot, now()->addDay());
    });

    app(PermissionRegistrar::class)->clearPermissionsCollection();
    try {
        expect($access->allowed($operator->fresh(), 'topups.review'))->toBeFalse();
    } finally {
        // Respect the reviewed rollback guards on delegated permissions.
        $operator->syncRoles(['member']);
        $role->delete();
    }
});

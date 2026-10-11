<?php

use App\Modules\Identity\Application\CreateRole;
use App\Modules\Identity\Application\ManageRoles;
use App\Modules\Identity\Application\ToggleMemberRole;
use App\Modules\Identity\Application\ToggleRolePermission;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function rbacRoot(int $telegram = 525252): User
{
    config()->set('super_admin.telegram_ids', ['525252', '626262']);

    $root = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => $telegram]);
    $root->syncRoles(['super-admin']);

    return $root;
}

it('lists role and permission DTOs for a trusted root only', function (): void {
    $root = rbacRoot();
    $page = app(ManageRoles::class)->page($root->id);

    expect($page->total)->toBe(3)
        ->and($page->permissions[0]->name)->toBe('users.manage')
        ->and(collect($page->roles)->pluck('name')->all())->toContain('super-admin');

    $member = User::factory()->create();

    expect(fn () => app(ManageRoles::class)->page($member->id))->toThrow(AuthorizationException::class);
    config()->set('super_admin.telegram_ids', ['626262', '777777']);
    expect(fn () => app(ManageRoles::class)->page($root->id))->toThrow(AuthorizationException::class);
});

it('creates custom roles and toggles only their existing permissions with an audit trail', function (): void {
    $root = rbacRoot();
    $role = app(CreateRole::class)->handle($root, 'reseller');
    $permission = Permission::findByName('users.manage', 'web');
    $toggle = app(ToggleRolePermission::class);

    expect($toggle->handle($root->id, $role->id, $permission->id, false)->permissions)->toBe(['users.manage']);
    expect($toggle->handle($root->id, $role->id, $permission->id, true)->permissions)->toBe([]);

    expect(DB::table('identity_audit_events')->where('event', 'identity.role.permission_changed')->count())->toBe(2);
    expect(fn () => $toggle->handle($root->id, $role->id, $permission->id, true))
        ->toThrow(ValidationException::class);
});

it('never edits built-in roles from generic permission actions', function (): void {
    $root = rbacRoot();
    $permission = Permission::findByName('users.manage', 'web');
    $admin = Role::findByName('admin', 'web');

    expect(fn () => app(ToggleRolePermission::class)->handle($root->id, $admin->id, $permission->id, true))
        ->toThrow(ValidationException::class);
    expect($admin->fresh()->hasPermissionTo('users.manage'))->toBeTrue();
});

it('assigns and removes one of several user roles while preserving safeguards', function (): void {
    $root = rbacRoot();
    $member = User::factory()->create();
    $role = app(CreateRole::class)->handle($root, 'reseller');
    $toggle = app(ToggleMemberRole::class);

    $assigned = $toggle->handle($root->id, $member->id, $role->id, false);
    expect($assigned->roles)->toContain('member', 'reseller');

    expect(fn () => $toggle->handle($root->id, $member->id, $role->id, false))
        ->toThrow(ValidationException::class);

    expect($toggle->handle($root->id, $member->id, $role->id, true)->roles)->toBe(['member']);
    expect(DB::table('identity_audit_events')->where('event', 'identity.roles.assigned')->count())->toBe(2);
});

it('blocks removing a users final role, root edits and non-root role assignments', function (): void {
    $root = rbacRoot();
    $member = User::factory()->create();
    $nonRoot = User::factory()->admin()->create();
    $memberRole = Role::findByName('member', 'web');
    $rootRole = Role::findByName('super-admin', 'web');

    $toggle = app(ToggleMemberRole::class);

    expect(fn () => $toggle->handle($root->id, $member->id, $memberRole->id, true))
        ->toThrow(ValidationException::class);
    expect(fn () => $toggle->handle($root->id, $member->id, $rootRole->id, false))
        ->toThrow(ValidationException::class);
    expect(fn () => $toggle->handle($root->id, $root->id, $memberRole->id, false))
        ->toThrow(ValidationException::class);
    expect(fn () => $toggle->handle($nonRoot->id, $member->id, $memberRole->id, true))
        ->toThrow(AuthorizationException::class);
    expect($member->fresh()->hasRole('member'))->toBeTrue();
});

it('rejects permission updates after a root loses allowlist access', function (): void {
    $root = rbacRoot();
    $role = app(CreateRole::class)->handle($root, 'reseller');
    $permission = Permission::findByName('users.manage', 'web');
    config()->set('super_admin.telegram_ids', ['626262', '777777']);

    expect(fn () => app(ToggleRolePermission::class)->handle($root->id, $role->id, $permission->id, false))
        ->toThrow(AuthorizationException::class);
    expect($role->fresh()->permissions)->toHaveCount(0);
});

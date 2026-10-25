<?php

declare(strict_types=1);

use App\Modules\Identity\Application\AdminCapabilityAccess;
use App\Modules\Identity\Application\AssignUserRoles;
use App\Modules\Identity\Application\BrowseUsers;
use App\Modules\Identity\Application\ChangeUserStatus;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('protects every custom Admin grant from ordinary admin status changes and role removal', function (string $permission): void {
    $admin = User::factory()->admin()->create();
    $role = Role::findOrCreate('custom-operator', 'web');
    $role->givePermissionTo($permission);
    $subject = User::factory()->create();
    $subject->syncRoles([$role]);

    expect(app(BrowseUsers::class)->find($admin->id, $subject->id)?->canChangeStatus)->toBeFalse();
    expect(fn () => app(ChangeUserStatus::class)->handle($admin->id, $subject->id, true, false))
        ->toThrow(ValidationException::class);
    expect(fn () => app(AssignUserRoles::class)->handle($admin, $subject, ['member']))
        ->toThrow(ValidationException::class);

    $this->actingAs($admin)->patchJson("/admin/users/{$subject->id}", [
        'roles' => ['member'], 'is_active' => false,
    ])->assertUnprocessable();

    expect($subject->fresh()->is_active)->toBeTrue()
        ->and($subject->fresh()->getRoleNames()->all())->toBe(['custom-operator'])
        ->and(DB::table('identity_audit_events')->count())->toBe(0);
})->with(AdminCapabilityAccess::CAPABILITIES);

it('protects inactive and unlinked custom operators from reactivation and demotion', function (): void {
    $admin = User::factory()->admin()->create();
    $role = Role::findOrCreate('disabled-finance', 'web');
    $role->givePermissionTo('finance.view');
    $subject = User::factory()->create(['is_active' => false]);
    $subject->syncRoles([$role]);

    expect(app(BrowseUsers::class)->find($admin->id, $subject->id)?->canChangeStatus)->toBeFalse();
    $this->actingAs($admin)->patchJson("/admin/users/{$subject->id}", [
        'is_active' => true,
    ])->assertUnprocessable();
    $this->patchJson("/admin/users/{$subject->id}", ['role' => 'member'])->assertUnprocessable();

    expect($subject->fresh()->is_active)->toBeFalse()
        ->and($subject->fresh()->hasRole($role))->toBeTrue()
        ->and(DB::table('identity_audit_events')->count())->toBe(0);
});

it('protects direct Admin grants and prevents custom user managers from changing peers', function (): void {
    $role = Role::findOrCreate('custom-user-manager', 'web');
    $role->givePermissionTo('users.manage');
    $manager = User::factory()->create();
    $manager->syncRoles([$role]);
    $subject = User::factory()->create();
    $subject->givePermissionTo('finance.view');

    expect(app(BrowseUsers::class)->find($manager->id, $subject->id)?->canChangeStatus)->toBeFalse();
    expect(fn () => app(ChangeUserStatus::class)->handle($manager->id, $subject->id, true, false))
        ->toThrow(ValidationException::class);
    expect(fn () => app(AssignUserRoles::class)->handle($manager, $subject, ['member']))
        ->toThrow(ValidationException::class);
    expect(fn () => app(AssignUserRoles::class)->handle($manager, $manager, ['member']))
        ->toThrow(ValidationException::class);
});

it('retains audited trusted-root management and ordinary customer status management', function (): void {
    config()->set('super_admin.telegram_ids', ['997001', '997002']);
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::create(['user_id' => $root->id, 'telegram_user_id' => 997001]);
    $role = Role::findOrCreate('root-managed-finance', 'web');
    $role->givePermissionTo('finance.view');
    $subject = User::factory()->create();
    $subject->syncRoles([$role]);

    expect(app(BrowseUsers::class)->find($root->id, $subject->id)?->canChangeStatus)->toBeTrue();
    app(ChangeUserStatus::class)->handle($root->id, $subject->id, true, false);
    app(ChangeUserStatus::class)->handle($root->id, $subject->id, false, true);
    app(AssignUserRoles::class)->handle($root, $subject, ['member']);
    expect($subject->fresh()->getRoleNames()->all())->toBe(['member'])
        ->and(DB::table('identity_audit_events')->where('event', 'identity.user.status_changed')->count())->toBe(2)
        ->and(DB::table('identity_audit_events')->where('event', 'identity.roles.assigned')->count())->toBe(1);

    $admin = User::factory()->admin()->create();
    $customer = User::factory()->create();
    $reseller = User::factory()->create();
    $reseller->syncRoles([Role::findOrCreate('reseller', 'web')]);
    foreach ([$customer, $reseller] as $user) {
        expect(app(BrowseUsers::class)->find($admin->id, $user->id)?->canChangeStatus)->toBeTrue();
        app(ChangeUserStatus::class)->handle($admin->id, $user->id, true, false);
        app(ChangeUserStatus::class)->handle($admin->id, $user->id, false, true);
        expect($user->fresh()->is_active)->toBeTrue();
    }
});

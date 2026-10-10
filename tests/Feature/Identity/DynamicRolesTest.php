<?php

use App\Modules\Identity\Application\CreateRole;
use App\Modules\Identity\Application\SuperAdminTrust;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['111111', '222222']);
});

function trustedRbacOperator(int $telegramId = 111111): User
{
    $user = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);
    $user->syncRoles(['super-admin']);

    return $user;
}

it('creates a role dynamically and supports multiple roles without enum changes', function (): void {
    $operator = trustedRbacOperator();
    $create = app(CreateRole::class);
    $role = $create->handle($operator, 'reseller', ['users.manage']);
    expect($role->hasPermissionTo('users.manage'))->toBeTrue();

    $member = User::factory()->create();

    $this->actingAs($operator)->patchJson("/admin/users/{$member->id}", [
        'roles' => ['member', 'reseller'],
    ])->assertOk()->assertJsonCount(2, 'data.roles');

    expect($member->fresh()->hasRole('reseller'))->toBeTrue();
    expect($member->fresh()->can('users.manage'))->toBeTrue();
    expect(DB::table('identity_audit_events')->where('event', 'identity.roles.assigned')->exists())->toBeTrue();
});

it('requires DB role, active account, matching Telegram binding and two configured IDs', function (): void {
    $super = trustedRbacOperator();
    expect(app(SuperAdminTrust::class)->eligible($super))->toBeTrue();
    expect($super->can('identity.manage-roles'))->toBeTrue();

    config()->set('super_admin.telegram_ids', ['222222', '333333']);
    expect($super->fresh()->can('identity.manage-roles'))->toBeFalse();

    config()->set('super_admin.telegram_ids', ['111111']);
    expect($super->fresh()->can('identity.manage-roles'))->toBeFalse();

    config()->set('super_admin.telegram_ids', ['111111', '222222']);
    $super->forceFill(['is_active' => false])->save();
    expect($super->fresh()->can('identity.manage-roles'))->toBeFalse();
});

it('does not grant special access just because a Telegram ID is in environment', function (): void {
    $user = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 111111]);

    expect($user->can('identity.manage-roles'))->toBeFalse();
    expect($user->isAdmin())->toBeFalse();

    $this->actingAs($user)->postJson('/admin/roles', ['name' => 'reseller'])->assertForbidden();
});

it('blocks assigning or mutating super-admin roles over HTTP', function (): void {
    $operator = trustedRbacOperator();
    $member = User::factory()->create();

    $this->actingAs($operator)->postJson('/admin/users', [
        'name' => 'New Root',
        'email' => 'root@example.test',
        'password' => 'very-secure-password-123',
        'role' => 'super-admin',
    ])->assertForbidden();

    $this->patchJson("/admin/users/{$member->id}", ['roles' => ['super-admin']])
        ->assertUnprocessable()->assertJsonValidationErrors('role');

    $this->patchJson("/admin/users/{$operator->id}", ['is_active' => false])
        ->assertUnprocessable();

    $this->postJson('/admin/roles', ['name' => 'super-admin'])
        ->assertUnprocessable();
});

it('allows two independent allowlisted super-admins and rejects attempts to remove either via HTTP', function (): void {
    $one = trustedRbacOperator(111111);
    $two = trustedRbacOperator(222222);

    expect(app(SuperAdminTrust::class)->eligible($one))->toBeTrue();
    expect(app(SuperAdminTrust::class)->eligible($two))->toBeTrue();

    $this->actingAs($one)->patchJson("/admin/users/{$two->id}", ['is_active' => false])
        ->assertUnprocessable();

    $this->actingAs($two)->patchJson("/admin/users/{$one->id}", ['roles' => ['member']])
        ->assertUnprocessable();

    expect($one->fresh()->is_active)->toBeTrue();
    expect($two->fresh()->hasRole('super-admin'))->toBeTrue();
});

it('keeps ordinary admins out of role management and privileged account changes', function (): void {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();

    $this->actingAs($admin)->getJson('/admin/roles')->assertForbidden();
    $this->postJson('/admin/roles', ['name' => 'reseller'])->assertForbidden();
    $this->patchJson("/admin/users/{$other->id}", ['is_active' => false])
        ->assertUnprocessable();
});

it('creates a custom role through a verified super-admin session and records audit', function (): void {
    $super = trustedRbacOperator();

    $this->actingAs($super)->postJson('/admin/roles', [
        'name' => 'reseller',
        'permissions' => [],
    ])->assertCreated()->assertJsonPath('data.name', 'reseller');

    expect(Role::query()->where('name', 'reseller')->exists())->toBeTrue();
    expect(DB::table('identity_audit_events')->where('event', 'identity.role.created')->exists())->toBeTrue();
});

it('enrolls only allowlisted accounts through explicitly confirmed CLI promotion', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $first->id, 'telegram_user_id' => 111111]);
    TelegramAccount::query()->create(['user_id' => $second->id, 'telegram_user_id' => 222222]);

    $this->artisan('identity:super-admin:promote', ['user' => $first->id])
        ->expectsConfirmation("Promote user {$first->id} to Super Admin (replaces existing roles)?", 'yes')
        ->assertSuccessful();

    $this->artisan('identity:super-admin:promote', ['user' => $second->id])
        ->expectsConfirmation("Promote user {$second->id} to Super Admin (replaces existing roles)?", 'yes')
        ->assertSuccessful();

    expect($first->fresh()->hasRole('super-admin'))->toBeTrue();
    expect($second->fresh()->hasRole('super-admin'))->toBeTrue();

    $stranger = User::factory()->create();
    $this->artisan('identity:super-admin:promote', ['user' => $stranger->id])->assertFailed();
});

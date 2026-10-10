<?php

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('denies user management to guests and regular members', function (): void {
    $this->get('/admin/users')->assertRedirect('/login');

    $member = User::factory()->create();

    $this->actingAs($member)->getJson('/admin/users')->assertForbidden();
    $this->actingAs($member)->postJson('/admin/users', [
        'name' => 'Escalation',
        'email' => 'escalation@example.test',
        'password' => 'securepassword123',
        'role' => 'admin',
    ])->assertForbidden();
});

it('allows administrators to list users without leaking secrets', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)
        ->getJson('/admin/users')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.role', 'admin')
        ->assertJsonMissingPath('data.0.password')
        ->assertJsonMissingPath('data.0.remember_token');
});

it('creates members through validated administrator actions', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->postJson('/admin/users', [
        'name' => 'New member',
        'email' => 'New.Member@Example.Test',
        'password' => 'new-secret-password123',
    ])->assertCreated()
        ->assertJsonPath('data.email', 'new.member@example.test')
        ->assertJsonPath('data.role', 'member')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonMissingPath('data.password');

    $newUser = User::query()->where('email', 'new.member@example.test')->firstOrFail();
    expect(Hash::check('new-secret-password123', $newUser->password))->toBeTrue();
});

it('validates password and unique email when creating users', function (): void {
    $admin = User::factory()->admin()->create();
    $existing = User::factory()->create(['email' => 'existing@example.test']);

    $this->actingAs($admin)->postJson('/admin/users', [
        'name' => 'Weak',
        'email' => 'unused@example.test',
        'password' => 'short',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');

    $this->actingAs($admin)->postJson('/admin/users', [
        'name' => 'Duplicate',
        'email' => strtoupper($existing->email),
        'password' => 'long-password123',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('blocks normal administrators from granting privileged roles', function (): void {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();

    $this->actingAs($admin)->patchJson("/admin/users/{$member->id}", [
        'role' => 'admin',
    ])->assertUnprocessable()->assertJsonValidationErrors('role');

    expect($member->fresh()->hasRole('admin'))->toBeFalse();
});

it('blocks self-demotion and self-deactivation', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->patchJson("/admin/users/{$admin->id}", [
        'role' => 'member',
    ])->assertUnprocessable()->assertJsonValidationErrors('role');

    $this->patchJson("/admin/users/{$admin->id}", [
        'is_active' => false,
    ])->assertUnprocessable()->assertJsonValidationErrors('role');

    expect($admin->fresh()->isAdmin())->toBeTrue();
    expect($admin->fresh()->is_active)->toBeTrue();
});

it('prevents an administrator from deactivating another administrator', function (): void {
    $adminA = User::factory()->admin()->create();
    $adminB = User::factory()->admin()->create();

    $this->actingAs($adminA)->patchJson("/admin/users/{$adminB->id}", [
        'is_active' => false,
    ])->assertUnprocessable();

    $this->patchJson("/admin/users/{$adminA->id}", [
        'is_active' => false,
    ])->assertUnprocessable();

    expect($adminB->fresh()->is_active)->toBeTrue();
});

it('rejects an empty or invalid access update', function (): void {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();

    $this->actingAs($admin)->patchJson("/admin/users/{$member->id}", [])
        ->assertUnprocessable();

    $this->patchJson("/admin/users/{$member->id}", ['role' => 'superadmin'])
        ->assertUnprocessable()->assertJsonValidationErrors('role');
});

<?php

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('has role tables and removes the legacy single role column', function (): void {
    expect(Schema::hasTable('model_has_roles'))->toBeTrue();
    expect(Schema::hasTable('roles'))->toBeTrue();
    expect(Schema::hasColumn('users', 'role'))->toBeFalse();
    expect(Role::findByName('admin', 'web')->hasPermissionTo('users.manage'))->toBeTrue();
});

it('assigns member by default for new users and lets factory build an admin', function (): void {
    $user = User::factory()->create();
    $admin = User::factory()->admin()->create();

    expect($user->getRoleNames()->all())->toBe(['member']);
    expect($admin->hasRole('admin'))->toBeTrue();
    expect($admin->isAdmin())->toBeTrue();
});

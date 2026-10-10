<?php

use App\Modules\Identity\Domain\UserRole;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('bootstraps the first administrator without a seeded account', function (): void {
    expect(User::query()->count())->toBe(0);

    $this->artisan('identity:bootstrap-admin')
        ->expectsQuestion('Name', 'SkyNet Owner')
        ->expectsQuestion('Email', 'Owner@Example.Test')
        ->expectsQuestion('Password (at least 12 characters)', 'a-very-secure-password!')
        ->assertSuccessful();

    $admin = User::query()->sole();
    expect($admin->email)->toBe('owner@example.test');
    expect($admin->role)->toBe(UserRole::Admin);
    expect($admin->is_active)->toBeTrue();
    expect(Hash::check('a-very-secure-password!', $admin->password))->toBeTrue();
});

it('refuses to bootstrap a second administrator', function (): void {
    User::factory()->create(['role' => UserRole::Admin]);

    $this->artisan('identity:bootstrap-admin')->assertFailed();

    expect(User::query()->count())->toBe(1);
});

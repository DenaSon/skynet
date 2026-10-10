<?php

use App\Modules\Identity\Domain\UserRole;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('displays a public login form without offering registration', function (): void {
    $this->get('/login')->assertOk()->assertSee('SkyNet sign in');
    $this->get('/register')->assertNotFound();
    $this->post('/register')->assertNotFound();
});

it('authenticates an active user and exposes a safe identity summary', function (): void {
    $user = User::factory()->create(['role' => UserRole::Member, 'password' => 'some-secure-password']);

    $this->post('/login', [
        'email' => strtoupper($user->email),
        'password' => 'some-secure-password',
    ])->assertRedirect('/account');

    $this->assertAuthenticatedAs($user, 'web');

    $this->getJson('/account')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.role', 'member')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonMissingPath('data.password');

    expect(Hash::check('some-secure-password', $user->fresh()->password))->toBeTrue();
});

it('rejects incorrect credentials and disabled accounts with the same generic message', function (): void {
    $user = User::factory()->create(['is_active' => false]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest('web');
});

it('limits repeated login attempts', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', [
            'email' => 'missing-user@example.test',
            'password' => 'guess',
        ])->assertSessionHasErrors('email');
    }

    $this->post('/login', [
        'email' => 'missing-user@example.test',
        'password' => 'guess',
    ])->assertStatus(429);
});

it('requires a session and logs out by invalidating the session', function (): void {
    $this->get('/account')->assertRedirect('/login');

    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect('/login');

    $this->assertGuest('web');
    $this->get('/account')->assertRedirect('/login');
});

it('invalidates an existing session when the user is deactivated', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/account')->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->getJson('/account')->assertForbidden();
    $this->assertGuest('web');
});

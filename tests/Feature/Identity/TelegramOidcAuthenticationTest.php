<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Interfaces\Web\Live\LiveAuthController;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();

    config()->set('denagate_crm.host', 'crm.denagate.ir');
    config()->set('super_admin.telegram_ids', ['997001', '997002']);
    config()->set('services.telegram_oidc.client_id', '123456789');
    config()->set('services.telegram_oidc.client_secret', 'test-client-secret');

    Cache::forget('identity.telegram_oidc.jwks');
    Role::findOrCreate('super-admin', 'web');
});

function telegramOidcRootUser(string $password = 'strong-password'): User
{
    $user = User::factory()->create(['password' => $password]);
    $user->syncRoles(['super-admin']);

    TelegramAccount::query()->create([
        'user_id' => $user->id,
        'telegram_user_id' => 997001,
    ]);

    return $user;
}

/**
 * @return array{private: OpenSSLAsymmetricKey, jwk: array<string, string>}
 */
function telegramOidcKeyPair(): array
{
    $privateKey = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 2048,
    ]);

    if ($privateKey === false) {
        throw new RuntimeException('Unable to create test signing key.');
    }

    $details = openssl_pkey_get_details($privateKey);

    if (! is_array($details) || ! is_array($details['rsa'] ?? null) || ! is_string($details['rsa']['n'] ?? null) || ! is_string($details['rsa']['e'] ?? null)) {
        throw new RuntimeException('Unable to read test signing key.');
    }

    return [
        'private' => $privateKey,
        'jwk' => [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => 'test-key',
            'n' => telegramOidcBase64Url($details['rsa']['n']),
            'e' => telegramOidcBase64Url($details['rsa']['e']),
        ],
    ];
}

function telegramOidcBase64Url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/**
 * @param  array{private: OpenSSLAsymmetricKey, jwk: array<string, string>}  $keyPair
 * @param  array<string, mixed>  $overrides
 */
function telegramOidcToken(array $keyPair, string $nonce, array $overrides = []): string
{
    $claims = array_merge([
        'iss' => 'https://oauth.telegram.org',
        'aud' => '123456789',
        'sub' => 'subject-specific-to-this-oidc-client',
        'iat' => now()->timestamp,
        'exp' => now()->addMinutes(5)->timestamp,
        'nonce' => $nonce,
        'id' => '997001',
        'name' => 'Trusted Administrator',
        'preferred_username' => 'trusted_admin',
    ], $overrides);

    return JWT::encode($claims, $keyPair['private'], 'RS256', 'test-key');
}

/**
 * @return array{state: string, nonce: string, code_verifier: string, redirect_uri: string, user_id: int, created_at: int}
 */
function telegramOidcTransaction(): array
{
    $transaction = session()->get('identity.telegram_oidc_transaction');

    if (! is_array($transaction)
        || ! is_string($transaction['state'] ?? null)
        || ! is_string($transaction['nonce'] ?? null)
        || ! is_string($transaction['code_verifier'] ?? null)
        || ! is_string($transaction['redirect_uri'] ?? null)
        || ! is_int($transaction['user_id'] ?? null)
        || ! is_int($transaction['created_at'] ?? null)) {
        throw new RuntimeException('OIDC transaction was not stored in the session.');
    }

    return $transaction;
}

function telegramOidcFakeProvider(string $idToken, array $jwk): void
{
    Cache::forget('identity.telegram_oidc.jwks');

    Http::fake([
        'https://oauth.telegram.org/token' => Http::response([
            'access_token' => 'unused-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'id_token' => $idToken,
        ]),
        'https://oauth.telegram.org/.well-known/jwks.json' => Http::response([
            'keys' => [$jwk],
        ]),
    ]);
}

it('routes trusted CRM password login to the Telegram second factor', function (): void {
    $user = telegramOidcRootUser();

    $this->post('https://crm.denagate.ir/login', [
        'email' => $user->email,
        'password' => 'strong-password',
    ])->assertRedirect('https://crm.denagate.ir/auth/telegram');

    $this->assertAuthenticatedAs($user, 'web');

    $this->get('https://crm.denagate.ir/admin/crm')
        ->assertRedirect('https://crm.denagate.ir/auth/telegram');
});

it('fails closed on the CRM host when Telegram OIDC credentials are missing', function (): void {
    config()->set('services.telegram_oidc.client_secret', null);

    $user = telegramOidcRootUser();

    $this->post('https://crm.denagate.ir/login', [
        'email' => $user->email,
        'password' => 'strong-password',
    ])->assertStatus(503);

    $this->assertGuest('web');
});

it('starts the standard Telegram authorization-code flow with state nonce and PKCE', function (): void {
    $user = telegramOidcRootUser();

    $response = $this->actingAs($user, 'web')
        ->post('https://crm.denagate.ir/auth/telegram');

    $response->assertRedirect();

    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $transaction = telegramOidcTransaction();

    expect($location)->toStartWith('https://oauth.telegram.org/auth?')
        ->and($query['client_id'] ?? null)->toBe('123456789')
        ->and($query['response_type'] ?? null)->toBe('code')
        ->and($query['scope'] ?? null)->toBe('openid profile')
        ->and($query['code_challenge_method'] ?? null)->toBe('S256')
        ->and($query['state'] ?? null)->toBe($transaction['state'])
        ->and($query['nonce'] ?? null)->toBe($transaction['nonce'])
        ->and($query['code_challenge'] ?? null)->toBe(
            telegramOidcBase64Url(hash('sha256', $transaction['code_verifier'], true)),
        )
        ->and($location)->not->toContain('test-client-secret');
});

it('verifies a real RSA-signed ID token and completes the CRM second factor', function (): void {
    $user = telegramOidcRootUser();
    $keyPair = telegramOidcKeyPair();

    $start = $this->actingAs($user, 'web')
        ->post('https://crm.denagate.ir/auth/telegram')
        ->assertRedirect();

    $transaction = telegramOidcTransaction();
    $token = telegramOidcToken($keyPair, $transaction['nonce']);
    telegramOidcFakeProvider($token, $keyPair['jwk']);

    $callback = $this->get('https://crm.denagate.ir/auth/telegram/callback?code=one-time-code&state='.$transaction['state']);

    $callback->assertRedirect('https://crm.denagate.ir/admin/crm')
        ->assertSessionHas('identity.telegram_second_factor.user_id', $user->id);

    $this->get('https://crm.denagate.ir/admin/crm')->assertOk();

    Http::assertSent(function (HttpRequest $request) use ($transaction): bool {
        return $request->url() === 'https://oauth.telegram.org/token'
            && $request->method() === 'POST'
            && $request['grant_type'] === 'authorization_code'
            && $request['client_id'] === '123456789'
            && $request['code_verifier'] === $transaction['code_verifier']
            && $request->hasHeader('Authorization');
    });

    Http::assertSentCount(2);
    $this->assertDatabaseHas('identity_audit_events', [
        'event' => 'telegram_oidc.verified',
        'actor_user_id' => $user->id,
        'subject_type' => 'user',
        'subject_id' => $user->id,
    ]);
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('telegram_accounts', 1);
});

it('rejects a valid Telegram identity that does not match the existing linked account', function (): void {
    $user = telegramOidcRootUser();
    $keyPair = telegramOidcKeyPair();

    $start = $this->actingAs($user, 'web')
        ->post('https://crm.denagate.ir/auth/telegram')
        ->assertRedirect();

    $transaction = telegramOidcTransaction();
    $token = telegramOidcToken($keyPair, $transaction['nonce'], ['id' => '997002']);
    telegramOidcFakeProvider($token, $keyPair['jwk']);

    $this->get('https://crm.denagate.ir/auth/telegram/callback?code=one-time-code&state='.$transaction['state'])
        ->assertRedirect('https://crm.denagate.ir/auth/telegram');

    expect(session()->get('identity.telegram_second_factor'))->toBeNull();
    $this->assertDatabaseMissing('identity_audit_events', ['event' => 'telegram_oidc.verified']);
});

it('rejects an ID token from an unexpected issuer', function (): void {
    $user = telegramOidcRootUser();
    $keyPair = telegramOidcKeyPair();

    $start = $this->actingAs($user, 'web')
        ->post('https://crm.denagate.ir/auth/telegram')
        ->assertRedirect();

    $transaction = telegramOidcTransaction();
    $token = telegramOidcToken($keyPair, $transaction['nonce'], [
        'iss' => 'https://attacker.example',
    ]);
    telegramOidcFakeProvider($token, $keyPair['jwk']);

    $this->get('https://crm.denagate.ir/auth/telegram/callback?code=one-time-code&state='.$transaction['state'])
        ->assertRedirect('https://crm.denagate.ir/auth/telegram');

    expect(session()->get('identity.telegram_second_factor'))->toBeNull();
});

it('rejects an ID token issued for a different client audience', function (): void {
    $user = telegramOidcRootUser();
    $keyPair = telegramOidcKeyPair();

    $this->actingAs($user, 'web')
        ->post('https://crm.denagate.ir/auth/telegram')
        ->assertRedirect();

    $transaction = telegramOidcTransaction();
    $token = telegramOidcToken($keyPair, $transaction['nonce'], [
        'aud' => 'another-client',
    ]);
    telegramOidcFakeProvider($token, $keyPair['jwk']);

    $this->get('https://crm.denagate.ir/auth/telegram/callback?code=one-time-code&state='.$transaction['state'])
        ->assertRedirect('https://crm.denagate.ir/auth/telegram');

    expect(session()->get('identity.telegram_second_factor'))->toBeNull();
});

it('rejects an ID token with an invalid nonce', function (): void {
    $user = telegramOidcRootUser();
    $keyPair = telegramOidcKeyPair();

    $start = $this->actingAs($user, 'web')
        ->post('https://crm.denagate.ir/auth/telegram')
        ->assertRedirect();

    $transaction = telegramOidcTransaction();
    $token = telegramOidcToken($keyPair, 'different-nonce', []);
    telegramOidcFakeProvider($token, $keyPair['jwk']);

    $this->get('https://crm.denagate.ir/auth/telegram/callback?code=one-time-code&state='.$transaction['state'])
        ->assertRedirect('https://crm.denagate.ir/auth/telegram');

    expect(session()->get('identity.telegram_second_factor'))->toBeNull();
});

it('rejects an expired Telegram ID token', function (): void {
    $user = telegramOidcRootUser();
    $keyPair = telegramOidcKeyPair();

    $this->actingAs($user, 'web')
        ->post('https://crm.denagate.ir/auth/telegram')
        ->assertRedirect();

    $transaction = telegramOidcTransaction();
    $token = telegramOidcToken($keyPair, $transaction['nonce'], [
        'exp' => now()->subMinute()->timestamp,
    ]);
    telegramOidcFakeProvider($token, $keyPair['jwk']);

    $this->get('https://crm.denagate.ir/auth/telegram/callback?code=one-time-code&state='.$transaction['state'])
        ->assertRedirect('https://crm.denagate.ir/auth/telegram');

    expect(session()->get('identity.telegram_second_factor'))->toBeNull();
});

it('rejects an ID token whose signature does not match the published key', function (): void {
    $user = telegramOidcRootUser();
    $signingKey = telegramOidcKeyPair();
    $publishedKey = telegramOidcKeyPair();

    $start = $this->actingAs($user, 'web')
        ->post('https://crm.denagate.ir/auth/telegram')
        ->assertRedirect();

    $transaction = telegramOidcTransaction();
    $token = telegramOidcToken($signingKey, $transaction['nonce']);
    telegramOidcFakeProvider($token, $publishedKey['jwk']);

    $this->get('https://crm.denagate.ir/auth/telegram/callback?code=one-time-code&state='.$transaction['state'])
        ->assertRedirect('https://crm.denagate.ir/auth/telegram');

    expect(session()->get('identity.telegram_second_factor'))->toBeNull();
});

it('does not consume a pending OIDC transaction when the callback state is invalid', function (): void {
    $user = telegramOidcRootUser();
    $keyPair = telegramOidcKeyPair();

    $this->actingAs($user, 'web')
        ->post('https://crm.denagate.ir/auth/telegram')
        ->assertRedirect();

    $transaction = telegramOidcTransaction();

    $this->get('https://crm.denagate.ir/auth/telegram/callback?code=wrong-code&state=invalid-state')
        ->assertRedirect('https://crm.denagate.ir/auth/telegram');

    expect(session()->get('identity.telegram_oidc_transaction.state'))->toBe($transaction['state']);

    $token = telegramOidcToken($keyPair, $transaction['nonce']);
    telegramOidcFakeProvider($token, $keyPair['jwk']);

    $this->get('https://crm.denagate.ir/auth/telegram/callback?code=one-time-code&state='.$transaction['state'])
        ->assertRedirect('https://crm.denagate.ir/admin/crm')
        ->assertSessionHas('identity.telegram_second_factor.telegram_user_id', '997001');
});

it('consumes the OIDC state once and rejects callback replay', function (): void {
    $user = telegramOidcRootUser();
    $keyPair = telegramOidcKeyPair();

    $start = $this->actingAs($user, 'web')
        ->post('https://crm.denagate.ir/auth/telegram')
        ->assertRedirect();

    $transaction = telegramOidcTransaction();
    $token = telegramOidcToken($keyPair, $transaction['nonce']);
    telegramOidcFakeProvider($token, $keyPair['jwk']);

    $callbackUrl = 'https://crm.denagate.ir/auth/telegram/callback?code=one-time-code&state='.$transaction['state'];

    $this->get($callbackUrl)->assertRedirect('https://crm.denagate.ir/admin/crm');
    session()->forget('identity.telegram_second_factor');

    $this->get($callbackUrl)->assertRedirect('https://crm.denagate.ir/auth/telegram');

    expect(session()->get('identity.telegram_second_factor'))->toBeNull();
});

it('keeps Telegram OIDC scoped to CRM and preserves Live Mini App authentication', function (): void {
    $this->get('https://example.test/auth/telegram')->assertNotFound();

    $liveTelegramRoute = Route::getRoutes()->getByName('denagate-live.telegram.login');

    expect($liveTelegramRoute)->not->toBeNull()
        ->and($liveTelegramRoute->getActionName())->toBe(LiveAuthController::class.'@login');
});

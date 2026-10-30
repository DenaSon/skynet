<?php

declare(strict_types=1);

use App\Interfaces\Web\Live\ValidateTelegramInitData;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('denagate_live.enabled', true);
    config()->set('nutgram.token', '123:admin-bot');
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram.sales.token', '321:sales-bot');
    Role::findOrCreate('reseller', 'web');
});

function liveSignedData(int $telegramId, ?int $date = null, string $token = '321:sales-bot', ?string $signature = null): string
{
    $fields = [
        'auth_date' => (string) ($date ?? time()),
        'query_id' => 'AA-test-session',
        'user' => json_encode(['id' => $telegramId, 'first_name' => 'Reseller'], JSON_THROW_ON_ERROR),
    ];
    if ($signature !== null) {
        $fields['signature'] = $signature;
    }
    ksort($fields, SORT_STRING);
    $check = implode("\n", array_map(
        static fn (string $key, string $value): string => $key.'='.$value,
        array_keys($fields), array_values($fields),
    ));
    $secret = hash_hmac('sha256', $token, 'WebAppData', true);
    $fields['hash'] = hash_hmac('sha256', $check, $secret);

    return http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
}

function liveReseller(int $telegramId): User
{
    $user = User::factory()->create();
    $user->assignRole('reseller');
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);

    return $user;
}

it('keeps Live fully invisible until enabled', function (): void {
    config()->set('denagate_live.enabled', false);
    $this->get('https://live.denagate.ir/')->assertNotFound();
    $this->post('https://live.denagate.ir/auth/telegram', ['init_data' => liveSignedData(445566)])->assertNotFound();
});

it('shows Telegram-only entry with no direct browser credential or signup form', function (): void {
    $this->get('https://live.denagate.ir/')->assertOk()
        ->assertSee('پنل نمایندگی')
        ->assertSee('telegram-web-app.js')
        ->assertDontSee('type="password"', false);
});

it('accepts a signed Sales Mini App identity and creates a reseller-only session', function (): void {
    $user = liveReseller(445566);
    $this->get('https://live.denagate.ir/dashboard')->assertRedirect();

    $this->post('https://live.denagate.ir/auth/telegram', ['init_data' => liveSignedData(445566)])
        ->assertRedirect();
    $this->assertAuthenticatedAs($user, 'web');

    $this->get('https://live.denagate.ir/dashboard')->assertOk()->assertSee($user->name);
    $this->post('https://live.denagate.ir/logout')->assertRedirect();
    $this->assertGuest('web');
    $this->get('https://live.denagate.ir/dashboard')->assertRedirect();
});

it('rejects tampering, Admin Bot signatures, stale data, future timestamps and duplicate fields', function (): void {
    liveReseller(445566);
    $valid = liveSignedData(445566);
    $tampered = substr($valid, 0, -1).(substr($valid, -1) === '0' ? '1' : '0');

    foreach ([
        $tampered,
        liveSignedData(445566, null, '123:admin-bot'),
        liveSignedData(445566, time() - 3600),
        liveSignedData(445566, time() + 120),
        $valid.'&auth_date=1',
        'user=not-signed',
    ] as $payload) {
        $this->post('https://live.denagate.ir/auth/telegram', ['init_data' => $payload])
            ->assertForbidden();
    }
    $this->assertGuest('web');
});

it('denies unlinked, ordinary and inactive accounts', function (): void {
    $this->post('https://live.denagate.ir/auth/telegram', ['init_data' => liveSignedData(999000)])
        ->assertForbidden();

    $member = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $member->id, 'telegram_user_id' => 999001]);
    $this->post('https://live.denagate.ir/auth/telegram', ['init_data' => liveSignedData(999001)])
        ->assertForbidden();

    $inactive = liveReseller(999002);
    $inactive->forceFill(['is_active' => false])->save();
    $this->post('https://live.denagate.ir/auth/telegram', ['init_data' => liveSignedData(999002)])
        ->assertForbidden();
    $this->assertGuest('web');
});

it('revokes the Live session on role removal or account deactivation', function (): void {
    $user = liveReseller(445566);
    $this->post('https://live.denagate.ir/auth/telegram', ['init_data' => liveSignedData(445566)])
        ->assertRedirect();
    $user->syncRoles(['member']);

    $this->get('https://live.denagate.ir/dashboard')->assertForbidden();
    $this->assertGuest('web');

    $user->syncRoles(['reseller']);
    $this->post('https://live.denagate.ir/auth/telegram', ['init_data' => liveSignedData(445566)])
        ->assertRedirect();
    $user->forceFill(['is_active' => false])->save();
    $this->get('https://live.denagate.ir/dashboard')->assertForbidden();
    $this->assertGuest('web');
});

it('does not accept a preexisting password-authenticated web session as Mini App proof', function (): void {
    $user = liveReseller(445566);
    $this->actingAs($user)->get('https://live.denagate.ir/dashboard')->assertRedirect();
});

it('fails closed without configured distinct Sales bot credentials', function (): void {
    liveReseller(445566);
    config()->set('telegram.sales.token', null);
    $this->post('https://live.denagate.ir/auth/telegram', ['init_data' => liveSignedData(445566)])
        ->assertStatus(503);
    config()->set('telegram.sales.token', '123:admin-bot');
    $this->post('https://live.denagate.ir/auth/telegram', ['init_data' => liveSignedData(445566)])
        ->assertStatus(503);
});

it('validates raw Telegram query strings rather than trusting initDataUnsafe or arbitrary user IDs', function (): void {
    $validator = app(ValidateTelegramInitData::class);
    expect($validator->userId(liveSignedData(445566), '321:sales-bot'))->toBe(445566)
        ->and($validator->userId('user='.urlencode('{"id":445566}'), '321:sales-bot'))->toBeNull();
});

it('generates HTTPS Mini App form and redirects when Runflare forwards an HTTP-origin request', function (): void {
    $this->get('http://live.denagate.ir/')
        ->assertOk()
        ->assertSee('action="https://live.denagate.ir/auth/telegram"', false)
        ->assertDontSee('action="http://live.denagate.ir/auth/telegram"', false);

    // The host-scoped workaround must not leak into subsequent URL generation.
    expect(url('/after-live-request'))->toBe('http://live.denagate.ir/after-live-request');

    $user = liveReseller(787878);

    $this->post('http://live.denagate.ir/auth/telegram', ['init_data' => liveSignedData(787878)])
        ->assertRedirect('https://live.denagate.ir/dashboard');

    $this->get('http://live.denagate.ir/dashboard')
        ->assertOk()
        ->assertSee('action="https://live.denagate.ir/logout"', false)
        ->assertDontSee('action="http://live.denagate.ir/logout"', false);

    $this->get('http://live.denagate.ir/')
        ->assertRedirect('https://live.denagate.ir/dashboard');

    $this->post('http://live.denagate.ir/logout')
        ->assertRedirect('https://live.denagate.ir');

    $this->assertGuest('web');
});

it('accepts Telegram initData containing signature as a signed HMAC field', function (): void {
    $validator = app(ValidateTelegramInitData::class);
    $telegramId = 551177;
    $signed = liveSignedData($telegramId, signature: 'telegram-ed25519-signature-sample');

    // The optional signature must be included in bot-token HMAC data-check-string.
    expect($validator->userId($signed, '321:sales-bot'))->toBe($telegramId);

    // Changing or stripping the field must invalidate the original HMAC.
    $modified = str_replace('telegram-ed25519-signature-sample', 'forged-signature', $signed);
    $stripped = implode('&', array_filter(
        explode('&', $signed),
        static fn (string $part): bool => ! str_starts_with($part, 'signature='),
    ));

    expect($validator->userId($modified, '321:sales-bot'))->toBeNull()
        ->and($validator->userId($stripped, '321:sales-bot'))->toBeNull()
        ->and($validator->userId($signed, '123:admin-bot'))->toBeNull();
});

it('logs in an existing active reseller using Telegram initData that contains signature', function (): void {
    $user = liveReseller(551177);

    $this->post('http://live.denagate.ir/auth/telegram', [
        'init_data' => liveSignedData(551177, signature: 'telegram-ed25519-signature-sample'),
    ])->assertRedirect('https://live.denagate.ir/dashboard');

    $this->assertAuthenticatedAs($user, 'web');
    $this->get('http://live.denagate.ir/dashboard')->assertOk()->assertSee($user->name);
});

<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Trial\Application\ReserveFreeTrial;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('trial.enabled', true);
    config()->set('trial.daily_limit', 20);
    config()->set('trial.phone_hmac_key', str_repeat('K', 48));
    Role::findOrCreate('member', 'web');
    Role::findOrCreate('reseller', 'web');
});

function freeTrialMember(int $telegramId): User
{
    $user = User::factory()->create();
    $user->assignRole('member');
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);

    return $user;
}

it('reserves once and stores only a fingerprint, without money or provider effects', function (): void {
    $member = freeTrialMember(9201001);
    $id = app(ReserveFreeTrial::class)->handle($member->id, 9201001, 9201001, '09123456789');

    $row = DB::table('trial_claims')->where('id', $id)->first();
    expect($row)->not->toBeNull()
        ->and($row->state)->toBe('reserved')
        ->and($row->phone_fingerprint)->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($row->phone_fingerprint)->not->toContain('09123456789')
        ->and(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('provisioning_operations')->count())->toBe(0);
});

it('refuses another claim for the same user or normalized phone on a second identity', function (): void {
    $one = freeTrialMember(9201002);
    $two = freeTrialMember(9201003);
    app(ReserveFreeTrial::class)->handle($one->id, 9201002, 9201002, '09123456789');

    expect(fn () => app(ReserveFreeTrial::class)->handle($one->id, 9201002, 9201002, '+989123456789'))
        ->toThrow(ValidationException::class);
    expect(fn () => app(ReserveFreeTrial::class)->handle($two->id, 9201003, 9201003, '989123456789'))
        ->toThrow(ValidationException::class);
    expect(DB::table('trial_claims')->count())->toBe(1);
});

it('rejects foreign, missing or mismatched contact ids and inactive or reseller accounts', function (): void {
    $member = freeTrialMember(9201004);
    $claims = app(ReserveFreeTrial::class);
    foreach ([null, 9202004] as $invalidId) {
        expect(fn () => $claims->handle($member->id, 9201004, $invalidId, '09123456789'))
            ->toThrow(ValidationException::class);
    }
    expect(fn () => $claims->handle($member->id, 9299999, 9299999, '09123456789'))
        ->toThrow(AuthorizationException::class);

    $member->syncRoles(['reseller']);
    expect(fn () => $claims->handle($member->id, 9201004, 9201004, '09123456789'))
        ->toThrow(AuthorizationException::class);
    expect(DB::table('trial_claims')->count())->toBe(0);
});

it('rejects previously paid buyers even when their old order is no longer fulfilled', function (): void {
    $buyer = freeTrialMember(9201005);
    DB::table('sales_orders')->insert([
        'buyer_user_id' => $buyer->id, 'idempotency_key' => bin2hex(random_bytes(16)),
        'product_slug' => 'legacy', 'product_name' => 'Plan',
        'duration_days' => 30, 'traffic_gb' => 10, 'public_price_rials' => 1000,
        'discount_percent' => 0, 'discount_rials' => 0, 'total_price_rials' => 1000,
        'status' => 'fulfilled', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => app(ReserveFreeTrial::class)->handle($buyer->id, 9201005, 9201005, '09123456789'))
        ->toThrow(ValidationException::class);
    expect(DB::table('trial_claims')->count())->toBe(0);
});

it('honors the disabled gate and the atomic daily allowance', function (): void {
    $one = freeTrialMember(9201006);
    $two = freeTrialMember(9201007);
    config()->set('trial.enabled', false);
    expect(fn () => app(ReserveFreeTrial::class)->handle($one->id, 9201006, 9201006, '09123456789'))
        ->toThrow(ValidationException::class);
    config()->set('trial.enabled', true);
    config()->set('trial.daily_limit', 1);
    app(ReserveFreeTrial::class)->handle($one->id, 9201006, 9201006, '09123456789');
    expect(fn () => app(ReserveFreeTrial::class)->handle($two->id, 9201007, 9201007, '09125556677'))
        ->toThrow(ValidationException::class);
    expect(DB::table('trial_claims')->count())->toBe(1);
});

it('fails closed for missing weak or reused keys and invalid daily limits', function ($setting, $value): void {
    $member = freeTrialMember(9201030);
    if ($value === 'APP_KEY') {
        $value = config('app.key');
    }
    config()->set($setting, $value);
    expect(fn () => app(ReserveFreeTrial::class)->handle($member->id, 9201030, 9201030, '09123456789'))
        ->toThrow(ValidationException::class);
    expect(DB::table('trial_claims')->count())->toBe(0);
})->with([
    ['trial.phone_hmac_key', null], ['trial.phone_hmac_key', 'short'],
    ['trial.phone_hmac_key', 'APP_KEY'], ['trial.daily_limit', 0], ['trial.daily_limit', 1001],
]);

it('denies disabled and custom privileged or mixed-role identities', function (): void {
    $member = freeTrialMember(9201031);
    $member->is_active = false;
    $member->save();
    expect(fn () => app(ReserveFreeTrial::class)->handle($member->id, 9201031, 9201031, '09123456789'))
        ->toThrow(AuthorizationException::class);
    $member->is_active = true;
    $member->save();
    Role::findOrCreate('support-team', 'web');
    $member->assignRole('support-team');
    expect(fn () => app(ReserveFreeTrial::class)->handle($member->id, 9201031, 9201031, '09123456789'))
        ->toThrow(AuthorizationException::class);
});

it('retains telegram identity deduplication after relinking and username changes', function (): void {
    $one = freeTrialMember(9201032);
    app(ReserveFreeTrial::class)->handle($one->id, 9201032, 9201032, '09123456789');
    DB::table('telegram_accounts')->where('user_id', $one->id)->delete();
    $two = freeTrialMember(9201032);
    expect(fn () => app(ReserveFreeTrial::class)->handle($two->id, 9201032, 9201032, '09125556677'))
        ->toThrow(ValidationException::class);
    expect(DB::table('trial_claims')->count())->toBe(1);
});

it('normalizes native contact variants and rejects malformed input without storing a phone', function (): void {
    $member = freeTrialMember(9201033);
    $action = app(ReserveFreeTrial::class);
    $expected = $action->contact($member->id, 9201033, 9201033, '09123456789');
    foreach (['+989123456789', '00989123456789', '989123456789', '9123456789', '+98 (912) 345-6789'] as $phone) {
        expect($action->contact($member->id, 9201033, 9201033, $phone))->toBe($expected);
    }
    expect($expected['masked'])->toBe('+98******6789');
    foreach (['', 'abc', '+00000000', str_repeat('1', 41), '0912<script>'] as $phone) {
        expect(fn () => $action->contact($member->id, 9201033, 9201033, $phone))->toThrow(ValidationException::class);
    }
});

it('counts reservations atomically and resets only the Tehran calendar allowance', function (): void {
    $this->travelTo(now()->setDate(2026, 10, 26)->setTime(20, 29, 59)->utc());
    config()->set('trial.daily_limit', 1);
    $one = freeTrialMember(9201034);
    app(ReserveFreeTrial::class)->handle($one->id, 9201034, 9201034, '09123456789');
    $this->travel(2)->seconds();
    $two = freeTrialMember(9201035);
    app(ReserveFreeTrial::class)->handle($two->id, 9201035, 9201035, '09125556677');
    expect(DB::table('trial_admission_days')->count())->toBe(2)
        ->and(DB::table('trial_admission_days')->sum('reserved_count'))->toBe(2);
});

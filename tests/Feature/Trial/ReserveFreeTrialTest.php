<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Trial\Application\ReserveFreeTrial;
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
        ->toThrow(ValidationException::class);

    $member->syncRoles(['reseller']);
    expect(fn () => $claims->handle($member->id, 9201004, 9201004, '09123456789'))
        ->toThrow(ValidationException::class);
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

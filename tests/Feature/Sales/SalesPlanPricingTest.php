<?php

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\CalculatePlanQuote;
use App\Modules\Sales\Application\CalculateQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Sales\Application\CreatePendingOrder;
use App\Modules\Sales\Application\ManagePlanPricing;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\PlanDefinition;
use App\Modules\Sales\Application\PlanPricingDefinition;
use App\Modules\Sales\Application\RevalidatePlanQuote;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function b12Root(): User
{
    config()->set('super_admin.telegram_ids', ['992201', '992202']);
    $root = User::factory()->create();
    $root->assignRole('super-admin');
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 992201]);

    return $root;
}

function b12Plan(
    User $root, string $slug, string $kind = 'hybrid',
    string $mode = 'preset', string $audience = 'both',
): int {
    $plan = app(ManagePlans::class)->create($root->id, new PlanDefinition(
        productSlug: 'vpn-access',
        slug: $slug,
        name: 'Test plan '.$slug,
        kind: $kind,
        mode: $mode,
        audience: $audience,
        trafficGb: $kind === 'time' || $mode === 'builder' ? null : 30,
        durationDays: $mode === 'builder' ? null : 30,
        minGb: $kind === 'time' ? null : 1,
        maxGb: $kind === 'time' ? null : 100,
        minDays: 7,
        maxDays: 180,
        sortOrder: 20,
    ));
    $token = app(ManagePlans::class)->requestChange($root->id, $plan->id, 1, 'activate');
    app(ManagePlans::class)->confirmChange($root->id, $token);

    return $plan->id;
}

function b12Price(
    string $method = 'formula', ?int $fixed = null, int $base = 50_000,
    int $gb = 2_000, int $day = 1_000, int $discount = 5, int $floor = 1,
): PlanPricingDefinition {
    return new PlanPricingDefinition($method, $fixed, $base, $gb, $day, $discount, $floor);
}

function b12Publish(User $root, int $planId, PlanPricingDefinition $pricing, int $planRev = 2): void
{
    $action = app(ManagePlanPricing::class);
    $revision = $action->configure($root->id, $planId, $planRev, 0, $pricing);
    $token = $action->requestChange($root->id, $planId, $planRev, $revision, 'activate');
    $action->confirmChange($root->id, $token);
}

beforeEach(function (): void {
    app(ConfigureProduct::class)->handle('vpn-access', 'VPN Access');
    Role::findOrCreate('reseller', 'web');
});

it('calculates fixed public and single reseller-discounted preset prices in integer rials', function (): void {
    $root = b12Root();
    Role::findOrCreate('reseller', 'web');
    $member = User::factory()->create();
    $reseller = User::factory()->create();
    $reseller->assignRole('reseller');
    $plan = b12Plan($root, 'standard-fixed');

    b12Publish($root, $plan, b12Price('fixed', 300_001, 0, 0, 0, 7, 1));
    $calculator = app(CalculatePlanQuote::class);
    $public = $calculator->handle($member->id, $plan, 30, 30);
    $partner = $calculator->handle($reseller->id, $plan, 30, 30);

    expect($public->publicPriceRials)->toBe(300_001)
        ->and($public->totalPriceRials)->toBe(300_001)
        ->and($public->discountPercent)->toBe(0)
        ->and($partner->discountPercent)->toBe(7)
        ->and($partner->discountRials)->toBe(21_000)
        ->and($partner->totalPriceRials)->toBe(279_001)
        ->and($partner->pricingMethod)->toBe('fixed')
        ->and($partner->planRevision)->toBe(2)
        ->and($partner->pricingRevision)->toBe(2);
});

it('calculates reseller-only flexible volume and duration without floating point', function (): void {
    $root = b12Root();
    Role::findOrCreate('reseller', 'web');
    $member = User::factory()->create();
    $reseller = User::factory()->create();
    $reseller->assignRole('reseller');

    $plan = b12Plan($root, 'custom-hybrid', 'hybrid', 'builder', 'reseller');
    b12Publish($root, $plan, b12Price('formula', null, 50_000, 2_000, 1_000, 10));
    $calculator = app(CalculatePlanQuote::class);
    $quote = $calculator->handle($reseller->id, $plan, 45, 70);

    expect($quote->publicPriceRials)->toBe(235_000)
        ->and($quote->discountRials)->toBe(23_500)
        ->and($quote->totalPriceRials)->toBe(211_500)
        ->and($quote->trafficGb)->toBe(70)
        ->and($quote->durationDays)->toBe(45)
        ->and(app(RevalidatePlanQuote::class)->handle($reseller->id, $quote))->toEqual($quote);

    expect(fn () => $calculator->handle($member->id, $plan, 45, 70))->toThrow(ValidationException::class);
    foreach ([[6, 20], [181, 20], [30, 0], [30, 101], [30, null]] as [$days, $volume]) {
        expect(fn () => $calculator->handle($reseller->id, $plan, $days, $volume))
            ->toThrow(ValidationException::class);
    }
});

it('supports true unlimited time plans without interpreting null traffic as free', function (): void {
    $root = b12Root();
    $reseller = User::factory()->create();
    $reseller->assignRole('reseller');
    $plan = b12Plan($root, 'unlimited-30', 'time', 'preset', 'reseller');

    b12Publish($root, $plan, b12Price('formula', null, 80_000, 0, 10_000, 0));
    $quote = app(CalculatePlanQuote::class)->handle($reseller->id, $plan, 30, null);

    expect($quote->trafficGb)->toBeNull()
        ->and($quote->publicPriceRials)->toBe(380_000)
        ->and($quote->totalPriceRials)->toBe(380_000);

    expect(fn () => app(CalculatePlanQuote::class)->handle($reseller->id, $plan, 30, 0))->toThrow(ValidationException::class);
    expect(fn () => app(CalculatePlanQuote::class)->handle($reseller->id, $plan, 31, null))->toThrow(ValidationException::class);
});

it('keeps volume-only plans independent of expiration day-based charges', function (): void {
    $root = b12Root();
    $member = User::factory()->create();
    $plan = b12Plan($root, 'volume-180', 'volume');
    b12Publish($root, $plan, b12Price('formula', null, 100_000, 3_000, 0, 5));
    $quote = app(CalculatePlanQuote::class)->handle($member->id, $plan, 30, 30);

    expect($quote->totalPriceRials)->toBe(190_000);
});

it('blocks unpriced plans, disabled tariffs, incorrect durations and stale repricing', function (): void {
    $root = b12Root();
    $buyer = User::factory()->create();
    $plan = b12Plan($root, 'repricing-plan');
    $calc = app(CalculatePlanQuote::class);
    $manage = app(ManagePlanPricing::class);

    expect(fn () => $calc->handle($buyer->id, $plan, 30, 30))->toThrow(ValidationException::class);

    b12Publish($root, $plan, b12Price());
    $quote = $calc->handle($buyer->id, $plan, 30, 30);

    expect(fn () => $calc->handle($buyer->id, $plan, 15, 30))->toThrow(ValidationException::class);
    expect(fn () => $calc->handle($buyer->id, $plan, 30, 25))->toThrow(ValidationException::class);

    // Editing a published price first makes it DRAFT: no live surprise price.
    $rev = $manage->configure($root->id, $plan, 2, 2, b12Price('formula', null, 200_000));
    expect($rev)->toBe(3);
    expect(fn () => $calc->handle($buyer->id, $plan, 30, 30))->toThrow(ValidationException::class);

    $token = $manage->requestChange($root->id, $plan, 2, 3, 'activate');
    $manage->confirmChange($root->id, $token);

    expect(fn () => app(RevalidatePlanQuote::class)->handle($buyer->id, $quote))
        ->toThrow(ValidationException::class)
        ->and($calc->handle($buyer->id, $plan, 30, 30)->publicPriceRials)->toBe(290_000);
});

it('invalidates published prices after a plan revision or role change', function (): void {
    $root = b12Root();
    $buyer = User::factory()->create();
    $plan = b12Plan($root, 'revision-plan');
    b12Publish($root, $plan, b12Price());
    $quote = app(CalculatePlanQuote::class)->handle($buyer->id, $plan, 30, 30);

    $definition = new PlanDefinition(
        'vpn-access', 'revision-plan', 'Changed name', 'hybrid', 'preset', 'both',
        30, 30, 1, 100, 7, 180,
    );
    app(ManagePlans::class)->update($root->id, $plan, 2, $definition);

    expect(fn () => app(CalculatePlanQuote::class)->handle($buyer->id, $plan, 30, 30))
        ->toThrow(ValidationException::class);
    expect(fn () => app(RevalidatePlanQuote::class)->handle($buyer->id, $quote))
        ->toThrow(ValidationException::class);
    expect(fn () => app(ManagePlanPricing::class)->requestChange($root->id, $plan, 3, 2, 'deactivate'))
        ->toThrow(ValidationException::class);
});

it('requires Super Admin for price mutations and fresh actor-bound single-use confirmation', function (): void {
    $root = b12Root();
    $other = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $plan = b12Plan($root, 'root-pricing');
    $manage = app(ManagePlanPricing::class);

    expect(fn () => $manage->configure($admin->id, $plan, 2, 0, b12Price()))
        ->toThrow(AuthorizationException::class);

    $priceRev = $manage->configure($root->id, $plan, 2, 0, b12Price());
    expect(fn () => $manage->configure($root->id, $plan, 2, 0, b12Price()))
        ->toThrow(ValidationException::class);

    $other->assignRole('super-admin');
    TelegramAccount::query()->create(['user_id' => $other->id, 'telegram_user_id' => 992202]);
    $token = $manage->requestChange($root->id, $plan, 2, $priceRev, 'activate');

    expect(fn () => $manage->confirmChange($other->id, $token))->toThrow(ValidationException::class);
    $rev = $manage->confirmChange($root->id, $token);
    expect($rev)->toBe(2);
    expect(fn () => $manage->confirmChange($root->id, $token))->toThrow(ValidationException::class);
    expect(DB::table('sales_plan_price_audits')->count())->toBe(2);

    $disable = $manage->requestChange($root->id, $plan, 2, 2, 'deactivate');
    config()->set('super_admin.telegram_ids', ['992202', '992203']);
    expect(fn () => $manage->confirmChange($root->id, $disable))->toThrow(AuthorizationException::class);
});

it('rejects expired tokens and checks latest tariff revision at confirmation', function (): void {
    $root = b12Root();
    $plan = b12Plan($root, 'expire-price');
    $manage = app(ManagePlanPricing::class);

    $manage->configure($root->id, $plan, 2, 0, b12Price());
    $expired = $manage->requestChange($root->id, $plan, 2, 1, 'activate');
    $this->travel(4)->minutes();
    expect(fn () => $manage->confirmChange($root->id, $expired))->toThrow(ValidationException::class);
    $this->travelBack();

    $stale = $manage->requestChange($root->id, $plan, 2, 1, 'activate');
    $manage->configure($root->id, $plan, 2, 1, b12Price('formula', null, 60_000));
    expect(fn () => $manage->confirmChange($root->id, $stale))->toThrow(ValidationException::class);
    expect(DB::table('sales_plan_prices')->where('plan_id', $plan)->value('status'))->toBe('draft');
});

it('rejects invalid price shapes, stacking, zero totals and overflowing formulas', function (): void {
    $root = b12Root();
    $plan = b12Plan($root, 'bad-price');
    $time = b12Plan($root, 'bad-time', 'time');
    $volume = b12Plan($root, 'bad-volume', 'volume');
    $builder = b12Plan($root, 'bad-builder', 'hybrid', 'builder', 'reseller');
    $manage = app(ManagePlanPricing::class);

    foreach ([
        [$plan, b12Price('fixed', 0, 0, 0, 0)],
        [$plan, b12Price('fixed', 100_000, 1, 0, 0)],
        [$plan, b12Price('formula', 10_000)],
        [$plan, b12Price('formula', null, 0, 0, 0)],
        [$plan, b12Price('formula', null, -1)],
        [$plan, b12Price('formula', null, 50_000, 2_000, 1_000, 51)],
        [$plan, b12Price('formula', null, 50_000, 2_000, 1_000, 5, 999_000_000)],
        [$plan, b12Price('formula', null, 1_000_000_000_000, 1_000_000_000_000)],
        [$time, b12Price('formula', null, 5_000, 1_000, 0)],
        [$volume, b12Price('formula', null, 5_000, 1_000, 100)],
        [$builder, b12Price('fixed', 80_000, 0, 0, 0)],
    ] as [$id, $def]) {
        expect(fn () => $manage->configure($root->id, $id, 2, 0, $def))
            ->toThrow(ValidationException::class);
    }
    expect(DB::table('sales_plan_prices')->count())->toBe(0);
});

it('rejects selected builder options below configured minimum and mismatched buyer quotes', function (): void {
    $root = b12Root();
    $buyer = User::factory()->create();
    $reseller = User::factory()->create();
    $reseller->assignRole('reseller');
    $plan = b12Plan($root, 'floor-builder', 'hybrid', 'builder', 'reseller');

    b12Publish($root, $plan, b12Price('formula', null, 10_000, 500, 100, 10, 50_000));
    expect(fn () => app(CalculatePlanQuote::class)->handle($reseller->id, $plan, 7, 1))
        ->toThrow(ValidationException::class);

    $good = app(CalculatePlanQuote::class)->handle($reseller->id, $plan, 60, 100);
    expect($good->totalPriceRials)->toBe(59_400);
    expect(fn () => app(RevalidatePlanQuote::class)->handle($buyer->id, $good))
        ->toThrow(ValidationException::class);
    $reseller->syncRoles(['member']);
    expect(fn () => app(RevalidatePlanQuote::class)->handle($reseller->id, $good))
        ->toThrow(ValidationException::class);
});

it('does not mutate legacy F09 quote and order flow, wallets or provider', function (): void {
    $root = b12Root();
    $plan = b12Plan($root, 'isolated-pricing');
    b12Publish($root, $plan, b12Price());
    app(ConfigureRate::class)->handle('vpn-access', 30, 100_000, 20_000);
    $buyer = User::factory()->create();
    Http::preventStrayRequests();
    Http::fake();

    $old = app(CalculateQuote::class)->handle($buyer->id, 'vpn-access', 30, 10);
    $order = app(CreatePendingOrder::class)->handle($buyer->id, $old, bin2hex(random_bytes(16)));
    $new = app(CalculatePlanQuote::class)->handle($buyer->id, $plan, 30, 30);

    expect($old->totalPriceRials)->toBe(300_000)
        ->and($order->status)->toBe('pending_payment')
        ->and($new->totalPriceRials)->toBe(140_000)
        ->and(DB::table('sales_orders')->where('id', $order->id)->value('plan_id'))->toBeNull()
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(0)
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(0);

    Http::assertNothingSent();
});

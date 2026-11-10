<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\CalculatePlanQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ListDemoPublishedPresets;
use App\Modules\Sales\Application\ManagePlanPricing;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\PlanDefinition;
use App\Modules\Sales\Application\PlanPricingDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('denagate_live.enabled', true);
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram.sales.token', '321:sales-bot');
    config()->set('telegram.sales.bot_username', 'DenaGateSalesBot');
    config()->set('nutgram.token', '123:admin-bot');
    config()->set('super_admin.telegram_ids', ['988881', '988889']);
    foreach (['member', 'reseller', 'super-admin'] as $role) {
        Role::findOrCreate($role, 'web');
    }
    Http::preventStrayRequests();
});

function demo2SignedData(int $telegramId): string
{
    $fields = [
        'auth_date' => (string) time(),
        'query_id' => 'AA-demo-catalog',
        'user' => json_encode(['id' => $telegramId, 'first_name' => 'Demo'], JSON_THROW_ON_ERROR),
    ];
    ksort($fields, SORT_STRING);
    $check = implode("\n", array_map(
        static fn (string $key, string $value): string => $key.'='.$value,
        array_keys($fields), array_values($fields),
    ));
    $fields['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', '321:sales-bot', 'WebAppData', true));

    return http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
}

function demo2Root(): User
{
    $root = User::factory()->create();
    $root->assignRole('super-admin');
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 988881]);
    app(ConfigureProduct::class)->handle('demo-catalog', 'Demo VPN');

    return $root;
}

function demo2Plan(User $root, string $slug, string $audience = 'both', string $mode = 'preset', int $discount = 17, bool $formula = false): int
{
    $plan = app(ManagePlans::class)->create($root->id, new PlanDefinition(
        productSlug: 'demo-catalog',
        slug: $slug,
        name: 'پلن '.$slug,
        kind: 'hybrid',
        mode: $mode,
        audience: $audience,
        trafficGb: $mode === 'preset' ? 30 : null,
        durationDays: $mode === 'preset' ? 30 : null,
        minGb: 1,
        maxGb: 100,
        minDays: 7,
        maxDays: 180,
    ));
    $manage = app(ManagePlans::class);
    $manage->confirmChange($root->id, $manage->requestChange($root->id, $plan->id, 1, 'activate'));

    $pricing = new PlanPricingDefinition(
        method: $formula || $mode !== 'preset' ? 'formula' : 'fixed',
        fixedPriceRials: $formula || $mode !== 'preset' ? null : 1_000_003,
        basePriceRials: $formula || $mode !== 'preset' ? 200_000 : 0,
        perGbRials: $formula || $mode !== 'preset' ? 1_000 : 0,
        perDayRials: $formula || $mode !== 'preset' ? 2_000 : 0,
        resellerDiscountPercent: $discount,
        minimumTotalRials: 1,
    );
    $prices = app(ManagePlanPricing::class);
    $revision = $prices->configure($root->id, $plan->id, 2, 0, $pricing);
    $prices->confirmChange($root->id, $prices->requestChange($root->id, $plan->id, 2, $revision, 'activate'));

    return $plan->id;
}

it('shows canonical current reseller prices to a signed member with no role elevation or financial writes', function (): void {
    $root = demo2Root();
    $fixed = demo2Plan($root, 'fixed-17', discount: 17);
    $formula = demo2Plan($root, 'formula-35', audience: 'reseller', discount: 35, formula: true);
    demo2Plan($root, 'builder-only', audience: 'reseller', mode: 'builder');

    $member = User::factory()->create();
    $member->assignRole('member');
    TelegramAccount::query()->create(['user_id' => $member->id, 'telegram_user_id' => 988882]);
    $partner = User::factory()->create();
    $partner->assignRole('reseller');

    $catalog = app(ListDemoPublishedPresets::class)->handle();
    expect($catalog)->toHaveCount(2);
    foreach ([$fixed, $formula] as $index => $planId) {
        $quote = app(CalculatePlanQuote::class)->handle($partner->id, $planId, 30, 30);
        expect($catalog[$index]['publicRials'])->toBe($quote->publicPriceRials)
            ->and($catalog[$index]['resellerRials'])->toBe($quote->totalPriceRials)
            ->and($catalog[$index]['discountPercent'])->toBe($quote->discountPercent);
    }

    $this->post('https://live.denagate.ir/demo/auth/telegram', ['init_data' => demo2SignedData(988882)])
        ->assertRedirect('https://live.denagate.ir/demo/preview');
    $this->assertGuest('web');
    $this->get('https://live.denagate.ir/demo/preview?section=shop')
        ->assertOk()
        ->assertSee('پلن fixed-17')
        ->assertSee('پلن formula-35')
        ->assertSee('dg-card dg-plan-card flex flex-col', false)
        ->assertSee('data-tone="teal"', false)
        ->assertSee('data-tone="blue"', false)
        ->assertSee('text-sm font-extrabold leading-7 text-[#354C58]', false)
        ->assertSee('dg-stat block w-full text-center text-2xl sm:text-3xl', false)
        ->assertDontSee('پلن builder-only')
        ->assertSee('100,000.3')
        ->assertSee('83,000.2')
        ->assertSee('17٪')
        ->assertSee('35٪')
        ->assertSee('درخواست نمایندگی')
        ->assertSee('https://t.me/DenaGateSalesBot?start=demo_panel')
        ->assertSee('به‌روزرسانی قیمت‌ها')
        ->assertSee("demoSection: 'shop'", false)
        ->assertDontSee('wire:click="confirmWalletPayment"', false)
        ->assertDontSee('wire:click="confirmOrder"', false);

    $this->get('https://live.denagate.ir/dashboard')->assertRedirect();
    expect($member->fresh()->hasRole('member'))->toBeTrue()
        ->and($member->fresh()->hasRole('reseller'))->toBeFalse();
    foreach (['sales_orders', 'billing_journals', 'provisioning_operations', 'sales_panel_requests'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
    Http::assertNothingSent();
});

it('renders all three shared demo card tones while keeping published prices read-only and example metrics fixed', function (): void {
    $root = demo2Root();
    demo2Plan($root, 'color-one');
    demo2Plan($root, 'color-two');
    demo2Plan($root, 'color-three');

    $member = User::factory()->create();
    $member->assignRole('member');
    TelegramAccount::query()->create(['user_id' => $member->id, 'telegram_user_id' => 988885]);

    $this->post('https://live.denagate.ir/demo/auth/telegram', ['init_data' => demo2SignedData(988885)])
        ->assertRedirect('https://live.denagate.ir/demo/preview');

    $this->get('https://live.denagate.ir/demo/preview?section=shop')
        ->assertOk()
        ->assertSee('پلن color-one')
        ->assertSee('پلن color-two')
        ->assertSee('پلن color-three')
        ->assertSee('data-tone="teal"', false)
        ->assertSee('data-tone="blue"', false)
        ->assertSee('data-tone="sand"', false)
        ->assertSee('dg-card dg-plan-card flex flex-col', false)
        ->assertSee('grid grid-cols-1 justify-items-center gap-3 sm:grid-cols-2 sm:gap-4', false)
        ->assertSee('text-sm font-extrabold leading-7 text-[#354C58]', false)
        ->assertSee('کل سرویس‌ها')
        ->assertSee('فعال در سوابق داخلی')
        ->assertSee('w-full text-center text-2xl sm:text-3xl', false)
        ->assertSee('سرویس‌های دارای نام')
        ->assertSee('100,000.3')
        ->assertDontSee('wire:click="selectPlan', false)
        ->assertDontSee('wire:click="confirmOrder', false)
        ->assertDontSee('wire:click="prepareWalletPayment', false)
        ->assertDontSee('wire:submit', false);

    $this->assertGuest('web');
    expect($member->fresh()->hasRole('reseller'))->toBeFalse();
    foreach (['sales_orders', 'billing_journals', 'sales_panel_requests', 'provisioning_operations'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
    Http::assertNothingSent();
});

it('updates next reads automatically on publication and suppresses draft, disabled and stale tariffs', function (): void {
    $root = demo2Root();
    $first = demo2Plan($root, 'first');
    $second = demo2Plan($root, 'second', audience: 'reseller');
    $third = demo2Plan($root, 'third');

    $all = app(ListDemoPublishedPresets::class)->handle();
    expect($all)->toHaveCount(3);

    $prices = app(ManagePlanPricing::class);
    $revision = $prices->configure($root->id, $first, 2, 2,
        new PlanPricingDefinition('fixed', 1_200_000, 0, 0, 0, 25, 1));
    expect($revision)->toBe(3);
    expect(collect(app(ListDemoPublishedPresets::class)->handle())->pluck('name')->all())
        ->not->toContain('پلن first');

    $prices->confirmChange($root->id, $prices->requestChange($root->id, $first, 2, 3, 'activate'));
    $updated = collect(app(ListDemoPublishedPresets::class)->handle())->firstWhere('name', 'پلن first');
    expect($updated)->not->toBeNull()
        ->and($updated['publicRials'])->toBe(1_200_000)
        ->and($updated['resellerRials'])->toBe(900_000)
        ->and($updated['discountPercent'])->toBe(25);

    DB::table('sales_plan_prices')->where('plan_id', $second)->update(['status' => 'draft']);
    DB::table('sales_plans')->where('id', $third)->update(['revision' => 3]);
    $names = collect(app(ListDemoPublishedPresets::class)->handle())->pluck('name')->all();
    expect($names)->toBe(['پلن first']);

    DB::table('sales_products')->where('slug', 'demo-catalog')->update(['is_active' => false]);
    expect(app(ListDemoPublishedPresets::class)->handle())->toBe([]);
});

it('shows only a safe empty state and never reveals live prices to direct, stale or revoked sessions', function (): void {
    $root = demo2Root();
    demo2Plan($root, 'secure-private');

    $this->get('https://live.denagate.ir/demo/preview')->assertRedirect();
    $this->get('https://live.denagate.ir/demo/preview?section=shop')->assertRedirect();

    $member = User::factory()->create();
    $member->assignRole('member');
    TelegramAccount::query()->create(['user_id' => $member->id, 'telegram_user_id' => 988883]);
    $this->post('https://live.denagate.ir/demo/auth/telegram', ['init_data' => demo2SignedData(988883)])
        ->assertRedirect();
    $member->forceFill(['is_active' => false])->save();
    $this->get('https://live.denagate.ir/demo/preview')->assertForbidden();

    $this->assertGuest('web');
    Http::assertNothingSent();
});

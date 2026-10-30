<?php

declare(strict_types=1);

use App\Interfaces\Web\Live\Dashboard;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\CalculatePlanQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ManagePlanPricing;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\ManageResellerServiceLabels;
use App\Modules\Sales\Application\OrderReader;
use App\Modules\Sales\Application\PlanDefinition;
use App\Modules\Sales\Application\PlanPricingDefinition;
use App\Modules\Sales\Application\RegisterPendingResellerOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('denagate_live.enabled', true);
    config()->set('services.remnawave.squad_bindings_live_verified', true);
    config()->set('super_admin.telegram_ids', ['944401', '944402']);
    Role::findOrCreate('super-admin', 'web');
    Role::findOrCreate('reseller', 'web');
    Role::findOrCreate('member', 'web');
    Http::preventStrayRequests();
});

function live3bReseller(int $tgId): User
{
    $user = User::factory()->create();
    $user->assignRole('reseller');
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $tgId]);

    return $user;
}

function live3bPlan(bool $ready = true, string $mode = 'preset'): int
{
    $root = User::factory()->create();
    $root->assignRole('super-admin');
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 944401]);
    app(ConfigureProduct::class)->handle('live-three-b', 'سرویس نمایندگی');

    $manager = app(ManagePlans::class);
    $plan = $manager->create($root->id, new PlanDefinition(
        productSlug: 'live-three-b',
        slug: 'live-three-b-'.$mode,
        name: 'پلن تست فروش',
        kind: 'hybrid',
        mode: $mode,
        audience: 'reseller',
        trafficGb: $mode === 'preset' ? 30 : null,
        durationDays: $mode === 'preset' ? 30 : null,
        minGb: 1,
        maxGb: 100,
        minDays: 7,
        maxDays: 180,
    ));
    $manager->confirmChange($root->id, $manager->requestChange($root->id, $plan->id, 1, 'activate'));

    $prices = app(ManagePlanPricing::class);
    $revision = $prices->configure($root->id, $plan->id, 2, 0, new PlanPricingDefinition(
        method: $mode === 'preset' ? 'fixed' : 'formula',
        fixedPriceRials: $mode === 'preset' ? 1_000_003 : null,
        basePriceRials: $mode === 'preset' ? 0 : 50_000,
        perGbRials: $mode === 'preset' ? 0 : 2_000,
        perDayRials: $mode === 'preset' ? 0 : 1_000,
        resellerDiscountPercent: 35,
        minimumTotalRials: 1,
    ));
    $prices->confirmChange($root->id, $prices->requestChange($root->id, $plan->id, 2, $revision, 'activate'));

    if ($ready) {
        DB::table('sales_plan_internal_squad_bindings')->insert([
            'plan_id' => $plan->id,
            'internal_squad_uuid' => '11111111-2222-4333-8444-555555555555',
            'plan_revision' => 2,
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $plan->id;
}

function live3bNoMoneyOrProvider(): void
{
    foreach ([
        'sales_order_settlements', 'billing_journals', 'billing_postings',
        'sales_fulfillment_outbox', 'provisioning_operations', 'provisioning_subscriptions',
    ] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
    Http::assertNothingSent();
}

it('registers exactly one pending reseller order with immutable price and customer name', function (): void {
    $buyer = live3bReseller(944411);
    $plan = live3bPlan();
    $this->actingAs($buyer)->withSession(['denagate_live.user_id' => $buyer->id]);

    $page = Livewire::test(Dashboard::class)
        ->call('showSection', 'shop')
        ->call('selectPlan', $plan)
        ->call('previewQuote')
        ->assertSee('65,000.2')
        ->assertSee('نام کاربری مشتری')
        ->set('customerUsername', 'customer_01')
        ->call('confirmOrder')
        ->assertSee('سفارش شما ثبت شد')
        ->assertSee('در انتظار پرداخت')
        ->assertSee('customer_01')
        ->assertDontSee('تأیید و ثبت سفارش');

    $orderId = (int) DB::table('sales_orders')->value('id');
    $page->assertSet('createdOrderId', $orderId)->call('confirmOrder')->call('previewQuote');

    $order = app(OrderReader::class)->findPlan($buyer->id, $orderId);
    expect($order->status)->toBe('pending_payment')
        ->and($order->buyerUserId)->toBe($buyer->id)
        ->and($order->totalPriceRials)->toBe(650_002)
        ->and($order->discountPercent)->toBe(35)
        ->and($order->internalSquadUuid)->toBe('11111111-2222-4333-8444-555555555555')
        ->and(app(ManageResellerServiceLabels::class)->pendingName($buyer->id, $orderId))->toBe('customer_01')
        ->and(DB::table('sales_orders')->count())->toBe(1);

    live3bNoMoneyOrProvider();
});

it('registers an explicitly priced custom builder using the published reseller tariff', function (): void {
    $buyer = live3bReseller(944418);
    $plan = live3bPlan(mode: 'builder');
    $this->actingAs($buyer)->withSession(['denagate_live.user_id' => $buyer->id]);

    Livewire::test(Dashboard::class)
        ->call('showSection', 'shop')
        ->call('selectPlan', $plan)
        ->set('daysInput', '45')
        ->set('gbInput', '70')
        ->call('previewQuote')
        ->assertSee('15,275')
        ->set('customerUsername', 'builder-customer')
        ->call('confirmOrder')
        ->assertSee('سفارش شما ثبت شد');

    expect(DB::table('sales_orders')->value('duration_days'))->toBe(45)
        ->and(DB::table('sales_orders')->value('traffic_gb'))->toBe(70)
        ->and(DB::table('sales_orders')->value('discount_percent'))->toBe(35)
        ->and(DB::table('sales_orders')->value('total_price_rials'))->toBe(152_750)
        ->and(DB::table('sales_orders')->count())->toBe(1);
    live3bNoMoneyOrProvider();
});

it('rolls back invalid customer names and permits a corrected retry under the same intent', function (): void {
    $buyer = live3bReseller(944412);
    $plan = live3bPlan();
    $this->actingAs($buyer)->withSession(['denagate_live.user_id' => $buyer->id]);

    $page = Livewire::test(Dashboard::class)
        ->call('showSection', 'shop')->call('selectPlan', $plan)->call('previewQuote');

    foreach (['', "invalid\nnewline", str_repeat('x', 61)] as $bad) {
        $page->set('customerUsername', $bad)->call('confirmOrder')
            ->assertSee('نام کاربری مشتری را در یک خط');
        expect(DB::table('sales_orders')->count())->toBe(0);
    }

    $page->set('customerUsername', 'نام مشتری')->call('confirmOrder')->assertSee('سفارش شما ثبت شد');
    expect(DB::table('sales_orders')->count())->toBe(1);
    live3bNoMoneyOrProvider();
});

it('rejects changed published prices until the buyer explicitly previews again', function (): void {
    $buyer = live3bReseller(944413);
    $plan = live3bPlan();
    $this->actingAs($buyer)->withSession(['denagate_live.user_id' => $buyer->id]);

    $page = Livewire::test(Dashboard::class)
        ->call('showSection', 'shop')->call('selectPlan', $plan)->call('previewQuote')
        ->set('customerUsername', 'fresh-price');

    DB::table('sales_plan_prices')->where('plan_id', $plan)
        ->update(['fixed_price_rials' => 1_500_003, 'revision' => DB::raw('revision + 1')]);

    $page->call('confirmOrder')
        ->assertSee('ثبت سفارش امکان‌پذیر نیست')
        ->assertDontSee('65,000.2');
    expect(DB::table('sales_orders')->count())->toBe(0);

    $page->call('previewQuote')->assertSee('97,500.2')
        ->call('confirmOrder')->assertSee('سفارش شما ثبت شد');
    expect(DB::table('sales_orders')->value('total_price_rials'))->toBe(975_002);
    live3bNoMoneyOrProvider();
});

it('requires a currently verified Squad and leaves no orphan order on failed readiness', function (): void {
    $buyer = live3bReseller(944414);
    $plan = live3bPlan(ready: false);
    $this->actingAs($buyer)->withSession(['denagate_live.user_id' => $buyer->id]);

    Livewire::test(Dashboard::class)
        ->call('showSection', 'shop')->call('selectPlan', $plan)->call('previewQuote')
        ->set('customerUsername', 'buyer-A')
        ->call('confirmOrder')
        ->assertSee('ثبت سفارش امکان‌پذیر نیست');

    expect(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('sales_service_customer_labels')->count())->toBe(0);
    live3bNoMoneyOrProvider();
});

it('keeps cross-buyer idempotency keys private and rejects changed customer on retry', function (): void {
    $one = live3bReseller(944415);
    $two = live3bReseller(944416);
    $plan = live3bPlan();
    $quote1 = app(CalculatePlanQuote::class)->handle($one->id, $plan, 30, 30);
    $quote2 = app(CalculatePlanQuote::class)->handle($two->id, $plan, 30, 30);
    $key = bin2hex(random_bytes(16));

    $action = app(RegisterPendingResellerOrder::class);
    $first = $action->handle($one->id, $quote1, $key, 'first-user');
    $same = $action->handle($one->id, $quote1, $key, 'first-user');

    expect($same->id)->toBe($first->id)
        ->and(fn () => $action->handle($one->id, $quote1, $key, 'different-user'))->toThrow(ValidationException::class)
        ->and(fn () => $action->handle($two->id, $quote2, $key, 'another-user'))->toThrow(ValidationException::class)
        ->and(fn () => app(OrderReader::class)->find($two->id, $first->id))->toThrow(ValidationException::class)
        ->and(DB::table('sales_orders')->count())->toBe(1);
    live3bNoMoneyOrProvider();
});

it('does not allow bypassing preview, cancellation, forged plans or revoked reseller access', function (): void {
    $buyer = live3bReseller(944417);
    $plan = live3bPlan();
    $this->actingAs($buyer)->withSession(['denagate_live.user_id' => $buyer->id]);

    $page = Livewire::test(Dashboard::class)
        ->call('showSection', 'shop')
        ->call('confirmOrder')->assertSee('ابتدا پیش‌فاکتور')
        ->call('selectPlan', $plan)
        ->call('previewQuote')
        ->set('customerUsername', 'not-purchased')
        ->call('backToPlans')
        ->call('confirmOrder')
        ->assertSee('ابتدا پیش‌فاکتور');

    $page->call('selectPlan', $plan)->call('previewQuote')
        ->set('selectedPlanId', $plan + 1234)
        ->call('confirmOrder')->assertSee('این پلن دیگر قابل ارائه نیست');

    expect(DB::table('sales_orders')->count())->toBe(0);

    $buyer->syncRoles(['member']);
    $page->call('confirmOrder')->assertForbidden();
    live3bNoMoneyOrProvider();
});

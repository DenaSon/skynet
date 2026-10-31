<?php

declare(strict_types=1);

use App\Interfaces\Web\Live\Dashboard;
use App\Modules\Billing\Application\RequestTopup;
use App\Modules\Billing\Application\ReviewTopup;
use App\Modules\Billing\Application\SubmitTopupReference;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\CalculatePlanQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ManagePlanPricing;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\OrderReader;
use App\Modules\Sales\Application\PlanDefinition;
use App\Modules\Sales\Application\PlanPricingDefinition;
use App\Modules\Sales\Application\RegisterPendingResellerOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('denagate_live.enabled', true);
    config()->set('super_admin.telegram_ids', ['955501', '955502']);
    config()->set('sales.wallet_settlement_enabled', true);
    config()->set('sales.auto_fulfillment_enabled', true);
    config()->set('services.remnawave.writes_enabled', true);
    config()->set('services.remnawave.squad_bindings_live_verified', true);
    config()->set('telegram_notifications.enabled', true);
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram.sales.token', '54321:live-sales-test');
    config()->set('nutgram.token', '12345:live-admin-test');
    config()->set('billing.topups_enabled', true);
    Role::findOrCreate('super-admin', 'web');
    Role::findOrCreate('reseller', 'web');
    Role::findOrCreate('member', 'web');
    Http::preventStrayRequests();
    Queue::fake();
});

/** @return array{root:User, buyer:User, order:int, plan:int} */
function live3cFixture(): array
{
    $root = User::factory()->create();
    $root->assignRole('super-admin');
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 955501]);

    $buyer = User::factory()->create();
    $buyer->assignRole('reseller');
    TelegramAccount::query()->create(['user_id' => $buyer->id, 'telegram_user_id' => 955510]);

    app(ConfigureProduct::class)->handle('live-three-c', 'سرویس نمایندگی');
    $plans = app(ManagePlans::class);
    $plan = $plans->create($root->id, new PlanDefinition(
        productSlug: 'live-three-c',
        slug: 'reseller-thirty',
        name: 'پلن سی روزه',
        kind: 'hybrid',
        mode: 'preset',
        audience: 'reseller',
        trafficGb: 30,
        durationDays: 30,
        minGb: 1,
        maxGb: 100,
        minDays: 7,
        maxDays: 180,
    ));
    $plans->confirmChange($root->id, $plans->requestChange($root->id, $plan->id, 1, 'activate'));

    $pricing = app(ManagePlanPricing::class);
    $revision = $pricing->configure($root->id, $plan->id, 2, 0, new PlanPricingDefinition(
        method: 'fixed',
        fixedPriceRials: 1_000_000,
        basePriceRials: 0,
        perGbRials: 0,
        perDayRials: 0,
        resellerDiscountPercent: 35,
        minimumTotalRials: 1,
    ));
    $pricing->confirmChange($root->id, $pricing->requestChange($root->id, $plan->id, 2, $revision, 'activate'));

    DB::table('sales_plan_internal_squad_bindings')->insert([
        'plan_id' => $plan->id,
        'internal_squad_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'plan_revision' => 2,
        'verified_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $quote = app(CalculatePlanQuote::class)->handle($buyer->id, $plan->id, 30, 30);
    $order = app(RegisterPendingResellerOrder::class)
        ->handle($buyer->id, $quote, bin2hex(random_bytes(16)), 'customer-test');

    return ['root' => $root, 'buyer' => $buyer, 'order' => $order->id, 'plan' => $plan->id];
}

function live3cCredit(User $root, User $buyer, int $amount = 1_000_000): void
{
    $reference = 'WALLETLIVE'.strtoupper(bin2hex(random_bytes(5)));
    $topup = app(RequestTopup::class)->handle($buyer->id, $amount, 'live3c:'.bin2hex(random_bytes(8)));
    app(SubmitTopupReference::class)->handle($buyer->id, $topup, $reference);
    app(ReviewTopup::class)->handle($root->id, $topup, true, $reference);
}

function live3cAssertNoPurchase(): void
{
    expect(DB::table('billing_journals')->where('kind', 'purchase')->count())->toBe(0)
        ->and(DB::table('sales_order_settlements')->count())->toBe(0)
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(0)
        ->and(DB::table('provisioning_operations')->count())->toBe(0);
    Http::assertNothingSent();
}

it('shows owner-scoped order history and pays a confirmed snapshot exactly once', function (): void {
    $data = live3cFixture();
    live3cCredit($data['root'], $data['buyer']);
    $this->actingAs($data['buyer'])->withSession(['denagate_live.user_id' => $data['buyer']->id]);

    $page = Livewire::test(Dashboard::class)
        ->call('showSection', 'shop')
        ->assertSee('سفارش‌های اخیر من')
        ->call('openOrder', $data['order'])
        ->assertSee('در انتظار پرداخت')
        ->call('confirmWalletPayment')
        ->assertSee('ابتدا مبلغ و اعتبار')
        ->call('prepareWalletPayment')
        ->assertSee('تأیید پرداخت از اعتبار')
        ->assertSee('برای سفارش #')
        ->assertSee('65,000')
        ->assertSee('100,000')
        ->assertSee('35,000')
        ->call('confirmWalletPayment')
        ->assertSee('پرداخت ثبت‌شده؛ سرویس در انتظار آماده‌سازی')
        ->assertDontSee('تأیید و پرداخت')
        ->call('confirmWalletPayment');

    $order = app(OrderReader::class)->findPlan($data['buyer']->id, $data['order']);
    expect($order->status)->toBe('paid')
        ->and($order->totalPriceRials)->toBe(650_000)
        ->and(app(ViewWallet::class)->handle($data['buyer']->id)->balanceRials)->toBe(350_000)
        ->and(DB::table('billing_journals')->where('kind', 'purchase')->count())->toBe(1)
        ->and(DB::table('sales_order_settlements')->where('sales_order_id', $data['order'])->count())->toBe(1)
        ->and(DB::table('sales_fulfillment_outbox')->where('sales_order_id', $data['order'])->value('state'))->toBe('pending')
        ->and(DB::table('provisioning_operations')->count())->toBe(0);
    expect((int) DB::table('billing_postings')->where('side', 'debit')->sum('amount_rials'))
        ->toBe((int) DB::table('billing_postings')->where('side', 'credit')->sum('amount_rials'));
    Http::assertNothingSent();

    Livewire::test(Dashboard::class)
        ->call('showSection', 'shop')
        ->call('openOrder', $data['order'])
        ->assertSee('پرداخت ثبت‌شده؛ سرویس در انتظار آماده‌سازی')
        ->assertDontSee('پرداخت با اعتبار');
});

it('never settles without sufficient confirmed wallet balance', function (): void {
    $data = live3cFixture();
    $this->actingAs($data['buyer'])->withSession(['denagate_live.user_id' => $data['buyer']->id]);

    Livewire::test(Dashboard::class)
        ->call('showSection', 'shop')->call('openOrder', $data['order'])
        ->call('prepareWalletPayment')
        ->assertSee('اعتبار شما کافی نیست')
        ->call('confirmWalletPayment')
        ->assertSee('ابتدا مبلغ و اعتبار');

    live3cAssertNoPurchase();
    expect(DB::table('sales_orders')->where('id', $data['order'])->value('status'))->toBe('pending_payment');
});

it('honors disabled wallet and provider-activation gates without debiting', function (): void {
    $data = live3cFixture();
    live3cCredit($data['root'], $data['buyer']);
    $this->actingAs($data['buyer'])->withSession(['denagate_live.user_id' => $data['buyer']->id]);

    config()->set('sales.wallet_settlement_enabled', false);
    $page = Livewire::test(Dashboard::class)
        ->call('showSection', 'shop')->call('openOrder', $data['order'])
        ->assertDontSee('پرداخت با اعتبار')
        ->call('prepareWalletPayment')
        ->assertSee('پرداخت از اعتبار فعلاً در دسترس نیست');
    live3cAssertNoPurchase();

    config()->set('sales.wallet_settlement_enabled', true);
    config()->set('services.remnawave.writes_enabled', false);
    $page->call('prepareWalletPayment')->call('confirmWalletPayment')
        ->assertSee('پرداخت فعلاً آماده نیست؛ مبلغی برداشت نشد.');
    live3cAssertNoPurchase();
});

it('requires re-approval if the balance changes after the customer reviewed it', function (): void {
    $data = live3cFixture();
    live3cCredit($data['root'], $data['buyer']);
    $this->actingAs($data['buyer'])->withSession(['denagate_live.user_id' => $data['buyer']->id]);

    $page = Livewire::test(Dashboard::class)
        ->call('showSection', 'shop')->call('openOrder', $data['order'])
        ->call('prepareWalletPayment');

    live3cCredit($data['root'], $data['buyer'], 100_000);
    $page->call('confirmWalletPayment')->assertSee('مبلغ، اعتبار یا وضعیت سفارش تغییر کرده است');
    live3cAssertNoPurchase();
    $page->call('prepareWalletPayment')->call('confirmWalletPayment')
        ->assertSee('پرداخت ثبت‌شده');
    expect(app(ViewWallet::class)->handle($data['buyer']->id)->balanceRials)->toBe(450_000)
        ->and(DB::table('billing_journals')->where('kind', 'purchase')->count())->toBe(1);
    Http::assertNothingSent();
});

it('expires or cancels a confirmation without settling the pending order', function (): void {
    $data = live3cFixture();
    live3cCredit($data['root'], $data['buyer']);
    $this->actingAs($data['buyer'])->withSession(['denagate_live.user_id' => $data['buyer']->id]);

    $page = Livewire::test(Dashboard::class)
        ->call('showSection', 'shop')->call('openOrder', $data['order'])
        ->call('prepareWalletPayment')
        ->call('cancelWalletPayment')
        ->call('confirmWalletPayment')
        ->assertSee('ابتدا مبلغ و اعتبار');
    live3cAssertNoPurchase();

    $page->call('prepareWalletPayment');
    $this->travel(6)->minutes();
    $page->call('confirmWalletPayment')->assertSee('تأیید پرداخت منقضی شده است');
    $this->travelBack();
    live3cAssertNoPurchase();
});

it('refuses changed Squad or paid status between review and confirmation', function (): void {
    $data = live3cFixture();
    live3cCredit($data['root'], $data['buyer']);
    $this->actingAs($data['buyer'])->withSession(['denagate_live.user_id' => $data['buyer']->id]);
    $page = Livewire::test(Dashboard::class)
        ->call('showSection', 'shop')->call('openOrder', $data['order'])
        ->call('prepareWalletPayment');

    DB::table('sales_plan_internal_squad_bindings')->where('plan_id', $data['plan'])
        ->update(['verified_at' => null]);
    $page->call('confirmWalletPayment')
        ->assertSee('مشخصات سرویس تغییر کرده است');
    live3cAssertNoPurchase();

    DB::table('sales_plan_internal_squad_bindings')->where('plan_id', $data['plan'])
        ->update(['verified_at' => now()]);
    DB::table('sales_orders')->where('id', $data['order'])->update(['status' => 'paid']);
    $page->call('prepareWalletPayment')->assertSee('این سفارش دیگر در انتظار پرداخت نیست');
    live3cAssertNoPurchase();
});

it('never exposes renewal orders to the new-service wallet checkout', function (): void {
    $data = live3cFixture();
    $buyer = $data['buyer'];
    $quote = app(CalculatePlanQuote::class)->handle($buyer->id, $data['plan'], 30, 30);
    $renewalOrder = app(RegisterPendingResellerOrder::class)
        ->handle($buyer->id, $quote, bin2hex(random_bytes(16)), 'renewal-label');

    $subscriptionId = (int) DB::table('provisioning_subscriptions')->insertGetId([
        'reference' => 'test-renewal-scope',
        'remote_username' => 'renewal_scope_test',
        'state' => 'active',
        'last_operation_key' => 'test-renewal-scope',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('sales_renewal_orders')->insert([
        'sales_order_id' => $renewalOrder->id,
        'source_order_id' => $data['order'],
        'provisioning_subscription_id' => $subscriptionId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(array_map(static fn ($row): int => $row->id,
        app(OrderReader::class)->recentNewPlans($buyer->id)))->toBe([$data['order']]);

    $this->actingAs($buyer)->withSession(['denagate_live.user_id' => $buyer->id]);
    Livewire::test(Dashboard::class)
        ->call('showSection', 'shop')
        ->assertDontSee('#'.$renewalOrder->id.' ·')
        ->call('openOrder', $renewalOrder->id)
        ->assertSee('سفارش در دسترس نیست')
        ->call('prepareWalletPayment')
        ->assertSee('پرداخت از اعتبار فعلاً در دسترس نیست');

    live3cAssertNoPurchase();
});

it('denies foreign orders and revoked reseller authorization on payment attempts', function (): void {
    $data = live3cFixture();
    live3cCredit($data['root'], $data['buyer']);
    $other = User::factory()->create();
    $other->assignRole('reseller');
    TelegramAccount::query()->create(['user_id' => $other->id, 'telegram_user_id' => 955511]);

    $this->actingAs($other)->withSession(['denagate_live.user_id' => $other->id]);
    Livewire::test(Dashboard::class)->call('showSection', 'shop')
        ->assertDontSee('سفارش‌های اخیر من')
        ->call('openOrder', $data['order'])->assertSee('سفارش در دسترس نیست');
    live3cAssertNoPurchase();

    $this->actingAs($data['buyer'])->withSession(['denagate_live.user_id' => $data['buyer']->id]);
    $page = Livewire::test(Dashboard::class)->call('showSection', 'shop')
        ->call('openOrder', $data['order'])
        ->call('prepareWalletPayment');
    $data['buyer']->syncRoles(['member']);
    $page->call('confirmWalletPayment')->assertForbidden();
    live3cAssertNoPurchase();
});

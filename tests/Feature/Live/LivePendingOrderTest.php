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

it('shows plans before nine owned named orders per page and restores the same page after opening an order', function (): void {
    $owner = live3bReseller(944451);
    $foreign = live3bReseller(944452);
    $plan = live3bPlan();
    $ownerQuote = app(CalculatePlanQuote::class)->handle($owner->id, $plan, 30, 30);
    $foreignQuote = app(CalculatePlanQuote::class)->handle($foreign->id, $plan, 30, 30);
    $register = app(RegisterPendingResellerOrder::class);
    $ids = [];

    for ($i = 0; $i < 20; $i++) {
        $name = $i === 19 ? 'مشتری <ویژه> & تست' : 'مشتری '.$i;
        $ids[] = $register->handle($owner->id, $ownerQuote, bin2hex(random_bytes(16)), $name)->id;
    }
    $foreignOrder = $register->handle($foreign->id, $foreignQuote, bin2hex(random_bytes(16)), 'نام خصوصی نماینده دیگر');
    DB::table('sales_orders')->where('id', $ids[0])->update(['status' => 'fulfilled']);

    $reader = app(OrderReader::class);
    $first = $reader->pageNewPlans($owner->id);
    expect(array_map(static fn ($order): int => $order->id, $first->orders))->toBe(array_reverse(array_slice($ids, 11, 9)))
        ->and($first->nextBeforeOrderId)->toBe($ids[11])
        ->and($reader->pageNewPlans($owner->id, 9, $first->nextBeforeOrderId)->nextBeforeOrderId)->toBe($ids[2])
        ->and($reader->pageNewPlans($owner->id, 9, $ids[2])->nextBeforeOrderId)->toBeNull()
        ->and(fn () => $reader->pageNewPlans($owner->id, 0))->toThrow(ValidationException::class)
        ->and(fn () => $reader->pageNewPlans($owner->id, 9, -1))->toThrow(ValidationException::class);
    $names = app(ManageResellerServiceLabels::class)->forRecentNewPlanOrders($owner->id,
        [$ids[0], $ids[19], $foreignOrder->id]);
    expect($names[$ids[0]] ?? null)->toBe('مشتری 0')
        ->and($names[$ids[19]] ?? null)->toBe('مشتری <ویژه> & تست')
        ->and(array_key_exists($foreignOrder->id, $names))->toBeFalse();

    $this->actingAs($owner)->withSession(['denagate_live.user_id' => $owner->id]);
    $page = Livewire::test(Dashboard::class)
        ->call('showSection', 'shop')
        ->assertSee('۹ سفارش در هر صفحه')
        ->assertSee('مشتری &lt;ویژه&gt; &amp; تست', false)
        ->assertSee('پلن تست فروش · سفارش #'.$ids[19])
        ->assertDontSee('نام خصوصی نماینده دیگر')
        ->assertDontSee('مشتری 0')
        ->assertSee('صفحه بعد');
    $html = $page->html();
    expect(strpos($html, 'wire:key="live-plan-'.$plan.'"'))
        ->toBeLessThan(strpos($html, 'aria-label="سفارش‌های اخیر من"'));

    $page->call('nextRecentOrders')
        ->assertSet('recentOrdersCursor', $ids[11])
        ->assertSee('مشتری 10')
        ->assertDontSee('مشتری &lt;ویژه&gt; &amp; تست', false)
        ->assertSee('صفحه قبل')
        ->call('openOrder', $ids[9])
        ->assertSet('createdOrderId', $ids[9])
        ->call('backToPlans')
        ->assertSet('recentOrdersCursor', $ids[11])
        ->assertSee('مشتری 9')
        ->assertDontSee('مشتری &lt;ویژه&gt; &amp; تست', false)
        ->call('nextRecentOrders')
        ->assertSet('recentOrdersCursor', $ids[2])
        ->assertSee('مشتری 0')
        ->assertDontSee('صفحه بعد')
        ->call('nextRecentOrders')
        ->assertSet('recentOrdersCursor', $ids[2])
        ->call('previousRecentOrders')
        ->assertSet('recentOrdersCursor', $ids[11])
        ->call('previousRecentOrders')
        ->assertSet('recentOrdersCursor', null)
        ->assertSee('مشتری &lt;ویژه&gt; &amp; تست', false);

    expect(DB::table('sales_orders')->count())->toBe(21)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('sales_order_settlements')->count())->toBe(0)
        ->and(DB::table('provisioning_operations')->count())->toBe(0);
    Http::assertNothingSent();
});

it('resets only recent-order paging after a new purchase and keeps reseller order ownership', function (): void {
    $owner = live3bReseller(944453);
    $foreign = live3bReseller(944454);
    $plan = live3bPlan();
    $quote = app(CalculatePlanQuote::class)->handle($owner->id, $plan, 30, 30);
    $register = app(RegisterPendingResellerOrder::class);

    for ($i = 0; $i < 10; $i++) {
        $register->handle($owner->id, $quote, bin2hex(random_bytes(16)), 'قدیمی '.$i);
    }

    $this->actingAs($owner)->withSession(['denagate_live.user_id' => $owner->id]);
    $page = Livewire::test(Dashboard::class)->call('showSection', 'shop')
        ->call('nextRecentOrders')
        ->assertSet('recentOrdersHistory', [null])
        ->call('selectPlan', $plan)
        ->set('customerUsername', 'خرید جدید')
        ->call('confirmOrder')
        ->assertSet('recentOrdersCursor', null)
        ->assertSet('recentOrdersHistory', [])
        ->assertSee('سفارش شما ثبت شد')
        ->call('backToPlans')
        ->assertSee('خرید جدید')
        ->assertDontSee('صفحه قبل');
    $latest = (int) DB::table('sales_orders')->where('buyer_user_id', $owner->id)->max('id');

    $page->call('openOrder', $latest)
        ->assertSet('createdOrderId', $latest);
    $owner->syncRoles(['member']);
    $page->call('nextRecentOrders')->assertForbidden();

    expect(app(OrderReader::class)->pageNewPlans($foreign->id, 9, $latest)->orders)->toBe([]);
    expect(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});

it('excludes renewal orders across all recent-purchase pages and prevents opening them', function (): void {
    $owner = live3bReseller(944455);
    $plan = live3bPlan();
    $quote = app(CalculatePlanQuote::class)->handle($owner->id, $plan, 30, 30);
    $register = app(RegisterPendingResellerOrder::class);
    $orders = [];
    for ($i = 0; $i < 11; $i++) {
        $orders[] = $register->handle($owner->id, $quote, bin2hex(random_bytes(16)), 'کاربر '.$i)->id;
    }
    $renewal = $register->handle($owner->id, $quote, bin2hex(random_bytes(16)), 'فقط تمدید');
    $subscriptionId = DB::table('provisioning_subscriptions')->insertGetId([
        'reference' => 'live-ux01-test-renewal',
        'remote_username' => 'live_ux01_test',
        'state' => 'active',
        'last_operation_key' => 'live-ux01-test-renewal',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('sales_renewal_orders')->insert([
        'sales_order_id' => $renewal->id,
        'source_order_id' => $orders[0],
        'provisioning_subscription_id' => $subscriptionId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('sales_orders')->where('id', $renewal->id)->update([
        'created_at' => now()->subDays(3),
    ]);
    $reader = app(OrderReader::class);
    expect(array_map(static fn ($order): int => $order->id, $reader->recentPlans($owner->id)))
        ->toContain($renewal->id);
    $first = $reader->pageNewPlans($owner->id);
    $second = $reader->pageNewPlans($owner->id, 9, $first->nextBeforeOrderId);
    expect(array_map(static fn ($order): int => $order->id, $first->orders))
        ->toBe(array_reverse(array_slice($orders, 2)))
        ->and(array_map(static fn ($order): int => $order->id, $second->orders))
        ->toBe(array_reverse(array_slice($orders, 0, 2)))
        ->and($second->nextBeforeOrderId)->toBeNull()
        ->and(app(ManageResellerServiceLabels::class)->forRecentNewPlanOrders($owner->id, [$renewal->id]))->toBe([]);

    $this->actingAs($owner)->withSession(['denagate_live.user_id' => $owner->id]);
    Livewire::test(Dashboard::class)
        ->call('showSection', 'shop')
        ->assertDontSee('فقط تمدید')
        ->call('nextRecentOrders')
        ->assertDontSee('فقط تمدید')
        ->call('openOrder', $renewal->id)
        ->assertSee('سفارش در دسترس نیست')
        ->assertSet('createdOrderId', null);
    expect(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('sales_order_settlements')->count())->toBe(0)
        ->and(DB::table('provisioning_operations')->count())->toBe(0);
    Http::assertNothingSent();
});

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
        ->assertDontSee('ثبت سفارش بدون پرداخت');

    $orderId = (int) DB::table('sales_orders')->value('id');
    $page->assertSet('createdOrderId', $orderId)->call('confirmOrder')->call('previewQuote');

    $order = app(OrderReader::class)->findPlan($buyer->id, $orderId);
    expect($order->status)->toBe('pending_payment')
        ->and($order->buyerUserId)->toBe($buyer->id)
        ->and($order->totalPriceRials)->toBe(650_002)
        ->and($order->discountPercent)->toBe(35)
        ->and($order->buyerCategory)->toBe('reseller')
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
        ->call('confirmOrder')->assertSee('ابتدا قیمت نهایی')
        ->call('selectPlan', $plan)
        ->call('previewQuote')
        ->set('customerUsername', 'not-purchased')
        ->call('backToPlans')
        ->call('confirmOrder')
        ->assertSee('ابتدا قیمت نهایی');

    $page->call('selectPlan', $plan)->call('previewQuote')
        ->set('selectedPlanId', $plan + 1234)
        ->call('confirmOrder')->assertSee('این پلن دیگر قابل ارائه نیست');

    expect(DB::table('sales_orders')->count())->toBe(0);

    $buyer->syncRoles(['member']);
    $page->call('confirmOrder')->assertForbidden();
    live3bNoMoneyOrProvider();
});

it('requires explicit order confirmation after an automatic preset quote and rejects changed prices', function (): void {
    $buyer = live3bReseller(944430);
    $plan = live3bPlan();
    $this->actingAs($buyer)->withSession(['denagate_live.user_id' => $buyer->id]);
    $page = Livewire::test(Dashboard::class)->call('showSection', 'shop')->call('selectPlan', $plan)
        ->assertSee('پیش‌فاکتور شما')->assertSee('ثبت سفارش بدون پرداخت')
        ->assertSet('createdOrderId', null);
    expect(DB::table('sales_orders')->count())->toBe(0);
    live3bNoMoneyOrProvider();

    DB::table('sales_plan_prices')->where('plan_id', $plan)->update(['fixed_price_rials' => 2_000_000]);
    $page->set('customerUsername', 'customer_changed')->call('confirmOrder')
        ->assertSet('createdOrderId', null)->assertSee('قیمت را دوباره بررسی کنید');
    expect(DB::table('sales_orders')->count())->toBe(0);
    $page->call('previewQuote')->call('confirmOrder')->assertSee('در انتظار پرداخت');
    expect(DB::table('sales_orders')->count())->toBe(1);
    live3bNoMoneyOrProvider();
});

it('filters stale unpaid orders before Mini App pagination without changing direct order access', function (): void {
    $this->travelTo(now()->startOfSecond());
    $owner = live3bReseller(944490);
    $foreignOwner = live3bReseller(944491);
    $plan = live3bPlan();
    $register = app(RegisterPendingResellerOrder::class);
    $quote = app(CalculatePlanQuote::class)->handle($owner->id, $plan, 30, 30);

    $paid = $register->handle($owner->id, $quote, bin2hex(random_bytes(16)), 'خرید پرداخت‌شده')->id;
    $review = $register->handle($owner->id, $quote, bin2hex(random_bytes(16)), 'نیازمند بررسی')->id;
    $receipt = $register->handle($owner->id, $quote, bin2hex(random_bytes(16)), 'رسید ثبت‌شده')->id;
    $boundary = $register->handle($owner->id, $quote, bin2hex(random_bytes(16)), 'دقیقاً ۴۸ ساعت')->id;
    $stale = $register->handle($owner->id, $quote, bin2hex(random_bytes(16)), 'قدیمی پرداخت‌نشده')->id;
    $recent = $register->handle($owner->id, $quote, bin2hex(random_bytes(16)), 'کمتر از ۴۸ ساعت')->id;
    $foreignQuote = app(CalculatePlanQuote::class)->handle($foreignOwner->id, $plan, 30, 30);
    $foreign = $register->handle($foreignOwner->id, $foreignQuote, bin2hex(random_bytes(16)), 'نماینده دیگر')->id;

    DB::table('sales_orders')->whereIn('id', [$paid, $review, $receipt, $boundary, $stale])
        ->update(['created_at' => now()->subHours(72)]);
    DB::table('sales_orders')->where('id', $boundary)->update(['created_at' => now()->subHours(48)]);
    DB::table('sales_orders')->where('id', $recent)->update(['created_at' => now()->subHours(48)->addSecond()]);
    DB::table('sales_orders')->where('id', $paid)->update(['status' => 'paid']);
    DB::table('sales_orders')->where('id', $review)->update(['status' => 'needs_review']);
    DB::table('sales_bank_payment_claims')->insert([
        'sales_order_id' => $receipt, 'buyer_user_id' => $owner->id,
        'photo_encrypted' => 'test-evidence', 'photo_fingerprint' => str_repeat('f', 64),
        'telegram_message_key' => 'tg:test:944490:1', 'status' => 'submitted',
        'created_at' => now()->subHours(70), 'updated_at' => now()->subHours(70),
    ]);

    $reader = app(OrderReader::class);
    $first = $reader->pageNewPlans($owner->id, 2);
    expect(array_map(static fn ($order): int => $order->id, $first->orders))
        ->toBe([$recent, $receipt])
        ->and($first->nextBeforeOrderId)->toBe($receipt);
    $second = $reader->pageNewPlans($owner->id, 2, $first->nextBeforeOrderId);
    expect(array_map(static fn ($order): int => $order->id, $second->orders))
        ->toBe([$review, $paid])
        ->and($second->nextBeforeOrderId)->toBeNull()
        ->and(array_map(static fn ($order): int => $order->id, $reader->recentPlans($owner->id)))
        ->toBe([$recent, $receipt, $review, $paid])
        ->and($reader->findNewPlan($owner->id, $stale)->id)->toBe($stale)
        ->and($reader->findNewPlan($owner->id, $boundary)->id)->toBe($boundary)
        ->and(fn () => $reader->findNewPlan($owner->id, $foreign))->toThrow(ValidationException::class);

    $this->actingAs($owner)->withSession(['denagate_live.user_id' => $owner->id]);
    Livewire::test(Dashboard::class)->call('showSection', 'shop')
        ->assertSee('کمتر از ۴۸ ساعت')
        ->assertSee('رسید ثبت‌شده')
        ->assertDontSee('قدیمی پرداخت‌نشده')
        ->assertDontSee('دقیقاً ۴۸ ساعت')
        ->assertDontSee('نماینده دیگر')
        ->call('openOrder', $stale)
        ->assertSet('createdOrderId', $stale);

    expect(DB::table('sales_orders')->count())->toBe(7)
        ->and(DB::table('sales_orders')->where('id', $stale)->value('status'))->toBe('pending_payment')
        ->and(DB::table('sales_bank_payment_claims')->where('sales_order_id', $receipt)->count())->toBe(1)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('provisioning_operations')->count())->toBe(0);
    Http::assertNothingSent();
});

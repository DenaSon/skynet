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
use App\Modules\Sales\Application\CreatePendingPlanOrder;
use App\Modules\Sales\Application\ManagePlanPricing;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\OrderReader;
use App\Modules\Sales\Application\PlanDefinition;
use App\Modules\Sales\Application\PlanPricingDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

const LIVE4C_SQUAD = '123e4567-e89b-12d3-a456-426614174000';

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(now()->startOfSecond());
    config()->set('denagate_live.enabled', true);
    config()->set('sales.renewal_enabled', true);
    config()->set('sales.wallet_settlement_enabled', true);
    config()->set('sales.auto_fulfillment_enabled', true);
    config()->set('services.remnawave.url', 'https://rw-live4c.test');
    config()->set('services.remnawave.token', 'fake-live4c-token');
    config()->set('services.remnawave.writes_enabled', true);
    config()->set('services.remnawave.squad_bindings_live_verified', true);
    config()->set('telegram_notifications.enabled', true);
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram.sales.token', '54321:live4c-sales');
    config()->set('nutgram.token', '12345:live4c-admin');
    config()->set('super_admin.telegram_ids', ['989101', '989102']);
    config()->set('billing.topups_enabled', true);
    Queue::fake();
    Http::preventStrayRequests();
    Role::findOrCreate('super-admin', 'web');
    Role::findOrCreate('reseller', 'web');
    Role::findOrCreate('member', 'web');
});

/** @return array{root:User,buyer:User,source:int,plan:int} */
function live4cFixture(): array
{
    $root = User::factory()->create();
    $root->assignRole('super-admin');
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 989101]);

    $buyer = User::factory()->create();
    $buyer->assignRole('reseller');
    TelegramAccount::query()->create(['user_id' => $buyer->id, 'telegram_user_id' => 989110]);
    DB::table('telegram_bot_contacts')->insert([
        'user_id' => $buyer->id, 'chat_id' => 989110, 'bot_key' => 'sales',
        'delivery_status' => 'reachable', 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    app(ConfigureProduct::class)->handle('live4c-vpn', 'محصول تمدید');
    $plans = app(ManagePlans::class);
    $plan = $plans->create($root->id, new PlanDefinition(
        'live4c-vpn', 'live4c-plan', 'پلن تمدید ۳۰ روزه', 'hybrid', 'preset', 'reseller',
        20, 30, 1, 100, 15, 90,
    ));
    $plans->confirmChange($root->id, $plans->requestChange($root->id, $plan->id, 1, 'activate'));
    $prices = app(ManagePlanPricing::class);
    $revision = $prices->configure($root->id, $plan->id, 2, 0,
        new PlanPricingDefinition('fixed', 1_000_000, 0, 0, 0, 35, 1));
    $prices->confirmChange($root->id, $prices->requestChange($root->id, $plan->id, 2, $revision, 'activate'));
    DB::table('sales_plan_internal_squad_bindings')->insert([
        'plan_id' => $plan->id, 'plan_revision' => 2, 'internal_squad_uuid' => LIVE4C_SQUAD,
        'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $quote = app(CalculatePlanQuote::class)->handle($buyer->id, $plan->id, 30, 20);
    $source = app(CreatePendingPlanOrder::class)->handle($buyer->id, $quote, bin2hex(random_bytes(16)))->id;
    DB::table('sales_orders')->where('id', $source)->update(['status' => 'fulfilled']);
    $sid = DB::table('provisioning_subscriptions')->insertGetId([
        'reference' => 'sales-order:'.$source,
        'remote_username' => 'sk_'.substr(hash('sha256', 'sales-order:'.$source), 0, 24),
        'remote_user_id' => 52, 'state' => 'active',
        'last_operation_key' => 'sales-order:'.$source.':create',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('provisioning_order_links')->insert([
        'sales_order_id' => $source, 'provisioning_subscription_id' => $sid, 'created_at' => now(),
    ]);

    return ['root' => $root, 'buyer' => $buyer, 'source' => $source, 'plan' => $plan->id];
}

/** @param array<string,mixed> $extra */
function live4cProvider(int $source, array $extra = []): void
{
    $reference = 'sales-order:'.$source;
    $remote = array_replace_recursive([
        'id' => 52,
        'shortUuid' => 'live4c-private',
        'username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
        'description' => 'SkyNetwork ref:'.hash('sha256', $reference),
        'status' => 'ACTIVE',
        'expireAt' => now()->utc()->addDays(3)->format('Y-m-d\TH:i:s\Z'),
        'trafficLimitBytes' => 20 * 1073741824,
        'trafficLimitStrategy' => 'NO_RESET',
        'subscriptionUrl' => 'https://private.example.test/credential-4c',
        'userTraffic' => ['usedTrafficBytes' => 1073741824],
        'activeInternalSquads' => [['uuid' => LIVE4C_SQUAD, 'name' => 'Test']],
    ], $extra);
    Http::fake([
        'https://rw-live4c.test/api/users/52' => Http::response(['response' => $remote]),
    ]);
}

function live4cCredit(User $root, User $buyer, int $amount = 1_000_000): void
{
    $ref = 'WALLET4C'.strtoupper(bin2hex(random_bytes(5)));
    $topup = app(RequestTopup::class)->handle($buyer->id, $amount, 'live4c:'.bin2hex(random_bytes(8)));
    app(SubmitTopupReference::class)->handle($buyer->id, $topup, $ref);
    app(ReviewTopup::class)->handle($root->id, $topup, true, $ref);
}

function live4cNoDebit(): void
{
    expect(DB::table('billing_journals')->where('kind', 'purchase')->count())->toBe(0)
        ->and(DB::table('sales_order_settlements')->count())->toBe(0)
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(0)
        ->and(DB::table('provisioning_operations')->count())->toBe(0);
}

it('previews exact price without creating an order and settles exactly one existing-subscription renewal', function (): void {
    $d = live4cFixture();
    live4cCredit($d['root'], $d['buyer']);
    live4cProvider($d['source']);
    $this->actingAs($d['buyer'])->withSession(['denagate_live.user_id' => $d['buyer']->id]);

    $page = Livewire::test(Dashboard::class)
        ->call('showSection', 'services')->call('openService', $d['source'])
        ->assertSee('بررسی قیمت تمدید')
        ->call('previewServiceRenewal')
        ->assertSee('پیشنهاد تمدید')
        ->assertSee('65,000');

    expect(DB::table('sales_orders')->count())->toBe(1);
    live4cNoDebit();

    $page->call('createServiceRenewal')
        ->assertSee('آخرین سفارش تمدید')
        ->assertSee('در انتظار پرداخت');
    $order = app(OrderReader::class)->latestRenewalForSource($d['buyer']->id, $d['source']);
    expect($order)->not->toBeNull()
        ->and($order->totalPriceRials)->toBe(650_000)
        ->and(DB::table('sales_orders')->count())->toBe(2)
        ->and(DB::table('sales_renewal_orders')->count())->toBe(1)
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(1)
        ->and(DB::table('provisioning_order_links')->count())->toBe(1);
    live4cNoDebit();

    $page->call('confirmRenewalPayment')->assertSee('ابتدا مبلغ و اعتبار')
        ->call('prepareRenewalPayment')
        ->assertSee('تأیید پرداخت تمدید')
        ->assertSee('100,000')
        ->assertSee('35,000')
        ->call('confirmRenewalPayment')
        ->assertSee('پرداخت ثبت شده؛ در انتظار اعمال تمدید')
        ->assertDontSee('تأیید و پرداخت تمدید');

    expect(app(OrderReader::class)->findPlan($d['buyer']->id, $order->id)->status)->toBe('paid')
        ->and(app(ViewWallet::class)->handle($d['buyer']->id)->balanceRials)->toBe(350_000)
        ->and(DB::table('billing_journals')->where('kind', 'purchase')->count())->toBe(1)
        ->and(DB::table('sales_order_settlements')->where('sales_order_id', $order->id)->count())->toBe(1)
        ->and(DB::table('sales_fulfillment_outbox')->where('sales_order_id', $order->id)->value('event_key'))
        ->toBe('sales-order:'.$order->id.':renew')
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(1)
        ->and(DB::table('provisioning_operations')->count())->toBe(0);
    expect((int) DB::table('billing_postings')->where('side', 'debit')->sum('amount_rials'))
        ->toBe((int) DB::table('billing_postings')->where('side', 'credit')->sum('amount_rials'));
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET');
    Http::assertNotSent(static fn (Request $request): bool => $request->method() !== 'GET');

    Livewire::test(Dashboard::class)->call('showSection', 'services')
        ->call('openService', $d['source'])
        ->assertSee('پرداخت ثبت شده؛ در انتظار اعمال تمدید')
        ->assertDontSee('پرداخت تمدید با اعتبار');
});

it('reuses an already payable renewal after reopening the app and does not create a second order', function (): void {
    $d = live4cFixture();
    live4cProvider($d['source']);
    $this->actingAs($d['buyer'])->withSession(['denagate_live.user_id' => $d['buyer']->id]);
    $page = Livewire::test(Dashboard::class)->call('showSection', 'services')->call('openService', $d['source'])
        ->call('previewServiceRenewal')->call('createServiceRenewal');
    expect(DB::table('sales_orders')->count())->toBe(2);
    $page->call('previewServiceRenewal')->assertSee('ابتدا وضعیت سفارش تمدید قبلی را بررسی کنید.');
    Livewire::test(Dashboard::class)->call('showSection', 'services')->call('openService', $d['source'])
        ->assertSee('آخرین سفارش تمدید')
        ->assertSee('پرداخت تمدید با اعتبار');
    expect(DB::table('sales_renewal_orders')->count())->toBe(1);
    live4cNoDebit();
});

it('keeps the renewal preview read-only while renewal payment is disabled', function (): void {
    $d = live4cFixture();
    live4cProvider($d['source']);
    config()->set('sales.renewal_enabled', false);
    $this->actingAs($d['buyer'])->withSession(['denagate_live.user_id' => $d['buyer']->id]);
    Livewire::test(Dashboard::class)->call('showSection', 'services')->call('openService', $d['source'])
        ->call('previewServiceRenewal')->assertSee('پیشنهاد تمدید')
        ->assertSee('ثبت سفارش تمدید فعلاً در دسترس نیست')
        ->call('createServiceRenewal')->assertSee('ابتدا جزئیات و مبلغ تمدید را بررسی کنید.');
    expect(DB::table('sales_orders')->count())->toBe(1);
    live4cNoDebit();
});

it('refuses a changed price without creating an order or debit', function (): void {
    $d = live4cFixture();
    live4cProvider($d['source']);
    $this->actingAs($d['buyer'])->withSession(['denagate_live.user_id' => $d['buyer']->id]);
    $page = Livewire::test(Dashboard::class)->call('showSection', 'services')->call('openService', $d['source'])
        ->call('previewServiceRenewal')->assertSee('65,000');

    DB::table('sales_plan_prices')->where('plan_id', $d['plan'])
        ->update(['fixed_price_rials' => 1_500_000]);
    $page->call('createServiceRenewal')
        ->assertSee('قیمت یا مشخصات تمدید تغییر کرده است')
        ->assertDontSee('تأیید و ثبت سفارش تمدید');
    expect(DB::table('sales_orders')->count())->toBe(1);
    live4cNoDebit();
});

it('does not debit with insufficient wallet credit, stale balance or a cancelled token', function (): void {
    $d = live4cFixture();
    live4cProvider($d['source']);
    $this->actingAs($d['buyer'])->withSession(['denagate_live.user_id' => $d['buyer']->id]);
    $page = Livewire::test(Dashboard::class)->call('showSection', 'services')->call('openService', $d['source'])
        ->call('previewServiceRenewal')->call('createServiceRenewal')
        ->call('prepareRenewalPayment')->assertSee('اعتبار شما کافی نیست');
    live4cNoDebit();

    live4cCredit($d['root'], $d['buyer']);
    $page->call('prepareRenewalPayment')
        ->assertSee('تأیید پرداخت تمدید')
        ->call('cancelRenewal')
        ->call('confirmRenewalPayment')->assertSee('ابتدا مبلغ و اعتبار');
    live4cNoDebit();

    $page->call('prepareRenewalPayment');
    live4cCredit($d['root'], $d['buyer'], 100_000);
    $page->call('confirmRenewalPayment')->assertSee('مبلغ، اعتبار یا وضعیت سفارش تغییر کرده است');
    live4cNoDebit();
});

it('rejects expired payment approval and failed readiness without charging or Provider write', function (): void {
    $d = live4cFixture();
    live4cProvider($d['source']);
    live4cCredit($d['root'], $d['buyer']);
    $this->actingAs($d['buyer'])->withSession(['denagate_live.user_id' => $d['buyer']->id]);
    $page = Livewire::test(Dashboard::class)->call('showSection', 'services')->call('openService', $d['source'])
        ->call('previewServiceRenewal')->call('createServiceRenewal')
        ->call('prepareRenewalPayment');
    $this->travel(6)->minutes();
    $page->call('confirmRenewalPayment')->assertSee('تأیید پرداخت منقضی شده است');
    $this->travelBack();
    live4cNoDebit();

    config()->set('services.remnawave.writes_enabled', false);
    $page->call('prepareRenewalPayment')->call('confirmRenewalPayment')
        ->assertSee('پرداخت فعلاً آماده نیست؛ مبلغی برداشت نشد.');
    live4cNoDebit();
    Http::assertNotSent(static fn (Request $r): bool => $r->method() !== 'GET');
});

it('denies foreign owner and revoked reseller on the renewal and payment paths', function (): void {
    $d = live4cFixture();
    live4cProvider($d['source']);
    $foreign = User::factory()->create();
    $foreign->assignRole('reseller');
    TelegramAccount::query()->create(['user_id' => $foreign->id, 'telegram_user_id' => 989112]);

    $this->actingAs($foreign)->withSession(['denagate_live.user_id' => $foreign->id]);
    Livewire::test(Dashboard::class)->call('showSection', 'services')
        ->call('openService', $d['source'])
        ->assertSet('selectedServiceOrderId', null)
        ->call('previewServiceRenewal')->assertSee('ابتدا سرویس خود را انتخاب کنید.');
    expect(DB::table('sales_renewal_orders')->count())->toBe(0);

    $this->actingAs($d['buyer'])->withSession(['denagate_live.user_id' => $d['buyer']->id]);
    $page = Livewire::test(Dashboard::class)->call('showSection', 'services')
        ->call('openService', $d['source'])->call('previewServiceRenewal');
    $d['buyer']->syncRoles(['member']);
    $page->call('createServiceRenewal')->assertForbidden();
    expect(DB::table('sales_orders')->count())->toBe(1);
    live4cNoDebit();
});

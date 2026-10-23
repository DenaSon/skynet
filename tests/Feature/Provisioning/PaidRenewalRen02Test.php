<?php

declare(strict_types=1);

use App\Modules\Billing\Application\RequestTopup;
use App\Modules\Billing\Application\ReviewTopup;
use App\Modules\Billing\Application\SubmitTopupPhoto;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\FulfillPaidPlanOrder;
use App\Modules\Provisioning\Application\FulfillPaidRenewalOrder;
use App\Modules\Sales\Application\CalculatePlanQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\CreatePendingPlanOrder;
use App\Modules\Sales\Application\CreatePendingRenewalOrder;
use App\Modules\Sales\Application\ManagePlanPricing;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\ManualBankPayments;
use App\Modules\Sales\Application\PlanDefinition;
use App\Modules\Sales\Application\PlanPricingDefinition;
use App\Modules\Sales\Application\SettleWalletOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

const REN02_SQUAD = '123e4567-e89b-12d3-a456-426614174000';

beforeEach(function (): void {
    $this->travelTo(now()->startOfSecond());
    config()->set('super_admin.telegram_ids', ['991001', '991002']);
    config()->set('services.remnawave.url', 'https://rw-ren02.test');
    config()->set('services.remnawave.token', 'fake-token');
    config()->set('services.remnawave.writes_enabled', true);
    config()->set('services.remnawave.squad_bindings_live_verified', true);
    config()->set('sales.renewal_enabled', true);
    config()->set('sales.wallet_settlement_enabled', true);
    config()->set('sales.auto_fulfillment_enabled', true);
    config()->set('sales.manual_bank_review_enabled', true);
    config()->set('telegram_notifications.enabled', true);
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram.sales.token', '54321:sales');
    config()->set('nutgram.token', '12345:admin');
    config()->set('billing.topups_enabled', true);
    Queue::fake();
    Http::preventStrayRequests();
    app(ConfigureProduct::class)->handle('ren02-vpn', 'محصول آزمایشی');
});

/** @return array{root:User,buyer:User,source:int,plan:int,telegram:int} */
function ren02Fixture(bool $unlimited = false): array
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 991001]);

    $buyer = User::factory()->create();
    $telegram = 991100 + $buyer->id;
    TelegramAccount::query()->create(['user_id' => $buyer->id, 'telegram_user_id' => $telegram]);
    DB::table('telegram_bot_contacts')->insert([
        'user_id' => $buyer->id, 'chat_id' => $telegram, 'bot_key' => 'sales',
        'delivery_status' => 'reachable', 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $plans = app(ManagePlans::class);
    $plan = $plans->create($root->id, new PlanDefinition(
        'ren02-vpn', 'ren02-plan', 'پلن آزمایشی', $unlimited ? 'time' : 'hybrid',
        'preset', 'both', $unlimited ? null : 20, 30,
        $unlimited ? null : 1, $unlimited ? null : 100, 15, 90,
    ));
    $plans->confirmChange($root->id, $plans->requestChange($root->id, $plan->id, 1, 'activate'));
    $prices = app(ManagePlanPricing::class);
    $revision = $prices->configure($root->id, $plan->id, 2, 0,
        new PlanPricingDefinition('fixed', 1_000_000, 0, 0, 0, 0, 100_000));
    $prices->confirmChange($root->id, $prices->requestChange($root->id, $plan->id, 2, $revision, 'activate'));
    DB::table('sales_plan_internal_squad_bindings')->insert([
        'plan_id' => $plan->id, 'plan_revision' => 2, 'internal_squad_uuid' => REN02_SQUAD,
        'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $quote = app(CalculatePlanQuote::class)->handle($buyer->id, $plan->id, 30, $unlimited ? null : 20);
    $source = app(CreatePendingPlanOrder::class)->handle($buyer->id, $quote, bin2hex(random_bytes(16)))->id;
    DB::table('sales_orders')->where('id', $source)->update(['status' => 'fulfilled']);
    $subscriptionId = DB::table('provisioning_subscriptions')->insertGetId([
        'reference' => 'sales-order:'.$source,
        'remote_username' => 'sk_'.substr(hash('sha256', 'sales-order:'.$source), 0, 24),
        'remote_user_id' => 52,
        'state' => 'active', 'last_operation_key' => 'sales-order:'.$source.':create',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('provisioning_order_links')->insert([
        'sales_order_id' => $source, 'provisioning_subscription_id' => $subscriptionId, 'created_at' => now(),
    ]);

    return compact('root', 'buyer', 'source', 'telegram') + ['plan' => $plan->id];
}

/** @return array<string,mixed> */
function ren02Remote(int $source, int $limit = 21474836480, string $status = 'ACTIVE'): array
{
    $reference = 'sales-order:'.$source;

    return [
        'id' => 52, 'shortUuid' => 'ren02-short',
        'username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
        'description' => 'SkyNetwork ref:'.hash('sha256', $reference),
        'status' => $status,
        'expireAt' => now()->utc()->addDays(3)->format('Y-m-d\TH:i:s\Z'),
        'trafficLimitBytes' => $limit,
        'trafficLimitStrategy' => 'NO_RESET',
        'subscriptionUrl' => 'https://secret.test/unchanged-link',
        'userTraffic' => ['usedTrafficBytes' => $limit === 0 ? 0 : 1073741824],
        'activeInternalSquads' => [['uuid' => REN02_SQUAD, 'name' => 'Test']],
    ];
}

/** @param array<string,mixed> $remote */
function ren02Http(array &$remote, bool &$failUpdate, ?bool &$failRead = null, bool $disableAfterRead = false): void
{
    Http::fake(static function (Request $r) use (&$remote, &$failUpdate, &$failRead, $disableAfterRead) {
        if ($r->method() === 'GET' && $r->url() === 'https://rw-ren02.test/api/users/52') {
            $response = $failRead === true
                ? Http::response(['message' => 'gateway timeout'], 503)
                : Http::response(['response' => $remote]);
            if ($disableAfterRead) {
                // The response is the old snapshot; the panel changes before PATCH.
                $remote['status'] = 'DISABLED';
            }

            return $response;
        }
        if ($r->method() === 'PATCH' && $r->url() === 'https://rw-ren02.test/api/users') {
            // Remnawave 3.4.4 updateUser: quota/expiry restore LIMITED/EXPIRED,
            // while DISABLED is preserved unless ACTIVE is explicitly requested.
            if (isset($r['status'])) {
                $remote['status'] = $r['status'];
            } elseif (($remote['status'] === 'LIMITED' && $r['trafficLimitBytes'] > $remote['trafficLimitBytes'])
                || ($remote['status'] === 'EXPIRED' && $r['expireAt'] !== $remote['expireAt']
                    && new DateTimeImmutable($r['expireAt']) > new DateTimeImmutable('now'))) {
                $remote['status'] = 'ACTIVE';
            }
            $remote['expireAt'] = $r['expireAt'];
            $remote['trafficLimitBytes'] = $r['trafficLimitBytes'];

            return $failUpdate
                ? Http::response(['error' => 'lost-response'], 504)
                : Http::response(['response' => $remote]);
        }

        return Http::response(['message' => 'unmocked request'], 503);
    });
}

function ren02WalletPay(array $data, int $renewalOrder): void
{
    $topup = app(RequestTopup::class)->handle($data['buyer']->id, 1_500_000, 'ren02-topup:'.bin2hex(random_bytes(8)));
    app(SubmitTopupPhoto::class)->handle($data['buyer']->id, $topup, $data['telegram'],
        'ren02-wallet-photo-'.$topup, 'ren02-wallet-unique-'.$topup, $topup + 300);
    app(ReviewTopup::class)->handle($data['root']->id, $topup, true, 'REN02WALLET'.$topup);
    app(SettleWalletOrder::class)->handle(SubscriptionViewer::telegram($data['telegram']), $renewalOrder);
}

it('creates a stable payable renewal order bound to the old subscription without a second service', function (): void {
    $data = ren02Fixture();
    $remote = ren02Remote($data['source']);
    $fail = false;
    ren02Http($remote, $fail);
    $act = app(CreatePendingRenewalOrder::class);
    $viewer = SubscriptionViewer::telegram($data['telegram']);
    $one = $act->handle($viewer, $data['source']);
    $again = $act->handle($viewer, $data['source']);
    expect($again->id)->toBe($one->id)
        ->and(DB::table('sales_renewal_orders')->count())->toBe(1)
        ->and(DB::table('sales_orders')->count())->toBe(2)
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(1)
        ->and(DB::table('provisioning_order_links')->count())->toBe(1)
        ->and(DB::table('sales_order_settlements')->count())->toBe(0);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'GET');
    Http::assertNotSent(static fn (Request $r): bool => $r->method() !== 'GET');
});

it('settles wallet exactly once and sends one absolute renewal UPDATE without CREATE', function (): void {
    $data = ren02Fixture();
    $remote = ren02Remote($data['source']);
    $fail = false;
    ren02Http($remote, $fail);
    $order = app(CreatePendingRenewalOrder::class)
        ->handle(SubscriptionViewer::telegram($data['telegram']), $data['source'])->id;

    ren02WalletPay($data, $order);
    expect(DB::table('sales_fulfillment_outbox')->where('sales_order_id', $order)->value('event_key'))
        ->toBe('sales-order:'.$order.':renew')
        ->and(DB::table('sales_order_settlements')->where('sales_order_id', $order)->count())->toBe(1);

    $result = app(FulfillPaidRenewalOrder::class)->handle($order);
    expect($result)->toBe(52)
        ->and($remote['trafficLimitBytes'])->toBe(40 * 1073741824)
        ->and($remote['expireAt'])->toBe(now()->utc()->addDays(33)->format('Y-m-d\TH:i:s\Z'))
        ->and($remote['subscriptionUrl'])->toBe('https://secret.test/unchanged-link')
        ->and(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('fulfilled')
        ->and(DB::table('sales_fulfillment_outbox')->where('sales_order_id', $order)->value('state'))->toBe('completed')
        ->and(DB::table('provisioning_order_links')->count())->toBe(1)
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(1)
        ->and(DB::table('billing_journals')->where('kind', 'purchase')->count())->toBe(1)
        ->and(DB::table('telegram_notification_deliveries')->where('event_type', 'SubscriptionRenewed')->count())->toBe(1);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'PATCH'
        && $r->url() === 'https://rw-ren02.test/api/users'
        && $r['id'] === 52 && ! array_key_exists('status', $r->data())
        && $r['trafficLimitBytes'] === 40 * 1073741824);
    Http::assertNotSent(static fn (Request $r): bool => $r->method() === 'POST');
    expect(fn () => app(FulfillPaidRenewalOrder::class)->handle($order))->toThrow(RuntimeException::class);
    expect(fn () => app(FulfillPaidPlanOrder::class)->handle($order))->toThrow(RuntimeException::class);
    Http::assertSentCount(3); // Draft GET, fulfillment GET, one PATCH.
});

it('uses the existing verified bank review and renewal outbox without an extra provider user', function (): void {
    $data = ren02Fixture();
    $remote = ren02Remote($data['source']);
    $fail = false;
    ren02Http($remote, $fail);
    $order = app(CreatePendingRenewalOrder::class)
        ->handle(SubscriptionViewer::telegram($data['telegram']), $data['source'])->id;
    $bank = app(ManualBankPayments::class);
    $claim = $bank->submit(SubscriptionViewer::telegram($data['telegram']), $order,
        'ren02-bank-photo-'.$order, 'ren02-bank-unique-'.$order, 1234 + $order, 'sales');
    $bank->review($data['root']->id, $claim, true, 'REN02BANK'.$order);

    expect(DB::table('sales_bank_settlements')->where('sales_order_id', $order)->count())->toBe(1)
        ->and(DB::table('sales_fulfillment_outbox')->where('sales_order_id', $order)->value('event_key'))
        ->toBe('sales-order:'.$order.':renew');
    expect(app(FulfillPaidRenewalOrder::class)->handle($order))->toBe(52);
    expect(DB::table('provisioning_subscriptions')->count())->toBe(1)
        ->and(DB::table('provisioning_order_links')->count())->toBe(1)
        ->and(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('fulfilled');
    Http::assertNotSent(static fn (Request $r): bool => $r->method() === 'POST');
});

it('preserves unlimited traffic and changes the actual expiry only', function (): void {
    $data = ren02Fixture(unlimited: true);
    $remote = ren02Remote($data['source'], 0);
    $fail = false;
    ren02Http($remote, $fail);
    $order = app(CreatePendingRenewalOrder::class)
        ->handle(SubscriptionViewer::telegram($data['telegram']), $data['source'])->id;
    ren02WalletPay($data, $order);
    expect(app(FulfillPaidRenewalOrder::class)->handle($order))->toBe(52);
    expect($remote['trafficLimitBytes'])->toBe(0)
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(1);
});

it('renews eligible provider states through quota and expiry without requesting a status change', function (string $status, bool $unlimited): void {
    $data = ren02Fixture($unlimited);
    $remote = ren02Remote($data['source'], $unlimited ? 0 : 21474836480, $status);
    if ($status === 'EXPIRED') {
        $remote['expireAt'] = now()->utc()->subDay()->format('Y-m-d\TH:i:s\Z');
    }
    $fail = false;
    ren02Http($remote, $fail);
    $order = app(CreatePendingRenewalOrder::class)
        ->handle(SubscriptionViewer::telegram($data['telegram']), $data['source'])->id;
    ren02WalletPay($data, $order);
    $postings = DB::table('billing_postings')->orderBy('id')->get()->all();

    expect(app(FulfillPaidRenewalOrder::class)->handle($order))->toBe(52)
        ->and($remote['status'])->toBe('ACTIVE')
        ->and($remote['trafficLimitBytes'])->toBe($unlimited ? 0 : 40 * 1073741824)
        ->and(DB::table('billing_postings')->orderBy('id')->get()->all())->toEqual($postings)
        ->and(DB::table('provisioning_order_links')->count())->toBe(1);
    $expected = json_decode(DB::table('provisioning_operations')->where('kind', 'renewal')->value('expected_payload'), true, 512, JSON_THROW_ON_ERROR);
    expect($expected['status'])->toBe('ACTIVE');
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'PATCH'
        && ! array_key_exists('status', $r->data()));
    Http::assertNotSent(static fn (Request $r): bool => $r->method() === 'POST');
})->with([['ACTIVE', false], ['LIMITED', false], ['EXPIRED', false], ['EXPIRED', true]]);

it('preserves an administrative disable between renewal GET and PATCH without announcing success or replaying', function (string $payment): void {
    $data = ren02Fixture();
    $remote = ren02Remote($data['source']);
    $fail = false;
    ren02Http($remote, $fail);
    $order = app(CreatePendingRenewalOrder::class)
        ->handle(SubscriptionViewer::telegram($data['telegram']), $data['source'])->id;
    if ($payment === 'wallet') {
        ren02WalletPay($data, $order);
    } else {
        $bank = app(ManualBankPayments::class);
        $claim = $bank->submit(SubscriptionViewer::telegram($data['telegram']), $order,
            'race-bank-'.$order, 'race-bank-unique-'.$order, 8000 + $order, 'sales');
        $bank->review($data['root']->id, $claim, true, 'REN02RACE'.$order);
    }
    $journals = DB::table('billing_journals')->orderBy('id')->get()->all();
    $postings = DB::table('billing_postings')->orderBy('id')->get()->all();
    $failRead = false;
    ren02Http($remote, $fail, $failRead, disableAfterRead: true);

    expect(fn () => app(FulfillPaidRenewalOrder::class)->handle($order))->toThrow(RuntimeException::class);
    expect($remote['status'])->toBe('DISABLED')
        ->and(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('paid')
        ->and(DB::table('sales_fulfillment_outbox')->where('sales_order_id', $order)->value('state'))->toBe('needs_review')
        ->and(DB::table('provisioning_operations')->where('kind', 'renewal')->value('state'))->toBe('needs_review')
        ->and(DB::table('telegram_notification_deliveries')->where('event_type', 'SubscriptionRenewed')->count())->toBe(0);
    expect(fn () => app(FulfillPaidRenewalOrder::class)->handle($order))->toThrow(RuntimeException::class);
    config()->set('services.remnawave.writes_enabled', false);
    expect(fn () => app(FulfillPaidRenewalOrder::class)->reconcile($order))->toThrow(RuntimeException::class);
    expect(DB::table('billing_journals')->orderBy('id')->get()->all())->toEqual($journals)
        ->and(DB::table('billing_postings')->orderBy('id')->get()->all())->toEqual($postings)
        ->and(DB::table('provisioning_order_links')->count())->toBe(1);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'PATCH'
        && ! array_key_exists('status', $r->data()));
    expect(collect(Http::recorded())->filter(static fn ($pair): bool => $pair[0]->method() === 'PATCH')->count())->toBe(1);
    Http::assertNotSent(static fn (Request $r): bool => $r->method() === 'POST');
})->with(['bank', 'wallet']);

it('does not retry a timed-out provider UPDATE and only reconciles frozen targets using GET', function (): void {
    $data = ren02Fixture();
    $remote = ren02Remote($data['source']);
    $fail = true;
    ren02Http($remote, $fail);
    $order = app(CreatePendingRenewalOrder::class)
        ->handle(SubscriptionViewer::telegram($data['telegram']), $data['source'])->id;
    ren02WalletPay($data, $order);

    expect(fn () => app(FulfillPaidRenewalOrder::class)->handle($order))->toThrow(RuntimeException::class);
    expect(DB::table('sales_fulfillment_outbox')->where('sales_order_id', $order)->value('state'))
        ->toBe('needs_review')
        ->and(DB::table('provisioning_subscriptions')->value('state'))->toBe('needs_review');
    $patches = collect(Http::recorded())->filter(static fn ($pair): bool => $pair[0]->method() === 'PATCH')->count();
    expect($patches)->toBe(1);

    $fail = false;
    expect(app(FulfillPaidRenewalOrder::class)->reconcile($order))->toBe(52);
    expect(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('fulfilled')
        ->and(collect(Http::recorded())->filter(static fn ($pair): bool => $pair[0]->method() === 'PATCH')->count())->toBe(1);
});

it('does not allow foreign ownership or disabled feature to create or debit orders', function (): void {
    $data = ren02Fixture();
    $remote = ren02Remote($data['source']);
    $fail = false;
    ren02Http($remote, $fail);
    $other = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $other->id, 'telegram_user_id' => 991999]);

    expect(fn () => app(CreatePendingRenewalOrder::class)->handle(
        SubscriptionViewer::telegram(991999), $data['source'],
    ))->toThrow(ValidationException::class);
    config()->set('sales.renewal_enabled', false);
    expect(fn () => app(CreatePendingRenewalOrder::class)->handle(
        SubscriptionViewer::telegram($data['telegram']), $data['source'],
    ))->toThrow(ValidationException::class);
    expect(DB::table('sales_renewal_orders')->count())->toBe(0)
        ->and(DB::table('sales_orders')->count())->toBe(1);
    Http::assertNothingSent();
});

it('rejects a disabled remote service and never creates a payable renewal', function (): void {
    $data = ren02Fixture();
    $remote = ren02Remote($data['source'], status: 'DISABLED');
    $fail = false;
    ren02Http($remote, $fail);
    expect(fn () => app(CreatePendingRenewalOrder::class)->handle(
        SubscriptionViewer::telegram($data['telegram']), $data['source'],
    ))->toThrow(ValidationException::class);
    expect(DB::table('sales_renewal_orders')->count())->toBe(0);
});

it('retries only a failed provider preflight GET and never issues an uncertain PATCH twice', function (): void {
    $data = ren02Fixture();
    $remote = ren02Remote($data['source']);
    $fail = false;
    $failRead = false;
    ren02Http($remote, $fail, $failRead);
    $order = app(CreatePendingRenewalOrder::class)
        ->handle(SubscriptionViewer::telegram($data['telegram']), $data['source'])->id;
    ren02WalletPay($data, $order);

    $failRead = true;
    expect(fn () => app(FulfillPaidRenewalOrder::class)->handle($order))->toThrow(RuntimeException::class);
    expect(DB::table('sales_fulfillment_outbox')->where('sales_order_id', $order)->value('state'))->toBe('pending')
        ->and((int) DB::table('sales_fulfillment_outbox')->where('sales_order_id', $order)->value('attempts'))->toBe(0)
        ->and(DB::table('provisioning_operations')->where('kind', 'renewal')->count())->toBe(0)
        ->and(DB::table('sales_renewal_orders')->where('sales_order_id', $order)->value('target_expire_at'))->toBeNull();
    Http::assertNotSent(static fn (Request $r): bool => $r->method() === 'PATCH');

    $failRead = false;
    expect(app(FulfillPaidRenewalOrder::class)->handle($order))->toBe(52);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'PATCH');
});

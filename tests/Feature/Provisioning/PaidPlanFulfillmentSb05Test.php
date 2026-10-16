<?php

declare(strict_types=1);

use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\FulfillPaidPlanOrder;
use App\Modules\Provisioning\Application\GetMySubscription;
use App\Modules\Sales\Application\CalculatePlanQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\CreatePendingPlanOrder;
use App\Modules\Sales\Application\ManagePlanPricing;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\ManualBankPayments;
use App\Modules\Sales\Application\PlanDefinition;
use App\Modules\Sales\Application\PlanPricingDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

const SB05_SQUAD = '123e4567-e89b-12d3-a456-426614174000';

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['975001', '975002']);
    config()->set('services.remnawave.url', 'https://remna.test');
    config()->set('services.remnawave.token', 'fake-token');
    config()->set('services.remnawave.writes_enabled', false);
    config()->set('services.remnawave.squad_bindings_live_verified', false);
    config()->set('sales.manual_bank_review_enabled', false);
    config()->set('telegram_notifications.enabled', false);
    Http::preventStrayRequests();
});

/** @return array{buyer: User, root: User, order: int, plan: int} */
function sb05Order(bool $time = false, bool $pay = true): array
{
    app(ConfigureProduct::class)->handle('vpn-sb05', 'آزمایش VPN');
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 975001]);
    $buyer = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $buyer->id, 'telegram_user_id' => 975099]);

    $definition = new PlanDefinition(
        'vpn-sb05', $time ? 'sb05-time' : 'sb05-hybrid', 'آزمایش پلن',
        $time ? 'time' : 'hybrid', 'preset', 'both',
        $time ? null : 20, 30, $time ? null : 1, $time ? null : 100, 15, 90,
    );
    $plans = app(ManagePlans::class);
    $plan = $plans->create($root->id, $definition);
    $plans->confirmChange($root->id, $plans->requestChange($root->id, $plan->id, 1, 'activate'));

    $pricing = app(ManagePlanPricing::class);
    $revision = $pricing->configure($root->id, $plan->id, 2, 0,
        new PlanPricingDefinition('fixed', 1_000_000, 0, 0, 0, 0, 100_000));
    $pricing->confirmChange($root->id, $pricing->requestChange($root->id, $plan->id, 2, $revision, 'activate'));

    DB::table('sales_plan_internal_squad_bindings')->insert([
        'plan_id' => $plan->id, 'plan_revision' => 2, 'internal_squad_uuid' => SB05_SQUAD,
        'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    config()->set('services.remnawave.squad_bindings_live_verified', true);
    $quote = app(CalculatePlanQuote::class)->handle($buyer->id, $plan->id, 30, $time ? null : 20);
    $order = app(CreatePendingPlanOrder::class)->handle($buyer->id, $quote, bin2hex(random_bytes(16)))->id;

    if ($pay) {
        config()->set('sales.manual_bank_review_enabled', true);
        $bank = app(ManualBankPayments::class);
        $claim = $bank->submit(SubscriptionViewer::telegram(975099), $order,
            'receipt-file-sb05-'.$order, 'receipt-unique-sb05-'.$order, 500 + $order, 'sales');
        $bank->review($root->id, $claim, true, 'SB05BANK'.$order.'VERIFIED');
        config()->set('sales.manual_bank_review_enabled', false);
    }

    return ['buyer' => $buyer, 'root' => $root, 'order' => $order, 'plan' => $plan->id];
}

/** @return array<string, mixed> */
function sb05Remote(int $orderId, string $expire, int $bytes): array
{
    $reference = 'sales-order:'.$orderId;

    return [
        'id' => 52, 'shortUuid' => 'sb05-safe-short',
        'username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
        'status' => 'ACTIVE',
        'expireAt' => $expire, 'trafficLimitBytes' => $bytes,
        'trafficLimitStrategy' => 'NO_RESET',
        'description' => 'SkyNetwork ref:'.hash('sha256', $reference),
        'subscriptionUrl' => 'https://not-displayed.example.test/secret',
        'activeInternalSquads' => [['uuid' => SB05_SQUAD, 'name' => 'Staging']],
        'userTraffic' => ['usedTrafficBytes' => 0, 'lifetimeUsedTrafficBytes' => 0],
    ];
}

function sb05FakeCreate(int $orderId): void
{
    Http::fake(['https://remna.test/api/users' => static function (Request $request) use ($orderId) {
        return Http::response(['response' => sb05Remote(
            $orderId, (string) $request['expireAt'], (int) $request['trafficLimitBytes'],
        )], 201);
    }]);
}

it('keeps the existing fulfillment outbox dormant when provider writes are disabled', function (): void {
    $data = sb05Order();
    Http::fake();
    $action = app(FulfillPaidPlanOrder::class);

    expect(fn () => $action->handle($data['order']))->toThrow(RuntimeException::class, 'disabled');
    expect(DB::table('sales_fulfillment_outbox')->where('sales_order_id', $data['order'])->value('state'))->toBe('pending')
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(0)
        ->and(DB::table('provisioning_order_links')->count())->toBe(0);
    Http::assertNothingSent();
});

it('requires a genuine settled managed-plan order and does not trust a forged paid flag', function (): void {
    $data = sb05Order(pay: false);
    config()->set('services.remnawave.writes_enabled', true);
    Http::fake();
    $action = app(FulfillPaidPlanOrder::class);

    expect(fn () => $action->handle($data['order']))->toThrow(RuntimeException::class);
    DB::table('sales_orders')->where('id', $data['order'])->update(['status' => 'paid']);
    DB::table('sales_fulfillment_outbox')->insert([
        'sales_order_id' => $data['order'], 'event_key' => 'sales-order:'.$data['order'].':create',
        'state' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(fn () => $action->handle($data['order']))->toThrow(RuntimeException::class, 'settlement');
    expect(DB::table('provisioning_subscriptions')->count())->toBe(0);
    Http::assertNothingSent();
});

it('makes one verified provider CREATE and links exactly one subscription to its owner', function (): void {
    $data = sb05Order();
    config()->set('services.remnawave.writes_enabled', true);
    sb05FakeCreate($data['order']);
    $action = app(FulfillPaidPlanOrder::class);

    expect($action->handle($data['order']))->toBe(52);
    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
        && $r->url() === 'https://remna.test/api/users'
        && $r['trafficLimitBytes'] === 20 * 1073741824
        && $r['activeInternalSquads'] === [SB05_SQUAD]
        && $r->hasHeader('Authorization', 'Bearer fake-token'));

    expect(DB::table('sales_orders')->where('id', $data['order'])->value('status'))->toBe('fulfilled')
        ->and(DB::table('sales_fulfillment_outbox')->value('state'))->toBe('completed')
        ->and(DB::table('sales_fulfillment_outbox')->value('attempts'))->toBe(1)
        ->and(DB::table('provisioning_order_links')->count())->toBe(1)
        ->and(DB::table('provisioning_operations')->where('state', 'completed')->count())->toBe(1)
        ->and(app(GetMySubscription::class)->handle(SubscriptionViewer::telegram(975099), $data['order'])->summary->status)->toBe('active');

    $other = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $other->id, 'telegram_user_id' => 975098]);
    expect(fn () => app(GetMySubscription::class)->handle(SubscriptionViewer::telegram(975098), $data['order']))
        ->toThrow(ValidationException::class);
    expect(fn () => $action->handle($data['order']))->toThrow(RuntimeException::class, 'already claimed');
    Http::assertSentCount(1);
});

it('refuses a revoked squad verification or tampered bank claim without touching the provider', function (): void {
    $data = sb05Order();
    config()->set('services.remnawave.writes_enabled', true);
    Http::fake();
    DB::table('sales_plan_internal_squad_bindings')->where('plan_id', $data['plan'])
        ->update(['verified_at' => null]);
    expect(fn () => app(FulfillPaidPlanOrder::class)->handle($data['order']))
        ->toThrow(RuntimeException::class, 'Squad');

    DB::table('sales_plan_internal_squad_bindings')->where('plan_id', $data['plan'])
        ->update(['verified_at' => now()]);
    DB::table('sales_bank_payment_claims')->where('sales_order_id', $data['order'])
        ->update(['status' => 'rejected']);
    expect(fn () => app(FulfillPaidPlanOrder::class)->handle($data['order']))
        ->toThrow(RuntimeException::class, 'settlement');
    expect(DB::table('sales_fulfillment_outbox')->value('state'))->toBe('pending');
    Http::assertNothingSent();
});

it('never repeats a provider POST after an uncertain outcome and reconciles with GET only', function (): void {
    $data = sb05Order();
    config()->set('services.remnawave.writes_enabled', true);
    Http::fake(['https://remna.test/api/users' => Http::response(['message' => 'unavailable'], 503)]);
    $action = app(FulfillPaidPlanOrder::class);

    expect(fn () => $action->handle($data['order']))->toThrow(RuntimeException::class, 'reconciliation');
    expect(DB::table('sales_fulfillment_outbox')->value('state'))->toBe('needs_review')
        ->and(DB::table('provisioning_subscriptions')->value('state'))->toBe('needs_review')
        ->and(DB::table('provisioning_order_links')->count())->toBe(0);
    Http::assertSentCount(1);
    expect(fn () => $action->handle($data['order']))->toThrow(RuntimeException::class, 'already claimed');
    Http::assertSentCount(1);

    $expected = json_decode((string) DB::table('provisioning_operations')->value('expected_payload'), true, 512, JSON_THROW_ON_ERROR);
    config()->set('services.remnawave.writes_enabled', false);
    Http::fake(['https://remna.test/api/users/by-username/*' => Http::response([
        'response' => sb05Remote($data['order'], $expected['expireAt'], $expected['trafficLimitBytes']),
    ], 200)]);
    expect($action->reconcile($data['order']))->toBe(52);
    expect(DB::table('sales_fulfillment_outbox')->value('state'))->toBe('completed')
        ->and(DB::table('provisioning_order_links')->count())->toBe(1)
        ->and(DB::table('sales_orders')->where('id', $data['order'])->value('status'))->toBe('fulfilled');
    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'GET');
});

it('rejects foreign ownership markers during recovery and keeps the paid order blocked', function (): void {
    $data = sb05Order();
    config()->set('services.remnawave.writes_enabled', true);
    Http::fake(['https://remna.test/api/users' => Http::response('', 504)]);
    try {
        app(FulfillPaidPlanOrder::class)->handle($data['order']);
    } catch (RuntimeException) {
        // The provider may have applied the create: never retry it.
    }

    $expected = json_decode((string) DB::table('provisioning_operations')->value('expected_payload'), true, 512, JSON_THROW_ON_ERROR);
    $remote = sb05Remote($data['order'], $expected['expireAt'], $expected['trafficLimitBytes']);
    $remote['description'] = 'foreign-owned';
    Http::fake(['https://remna.test/api/users/by-username/*' => Http::response(['response' => $remote], 200)]);
    expect(fn () => app(FulfillPaidPlanOrder::class)->reconcile($data['order']))->toThrow(RuntimeException::class);
    expect(DB::table('provisioning_order_links')->count())->toBe(0)
        ->and(DB::table('sales_fulfillment_outbox')->value('state'))->toBe('needs_review');
    Http::assertSentCount(1);
});

it('converts an unlimited time plan into zero bytes without modifying the source order', function (): void {
    $data = sb05Order(time: true);
    config()->set('services.remnawave.writes_enabled', true);
    sb05FakeCreate($data['order']);
    expect(app(FulfillPaidPlanOrder::class)->handle($data['order']))->toBe(52);
    Http::assertSent(static fn (Request $r): bool => $r['trafficLimitBytes'] === 0);
    expect(DB::table('sales_orders')->where('id', $data['order'])->value('traffic_gb'))->toBeNull();
});

it('registers an explicit one-order operator command without a background dispatcher', function (): void {
    $data = sb05Order();
    Http::fake();
    $this->artisan('sales:fulfillment:run', ['order' => $data['order']])->assertFailed();
    expect(DB::table('sales_fulfillment_outbox')->value('state'))->toBe('pending');
    Http::assertNothingSent();
});

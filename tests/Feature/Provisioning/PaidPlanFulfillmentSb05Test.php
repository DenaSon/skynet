<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use App\Interfaces\Telegram\Notifications\NotificationUnavailable;
use App\Interfaces\Telegram\Notifications\TelegramNotificationSender;
use App\Modules\Billing\Application\RequestTopup;
use App\Modules\Billing\Application\ReviewTopup;
use App\Modules\Billing\Application\SubmitTopupPhoto;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\FulfillPaidPlanOrder;
use App\Modules\Provisioning\Application\GetMySubscription;
use App\Modules\Provisioning\Application\ManageFulfilledSubscriptionStatus;
use App\Modules\Provisioning\Application\ReviewPaidFulfillment;
use App\Modules\Provisioning\Jobs\ChangeFulfilledSubscriptionStatusJob;
use App\Modules\Provisioning\Jobs\FulfillPaidPlanOrderJob;
use App\Modules\Sales\Application\CalculatePlanQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\CreatePendingPlanOrder;
use App\Modules\Sales\Application\ManagePlanPricing;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\ManualBankPayments;
use App\Modules\Sales\Application\PlanDefinition;
use App\Modules\Sales\Application\PlanPricingDefinition;
use App\Modules\Sales\Application\SettleWalletOrder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;

uses(RefreshDatabase::class);

const SB05_SQUAD = '123e4567-e89b-12d3-a456-426614174000';

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['975001', '975002']);
    config()->set('services.remnawave.url', 'https://remna.test');
    config()->set('services.remnawave.token', 'fake-token');
    config()->set('services.remnawave.writes_enabled', false);
    config()->set('services.remnawave.squad_bindings_live_verified', false);
    config()->set('sales.manual_bank_review_enabled', false);
    config()->set('sales.auto_fulfillment_enabled', false);
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

it('holds a paid order with revoked squad verification before any provider attempt', function (): void {
    $data = sb05Order();
    config()->set('services.remnawave.writes_enabled', true);
    Http::fake();
    DB::table('sales_plan_internal_squad_bindings')->where('plan_id', $data['plan'])
        ->update(['verified_at' => null]);
    $rollbacks = 0;
    Event::listen(TransactionRolledBack::class, static function () use (&$rollbacks): void {
        $rollbacks++;
    });
    expect(fn () => app(FulfillPaidPlanOrder::class)->handle($data['order']))
        ->toThrow(RuntimeException::class, 'Squad');
    expect(DB::table('sales_fulfillment_outbox')->value('state'))->toBe('needs_review')
        ->and(DB::table('sales_fulfillment_outbox')->value('attempts'))->toBe(0)
        // No rollback may release readiness locks before committing the hold.
        ->and($rollbacks)->toBe(0);
    Http::assertNothingSent();
});

it('refuses a tampered bank claim without touching the provider', function (): void {
    $data = sb05Order();
    config()->set('services.remnawave.writes_enabled', true);
    Http::fake();
    DB::table('sales_bank_payment_claims')->where('sales_order_id', $data['order'])
        ->update(['status' => 'rejected']);
    expect(fn () => app(FulfillPaidPlanOrder::class)->handle($data['order']))
        ->toThrow(RuntimeException::class, 'settlement');
    expect(DB::table('sales_fulfillment_outbox')->value('state'))->toBe('pending');
    Http::assertNothingSent();
});

it('leaves stale managed bank receipts unapproved and preserves their original order', function (string $change): void {
    $data = sb05Order(pay: false);
    config()->set('sales.manual_bank_review_enabled', true);
    Http::fake();
    $bank = app(ManualBankPayments::class);
    $claim = $bank->submit(SubscriptionViewer::telegram(975099), $data['order'],
        'patch01-file-100', 'patch01-unique-100', 9100, 'sales');
    $snapshot = DB::table('sales_orders')->where('id', $data['order'])->first();

    match ($change) {
        'revision' => DB::table('sales_plans')->where('id', $data['plan'])->increment('revision'),
        'inactive' => DB::table('sales_plans')->where('id', $data['plan'])->update(['status' => 'inactive']),
        'squad' => DB::table('sales_plan_internal_squad_bindings')->where('plan_id', $data['plan'])
            ->update(['internal_squad_uuid' => '123e4567-e89b-12d3-a456-426614174001']),
        'unverified' => DB::table('sales_plan_internal_squad_bindings')->where('plan_id', $data['plan'])
            ->update(['verified_at' => null]),
    };

    expect(fn () => $bank->review($data['root']->id, $claim, true, 'PATCH01BANK100'))
        ->toThrow(ValidationException::class)
        ->and(DB::table('sales_orders')->where('id', $data['order'])->first())->toEqual($snapshot)
        ->and(DB::table('sales_bank_payment_claims')->where('id', $claim)->value('status'))->toBe('submitted')
        ->and(DB::table('sales_bank_settlements')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(0);
    // A catalog change must never prevent a human from rejecting the receipt.
    expect($bank->review($data['root']->id, $claim, false)->approved)->toBeFalse();
    Http::assertNothingSent();
})->with(['revision', 'inactive', 'squad', 'unverified']);

it('holds a changed paid plan once and alerts only a freshly authorized root', function (): void {
    $data = sb05Order();
    config()->set('services.remnawave.writes_enabled', true);
    config()->set('telegram_notifications.enabled', true);
    config()->set('nutgram.token', '12345:admin_test');
    Queue::fake();
    Http::fake(['https://api.telegram.org/bot12345:admin_test/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    DB::table('sales_plans')->where('id', $data['plan'])->increment('revision');
    $snapshot = DB::table('sales_orders')->where('id', $data['order'])->first();

    expect(fn () => app(FulfillPaidPlanOrder::class)->handle($data['order']))->toThrow(RuntimeException::class, 'operator review')
        ->and(DB::table('sales_orders')->where('id', $data['order'])->first())->toEqual($snapshot)
        ->and(DB::table('sales_fulfillment_outbox')->value('state'))->toBe('needs_review')
        ->and(DB::table('sales_fulfillment_outbox')->value('attempts'))->toBe(0)
        ->and(DB::table('provisioning_operations')->count())->toBe(0);
    $notice = DB::table('telegram_notification_deliveries')->where('event_type', 'FulfillmentPlanBlocked')->first();
    expect($notice)->not->toBeNull()
        ->and((int) $notice->recipient_user_id)->toBe($data['root']->id)
        ->and($notice->bot_key)->toBe('admin');
    expect(fn () => app(FulfillPaidPlanOrder::class)->handle($data['order']))->toThrow(RuntimeException::class);
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'FulfillmentPlanBlocked')->count())->toBe(1);
    Http::assertNothingSent();

    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make(975001, false, 'Root'));
    $bot->setCommonChat(Chat::make(975001, ChatType::PRIVATE));
    $bot->hearCallbackQueryData('full:view:'.$data['order'])->reply()->assertRaw(
        static fn (GuzzleHttp\Psr7\Request $request): bool => str_contains((string) $request->getBody(), 'full:prepare:') === false,
        index: 1,
    );
    expect(fn () => app(ReviewPaidFulfillment::class)->authorizeRequest($data['root']->id, $data['order'], true))
        ->toThrow(ValidationException::class);

    app(TelegramNotificationSender::class)->send($notice);
    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $request): bool => str_contains((string) $request['text'], 'پیش از ساخت اشتراک')
        && str_contains((string) $request['reply_markup'], 'full:view:'.$data['order']));
    $data['root']->syncRoles(['admin']);
    expect(fn () => app(TelegramNotificationSender::class)->send($notice))->toThrow(NotificationUnavailable::class);
    Http::assertSentCount(1);
});

it('fulfills a ready customer wallet order once through the existing provider job', function (): void {
    $data = sb05Order(pay: false);
    config()->set('billing.topups_enabled', true);
    $topup = app(RequestTopup::class)->handle($data['buyer']->id, 1_500_000, 'patch01:wallet:credit');
    app(SubmitTopupPhoto::class)->handle($data['buyer']->id, $topup, 975099, 'patch01-wallet-file', 'patch01-wallet-unique', 9200);
    app(ReviewTopup::class)->handle($data['root']->id, $topup, true, 'PATCH01WALLET100');
    config()->set('sales.wallet_settlement_enabled', true);
    config()->set('sales.auto_fulfillment_enabled', true);
    config()->set('services.remnawave.writes_enabled', true);
    config()->set('telegram_notifications.enabled', true);
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram.sales.token', '54321:sales_test');
    config()->set('nutgram.token', '12345:admin_test');
    Queue::fake();
    sb05FakeCreate($data['order']);
    $viewer = SubscriptionViewer::telegram(975099);
    app(SettleWalletOrder::class)->handle($viewer, $data['order']);
    Queue::assertPushed(FulfillPaidPlanOrderJob::class,
        static fn (FulfillPaidPlanOrderJob $job): bool => $job->orderId === $data['order'] && $job->actorId === 0);

    // An already paid replay remains read-only even if payment gates close.
    config()->set('services.remnawave.writes_enabled', false);
    app(SettleWalletOrder::class)->handle($viewer, $data['order']);
    config()->set('services.remnawave.writes_enabled', true);
    $job = new FulfillPaidPlanOrderJob($data['order']);
    $job->handle(app(FulfillPaidPlanOrder::class), app(AssertCatalogManagementAccess::class));
    $job->handle(app(FulfillPaidPlanOrder::class), app(AssertCatalogManagementAccess::class));

    expect(DB::table('sales_orders')->where('id', $data['order'])->value('status'))->toBe('fulfilled')
        ->and(app(ViewWallet::class)->handle($data['buyer']->id)->balanceRials)->toBe(500_000)
        ->and(DB::table('billing_journals')->where('kind', 'purchase')->count())->toBe(1)
        ->and(DB::table('provisioning_order_links')->count())->toBe(1);
    Http::assertSentCount(1);
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

it('keeps Admin-only fulfilled order directory read-only with genuine paid and outbox scopes', function (): void {
    $data = sb05Order();
    $guest = User::factory()->create();
    Http::fake();

    $reader = app(ReviewPaidFulfillment::class);
    expect($reader->pending($data['root']->id))->toHaveCount(1)
        ->and($reader->pending($data['root']->id)[0]->id)->toBe($data['order']);

    expect(fn () => $reader->pending($guest->id))
        ->toThrow(AuthorizationException::class);
    DB::table('sales_orders')->where('id', $data['order'])->update(['status' => 'pending_payment']);
    expect(fn () => $reader->detail($data['root']->id, $data['order']))
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('does not enqueue a fulfillment from Admin while provider writes are disabled', function (): void {
    $data = sb05Order();
    Queue::fake();
    Http::fake();
    $reviews = app(ReviewPaidFulfillment::class);

    expect(fn () => $reviews->authorizeRequest($data['root']->id, $data['order'], false))
        ->toThrow(ValidationException::class);
    expect($reviews->detail($data['root']->id, $data['order'])->intent_state)->toBe('pending');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('delivers no remote write during Admin confirmation and queues only the trusted paid order', function (): void {
    $data = sb05Order();
    config()->set('services.remnawave.writes_enabled', true);
    config()->set('services.remnawave.squad_bindings_live_verified', true);
    Queue::fake();
    Http::fake();
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make(975001, false, 'Root'));
    $bot->setCommonChat(Chat::make(975001, ChatType::PRIVATE));

    $bot->hearText('/fulfillment')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('full:prepare:'.$data['order'].':start')->reply()
        ->assertCalled('editMessageText');
    // The old manual CREATE confirmation is gone, even for a paid
    // pending order. Only automatic dispatch or explicit GET reconciliation
    // is allowed in the new Admin UX.
    $bot->hearCallbackQueryData('full:view:'.$data['order'])->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_fulfillment_outbox')->value('state'))->toBe('pending')
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('does not replay uncertain provider writes from a queued job', function (): void {
    $data = sb05Order();
    config()->set('services.remnawave.writes_enabled', true);
    Http::fake(['https://remna.test/api/users' => Http::response('provider timeout', 503)]);
    $job = new FulfillPaidPlanOrderJob($data['order'], false, $data['root']->id);

    expect($job->tries)->toBe(1)
        ->and($job->uniqueId())->toBe('parsa:fulfillment:'.$data['order']);
    $job->handle(app(FulfillPaidPlanOrder::class), app(AssertCatalogManagementAccess::class));
    expect(DB::table('sales_fulfillment_outbox')->value('state'))->toBe('needs_review');
    Http::assertSentCount(1);

    $job->handle(app(FulfillPaidPlanOrder::class), app(AssertCatalogManagementAccess::class));
    Http::assertSentCount(1);
});

it('never authorizes an unapproved or unavailable fulfillment state', function (): void {
    $data = sb05Order();
    config()->set('services.remnawave.writes_enabled', true);
    $reader = app(ReviewPaidFulfillment::class);
    expect(fn () => $reader->authorizeRequest($data['root']->id, $data['order'], true))
        ->toThrow(ValidationException::class);
    expect(fn () => $reader->authorizeRequest($data['buyer']->id, $data['order'], false))
        ->toThrow(AuthorizationException::class);

    DB::table('sales_fulfillment_outbox')->where('sales_order_id', $data['order'])
        ->update(['state' => 'needs_review']);
    expect(fn () => $reader->authorizeRequest($data['root']->id, $data['order'], true))
        ->toThrow(ValidationException::class);
    // Only a genuinely recorded uncertain CREATE offers GET reconciliation.
    DB::table('sales_fulfillment_outbox')->where('sales_order_id', $data['order'])
        ->update(['state' => 'pending']);
    Http::fake(['https://remna.test/api/users' => Http::response('provider timeout', 503)]);
    expect(fn () => app(FulfillPaidPlanOrder::class)->handle($data['order']))->toThrow(RuntimeException::class);
    expect($reader->authorizeRequest($data['root']->id, $data['order'], true)->id)->toBe($data['order']);
    expect(fn () => $reader->authorizeRequest($data['root']->id, $data['order'], false))
        ->toThrow(ValidationException::class);
});

it('keeps completed orders visible to the root after a genuine locally recorded activation', function (): void {
    $data = sb05Order();
    config()->set('services.remnawave.writes_enabled', true);
    sb05FakeCreate($data['order']);
    app(FulfillPaidPlanOrder::class)->handle($data['order']);
    Http::assertSentCount(1);

    config()->set('services.remnawave.writes_enabled', false);
    Http::fake();
    Queue::fake();

    $reader = app(ReviewPaidFulfillment::class);
    expect($reader->pending($data['root']->id))->toBeEmpty()
        ->and($reader->completed($data['root']->id))->toHaveCount(1)
        ->and($reader->completed($data['root']->id)[0]->id)->toBe($data['order'])
        ->and($reader->detail($data['root']->id, $data['order'])->intent_state)->toBe('completed')
        ->and($reader->detail($data['root']->id, $data['order'])->order_status)->toBe('fulfilled');

    expect(fn () => $reader->authorizeRequest($data['root']->id, $data['order'], false))
        ->toThrow(ValidationException::class);
    expect(fn () => $reader->authorizeRequest($data['root']->id, $data['order'], true))
        ->toThrow(ValidationException::class);
    expect(fn () => $reader->completed($data['buyer']->id))
        ->toThrow(AuthorizationException::class);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('keeps processing and uncertain orders in the original Admin queue without false success', function (): void {
    $data = sb05Order();
    Http::fake();
    $reader = app(ReviewPaidFulfillment::class);

    foreach (['processing', 'needs_review'] as $state) {
        DB::table('sales_fulfillment_outbox')->where('sales_order_id', $data['order'])
            ->update(['state' => $state]);
        expect($reader->pending($data['root']->id))->toHaveCount(1)
            ->and($reader->completed($data['root']->id))->toBeEmpty()
            ->and($reader->detail($data['root']->id, $data['order'])->intent_state)->toBe($state);
    }

    Http::assertNothingSent();
});

it('does not display incomplete or forged fulfillment state as completed', function (): void {
    $data = sb05Order();
    Http::fake();
    DB::table('sales_fulfillment_outbox')->where('sales_order_id', $data['order'])
        ->update(['state' => 'completed']);
    $reader = app(ReviewPaidFulfillment::class);

    expect($reader->pending($data['root']->id))->toBeEmpty()
        ->and($reader->completed($data['root']->id))->toBeEmpty();
    expect(fn () => $reader->detail($data['root']->id, $data['order']))
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('opens completed Admin results and order details with existing root-only navigation', function (): void {
    $data = sb05Order();
    config()->set('services.remnawave.writes_enabled', true);
    sb05FakeCreate($data['order']);
    app(FulfillPaidPlanOrder::class)->handle($data['order']);
    Http::fake();
    Queue::fake();

    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make(975001, false, 'Root'));
    $bot->setCommonChat(Chat::make(975001, ChatType::PRIVATE));

    $bot->hearCallbackQueryData('full:completed')->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('full:view:'.$data['order'])->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('full:list')->reply()->assertCalled('editMessageText');

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('blocks untrusted or disabled provider status changes without a network write', function (): void {
    $data = sb05Order();
    config()->set('services.remnawave.writes_enabled', true);
    sb05FakeCreate($data['order']);
    app(FulfillPaidPlanOrder::class)->handle($data['order']);
    config()->set('services.remnawave.writes_enabled', false);
    Http::fake();

    $status = app(ManageFulfilledSubscriptionStatus::class);
    expect(fn () => $status->authorizeChange($data['root']->id, $data['order'], 'disable'))
        ->toThrow(ValidationException::class);
    expect(fn () => $status->status($data['buyer']->id, $data['order']))
        ->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('changes only a fulfilled, owned provider subscription and blocks repeat clicks', function (): void {
    $data = sb05Order();
    config()->set('services.remnawave.writes_enabled', true);
    $current = 'ACTIVE';
    $expiry = '';
    $bytes = 0;
    Http::fake(static function (Request $request) use ($data, &$current, &$expiry, &$bytes) {
        if ($request->method() === 'POST') {
            $expiry = (string) $request['expireAt'];
            $bytes = (int) $request['trafficLimitBytes'];
        }
        if ($request->method() === 'PATCH' && $request->url() === 'https://remna.test/api/users') {
            $current = (string) $request['status'];
        }

        return Http::response(['response' => [
            ...sb05Remote($data['order'], $expiry, $bytes),
            'status' => $current,
        ]], $request->method() === 'POST' ? 201 : 200);
    });
    app(FulfillPaidPlanOrder::class)->handle($data['order']);

    $status = app(ManageFulfilledSubscriptionStatus::class);
    expect($status->status($data['root']->id, $data['order']))->toBe('active');
    $disable = 'mvp04:'.$data['order'].':'.str_repeat('a', 32);
    $status->change($data['root']->id, $data['order'], 'disable', $disable);
    expect($status->status($data['root']->id, $data['order']))->toBe('disabled');
    expect(fn () => $status->change($data['root']->id, $data['order'], 'disable', $disable))
        ->toThrow(ValidationException::class);
    $status->change($data['root']->id, $data['order'], 'enable',
        'mvp04:'.$data['order'].':'.str_repeat('b', 32));
    expect($status->status($data['root']->id, $data['order']))->toBe('active');
    expect(DB::table('provisioning_operations')->whereIn('kind', ['disable', 'enable'])
        ->where('state', 'completed')->count())->toBe(2);
    Http::assertSent(static fn (Request $req): bool => $req->method() === 'PATCH'
        && $req->url() === 'https://remna.test/api/users'
        && (int) $req['id'] === 52 && count($req->data()) === 2
        && $req['status'] === 'DISABLED');
    Http::assertSent(static fn (Request $req): bool => $req->method() === 'PATCH'
        && $req['status'] === 'ACTIVE');
});

it('blocks forged completed orders without a real local owner linkage before GET', function (): void {
    $data = sb05Order();
    $status = app(ManageFulfilledSubscriptionStatus::class);
    config()->set('services.remnawave.writes_enabled', true);
    Http::fake();

    expect(fn () => $status->status($data['root']->id, $data['order']))
        ->toThrow(ValidationException::class);
    DB::table('sales_orders')->where('id', $data['order'])->update(['status' => 'fulfilled']);
    DB::table('sales_fulfillment_outbox')->where('sales_order_id', $data['order'])
        ->update(['state' => 'completed']);
    expect(fn () => $status->status($data['root']->id, $data['order']))
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('reconciles uncertain status updates with GET only and never replays PATCH', function (): void {
    $data = sb05Order();
    config()->set('services.remnawave.writes_enabled', true);
    $expiry = '';
    $bytes = 0;
    $remoteStatus = 'ACTIVE';
    Http::fake(static function (Request $req) use ($data, &$expiry, &$bytes, &$remoteStatus) {
        if ($req->method() === 'POST') {
            $expiry = (string) $req['expireAt'];
            $bytes = (int) $req['trafficLimitBytes'];
        }
        if ($req->method() === 'PATCH') {
            return Http::response('', 503);
        }

        return Http::response(['response' => [
            ...sb05Remote($data['order'], $expiry, $bytes),
            'status' => $remoteStatus,
        ]], $req->method() === 'POST' ? 201 : 200);
    });
    app(FulfillPaidPlanOrder::class)->handle($data['order']);

    $status = app(ManageFulfilledSubscriptionStatus::class);
    expect(fn () => $status->change($data['root']->id, $data['order'], 'disable',
        'mvp04:'.$data['order'].':'.str_repeat('c', 32)))->toThrow(RuntimeException::class);
    expect($status->status($data['root']->id, $data['order']))->toBe('review')
        ->and(DB::table('provisioning_operations')->where('kind', 'disable')->value('state'))->toBe('needs_review');
    Http::assertSentCount(3);

    config()->set('services.remnawave.writes_enabled', false);
    $remoteStatus = 'DISABLED';
    $status->reconcile($data['root']->id, $data['order']);
    expect(DB::table('provisioning_operations')->where('kind', 'disable')->value('state'))->toBe('completed');
    Http::assertSentCount(4);
    Http::assertSent(static fn (Request $req): bool => $req->method() === 'GET');
});

it('keeps private status callbacks read-only when provider writes are disabled', function (): void {
    $data = sb05Order();
    config()->set('services.remnawave.writes_enabled', true);
    sb05FakeCreate($data['order']);
    app(FulfillPaidPlanOrder::class)->handle($data['order']);
    config()->set('services.remnawave.writes_enabled', false);
    Http::fake();
    Queue::fake();

    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make(975001, false, 'Root'));
    $bot->setCommonChat(Chat::make(975001, ChatType::PRIVATE));

    $bot->hearCallbackQueryData('full:service:view:'.$data['order'])->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('full:service:prepare:'.$data['order'].':disable')->reply()
        ->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('full:service:check:'.$data['order'])->reply()
        ->assertCalled('editMessageText');
    Queue::assertNothingPushed();
    Http::assertSentCount(1);

    $job = new ChangeFulfilledSubscriptionStatusJob($data['order'], $data['root']->id, 'disable',
        'mvp04:'.$data['order'].':'.str_repeat('d', 32));
    expect($job->tries)->toBe(1)
        ->and($job->uniqueId())->toBe('parsa:subscription-status:'.$data['order'])
        ->and($job->connection)->toBe('database');
});

it('rechecks queued Admin fulfillment trust and refuses revoked or legacy rootless jobs', function (): void {
    $data = sb05Order();
    config()->set('services.remnawave.writes_enabled', true);
    Http::fake();
    $job = new FulfillPaidPlanOrderJob($data['order'], false, $data['root']->id);
    $legacy = new FulfillPaidPlanOrderJob($data['order']);

    // The one-time Telegram confirmation happened before this role revocation.
    $data['root']->syncRoles(['member']);
    $job->handle(app(FulfillPaidPlanOrder::class), app(AssertCatalogManagementAccess::class));
    $legacy->handle(app(FulfillPaidPlanOrder::class), app(AssertCatalogManagementAccess::class));

    expect(DB::table('sales_fulfillment_outbox')->where('sales_order_id', $data['order'])->value('state'))->toBe('pending')
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(0);
    Http::assertNothingSent();
});

it('exposes a reserved status update for read-only recovery after worker interruption', function (): void {
    $data = sb05Order();
    config()->set('services.remnawave.writes_enabled', true);
    sb05FakeCreate($data['order']);
    app(FulfillPaidPlanOrder::class)->handle($data['order']);

    $reference = 'sales-order:'.$data['order'];
    $key = 'mvp04:'.$data['order'].':'.str_repeat('e', 32);
    $sub = DB::table('provisioning_subscriptions')->where('reference', $reference)->first();
    $createPayload = json_decode((string) DB::table('provisioning_operations')
        ->where('kind', 'create')->value('expected_payload'), true, 512, JSON_THROW_ON_ERROR);

    // Simulate a crash after the durable reservation, before marking the PATCH result.
    DB::table('provisioning_subscriptions')->where('id', $sub->id)->update([
        'state' => 'reserved', 'last_operation_key' => $key,
    ]);
    DB::table('provisioning_operations')->insert([
        'subscription_id' => $sub->id,
        'idempotency_key' => $key,
        'kind' => 'disable',
        'payload_hash' => hash('sha256', json_encode([$reference, 'disable', ['status' => 'DISABLED']], JSON_THROW_ON_ERROR)),
        'expected_payload' => json_encode(['status' => 'DISABLED'], JSON_THROW_ON_ERROR),
        'state' => 'reserved',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    config()->set('services.remnawave.writes_enabled', false);
    $remote = [
        ...sb05Remote($data['order'], $createPayload['expireAt'], $createPayload['trafficLimitBytes']),
        'status' => 'DISABLED',
    ];
    Http::fake(['*' => Http::response(['response' => $remote], 200)]);

    $statuses = app(ManageFulfilledSubscriptionStatus::class);
    expect($statuses->status($data['root']->id, $data['order']))->toBe('review');
    $statuses->authorizeReconcile($data['root']->id, $data['order']);
    expect(fn () => $statuses->authorizeChange($data['root']->id, $data['order'], 'enable'))
        ->toThrow(ValidationException::class);
    expect(fn () => $statuses->status($data['buyer']->id, $data['order']))
        ->toThrow(AuthorizationException::class);

    $statuses->reconcile($data['root']->id, $data['order']);
    expect(DB::table('provisioning_subscriptions')->where('id', $sub->id)->value('state'))->toBe('active')
        ->and(DB::table('provisioning_operations')->where('idempotency_key', $key)->value('state'))->toBe('completed')
        ->and($statuses->status($data['root']->id, $data['order']))->toBe('disabled');
    Http::assertSent(static fn (Request $req): bool => $req->method() === 'GET');
    Http::assertNotSent(static fn (Request $req): bool => $req->method() === 'PATCH');
});

it('queues automatic fulfillment after a committed root review only when all gates are enabled', function (): void {
    $data = sb05Order(pay: false);
    config()->set('sales.manual_bank_review_enabled', true);
    config()->set('sales.auto_fulfillment_enabled', true);
    config()->set('services.remnawave.writes_enabled', true);
    Queue::fake();
    $claim = app(ManualBankPayments::class)->submit(
        SubscriptionViewer::telegram(975099), $data['order'],
        'uxr03-file-123', 'uxr03-unique-123', 700, 'sales',
    );
    app(ManualBankPayments::class)->review($data['root']->id, $claim, true, 'UXR03BANK777');

    Queue::assertPushed(FulfillPaidPlanOrderJob::class,
        static fn (FulfillPaidPlanOrderJob $job): bool => $job->orderId === $data['order']
            && $job->actorId === $data['root']->id && ! $job->reconcile);
    expect(DB::table('sales_fulfillment_outbox')->value('state'))->toBe('pending');
    expect(DB::table('provisioning_subscriptions')->count())->toBe(0);
    Http::assertNothingSent();
});

it('recovers an approved pending bank order using the same guarded queue and no provider call in scheduler', function (): void {
    $data = sb05Order();
    Queue::fake();
    config()->set('sales.auto_fulfillment_enabled', true);
    config()->set('services.remnawave.writes_enabled', true);

    $this->artisan('sales:fulfillment:dispatch')->assertSuccessful();

    Queue::assertPushed(FulfillPaidPlanOrderJob::class,
        static fn (FulfillPaidPlanOrderJob $job): bool => $job->orderId === $data['order']
            && $job->actorId === $data['root']->id);
    Http::assertNothingSent();
});

it('emits one durable Sales activation notice only after verified RW completion and linked ownership', function (): void {
    config()->set('sales.subscription_page_host', 'sub.example.test');
    $data = sb05Order();
    config()->set('telegram_notifications.enabled', true);
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram.sales.token', '54321:sales_test');
    config()->set('nutgram.token', '12345:admin_test');
    config()->set('services.remnawave.writes_enabled', true);
    DB::table('telegram_bot_contacts')->insert([
        'user_id' => $data['buyer']->id, 'bot_key' => 'sales', 'chat_id' => 975099,
        'delivery_status' => 'reachable', 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    Queue::fake();
    sb05FakeCreate($data['order']);

    expect(app(FulfillPaidPlanOrder::class)->handle($data['order']))->toBe(52);
    $delivery = DB::table('telegram_notification_deliveries')
        ->where('event_type', 'SubscriptionActivated')->first();

    expect($delivery)->not->toBeNull()
        ->and($delivery->state)->toBe('pending')
        ->and($delivery->bot_key)->toBe('sales')
        ->and((int) $delivery->recipient_user_id)->toBe($data['buyer']->id)
        ->and((int) $delivery->order_id)->toBe($data['order']);
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'SubscriptionActivated')->count())->toBe(1);

    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
    app(DispatchTelegramNotification::class, ['deliveryId' => $delivery->id])
        ->handle(app(TelegramNotificationSender::class));

    expect(DB::table('telegram_notification_deliveries')->where('id', $delivery->id)->value('state'))->toBe('sent');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '54321:sales_test/sendMessage')
        && str_contains((string) ($request['reply_markup'] ?? ''), 'sales:subs:link:'.$data['order'].':0')
        && str_contains((string) ($request['reply_markup'] ?? ''), 'sales:subs:page:'.$data['order'].':0')
        && ! str_contains((string) ($request['reply_markup'] ?? ''), 'sub.example.test'));
    expect(fn () => app(FulfillPaidPlanOrder::class)->handle($data['order']))->toThrow(RuntimeException::class);
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'SubscriptionActivated')->count())->toBe(1);
});

it('does not notify activation when provider CREATE is uncertain', function (): void {
    $data = sb05Order();
    config()->set('telegram_notifications.enabled', true);
    config()->set('telegram.sales.enabled', true);
    config()->set('services.remnawave.writes_enabled', true);
    DB::table('telegram_bot_contacts')->insert([
        'user_id' => $data['buyer']->id, 'bot_key' => 'sales', 'chat_id' => 975099,
        'delivery_status' => 'reachable', 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    Queue::fake();
    Http::fake(['https://remna.test/api/users' => Http::response(['message' => 'provider unavailable'], 503)]);

    expect(fn () => app(FulfillPaidPlanOrder::class)->handle($data['order']))->toThrow(RuntimeException::class);
    expect(DB::table('sales_fulfillment_outbox')->value('state'))->toBe('needs_review')
        ->and(DB::table('telegram_notification_deliveries')->where('event_type', 'SubscriptionActivated')->count())->toBe(0);
});

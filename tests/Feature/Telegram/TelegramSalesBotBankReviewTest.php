<?php

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use App\Interfaces\Telegram\Notifications\TelegramNotificationSender;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Jobs\FulfillPaidPlanOrderJob;
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
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['974001', '974002']);
    config()->set('sales.manual_bank_review_enabled', false);
    config()->set('sales.one_click_bank_review_enabled', false);
    config()->set('billing.payment_card_number', '6037991234567890'); // Isolated fixture, never a shipped destination.
    config()->set('telegram_notifications.enabled', false);
    config()->set('telegram.sales.enabled', false);
    config()->set('services.remnawave.writes_enabled', false);
    config()->set('services.remnawave.squad_bindings_live_verified', false);
    config()->set('nutgram.token', '12345:admin_test');
    config()->set('telegram.sales.token', '54321:sales_test');
    Http::preventStrayRequests();
});

function sb04SalesContext(): void
{
    $route = new Route('POST', 'api/telegram/sales/webhook', static fn () => null);
    $route->defaults('bot_key', 'sales');
    $route->bind(request());
    request()->setRouteResolver(static fn () => $route);
}

function sb04Bot(int $tg): Nutgram
{
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/sales.php');
    $bot->setCommonUser(TelegramUser::make($tg, false, 'Buyer'));
    $bot->setCommonChat(Chat::make($tg, ChatType::PRIVATE));

    return $bot;
}

/** @return array{buyer: User, root: User, order: int} */
function sb04Order(bool $time = false): array
{
    app(ConfigureProduct::class)->handle('vpn-access', 'اشتراک');
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 974001]);
    $buyer = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $buyer->id, 'telegram_user_id' => 974099]);

    $plan = app(ManagePlans::class)->create($root->id, new PlanDefinition(
        'vpn-access', $time ? 'sb04-time' : 'sb04-hybrid', 'پلن آزمایشی',
        $time ? 'time' : 'hybrid', 'preset', 'both',
        $time ? null : 30, 30, $time ? null : 1, $time ? null : 100, 15, 90,
    ));
    $plans = app(ManagePlans::class);
    $plans->confirmChange($root->id, $plans->requestChange($root->id, $plan->id, 1, 'activate'));
    $pricing = app(ManagePlanPricing::class);
    $rev = $pricing->configure($root->id, $plan->id, 2, 0,
        new PlanPricingDefinition('fixed', 1_000_000, 0, 0, 0, 10, 100_000));
    $pricing->confirmChange($root->id, $pricing->requestChange($root->id, $plan->id, 2, $rev, 'activate'));

    DB::table('sales_plan_internal_squad_bindings')->insert([
        'plan_id' => $plan->id, 'plan_revision' => 2,
        'internal_squad_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Only a disposable SQLite test fixture is marked ready to create the
    // SB-03 pending order. The switch is immediately reset to OFF.
    config()->set('services.remnawave.squad_bindings_live_verified', true);
    $quote = app(CalculatePlanQuote::class)->handle($buyer->id, $plan->id, 30, $time ? null : 30);
    $order = app(CreatePendingPlanOrder::class)->handle($buyer->id, $quote, bin2hex(random_bytes(16)))->id;
    config()->set('services.remnawave.squad_bindings_live_verified', false);

    return compact('buyer', 'root', 'order');
}

function sb04Photo(Nutgram $bot, int $messageId = 55, bool $shouldReply = true): void
{
    $reply = $bot->hearMessage(['message_id' => $messageId, 'photo' => [
        ['file_id' => 'sale-photo-file-123', 'file_unique_id' => 'sale-unique-photo-123', 'width' => 600, 'height' => 600],
    ]])->reply();
    if ($shouldReply) {
        $reply->assertCalled('sendMessage');
    } else {
        $reply->assertNoReply();
    }
}

it('does not expose receipt submission or accept forged pending payment while the gate is off', function (): void {
    $data = sb04Order();
    sb04SalesContext();
    $bot = sb04Bot(974099);

    $bot->hearCallbackQueryData('sales:checkout:view:'.$data['order'])->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:payment:start:'.$data['order'])->reply()
        ->assertCalled('editMessageText');
    sb04Photo($bot, 55, false);

    expect(DB::table('sales_bank_payment_claims')->count())->toBe(0)
        ->and(DB::table('sales_orders')->where('id', $data['order'])->value('status'))->toBe('pending_payment')
        ->and(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});

it('accepts Sales-only photos for own SB-03 orders and keeps suppressed notifications without sending Admin buttons via Sales', function (): void {
    $data = sb04Order();
    config()->set('sales.manual_bank_review_enabled', true);
    sb04SalesContext();
    $bot = sb04Bot(974099);

    $bot->hearCallbackQueryData('sales:payment:start:'.$data['order'])->reply()->assertCalled('editMessageText');
    sb04Photo($bot);

    $claim = DB::table('sales_bank_payment_claims')->first();
    expect($claim)->not->toBeNull()
        ->and($claim->source_bot_key)->toBe('sales')
        ->and($claim->telegram_message_key)->toBe('tg:sales:974099:55')
        ->and($claim->photo_encrypted)->not->toContain('sale-photo-file-123')
        ->and(DB::table('telegram_notification_deliveries')->where('state', 'suppressed')->count())->toBe(1)
        ->and(DB::table('sales_orders')->where('id', $data['order'])->value('status'))->toBe('pending_payment');

    $bot->hearCallbackQueryData('sales:checkout:view:'.$data['order'])->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:payment:start:'.$data['order'])->reply()->assertCalled('editMessageText');
    expect(app(ManualBankPayments::class)->latestReceiptStatus(SubscriptionViewer::telegram(974099), $data['order']))
        ->toBe('submitted');
    expect(DB::table('sales_bank_payment_claims')->count())->toBe(1);
    Http::assertNothingSent();
});

it('limits Sales receipts to owner managed-plan orders and private chats, even with forged callbacks', function (): void {
    $data = sb04Order();
    $other = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $other->id, 'telegram_user_id' => 974098]);
    config()->set('sales.manual_bank_review_enabled', true);
    sb04SalesContext();

    $otherBot = sb04Bot(974098);
    $otherBot->hearCallbackQueryData('sales:payment:start:'.$data['order'])->reply()->assertCalled('editMessageText');
    sb04Photo($otherBot, 56, false);
    expect(fn () => app(ManualBankPayments::class)->latestReceiptStatus(
        SubscriptionViewer::telegram(974098), $data['order'],
    ))->toThrow(ValidationException::class);

    $buyerBot = sb04Bot(974099);
    $buyerBot->setCommonChat(Chat::make(-974099, ChatType::GROUP));
    $buyerBot->hearCallbackQueryData('sales:payment:start:'.$data['order'])->reply()->assertNoReply();
    $buyerBot->hearMessage(['message_id' => 57, 'photo' => [
        ['file_id' => 'sale-photo-file-123', 'file_unique_id' => 'sale-unique-photo-123', 'width' => 600, 'height' => 600],
    ]])->reply()->assertNoReply();
    expect(DB::table('sales_bank_payment_claims')->count())->toBe(0);
});

it('records a single root-verified bank decision and allows Sales buyer to see approved status without activating provider', function (): void {
    $data = sb04Order(true);
    config()->set('sales.manual_bank_review_enabled', true);
    config()->set('telegram_notifications.enabled', true);
    Queue::fake();
    sb04SalesContext();
    $bot = sb04Bot(974099);
    $bot->hearCallbackQueryData('sales:payment:start:'.$data['order'])->reply()->assertCalled('editMessageText');
    sb04Photo($bot);

    $claim = (int) DB::table('sales_bank_payment_claims')->value('id');
    expect(DB::table('telegram_notification_deliveries')->where('bot_key', 'admin')->count())->toBe(1);
    config()->set('telegram.sales.enabled', true);
    DB::table('telegram_bot_contacts')->insert([
        'user_id' => $data['buyer']->id, 'bot_key' => 'sales', 'chat_id' => 974099,
        'delivery_status' => 'reachable', 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $payments = app(ManualBankPayments::class);
    expect(fn () => $payments->review($data['root']->id, $claim, true, null))->toThrow(ValidationException::class);
    $payments->review($data['root']->id, $claim, true, 'SB04BANK12345');
    expect(fn () => $payments->review($data['root']->id, $claim, true, 'SB04BANK12345'))->toThrow(ValidationException::class);

    expect(DB::table('sales_orders')->where('id', $data['order'])->value('status'))->toBe('paid')
        ->and(DB::table('billing_journals')->where('kind', 'direct_bank_sale')->count())->toBe(1)
        ->and(DB::table('sales_bank_settlements')->count())->toBe(1)
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(1)
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(0)
        ->and(DB::table('telegram_notification_deliveries')->where('event_type', 'PaymentReviewed')->value('bot_key'))->toBe('sales')
        ->and($payments->latestReceiptStatus(SubscriptionViewer::telegram(974099), $data['order']))->toBe('approved');

    $bot->hearCallbackQueryData('sales:checkout:view:'.$data['order'])->reply()->assertCalled('editMessageText');
    Http::assertNothingSent();
});

it('routes the Sales-origin receipt photo across tokens only through the guarded durable worker', function (): void {
    $data = sb04Order();
    config()->set('sales.manual_bank_review_enabled', true);
    config()->set('telegram_notifications.enabled', true);
    config()->set('telegram.sales.enabled', true);
    Queue::fake();
    sb04SalesContext();
    $bot = sb04Bot(974099);
    $bot->hearCallbackQueryData('sales:payment:start:'.$data['order'])->reply()->assertCalled('editMessageText');
    sb04Photo($bot);
    $noticeId = (int) DB::table('telegram_notification_deliveries')->where('event_type', 'ReceiptSubmitted')->value('id');

    $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP8z8DAwMDAxMDAwMDAAAANHQEDasKb6QAAAABJRU5ErkJggg==', true);
    expect($image)->toBeString();
    Http::fake(function (Request $request) use ($image) {
        if (str_contains($request->url(), '54321:sales_test/getFile')) {
            return Http::response(['ok' => true, 'result' => ['file_path' => 'photos/test.png', 'file_size' => strlen($image)]]);
        }
        if (str_contains($request->url(), '/file/bot54321:sales_test/photos/test.png')) {
            return Http::response($image, 200, ['Content-Type' => 'image/png']);
        }
        if (str_contains($request->url(), '12345:admin_test/sendPhoto')) {
            return Http::response(['ok' => true, 'result' => ['message_id' => 2]]);
        }

        return Http::response(['ok' => false], 503);
    });
    app(DispatchTelegramNotification::class, ['deliveryId' => $noticeId])->handle(app(TelegramNotificationSender::class));

    expect(DB::table('telegram_notification_deliveries')->where('id', $noticeId)->value('state'))->toBe('sent');
    $urls = Http::recorded()->map(static fn (array $request): string => $request[0]->url())->all();
    expect($urls)->toBe([
        'https://api.telegram.org/bot54321:sales_test/getFile',
        'https://api.telegram.org/file/bot54321:sales_test/photos/test.png',
        'https://api.telegram.org/bot12345:admin_test/sendPhoto',
    ]);
});

it('gates the one-click operator attestation and settles only once with an internal audit reference', function (): void {
    $data = sb04Order();
    config()->set('sales.manual_bank_review_enabled', true);
    config()->set('telegram_notifications.enabled', true);
    Queue::fake();
    $claimId = app(ManualBankPayments::class)->submit(
        SubscriptionViewer::telegram(974099), $data['order'],
        'sale-photo-file-123', 'sale-unique-photo-123', 510, 'sales',
    );
    $review = app(ManualBankPayments::class);

    expect(fn () => $review->review($data['root']->id, $claimId, true))
        ->toThrow(ValidationException::class);

    config()->set('sales.one_click_bank_review_enabled', true);
    expect($review->review($data['root']->id, $claimId, true)->approved)->toBeTrue();
    expect(DB::table('sales_bank_settlements')->value('payment_reference'))
        ->toBe('MANUAL-CLAIM-'.$claimId);
    expect(DB::table('sales_bank_settlements')->count())->toBe(1)
        ->and(DB::table('billing_journals')->where('kind', 'direct_bank_sale')->count())->toBe(1)
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(1);
    expect(fn () => $review->review($data['root']->id, $claimId, true))
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('renders direct review actions in the durable Admin receipt alert without leaking Sales file IDs', function (): void {
    $data = sb04Order();
    config()->set('sales.manual_bank_review_enabled', true);
    // Direct actions are the only available buttons even before rollout.
    config()->set('sales.one_click_bank_review_enabled', false);
    config()->set('telegram_notifications.enabled', true);
    config()->set('telegram.sales.enabled', true);
    Queue::fake();
    $claimId = app(ManualBankPayments::class)->submit(
        SubscriptionViewer::telegram(974099), $data['order'],
        'sale-photo-file-123', 'sale-unique-photo-123', 511, 'sales',
    );
    $deliveryId = (int) DB::table('telegram_notification_deliveries')
        ->where('event_type', 'ReceiptSubmitted')->value('id');
    $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP8z8DAwMDAxMDAwMDAAAANHQEDasKb6QAAAABJRU5ErkJggg==', true);
    expect($image)->toBeString();
    Http::fake(function (Request $request) use ($image) {
        if (str_contains($request->url(), '54321:sales_test/getFile')) {
            return Http::response(['ok' => true, 'result' => ['file_path' => 'photos/test.png', 'file_size' => strlen($image)]]);
        }
        if (str_contains($request->url(), '/file/bot54321:sales_test/photos/test.png')) {
            return Http::response($image, 200, ['Content-Type' => 'image/png']);
        }

        return Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200);
    });
    app(DispatchTelegramNotification::class, ['deliveryId' => $deliveryId])
        ->handle(app(TelegramNotificationSender::class));

    expect(DB::table('telegram_notification_deliveries')->where('id', $deliveryId)->value('state'))->toBe('sent');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '12345:admin_test/sendPhoto')
        && str_contains($request->body(), 'bank:decide:'.$claimId.':a')
        && str_contains($request->body(), 'bank:decide:'.$claimId.':r'));
});

it('requires all gates before final approval and then queues one automatic RW activation without an Admin command', function (): void {
    $data = sb04Order();
    config()->set('sales.manual_bank_review_enabled', true);
    config()->set('telegram_notifications.enabled', true);
    config()->set('telegram.sales.enabled', true);
    Queue::fake();
    $claim = app(ManualBankPayments::class)->submit(
        SubscriptionViewer::telegram(974099), $data['order'],
        'sale-test-receipt-a', 'sale-test-unique-a', 702, 'sales',
    );

    $admin = Nutgram::fake();
    (static function (Nutgram $bot): void {
        require base_path('app/Interfaces/Telegram/routes/admin.php');
    })($admin);
    $admin->setCommonUser(TelegramUser::make(974001, false, 'Root'));
    $admin->setCommonChat(Chat::make(974001, ChatType::PRIVATE));

    // The former choose/bankconfirm path is gone. No writes when not ready.
    $admin->hearCallbackQueryData('bank:decide:'.$claim.':a')->reply()
        ->assertCalled('editMessageText');
    expect(DB::table('sales_orders')->where('id', $data['order'])->value('status'))->toBe('pending_payment')
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('sales_bank_payment_claims')->where('id', $claim)->value('status'))->toBe('submitted');
    Queue::assertNotPushed(FulfillPaidPlanOrderJob::class);

    config()->set('sales.one_click_bank_review_enabled', true);
    config()->set('sales.auto_fulfillment_enabled', true);
    config()->set('services.remnawave.writes_enabled', true);
    config()->set('services.remnawave.squad_bindings_live_verified', true);

    $admin->hearCallbackQueryData('bank:decide:'.$claim.':a')->reply()
        ->assertCalled('editMessageText');
    expect(DB::table('sales_orders')->where('id', $data['order'])->value('status'))->toBe('paid')
        ->and(DB::table('sales_bank_payment_claims')->where('id', $claim)->value('status'))->toBe('approved')
        ->and(DB::table('sales_bank_settlements')->count())->toBe(1)
        ->and(DB::table('billing_journals')->count())->toBe(1);
    Queue::assertPushed(
        FulfillPaidPlanOrderJob::class,
        static fn ($job): bool => $job->orderId === $data['order'] && $job->actorId === $data['root']->id,
    );

    $admin->hearCallbackQueryData('bank:decide:'.$claim.':a')->reply()
        ->assertCalled('editMessageText');
    $admin->hearCallbackQueryData('bank:decide:'.$claim.':r')->reply()
        ->assertCalled('editMessageText');
    expect(DB::table('billing_journals')->count())->toBe(1)
        ->and(DB::table('sales_payment_review_audits')->count())->toBe(1)
        ->and(DB::table('sales_bank_settlements')->count())->toBe(1);
    Http::assertNothingSent();
});

it('finishes receipt rejection with one button and never enqueues provider creation', function (): void {
    $data = sb04Order();
    config()->set('sales.manual_bank_review_enabled', true);
    config()->set('telegram_notifications.enabled', true);
    config()->set('telegram.sales.enabled', true);
    Queue::fake();
    $claim = app(ManualBankPayments::class)->submit(
        SubscriptionViewer::telegram(974099), $data['order'],
        'sale-test-receipt-r', 'sale-test-unique-r', 703, 'sales',
    );
    $admin = Nutgram::fake();
    (static function (Nutgram $bot): void {
        require base_path('app/Interfaces/Telegram/routes/admin.php');
    })($admin);
    $admin->setCommonUser(TelegramUser::make(974001, false, 'Root'));
    $admin->setCommonChat(Chat::make(974001, ChatType::PRIVATE));
    $admin->hearCallbackQueryData('bank:decide:'.$claim.':r')->reply()
        ->assertCalled('editMessageText');

    expect(DB::table('sales_bank_payment_claims')->where('id', $claim)->value('status'))->toBe('rejected')
        ->and(DB::table('sales_orders')->where('id', $data['order'])->value('status'))->toBe('pending_payment')
        ->and(DB::table('sales_bank_settlements')->count())->toBe(0)
        ->and(DB::table('telegram_notification_deliveries')->where('event_type', 'PaymentReviewed')->count())->toBe(1);
    Queue::assertNotPushed(
        FulfillPaidPlanOrderJob::class,
    );
});

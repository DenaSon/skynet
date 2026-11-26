<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use App\Interfaces\Telegram\Notifications\TelegramNotificationSender;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ManageFulfilledSubscriptionStatus;
use App\Modules\Sales\Application\AdminSubscriptionLinkResend;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['994201', '994202']);
    config()->set('services.remnawave.url', 'https://rw-link-resend.test');
    config()->set('services.remnawave.token', 'fake-link-resend-token');
    config()->set('services.remnawave.writes_enabled', false);
    config()->set('telegram_notifications.enabled', true);
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram.sales.token', '54321:sales_test');
    config()->set('nutgram.token', '12345:admin_test');
    Queue::fake();
    Http::preventStrayRequests();
});

/** @return array{root:User,buyer:User,order:int,subscription:int} */
function linkResendFixture(bool $salesContact = true, int $rootTelegramId = 994201, int $buyerTelegramId = 994099): array
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => $rootTelegramId]);

    $buyer = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $buyer->id, 'telegram_user_id' => $buyerTelegramId]);
    if ($salesContact) {
        DB::table('telegram_bot_contacts')->insert([
            'user_id' => $buyer->id,
            'bot_key' => 'sales',
            'chat_id' => $buyerTelegramId,
            'delivery_status' => 'reachable',
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $productSlug = 'link-resend-product-'.$rootTelegramId;
    $product = DB::table('sales_products')->insertGetId([
        'slug' => $productSlug,
        'name' => 'Link resend test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $plan = DB::table('sales_plans')->insertGetId([
        'product_id' => $product,
        'slug' => 'link-resend-plan-'.$rootTelegramId,
        'name' => 'Link resend plan',
        'kind' => 'hybrid',
        'mode' => 'preset',
        'audience' => 'public',
        'status' => 'active',
        'min_days' => 1,
        'max_days' => 365,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $order = DB::table('sales_orders')->insertGetId([
        'buyer_user_id' => $buyer->id,
        'buyer_category' => 'direct',
        'idempotency_key' => 'link-resend-order:'.$buyer->id,
        'plan_id' => $plan,
        'product_slug' => $productSlug,
        'product_name' => 'Link resend plan',
        'duration_days' => 30,
        'traffic_gb' => 20,
        'public_price_rials' => 1_000_000,
        'discount_percent' => 0,
        'discount_rials' => 0,
        'total_price_rials' => 1_000_000,
        'status' => 'fulfilled',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('sales_fulfillment_outbox')->insert([
        'sales_order_id' => $order,
        'event_key' => 'sales-order:'.$order.':create',
        'state' => 'completed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $reference = 'sales-order:'.$order;
    $subscription = DB::table('provisioning_subscriptions')->insertGetId([
        'reference' => $reference,
        'remote_username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
        'remote_user_id' => 100 + $order,
        'state' => 'active',
        'last_operation_key' => $reference.':create',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('provisioning_order_links')->insert([
        'sales_order_id' => $order,
        'provisioning_subscription_id' => $subscription,
        'created_at' => now(),
    ]);

    return compact('root', 'buyer', 'order', 'subscription');
}

function linkResendBot(int $telegramId, User $user): Nutgram
{
    TelegramAccount::query()->firstOrCreate(
        ['user_id' => $user->id],
        ['telegram_user_id' => $telegramId],
    );
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Operator'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/** @return array<string, mixed> */
function linkResendRemote(int $orderId, array $overrides = []): array
{
    $reference = 'sales-order:'.$orderId;

    return [
        'id' => 100 + $orderId,
        'username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
        'shortUuid' => 'link-resend-short-id',
        'status' => 'ACTIVE',
        'expireAt' => now()->utc()->addDays(12)->format('Y-m-d\TH:i:s\Z'),
        'trafficLimitBytes' => 10 * 1073741824,
        'trafficLimitStrategy' => 'NO_RESET',
        'subscriptionUrl' => 'https://private.example.test/link-resend-token',
        'description' => 'SkyNetwork ref:'.hash('sha256', $reference),
        'activeInternalSquads' => [],
        'userTraffic' => ['usedTrafficBytes' => 3 * 1073741824, 'lifetimeUsedTrafficBytes' => 3 * 1073741824],
        ...$overrides,
    ];
}

/** @return array<string,mixed> */
function linkResendPayload(NutgramRequest $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $data;
}

function linkResendEnqueue(int $actorId, int $orderId): int
{
    return app(AdminSubscriptionLinkResend::class)->enqueue($actorId, $orderId, bin2hex(random_bytes(16)));
}

it('shows the resend action only for a remotely active subscription without enabling provider writes', function (): void {
    ['root' => $root, 'order' => $order] = linkResendFixture();
    Http::fake(function (Request $request) use ($order) {
        return Http::response(['response' => linkResendRemote($order)], 200);
    });

    expect(app(ManageFulfilledSubscriptionStatus::class)->status($root->id, $order))->toBe('active');
    $payload = null;
    linkResendBot(994201, $root)->hearCallbackQueryData('full:service:view:'.$order)->reply()
        ->assertRaw(static function (NutgramRequest $request) use (&$payload): bool {
            $payload = linkResendPayload($request);

            return true;
        }, index: 1);
    $buttons = json_encode($payload['reply_markup'] ?? [], JSON_THROW_ON_ERROR);
    expect((string) ($payload['text'] ?? ''))->toContain('مدیریت اشتراک #'.$order)
        ->and($buttons)->toContain('full:service:resend:'.$order);

    expect(config('services.remnawave.writes_enabled'))->toBeFalse();
    Http::assertNotSent(static fn (Request $request): bool => in_array($request->method(), ['POST', 'PATCH', 'DELETE'], true));
    Queue::assertNotPushed(DispatchTelegramNotification::class);
});

it('hides the resend action when the provider reports a disabled subscription', function (): void {
    ['root' => $root, 'order' => $order] = linkResendFixture();
    Http::fake(function (Request $request) use ($order) {
        return Http::response([
            'response' => linkResendRemote($order, ['status' => 'DISABLED']),
        ], 200);
    });

    linkResendBot(994201, $root)->hearCallbackQueryData('full:service:view:'.$order)->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $payload = linkResendPayload($request);
            $buttons = json_encode($payload['reply_markup'], JSON_THROW_ON_ERROR);

            return ! str_contains($buttons, 'full:service:resend:');
        }, index: 1);

    Queue::assertNotPushed(DispatchTelegramNotification::class);
    Http::assertNotSent(static fn (Request $request): bool => in_array($request->method(), ['POST', 'PATCH', 'DELETE'], true));
});

it('uses an actor-bound one-time confirmation and queues one durable resend intent', function (): void {
    ['root' => $root, 'buyer' => $buyer, 'order' => $order] = linkResendFixture();
    $bot = linkResendBot(994201, $root);
    $token = null;

    $bot->hearCallbackQueryData('full:service:resend:'.$order)->reply()
        ->assertRaw(static function (NutgramRequest $request) use (&$token): bool {
            $payload = linkResendPayload($request);
            $buttons = json_encode($payload['reply_markup'], JSON_THROW_ON_ERROR);
            if (preg_match('/full:service:resend-confirm:([a-f0-9]{32})/', $buttons, $matches) !== 1) {
                return false;
            }
            $token = $matches[1];

            return str_contains((string) ($payload['text'] ?? ''), 'تأیید ارسال مجدد لینک اتصال');
        }, index: 1);

    expect($token)->not->toBeNull();
    Queue::assertNotPushed(DispatchTelegramNotification::class);
    expect(DB::table('telegram_notification_deliveries')->count())->toBe(0);

    Http::fake(function (Request $request) use ($order) {
        return Http::response(['response' => linkResendRemote($order)], 200);
    });
    $bot->hearCallbackQueryData('full:service:resend-confirm:'.$token)->reply();

    $delivery = DB::table('telegram_notification_deliveries')
        ->where('event_type', AdminSubscriptionLinkResend::EVENT)->first();
    expect($delivery)->not->toBeNull()
        ->and((int) $delivery->recipient_user_id)->toBe($buyer->id)
        ->and((int) $delivery->requested_by_user_id)->toBe($root->id)
        ->and((int) $delivery->order_id)->toBe($order)
        ->and($delivery->bot_key)->toBe('sales')
        ->and($delivery->state)->toBe('pending')
        ->and($delivery->event_key)->toContain('actor:'.$root->id.':'.$token);
    Queue::assertPushed(DispatchTelegramNotification::class, 1);

    $bot->hearCallbackQueryData('full:service:resend-confirm:'.$token)->reply();
    expect(DB::table('telegram_notification_deliveries')
        ->where('event_type', AdminSubscriptionLinkResend::EVENT)->count())->toBe(1);
    Queue::assertPushed(DispatchTelegramNotification::class, 1);
    expect(Cache::has('mvp04:subscription-link-resend:'.$root->id.':'.$token))->toBeFalse();
});

it('denies non-root actors and refuses an unstarted Sales Bot contact', function (): void {
    ['root' => $root, 'order' => $order] = linkResendFixture();
    $staff = User::factory()->admin()->create();
    TelegramAccount::query()->create(['user_id' => $staff->id, 'telegram_user_id' => 994203]);

    expect(fn () => app(AdminSubscriptionLinkResend::class)->enqueue($staff->id, $order, str_repeat('a', 32)))
        ->toThrow(AuthorizationException::class);

    ['root' => $otherRoot, 'order' => $otherOrder] = linkResendFixture(false, 994202, 994098);
    expect(app(AdminSubscriptionLinkResend::class)->canRequest($otherRoot->id, $otherOrder))->toBeFalse()
        ->and(fn () => app(AdminSubscriptionLinkResend::class)->enqueue($otherRoot->id, $otherOrder, str_repeat('b', 32)))
        ->toThrow(ValidationException::class);

    expect(DB::table('telegram_notification_deliveries')->count())->toBe(0);
    Http::assertNothingSent();
    Queue::assertNotPushed(DispatchTelegramNotification::class);
});

it('rechecks the trusted requester and active Sales destination before sending', function (): void {
    ['root' => $root, 'buyer' => $buyer, 'order' => $order, 'subscription' => $subscription] = linkResendFixture();
    $id = linkResendEnqueue($root->id, $order);

    $root->syncRoles(['member']);
    (new DispatchTelegramNotification($id))->handle(app(TelegramNotificationSender::class));

    $delivery = DB::table('telegram_notification_deliveries')->where('id', $id)->first();
    expect($delivery->state)->toBe('unavailable')
        ->and($delivery->last_failure)->toBe('obsolete_subscription_link_resend')
        ->and((int) $delivery->attempts)->toBe(1);
    Http::assertNothingSent();

    $root->syncRoles(['super-admin']);
    $another = linkResendEnqueue($root->id, $order);
    DB::table('telegram_bot_contacts')->where('user_id', $buyer->id)->where('bot_key', 'sales')
        ->update(['delivery_status' => 'blocked']);
    (new DispatchTelegramNotification($another))->handle(app(TelegramNotificationSender::class));

    expect(DB::table('telegram_notification_deliveries')->where('id', $another)->value('state'))->toBe('unavailable')
        ->and(DB::table('telegram_notification_deliveries')->where('id', $another)->value('last_failure'))
        ->toBe('not_started');
    Http::assertNothingSent();

    DB::table('provisioning_subscriptions')->where('id', $subscription)->update(['state' => 'disabled']);
    expect(app(AdminSubscriptionLinkResend::class)->canRequest($root->id, $order))->toBeFalse();
});

it('returns a delivery classification from the existing sender for an eligible resend', function (): void {
    ['root' => $root, 'order' => $order] = linkResendFixture();
    $id = linkResendEnqueue($root->id, $order);
    DB::table('telegram_notification_deliveries')->where('id', $id)->update(['attempts' => 1]);
    $delivery = DB::table('telegram_notification_deliveries')->where('id', $id)->first();

    Http::fake(function (Request $request) use ($order) {
        if (str_contains($request->url(), 'rw-link-resend.test')) {
            return Http::response(['response' => linkResendRemote($order)], 200);
        }
        if (str_contains($request->url(), 'api.telegram.org')) {
            return Http::response(['ok' => true, 'result' => ['message_id' => 122]], 200);
        }

        return Http::response([], 404);
    });

    expect(app(TelegramNotificationSender::class)->send($delivery))->toBe('link_included');
    Http::assertSentCount(2);
});

it('sends the existing owner-verified QR and copyable link through Sales and records the delivery mode', function (): void {
    ['root' => $root, 'order' => $order] = linkResendFixture();
    $id = linkResendEnqueue($root->id, $order);

    Http::fake(function (Request $request) use ($order) {
        if (str_contains($request->url(), 'rw-link-resend.test')) {
            return Http::response(['response' => linkResendRemote($order)], 200);
        }
        if (str_contains($request->url(), 'api.telegram.org')) {
            return Http::response(['ok' => true, 'result' => ['message_id' => 123]], 200);
        }

        return Http::response([], 404);
    });

    (new DispatchTelegramNotification($id))->handle(app(TelegramNotificationSender::class));

    expect(Schema::hasColumn('telegram_notification_deliveries', 'subscription_delivery_mode'))->toBeTrue();
    $delivery = DB::table('telegram_notification_deliveries')->where('id', $id)->first();
    expect($delivery->state)->toBe('sent')
        ->and($delivery->subscription_delivery_mode)->toBe('link_included')
        ->and((int) $delivery->requested_by_user_id)->toBe($root->id);

    $telegram = Http::recorded()->map(static fn (array $record): Request => $record[0])
        ->first(static fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org'));
    expect($telegram)->not->toBeNull()
        ->and($telegram->body())->toContain('https://private.example.test/link-resend-token')
        ->toContain('copy_text');
    Http::assertSentCount(2);
});

it('sends a safe QR retrieval fallback when the direct provider link cannot be read', function (): void {
    ['root' => $root, 'order' => $order] = linkResendFixture();
    $id = linkResendEnqueue($root->id, $order);

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'rw-link-resend.test')) {
            return Http::response([], 503);
        }
        if (str_contains($request->url(), 'api.telegram.org')) {
            return Http::response(['ok' => true, 'result' => ['message_id' => 124]], 200);
        }

        return Http::response([], 404);
    });

    (new DispatchTelegramNotification($id))->handle(app(TelegramNotificationSender::class));

    $delivery = DB::table('telegram_notification_deliveries')->where('id', $id)->first();
    expect($delivery->state)->toBe('sent')
        ->and($delivery->subscription_delivery_mode)->toBe('fallback');

    $telegram = Http::recorded()->map(static fn (array $record): Request => $record[0])
        ->first(static fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org'));
    expect($telegram)->not->toBeNull();
    parse_str($telegram->body(), $form);
    expect((string) ($form['reply_markup'] ?? ''))->toContain('sales:subs:link:'.$order.':0');
    expect($telegram->body())->not->toContain('private.example.test');
    Http::assertSentCount(2);
});

it('does not replay a resend after an uncertain Telegram transport result', function (): void {
    ['root' => $root, 'order' => $order] = linkResendFixture();
    $id = linkResendEnqueue($root->id, $order);

    Http::fake(function (Request $request) use ($order) {
        if (str_contains($request->url(), 'rw-link-resend.test')) {
            return Http::response(['response' => linkResendRemote($order)], 200);
        }
        if (str_contains($request->url(), 'api.telegram.org')) {
            return Http::response(['ok' => false], 500);
        }

        return Http::response([], 404);
    });

    $job = new DispatchTelegramNotification($id);
    $job->handle(app(TelegramNotificationSender::class));
    $delivery = DB::table('telegram_notification_deliveries')->where('id', $id)->first();
    expect($delivery->state)->toBe('dead')
        ->and($delivery->last_failure)->toBe('delivery_uncertain')
        ->and((int) $delivery->attempts)->toBe(1);

    $this->artisan('telegram:notifications:dispatch', ['--retry-id' => (string) $id])->assertFailed();
    expect(DB::table('telegram_notification_deliveries')->where('id', $id)->value('state'))->toBe('dead');

    $job->handle(app(TelegramNotificationSender::class));
    Http::assertSentCount(2);
    expect(DB::table('telegram_notification_deliveries')->where('id', $id)->value('attempts'))->toBe(1);
});

<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use App\Interfaces\Telegram\Notifications\TelegramNotificationSender;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\AdminPendingReceiptFollowup;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['998001', '998002']);
    config()->set('telegram_notifications.enabled', true);
    config()->set('sales.manual_bank_review_enabled', true);
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram.sales.token', '56789:sales_test');
    config()->set('nutgram.token', '12345:admin_test');
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake();
});

function receiptFollowupBot(int $telegramId, User $user): Nutgram
{
    TelegramAccount::query()->firstOrCreate(
        ['user_id' => $user->id], ['telegram_user_id' => $telegramId],
    );
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Operator'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/** @return array{root:User,buyer:User,order:int} */
function receiptFollowupFixture(string $category = 'direct', bool $contact = true): array
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    receiptFollowupBot(998001, $root);
    $buyer = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $buyer->id, 'telegram_user_id' => 998099]);
    if ($contact) {
        DB::table('telegram_bot_contacts')->insert([
            'user_id' => $buyer->id, 'bot_key' => 'sales', 'chat_id' => 998099,
            'delivery_status' => 'reachable', 'last_seen_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $product = DB::table('sales_products')->insertGetId([
        'slug' => 'receipt-followup-test', 'name' => 'Plan',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $plan = DB::table('sales_plans')->insertGetId([
        'product_id' => $product, 'slug' => 'receipt-followup-plan', 'name' => 'Plan',
        'kind' => 'hybrid', 'mode' => 'preset', 'audience' => 'public', 'status' => 'active',
        'min_days' => 1, 'max_days' => 365,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $order = DB::table('sales_orders')->insertGetId([
        'buyer_user_id' => $buyer->id,
        'buyer_category' => $category,
        'idempotency_key' => 'receipt-followup:order:'.$buyer->id,
        'plan_id' => $plan,
        'product_slug' => 'receipt-followup-test', 'product_name' => '30GB Test',
        'duration_days' => 30, 'traffic_gb' => 30, 'public_price_rials' => 1_250_000,
        'discount_percent' => 0, 'discount_rials' => 0, 'total_price_rials' => 1_250_000,
        'status' => 'pending_payment', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return compact('root', 'buyer', 'order');
}

/** @return array<string,mixed> */
function receiptFollowupPayload(NutgramRequest $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }
    if (isset($data['rich_message'])) {
        $rich = is_string($data['rich_message'])
            ? json_decode($data['rich_message'], true, flags: JSON_THROW_ON_ERROR)
            : $data['rich_message'];
        $data['text'] = (string) ($rich['html'] ?? '');
    }

    return $data;
}

function receiptFollowupClaim(int $orderId, int $buyerId): void
{
    DB::table('sales_bank_payment_claims')->insert([
        'sales_order_id' => $orderId, 'buyer_user_id' => $buyerId,
        'photo_encrypted' => 'fake-encrypted-photo',
        'photo_fingerprint' => hash('sha256', 'followup:'.$orderId),
        'telegram_message_key' => 'tg:sales:998099:'.($orderId + 20),
        'source_bot_key' => 'sales',
        'status' => 'submitted',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('lists only direct pending orders without any receipt and shows numeric Telegram contact without username', function (): void {
    ['root' => $root, 'order' => $order] = receiptFollowupFixture();
    $service = app(AdminPendingReceiptFollowup::class);
    $list = $service->browse($root->id);
    $detail = $service->detail($root->id, $order);

    expect($list['items'])->toHaveCount(1)
        ->and($detail['order']->telegram_user_id)->toBe(998099)
        ->and($detail['order']->chat_id)->toBe(998099)
        ->and($detail['canSend'])->toBeTrue();

    receiptFollowupBot(998001, $root)
        ->hearCallbackQueryData('receipt:followup:view:'.$order)->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $payload = receiptFollowupPayload($request);
            $buttons = json_encode($payload['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains((string) $payload['text'], 'شناسه تلگرام')
                && str_contains($buttons, '"copy_text":{"text":"998099"}')
                && str_contains($buttons, 'receipt:followup:prepare:')
                && ! str_contains((string) $payload['text'], '@username');
        }, index: 1);

    Queue::assertNotPushed(DispatchTelegramNotification::class);
    Http::assertNothingSent();
    expect(DB::table('billing_journals')->count())->toBe(0);
});

it('requires a private trusted root and denies forged normal-admin callbacks', function (): void {
    ['root' => $root, 'order' => $order] = receiptFollowupFixture();
    $staff = User::factory()->admin()->create();
    receiptFollowupBot(998003, $staff);

    expect(fn () => app(AdminPendingReceiptFollowup::class)->browse($staff->id))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(AdminPendingReceiptFollowup::class)->detail($staff->id, $order))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(AdminPendingReceiptFollowup::class)->enqueue($staff->id, $order, str_repeat('a', 32)))
        ->toThrow(AuthorizationException::class);

    receiptFollowupBot(998003, $staff)->hearCallbackQueryData('receipt:followup:prepare:'.$order)
        ->reply()->assertCalled('answerCallbackQuery');

    Queue::assertNotPushed(DispatchTelegramNotification::class);
    Http::assertNothingSent();
    expect(DB::table('telegram_notification_deliveries')->count())->toBe(0);
});

it('uses an actor-bound one-time confirmation then queues one auditable Sales reminder', function (): void {
    ['root' => $root, 'buyer' => $buyer, 'order' => $order] = receiptFollowupFixture();
    $bot = receiptFollowupBot(998001, $root);
    $token = null;

    $bot->hearCallbackQueryData('receipt:followup:prepare:'.$order)->reply()
        ->assertRaw(static function (NutgramRequest $request) use (&$token): bool {
            $data = receiptFollowupPayload($request);
            $buttons = json_encode($data['reply_markup'], JSON_THROW_ON_ERROR);
            if (preg_match('/receipt:followup:apply:([a-f0-9]{32})/', $buttons, $matches) !== 1) {
                return false;
            }
            $token = $matches[1];

            return str_contains((string) $data['text'], 'تأیید یادآوری')
                && str_contains($buttons, 'receipt:followup:apply:');
        }, index: 1);
    expect($token)->not->toBeNull();
    Queue::assertNotPushed(DispatchTelegramNotification::class);

    $bot->hearCallbackQueryData('receipt:followup:apply:'.$token)->reply();
    $delivery = DB::table('telegram_notification_deliveries')
        ->where('event_type', AdminPendingReceiptFollowup::EVENT)->first();

    expect($delivery)->not->toBeNull()
        ->and($delivery->recipient_user_id)->toBe($buyer->id)
        ->and($delivery->bot_key)->toBe('sales')
        ->and($delivery->order_id)->toBe($order)
        ->and($delivery->state)->toBe('pending')
        ->and($delivery->event_key)->toContain('actor:'.$root->id.':'.$token);
    Queue::assertPushed(DispatchTelegramNotification::class, 1);

    $bot->hearCallbackQueryData('receipt:followup:apply:'.$token)->reply();
    expect(DB::table('telegram_notification_deliveries')->count())->toBe(1);
    Queue::assertPushed(DispatchTelegramNotification::class, 1);
    Http::assertNothingSent();
    expect(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('pending_payment');
});

it('accepts the string order ID returned by Redis and consumes the confirmation exactly once', function (): void {
    ['root' => $root, 'buyer' => $buyer, 'order' => $order] = receiptFollowupFixture();
    $bot = receiptFollowupBot(998001, $root);
    $token = null;

    $bot->hearCallbackQueryData('receipt:followup:prepare:'.$order)->reply()
        ->assertRaw(static function (NutgramRequest $request) use (&$token): bool {
            $buttons = json_encode(receiptFollowupPayload($request)['reply_markup'], JSON_THROW_ON_ERROR);

            if (preg_match('/receipt:followup:apply:([a-f0-9]{32})/', $buttons, $matches) !== 1) {
                return false;
            }
            $token = $matches[1];

            return true;
        }, index: 1);
    expect($token)->not->toBeNull();

    // Production Redis returns strings even when Cache::put received an integer.
    $cacheKey = 'receipt:followup:confirm:'.$root->id.':'.$token;
    Cache::put($cacheKey, (string) $order, now()->addMinutes(3));

    $bot->hearCallbackQueryData('receipt:followup:apply:'.$token)->reply();
    $delivery = DB::table('telegram_notification_deliveries')
        ->where('event_type', AdminPendingReceiptFollowup::EVENT)->first();
    expect($delivery)->not->toBeNull()
        ->and($delivery->order_id)->toBe($order)
        ->and($delivery->recipient_user_id)->toBe($buyer->id)
        ->and($delivery->state)->toBe('pending')
        ->and(Cache::has($cacheKey))->toBeFalse();
    Queue::assertPushed(DispatchTelegramNotification::class, 1);

    $bot->hearCallbackQueryData('receipt:followup:apply:'.$token)->reply();
    expect(DB::table('telegram_notification_deliveries')
        ->where('event_type', AdminPendingReceiptFollowup::EVENT)->count())->toBe(1);
    Queue::assertPushed(DispatchTelegramNotification::class, 1);
    Http::assertNothingSent();
});

it('rejects malformed cached order IDs without enqueuing any reminder', function (): void {
    ['root' => $root] = receiptFollowupFixture();
    $bot = receiptFollowupBot(998001, $root);
    $token = str_repeat('a', 32);

    Cache::put('receipt:followup:confirm:'.$root->id.':'.$token, '1abc', now()->addMinutes(3));
    $bot->hearCallbackQueryData('receipt:followup:apply:'.$token)->reply();
    Queue::assertNotPushed(DispatchTelegramNotification::class);
    expect(DB::table('telegram_notification_deliveries')->count())->toBe(0);
    Http::assertNothingSent();
});

it('sends from the Sales Bot to the recorded private numeric chat and only once', function (): void {
    ['root' => $root, 'buyer' => $buyer, 'order' => $order] = receiptFollowupFixture();
    $service = app(AdminPendingReceiptFollowup::class);
    $id = $service->enqueue($root->id, $order, str_repeat('a', 32));

    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 123]])]);
    $job = new DispatchTelegramNotification($id);
    $job->handle(app(TelegramNotificationSender::class));

    $delivery = DB::table('telegram_notification_deliveries')->where('id', $id)->first();
    expect($delivery->state)->toBe('sent')
        ->and($delivery->recipient_user_id)->toBe($buyer->id)
        ->and(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('pending_payment');
    Http::assertSentCount(1);
    Http::assertSent(static function (Request $request) use ($order): bool {
        $keyboard = json_decode((string) $request['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
        $receipt = $keyboard['inline_keyboard'][0][0] ?? [];
        $view = $keyboard['inline_keyboard'][1][0] ?? [];
        $text = (string) $request['text'];

        return str_contains($request->url(), '56789:sales_test/sendMessage')
            && (string) $request['chat_id'] === '998099'
            && str_contains($text, '🧾 یادآوری پرداخت سفارش #'.$order)
            && str_contains($text, 'سلام! 👋')
            && str_contains($text, 'سفارش شما در دناگیت هنوز در انتظار ثبت رسید پرداخت است.')
            && str_contains($text, 'تا پرداختتان بررسی و مراحل فعال‌سازی سرویس انجام شود.')
            && str_contains($text, 'اگر هنوز پرداخت نکرده‌اید، نیازی به اقدامی نیست. 💚')
            && ! str_contains($text, 'پرداخت شما تأیید شد')
            && ($receipt['text'] ?? null) === '📷 ارسال رسید پرداخت'
            && ($receipt['callback_data'] ?? null) === 'sales:payment:receipt:'.$order
            && ($receipt['style'] ?? null) === 'success'
            && ($view['text'] ?? null) === '📋 مشاهده سفارش'
            && ($view['callback_data'] ?? null) === 'sales:checkout:view:'.$order
            && ! isset($view['style']);
    });

    $job->handle(app(TelegramNotificationSender::class));
    Http::assertSentCount(1);
    expect(DB::table('billing_journals')->count())->toBe(0);
});

it('prevents repeated reminders across operators during the quiet period', function (): void {
    ['root' => $root, 'order' => $order] = receiptFollowupFixture();
    $service = app(AdminPendingReceiptFollowup::class);
    $service->enqueue($root->id, $order, str_repeat('b', 32));
    expect(fn () => $service->enqueue($root->id, $order, str_repeat('c', 32)))
        ->toThrow(ValidationException::class);

    expect($service->detail($root->id, $order)['canSend'])->toBeFalse();
    $this->travel(7)->hours();
    expect($service->detail($root->id, $order)['canSend'])->toBeTrue();
    expect(DB::table('telegram_notification_deliveries')
        ->where('event_type', AdminPendingReceiptFollowup::EVENT)->count())->toBe(1);
});

it('suppresses queued reminders if a receipt arrives or payment state changes before delivery', function (): void {
    ['root' => $root, 'buyer' => $buyer, 'order' => $order] = receiptFollowupFixture();
    $service = app(AdminPendingReceiptFollowup::class);
    $id = $service->enqueue($root->id, $order, str_repeat('d', 32));

    receiptFollowupClaim($order, $buyer->id);
    (new DispatchTelegramNotification($id))->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->where('id', $id)->value('state'))->toBe('unavailable');
    Http::assertNothingSent();

    DB::table('sales_bank_payment_claims')->where('sales_order_id', $order)->delete();
    DB::table('sales_orders')->where('id', $order)->update(['status' => 'paid']);
    expect(fn () => $service->detail($root->id, $order))->toThrow(ValidationException::class);
    Queue::assertNotPushed(DispatchTelegramNotification::class);
});

it('suppresses blocked Sales contact, revoked root and ambiguous Telegram outcomes', function (): void {
    ['root' => $root, 'buyer' => $buyer, 'order' => $order] = receiptFollowupFixture();
    $service = app(AdminPendingReceiptFollowup::class);
    $id = $service->enqueue($root->id, $order, str_repeat('e', 32));
    DB::table('telegram_bot_contacts')->where('user_id', $buyer->id)->where('bot_key', 'sales')
        ->update(['delivery_status' => 'blocked']);
    (new DispatchTelegramNotification($id))->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->where('id', $id)->value('state'))->toBe('unavailable');
    Http::assertNothingSent();

    $this->travel(7)->hours();
    DB::table('telegram_bot_contacts')->where('user_id', $buyer->id)->where('bot_key', 'sales')
        ->update(['delivery_status' => 'reachable']);
    $another = $service->enqueue($root->id, $order, str_repeat('f', 32));
    $root->syncRoles(['member']);
    (new DispatchTelegramNotification($another))->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->where('id', $another)->value('state'))->toBe('unavailable');
    Http::assertNothingSent();
});

it('suppresses a direct order reminder when its buyer gains the reseller role after checkout', function (): void {
    ['root' => $root, 'buyer' => $buyer, 'order' => $order] = receiptFollowupFixture();
    $service = app(AdminPendingReceiptFollowup::class);
    $id = $service->enqueue($root->id, $order, str_repeat('1', 32));
    Role::findOrCreate('reseller', 'web');
    $buyer->syncRoles(['reseller']);

    expect($service->detail($root->id, $order)['canSend'])->toBeFalse();
    (new DispatchTelegramNotification($id))->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->where('id', $id)->value('state'))->toBe('unavailable');
    Http::assertNothingSent();
});

it('does not let the recovery command manually retry a one-shot reminder after an uncertain send', function (): void {
    ['root' => $root, 'order' => $order] = receiptFollowupFixture();
    $id = app(AdminPendingReceiptFollowup::class)->enqueue($root->id, $order, str_repeat('2', 32));
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => false], 500)]);

    (new DispatchTelegramNotification($id))->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->where('id', $id)->value('state'))->toBe('unavailable')
        ->and(DB::table('telegram_notification_deliveries')->where('id', $id)->value('last_failure'))->toBe('delivery_uncertain');
    Http::assertSentCount(1);

    expect(Artisan::call('telegram:notifications:dispatch', ['--retry-id' => (string) $id]))->toBe(1)
        ->and(DB::table('telegram_notification_deliveries')->where('id', $id)->value('state'))->toBe('unavailable');
    Http::assertSentCount(1);
});

it('does not enable a reminder when the buyer has never started the Sales Bot', function (): void {
    ['root' => $root, 'order' => $order] = receiptFollowupFixture(contact: false);
    $service = app(AdminPendingReceiptFollowup::class);
    expect($service->detail($root->id, $order)['canSend'])->toBeFalse()
        ->and(fn () => $service->enqueue($root->id, $order, str_repeat('3', 32)))
        ->toThrow(ValidationException::class);
    Queue::assertNotPushed(DispatchTelegramNotification::class);
    Http::assertNothingSent();
});

it('excludes reseller wallet orders, absent Sales chat and submitted receipts without any financial mutation', function (): void {
    ['root' => $root, 'order' => $order] = receiptFollowupFixture('reseller');
    $service = app(AdminPendingReceiptFollowup::class);
    expect($service->browse($root->id)['items'])->toHaveCount(0)
        ->and(fn () => $service->detail($root->id, $order))->toThrow(ValidationException::class);
    Http::assertNothingSent();
    Queue::assertNotPushed(DispatchTelegramNotification::class);
});

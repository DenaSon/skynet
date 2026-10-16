<?php

declare(strict_types=1);

use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\GetSubscriptionUsage;
use App\Modules\Sales\Application\CalculateQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Sales\Application\CreatePendingOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('services.remnawave.url', 'https://remna.test');
    config()->set('services.remnawave.token', 'fake-token');
    config()->set('services.remnawave.writes_enabled', false);
    config()->set('sales.manual_bank_review_enabled', false);
    config()->set('telegram.public_signup_enabled', false);
    Http::preventStrayRequests();
    app(ConfigureProduct::class)->handle('sb06-vpn', 'اشتراک آزمایشی');
    app(ConfigureRate::class)->handle('sb06-vpn', 30, 100_000, 20_000);
});

function sb06Bot(int $telegramId, ?User $account = null): Nutgram
{
    if ($account !== null) {
        TelegramAccount::query()->create(['user_id' => $account->id, 'telegram_user_id' => $telegramId]);
    }

    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/sales.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Buyer'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

function sb06Order(User $buyer): int
{
    $quote = app(CalculateQuote::class)->handle($buyer->id, 'sb06-vpn', 30, 10);

    return app(CreatePendingOrder::class)->handle($buyer->id, $quote, bin2hex(random_bytes(16)))->id;
}

function sb06Activated(int $orderId): void
{
    DB::table('sales_orders')->where('id', $orderId)->update(['status' => 'fulfilled']);
    $subscription = DB::table('provisioning_subscriptions')->insertGetId([
        'reference' => 'sb06-owned:'.$orderId,
        'remote_username' => 'sk_'.substr(hash('sha256', 'sb06-owned:'.$orderId), 0, 24),
        'remote_user_id' => 42,
        'state' => 'active',
        'last_operation_key' => 'sb06-test:'.$orderId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('provisioning_order_links')->insert([
        'sales_order_id' => $orderId, 'provisioning_subscription_id' => $subscription,
        'created_at' => now(),
    ]);
}

/** @return array<string, mixed> */
function sb06Provider(int $orderId, array $overrides = []): array
{
    $ref = 'sb06-owned:'.$orderId;

    return [
        'id' => 42, 'shortUuid' => 'sb06-test-user',
        'username' => 'sk_'.substr(hash('sha256', $ref), 0, 24),
        'status' => 'ACTIVE',
        'expireAt' => '2027-10-12T00:00:00.000Z',
        'trafficLimitBytes' => 2 * 1073741824,
        'trafficLimitStrategy' => 'NO_RESET',
        'subscriptionUrl' => 'https://test.example.test/private-sb06-credential',
        'userTraffic' => ['usedTrafficBytes' => 1073741824, 'lifetimeUsedTrafficBytes' => 1073741824],
        'description' => 'SkyNetwork ref:'.hash('sha256', $ref),
        'activeInternalSquads' => [['uuid' => '550e8400-e29b-41d4-a716-446655440005', 'name' => 'Squad']],
        ...$overrides,
    ];
}

it('adds simple owner-only Sales subscription navigation with an empty state and no provider calls', function (): void {
    $buyer = User::factory()->create();
    $bot = sb06Bot(976001, $buyer);
    Http::fake();

    $bot->hearText('/subscriptions')->reply()
        ->assertReplyText("📦 اشتراک‌های من\n\nهنوز سفارش یا اشتراکی ندارید.");

    $orderId = sb06Order($buyer);
    sb06Order(User::factory()->create());

    $bot->hearText('/subscriptions')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('sales:subs:list:0')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:subs:view:'.$orderId.':0')->reply()
        ->assertCalled('editMessageText')->assertCalled('sendMessage', 0);

    expect(DB::table('sales_orders')->count())->toBe(2)
        ->and(DB::table('provisioning_order_links')->count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps keyset paging bounded and blocks forged or stale cross-buyer identifiers', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $foreign = sb06Order($owner);
    for ($i = 0; $i < 8; $i++) {
        sb06Order($other);
    }

    $bot = sb06Bot(976002, $other);
    Http::fake();
    $bot->hearCallbackQueryData('sales:subs:list:0')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:subs:list:5')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:subs:list:-1')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:subs:view:'.$foreign.':0')->reply()
        ->assertCalled('editMessageText')->assertCalled('sendMessage', 0);
    $bot->hearCallbackQueryData('sales:subs:link:'.$foreign.':0')->reply()
        ->assertCalled('editMessageText')->assertCalled('sendMessage', 0);
    Http::assertNothingSent();
});

it('denies group chats, unlinked or deactivated identities on every callback', function (): void {
    $user = User::factory()->create();
    $order = sb06Order($user);
    $bot = sb06Bot(976003, $user);
    Http::fake();

    $bot->setCommonChat(Chat::make(-976003, ChatType::GROUP));
    $bot->hearText('/subscriptions')->reply()->assertNoReply();
    $bot->hearCallbackQueryData('sales:subs:link:'.$order.':0')->reply()->assertNoReply();

    $bot->setCommonChat(Chat::make(976003, ChatType::PRIVATE));
    $user->forceFill(['is_active' => false])->save();
    $bot->hearCallbackQueryData('sales:subs:view:'.$order.':0')->reply()
        ->assertCalled('editMessageText')->assertCalled('sendMessage', 0);
    $bot->hearCallbackQueryData('sales:subs:link:'.$order.':0')->reply()
        ->assertCalled('editMessageText')->assertCalled('sendMessage', 0);

    sb06Bot(976004)->hearText('/subscriptions')->reply()->assertCalled('sendMessage');
    Http::assertNothingSent();
});

it('never supplies credentials for unpaid orders, even through forged link callbacks', function (): void {
    $user = User::factory()->create();
    $order = sb06Order($user);
    $bot = sb06Bot(976005, $user);
    Http::fake();

    $bot->hearCallbackQueryData('sales:subs:view:'.$order.':0')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:subs:link:'.$order.':0')->reply()
        ->assertCalled('editMessageText')->assertCalled('sendMessage', 0);
    expect(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('pending_payment');
    Http::assertNothingSent();
});

it('shows verified usage, remaining quota and UTC expiration without leaking the connection credential', function (): void {
    $buyer = User::factory()->create();
    $order = sb06Order($buyer);
    sb06Activated($order);
    $bot = sb06Bot(976006, $buyer);
    Http::fake([
        'https://remna.test/api/users/42' => Http::response(['response' => sb06Provider($order)]),
    ]);

    $usage = app(GetSubscriptionUsage::class)->handle(SubscriptionViewer::telegram(976006), $order);
    expect($usage->limitBytes)->toBe(2 * 1073741824)
        ->and($usage->usedBytes)->toBe(1073741824)
        ->and($usage->expireAt)->toBe('2027-10-12T00:00:00.000Z')
        ->and(json_encode($usage, JSON_THROW_ON_ERROR))->not->toContain('private-sb06-credential');

    $bot->hearCallbackQueryData('sales:subs:view:'.$order.':0')->reply()
        ->assertCalled('editMessageText')->assertCalled('sendMessage', 0);
    Http::assertSentCount(2);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'GET'
        && $r->url() === 'https://remna.test/api/users/42');
    expect(config('services.remnawave.writes_enabled'))->toBeFalse();
});

it('sends the secret only after an explicit, freshly authorized private-link request', function (): void {
    $buyer = User::factory()->create();
    $order = sb06Order($buyer);
    sb06Activated($order);
    $bot = sb06Bot(976007, $buyer);
    Http::fake([
        'https://remna.test/api/users/42' => Http::response(['response' => sb06Provider($order)]),
    ]);

    $bot->hearCallbackQueryData('sales:subs:link:'.$order.':0')->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('sendMessage')
        ->assertCalled('editMessageText', 0);
    Http::assertSentCount(1);

    // A stale button must not bypass the current settlement/ownership policy.
    DB::table('sales_orders')->where('id', $order)->update(['status' => 'refunded']);
    $bot->hearCallbackQueryData('sales:subs:link:'.$order.':0')->reply()
        ->assertCalled('editMessageText')->assertCalled('sendMessage', 0);
    Http::assertSentCount(1);
});

it('handles an unavailable or expired provider without displaying fabricated live usage or delivering a link', function (): void {
    $buyer = User::factory()->create();
    $order = sb06Order($buyer);
    sb06Activated($order);
    $bot = sb06Bot(976008, $buyer);
    Http::fake([
        'https://remna.test/api/users/42' => Http::response([], 503),
    ]);

    $bot->hearCallbackQueryData('sales:subs:view:'.$order.':0')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:subs:link:'.$order.':0')->reply()
        ->assertCalled('editMessageText')->assertCalled('sendMessage', 0);
    Http::assertSentCount(2);

    config()->set('services.remnawave.url', 'https://expired-remna.test');
    Http::fake([
        'https://expired-remna.test/api/users/42' => Http::response([
            'response' => sb06Provider($order, ['status' => 'EXPIRED']),
        ]),
    ]);
    $bot->hearCallbackQueryData('sales:subs:link:'.$order.':0')->reply()
        ->assertCalled('editMessageText')->assertCalled('sendMessage', 0);
    Http::assertSentCount(1);
});

it('refuses remote identity mismatch and remains read-only for customer subscription actions', function (): void {
    $buyer = User::factory()->create();
    $order = sb06Order($buyer);
    sb06Activated($order);
    $bot = sb06Bot(976009, $buyer);
    Http::fake([
        'https://remna.test/api/users/42' => Http::response([
            'response' => sb06Provider($order, ['description' => 'other-user']),
        ]),
    ]);

    $bot->hearCallbackQueryData('sales:subs:link:'.$order.':0')->reply()
        ->assertCalled('editMessageText')->assertCalled('sendMessage', 0);
    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'GET');
    expect(DB::table('provisioning_order_links')->count())->toBe(1);
});

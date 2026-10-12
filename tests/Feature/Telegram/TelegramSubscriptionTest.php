<?php

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\CalculateQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Sales\Application\CreatePendingOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;

uses(RefreshDatabase::class);

function subscriptionUiBot(int $id, ?User $user = null): Nutgram
{
    if ($user !== null) {
        TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $id]);
    }

    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($id, false, 'Customer'));
    $bot->setCommonChat(Chat::make($id, ChatType::PRIVATE));

    return $bot;
}

function subscriptionUiCatalog(): void
{
    app(ConfigureProduct::class)->handle('sub-ui-plan', 'اشتراک تستی');
    app(ConfigureRate::class)->handle('sub-ui-plan', 30, 100_000, 20_000);
}

function subscriptionUiOrder(User $buyer): int
{
    $quote = app(CalculateQuote::class)->handle($buyer->id, 'sub-ui-plan', 30, 10);
    $order = app(CreatePendingOrder::class)->handle($buyer->id, $quote, bin2hex(random_bytes(16)));

    return $order->id;
}

function subscriptionUiPaid(int $orderId): void
{
    DB::table('sales_orders')->where('id', $orderId)->update(['status' => 'paid']);
    $id = DB::table('provisioning_subscriptions')->insertGetId([
        'reference' => 'order-e3-'.$orderId,
        'remote_username' => 'test-e3-'.$orderId,
        'remote_user_id' => 42,
        'state' => 'active',
        'last_operation_key' => 'create-e3-'.$orderId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('provisioning_order_links')->insert([
        'sales_order_id' => $orderId,
        'provisioning_subscription_id' => $id,
        'created_at' => now(),
    ]);
}

function subscriptionUiRemote(int $orderId): array
{
    $ref = 'order-e3-'.$orderId;

    return [
        'id' => 42,
        'username' => 'sk_'.substr(hash('sha256', $ref), 0, 24),
        'shortUuid' => 'private-short-id',
        'status' => 'ACTIVE',
        'expireAt' => '2027-10-12T00:00:00.000Z',
        'trafficLimitBytes' => 1073741824,
        'trafficLimitStrategy' => 'NO_RESET',
        'subscriptionUrl' => 'https://subscription.example.test/private-key-e3',
        'description' => 'SkyNetwork ref:'.hash('sha256', $ref),
        'activeInternalSquads' => [['uuid' => '550e8400-e29b-41d4-a716-446655440005', 'name' => 'Squad']],
        'userTraffic' => ['usedTrafficBytes' => 100, 'lifetimeUsedTrafficBytes' => 100],
    ];
}

function subscriptionUiFakeRemote(int $orderId, int $status = 200): void
{
    config()->set('services.remnawave.url', 'https://remna.test');
    config()->set('services.remnawave.token', 'test-token');
    config()->set('services.remnawave.writes_enabled', false);

    Http::preventStrayRequests();
    Http::fake([
        'https://remna.test/api/users/42' => Http::response(['response' => subscriptionUiRemote($orderId)], $status),
    ]);
}

it('shows an empty-state and an owner-scoped order directory with inline navigation', function (): void {
    subscriptionUiCatalog();
    $buyer = User::factory()->create();
    $bot = subscriptionUiBot(880001, $buyer);

    $bot->hearText('/subscriptions')->reply()
        ->assertReplyText("📦 سرویس‌های من\n\nهنوز سرویس یا سفارشی ندارید.\nاز بخش خرید می‌توانید سفارش جدید ثبت کنید.");

    $orderId = subscriptionUiOrder($buyer);
    subscriptionUiOrder(User::factory()->create());

    Http::preventStrayRequests();
    Http::fake();

    $bot->hearText('/subscriptions')->reply()
        ->assertCalled('sendMessage');

    $bot->hearCallbackQueryData("subs:view:{$orderId}:0")->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText')
        ->assertCalled('sendMessage', 0);

    Http::assertNothingSent();
});

it('accepts a keyset cursor but never trusts callback order ownership or arbitrary IDs', function (): void {
    subscriptionUiCatalog();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $orderId = subscriptionUiOrder($owner);
    $bot = subscriptionUiBot(880002, $other);

    $bot->hearCallbackQueryData("subs:view:{$orderId}:0")->reply()
        ->assertCalled('editMessageText')
        ->assertCalled('sendMessage', 0);
    $bot->hearCallbackQueryData("subs:link:{$orderId}:0")->reply()
        ->assertCalled('editMessageText')
        ->assertCalled('sendMessage', 0);
    $bot->hearCallbackQueryData('subs:list:-123')->reply()
        ->assertCalled('editMessageText');

    for ($i = 0; $i < 8; $i++) {
        subscriptionUiOrder($other);
    }

    $bot->hearCallbackQueryData('subs:list:0')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('subs:list:3')->reply()->assertCalled('editMessageText');
    expect(DB::table('provisioning_order_links')->count())->toBe(0);
});

it('blocks groups, unknown and disabled users even with stale inline buttons', function (): void {
    subscriptionUiCatalog();
    $owner = User::factory()->create();
    $id = subscriptionUiOrder($owner);
    $bot = subscriptionUiBot(880003, $owner);

    $bot->setCommonChat(Chat::make(-880003, ChatType::GROUP));
    $bot->hearText('/subscriptions')->reply()->assertNoReply();
    $bot->hearCallbackQueryData("subs:view:{$id}:0")->reply()->assertNoReply();

    $bot->setCommonChat(Chat::make(880003, ChatType::PRIVATE));
    $owner->forceFill(['is_active' => false])->save();
    $bot->hearCallbackQueryData("subs:view:{$id}:0")->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('editMessageText', 0);

    $unlinked = subscriptionUiBot(880004);
    $unlinked->hearCallbackQueryData('subs:list:0')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
});

it('does not turn an unpaid order into a subscription or call the provider', function (): void {
    subscriptionUiCatalog();
    $owner = User::factory()->create();
    $id = subscriptionUiOrder($owner);
    $bot = subscriptionUiBot(880005, $owner);

    Http::preventStrayRequests();
    Http::fake();

    $bot->hearCallbackQueryData("subs:view:{$id}:0")->reply()
        ->assertCalled('editMessageText');
    $bot->hearCallbackQueryData("subs:link:{$id}:0")->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('sendMessage', 0);
    $bot->hearCallbackQueryData("subs:nodes:{$id}:0")->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('sendMessage', 0);

    Http::assertNothingSent();
});

it('reveals the credential only on an explicit and freshly authorized private request', function (): void {
    subscriptionUiCatalog();
    $owner = User::factory()->create();
    $id = subscriptionUiOrder($owner);
    subscriptionUiPaid($id);
    $bot = subscriptionUiBot(880006, $owner);

    subscriptionUiFakeRemote($id);

    $bot->hearCallbackQueryData("subs:view:{$id}:0")->reply()
        ->assertCalled('sendMessage', 0)
        ->assertCalled('editMessageText');

    Http::assertNothingSent();

    $bot->hearCallbackQueryData("subs:link:{$id}:0")->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText')
        ->assertCalled('sendMessage');

    Http::assertSentCount(1);

    DB::table('sales_orders')->where('id', $id)->update(['status' => 'refunded']);
    $bot->hearCallbackQueryData("subs:link:{$id}:0")->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('sendMessage', 0);
    Http::assertSentCount(1);
});

it('shows public-safe node names and gracefully handles provider errors without leaking secrets', function (): void {
    subscriptionUiCatalog();
    $owner = User::factory()->create();
    $id = subscriptionUiOrder($owner);
    subscriptionUiPaid($id);
    $bot = subscriptionUiBot(880007, $owner);

    subscriptionUiFakeRemote($id);
    Http::fake(['https://remna.test/api/users/42/accessible-nodes' => Http::response([
        'response' => [
            'userId' => 42,
            'activeNodes' => [[
                'nodeName' => 'Canada East',
                'countryCode' => 'CA',
                'address' => '10.10.10.1',
                'privateSecret' => 'never-show',
            ]],
        ],
    ])]);

    $bot->hearCallbackQueryData("subs:nodes:{$id}:0")->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('sendMessage', 0)
        ->assertCalled('editMessageText');

    Http::assertSentCount(2);

    // A distinct origin avoids Laravel's additive HTTP fake registrations.
    config()->set('services.remnawave.url', 'https://remna-down.test');
    Http::fake(['https://remna-down.test/api/users/42' => Http::response([], 503)]);
    $bot->hearCallbackQueryData("subs:link:{$id}:0")->reply()
        ->assertCalled('editMessageText')
        ->assertCalled('sendMessage', 0);

    Http::assertSentCount(1);
    expect(config('services.remnawave.writes_enabled'))->toBeFalse();
});

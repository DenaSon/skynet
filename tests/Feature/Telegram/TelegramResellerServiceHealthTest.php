<?php

declare(strict_types=1);

use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ReadResellerServiceHealth;
use App\Modules\Sales\Application\CalculateQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Sales\Application\CreatePendingOrder;
use App\Modules\Sales\Application\ManageResellerServiceLabels;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::findOrCreate('reseller', 'web');
    Role::findOrCreate('member', 'web');
    config()->set('services.remnawave.url', 'https://rw-health.test');
    config()->set('services.remnawave.token', 'fake-token');
    config()->set('services.remnawave.writes_enabled', false);
    config()->set('sales.manual_bank_review_enabled', false);
    Http::preventStrayRequests();
    app(ConfigureProduct::class)->handle('health-vpn', 'پلن نمایندگی');
    app(ConfigureRate::class)->handle('health-vpn', 30, 100_000, 20_000);
    $route = new Route('POST', 'api/telegram/sales/webhook', static fn (): null => null);
    $route->defaults('bot_key', 'sales');
    $route->bind(request());
    request()->setRouteResolver(static fn (): Route => $route);
});

function rshUser(): User
{
    $user = User::factory()->create();
    $user->syncRoles(['reseller']);

    return $user;
}

function rshOrder(User $buyer): int
{
    $quote = app(CalculateQuote::class)->handle($buyer->id, 'health-vpn', 30, 10);
    $id = app(CreatePendingOrder::class)->handle($buyer->id, $quote, bin2hex(random_bytes(16)))->id;
    DB::table('sales_orders')->where('id', $id)->update(['status' => 'fulfilled']);
    $reference = 'rsh-owned:'.$id;
    $sub = DB::table('provisioning_subscriptions')->insertGetId([
        'reference' => $reference,
        'remote_username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
        'remote_user_id' => $id + 2300,
        'state' => 'active',
        'last_operation_key' => 'rsh-test:'.$id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('provisioning_order_links')->insert([
        'sales_order_id' => $id, 'provisioning_subscription_id' => $sub, 'created_at' => now(),
    ]);

    return $id;
}

/** @return array<string,mixed> */
function rshRemote(int $orderId, array $overrides = []): array
{
    $reference = 'rsh-owned:'.$orderId;

    return [
        'id' => $orderId + 2300,
        'username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
        'shortUuid' => 'health-owner',
        'status' => 'ACTIVE',
        'expireAt' => now()->addDays(14)->toIso8601String(),
        'trafficLimitBytes' => 2 * 1073741824,
        'trafficLimitStrategy' => 'NO_RESET',
        'subscriptionUrl' => 'https://private-health.test/token',
        'userTraffic' => ['usedTrafficBytes' => 0],
        'description' => 'SkyNetwork ref:'.hash('sha256', $reference),
        'activeInternalSquads' => [],
        ...$overrides,
    ];
}

function rshBot(User $buyer, int $telegramId): Nutgram
{
    TelegramAccount::query()->create(['user_id' => $buyer->id, 'telegram_user_id' => $telegramId]);
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/sales.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Buyer'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

it('counts only a bounded owner-scoped page and marks confirmed live status without leaking credentials', function (): void {
    $buyer = rshUser();
    $other = rshUser();
    $owned = rshOrder($buyer);
    $foreign = rshOrder($other);
    app(ManageResellerServiceLabels::class)->save($buyer->id, $owned, 'علی & <دوست>');
    $bot = rshBot($buyer, 981001);

    Http::fake(['https://rw-health.test/api/users/'.($owned + 2300) => Http::response(['response' => rshRemote($owned)])]);

    $page = app(ReadResellerServiceHealth::class)->handle(SubscriptionViewer::telegram(981001));
    expect($page['total'])->toBe(1)
        ->and($page['checked'])->toBe(1)
        ->and($page['active'])->toBe(1)
        ->and($page['expired'])->toBe(0)
        ->and($page['unknown'])->toBe(0)
        ->and($page['rows'][0]['orderId'])->toBe($owned)
        ->and($page['rows'][0]['name'])->toBe('علی & <دوست>')
        ->and(json_encode($page, JSON_THROW_ON_ERROR))->not->toContain('private-health.test');

    $bot->hearCallbackQueryData('sales:health:0')->reply()
        ->assertRaw(static function (NutgramRequest $request) use ($owned, $foreign): bool {
            $data = FakeNutgram::getActualData($request);

            return str_contains($data['text'], 'علی &amp; &lt;دوست&gt;')
                && ! str_contains($data['text'], 'private-health.test')
                && str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), "sales:subs:view:{$owned}:0")
                && ! str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), "sales:subs:view:{$foreign}:0");
        }, index: 1);

    Http::assertSent(static fn ($request): bool => $request->method() === 'GET');
    Http::assertSentCount(2);
});

it('reports expired, imminent expiration and failed provider reads without guessing active status', function (): void {
    $buyer = rshUser();
    $expired = rshOrder($buyer);
    $soon = rshOrder($buyer);
    $unavailable = rshOrder($buyer);
    rshBot($buyer, 981002);
    Http::fake([
        'https://rw-health.test/api/users/'.($expired + 2300) => Http::response(['response' => rshRemote($expired, ['status' => 'EXPIRED', 'expireAt' => now()->subDay()->toIso8601String()])]),
        'https://rw-health.test/api/users/'.($soon + 2300) => Http::response(['response' => rshRemote($soon, ['expireAt' => now()->addDays(3)->toIso8601String()])]),
        'https://rw-health.test/api/users/'.($unavailable + 2300) => Http::response([], 503),
    ]);

    $r = app(ReadResellerServiceHealth::class)->handle(SubscriptionViewer::telegram(981002));
    expect($r['checked'])->toBe(3)
        ->and($r['expired'])->toBe(1)
        ->and($r['active'])->toBe(1)
        ->and($r['attention'])->toBe(1)
        ->and($r['unknown'])->toBe(1)
        ->and(implode(' ', array_column($r['rows'], 'action')))->toContain('انقضا در ۷ روز');
    Http::assertSentCount(3);
});

it('bounds remote checks to six latest services and keeps unauthorized users and groups out', function (): void {
    $buyer = rshUser();
    $other = rshUser();
    for ($i = 0; $i < 8; $i++) {
        rshOrder($buyer);
    }
    $foreign = rshOrder($other);
    $bot = rshBot($buyer, 981003);
    Http::fake();
    $r = app(ReadResellerServiceHealth::class)->handle(SubscriptionViewer::telegram(981003));
    expect($r['total'])->toBe(8)
        ->and($r['checked'])->toBe(6)
        ->and($r['unknown'])->toBe(6)
        ->and($r['nextBeforeOrderId'])->not->toBeNull()
        ->and(array_column($r['rows'], 'orderId'))->not->toContain($foreign);
    Http::assertSentCount(6);
    $bot->setCommonChat(Chat::make(-981003, ChatType::GROUP));
    $bot->hearCallbackQueryData('sales:health:0')->reply()->assertNoReply();
    $bot->setCommonChat(Chat::make(981003, ChatType::PRIVATE));
    $buyer->syncRoles(['member']);
    expect(fn () => app(ReadResellerServiceHealth::class)->handle(SubscriptionViewer::telegram(981003)))
        ->toThrow(AuthorizationException::class);
    $bot->hearCallbackQueryData('sales:health:0')->reply()
        ->assertCalled('editMessageText');
    Http::assertSentCount(6);
});

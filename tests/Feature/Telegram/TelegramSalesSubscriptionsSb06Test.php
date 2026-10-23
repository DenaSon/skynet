<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Support\SubscriptionPageLink;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\GetSubscriptionUsage;
use App\Modules\Sales\Application\CalculateQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Sales\Application\CreatePendingOrder;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('services.remnawave.url', 'https://remna.test');
    config()->set('services.remnawave.token', 'fake-token');
    config()->set('services.remnawave.writes_enabled', false);
    config()->set('sales.manual_bank_review_enabled', false);
    config()->set('sales.subscription_page_host', '');
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
        ->assertCalled('sendMessage');

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
        ->and($usage->status)->toBe('ACTIVE')
        ->and(json_encode($usage, JSON_THROW_ON_ERROR))->not->toContain('private-sb06-credential');

    $bot->hearCallbackQueryData('sales:subs:view:'.$order.':0')->reply()
        ->assertReply('answerCallbackQuery', index: 0)
        ->assertReply('sendChatAction', ['action' => 'typing'], index: 1)
        ->assertReply('editMessageText', index: 2)
        ->assertCalled('answerCallbackQuery')->assertCalled('sendMessage', 0);
    Http::assertSentCount(2);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'GET'
        && $r->url() === 'https://remna.test/api/users/42');
    expect(config('services.remnawave.writes_enabled'))->toBeFalse();
});

it('shows one plan-named member entry while retaining private link access inside details', function (): void {
    $buyer = User::factory()->create();
    $order = sb06Order($buyer);
    sb06Activated($order);
    $bot = sb06Bot(976013, $buyer);
    Http::fake([
        'https://remna.test/api/users/42' => Http::response(['response' => sb06Provider($order)]),
    ]);

    $bot->hearCallbackQueryData('sales:subs:list:0')->reply()
        ->assertRaw(static function (NutgramRequest $request) use ($order): bool {
            $data = FakeNutgram::getActualData($request);
            $keyboard = json_decode($data['reply_markup'], true, 512, JSON_THROW_ON_ERROR);

            return ($keyboard['inline_keyboard'][0][0]['text'] ?? null) === '📦 اشتراک آزمایشی · 1'
                && ($keyboard['inline_keyboard'][0][0]['callback_data'] ?? null) === "sales:subs:view:{$order}:0"
                && count($keyboard['inline_keyboard'][0]) === 1
                && ! str_contains(json_encode($keyboard, JSON_THROW_ON_ERROR), 'sales:subs:link:');
        }, index: 1);

    $bot->hearCallbackQueryData("sales:subs:view:{$order}:0")->reply()
        ->assertRaw(static function (NutgramRequest $request) use ($order): bool {
            $data = FakeNutgram::getActualData($request);
            $keyboard = json_decode($data['reply_markup'], true, 512, JSON_THROW_ON_ERROR);

            return str_contains(json_encode($keyboard, JSON_THROW_ON_ERROR), "sales:subs:link:{$order}:0")
                && ! str_contains($data['text'], 'private-sb06-credential');
        }, index: 2);

    Http::assertSentCount(1);
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
        ->assertCalled('sendPhoto')
        ->assertCalled('sendMessage', 0)
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

it('links a pending subscription detail back to its own Sales order without extra writes', function (): void {
    $buyer = User::factory()->create();
    $order = sb06Order($buyer);
    $bot = sb06Bot(976011, $buyer);
    Http::fake();

    $bot->hearCallbackQueryData('sales:subs:view:'.$order.':0')->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText')
        ->assertCalled('sendMessage', 0);

    // The new order button reuses the existing owner-scoped Sales order callback.
    $bot->hearCallbackQueryData('sales:checkout:view:'.$order)->reply()
        ->assertCalled('editMessageText')
        ->assertCalled('sendMessage', 0);
    expect(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('pending_payment');
    Http::assertNothingSent();
});

it('delivers both short copyable and long selectable URLs only through a private confirmed link callback', function (): void {
    $buyer = User::factory()->create();
    $order = sb06Order($buyer);
    sb06Activated($order);
    $bot = sb06Bot(976012, $buyer);

    Http::fake([
        'https://remna.test/api/users/42' => Http::response(['response' => sb06Provider($order)]),
    ]);
    $bot->hearCallbackQueryData('sales:subs:link:'.$order.':0')->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('sendPhoto')
        ->assertCalled('sendMessage', 0)
        ->assertCalled('editMessageText', 0);
    Http::assertSentCount(1);

    // URLs beyond Telegram CopyTextButton's 256-char limit still get delivered
    // as private selectable text; the copy control is safely omitted.
    $longUrl = 'https://test.example.test/'.str_repeat('x', 285);
    Http::fake([
        'https://remna.test/api/users/42' => Http::response([
            'response' => sb06Provider($order, ['subscriptionUrl' => $longUrl]),
        ]),
    ]);
    $bot->hearCallbackQueryData('sales:subs:link:'.$order.':0')->reply()
        ->assertCalled('sendPhoto')
        ->assertCalled('sendMessage', 0)
        ->assertCalled('editMessageText', 0);
    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET');
    expect(DB::table('provisioning_order_links')->count())->toBe(1)
        ->and(config('services.remnawave.writes_enabled'))->toBeFalse();
});

it('keeps private link delivery fail-closed when remote usage is temporarily unavailable', function (): void {
    $buyer = User::factory()->create();
    $order = sb06Order($buyer);
    sb06Activated($order);
    $bot = sb06Bot(976013, $buyer);

    Http::fake([
        'https://remna.test/api/users/42' => Http::response([], 503),
    ]);
    $bot->hearCallbackQueryData('sales:subs:view:'.$order.':0')->reply()
        ->assertCalled('editMessageText')
        ->assertCalled('sendMessage', 0);
    $bot->hearCallbackQueryData('sales:subs:link:'.$order.':0')->reply()
        ->assertCalled('editMessageText')
        ->assertCalled('sendMessage', 0);

    Http::assertSentCount(2);
    expect(DB::table('provisioning_order_links')->count())->toBe(1);
});

it('rejects unsafe subscription page hosts and foreign or non-https URLs without leaking credentials', function (): void {
    $page = app(SubscriptionPageLink::class);
    foreach (['', 'https://sub.example.test', 'sub.example.test/path', 'sub.example.test:443', '*.example.test'] as $invalid) {
        config()->set('sales.subscription_page_host', $invalid);
        expect($page->configured())->toBeFalse();
    }

    config()->set('sales.subscription_page_host', 'Sub.Example.Test');
    expect($page->configured())->toBeTrue()
        ->and($page->matches('https://sub.example.test/short-user'))->toBeTrue();
    foreach ([
        'http://sub.example.test/short-user',
        'https://other.example.test/short-user',
        'https://sub.example.test.evil.test/short-user',
        'https://user:password@sub.example.test/short-user',
        'https://sub.example.test:443/short-user',
        'https://sub.example.test/short-user#private',
        "https://sub.example.test/short-user\r\nInjected: true",
        'https://sub.example.test/',
    ] as $unsafe) {
        expect($page->matches($unsafe))->toBeFalse();
    }
});

it('keeps the official page hidden by default and denies unlinked or unpaid callbacks', function (): void {
    $buyer = User::factory()->create();
    $order = sb06Order($buyer);
    $bot = sb06Bot(976101, $buyer);
    Http::fake();

    $bot->hearCallbackQueryData('sales:subs:page:'.$order.':0')->reply()
        ->assertCalled('editMessageText')->assertCalled('sendMessage', 0);
    config()->set('sales.subscription_page_host', 'sub.example.test');
    $bot->hearCallbackQueryData('sales:subs:page:'.$order.':0')->reply()
        ->assertCalled('editMessageText')->assertCalled('sendMessage', 0);
    $bot->hearCallbackQueryData('sales:subs:page:invalid:0')->reply()
        ->assertCalled('editMessageText')->assertCalled('sendMessage', 0);
    $bot->hearCallbackQueryData('sales:subs:page:'.$order.':-1')->reply()
        ->assertCalled('editMessageText')->assertCalled('sendMessage', 0);
    Http::assertNothingSent();
});

it('offers the exact verified official page URL only in the owner private chat', function (): void {
    config()->set('sales.subscription_page_host', 'sub.example.test');
    $buyer = User::factory()->create();
    $order = sb06Order($buyer);
    sb06Activated($order);
    $bot = sb06Bot(976102, $buyer);
    $url = 'https://sub.example.test/short-user';
    Http::fake(['https://remna.test/api/users/42' => Http::response([
        'response' => sb06Provider($order, ['subscriptionUrl' => $url]),
    ])]);

    $bot->hearCallbackQueryData('sales:subs:page:'.$order.':0')->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('sendPhoto')
        ->assertCalled('sendMessage', 0)
        ->assertCalled('editMessageText', 0);
    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET');
    expect(config('services.remnawave.writes_enabled'))->toBeFalse();
});

it('fails closed when RW returns a non-matching page URL or a stale/foreign owner', function (): void {
    config()->set('sales.subscription_page_host', 'sub.example.test');
    $buyer = User::factory()->create();
    $order = sb06Order($buyer);
    sb06Activated($order);
    $other = sb06Bot(976103, User::factory()->create());
    $owner = sb06Bot(976104, $buyer);
    Http::fake(['https://remna.test/api/users/42' => Http::response([
        'response' => sb06Provider($order, ['subscriptionUrl' => 'https://another.example.test/credential']),
    ])]);
    $other->hearCallbackQueryData('sales:subs:page:'.$order.':0')->reply()
        ->assertCalled('editMessageText')->assertCalled('sendMessage', 0);
    $owner->hearCallbackQueryData('sales:subs:page:'.$order.':0')->reply()
        ->assertCalled('editMessageText')->assertCalled('sendMessage', 0);
    Http::assertSentCount(1);

    Http::fake(['https://remna.test/api/users/42' => Http::response([
        'response' => sb06Provider($order, ['subscriptionUrl' => 'https://sub.example.test/short-user']),
    ])]);
    DB::table('sales_orders')->where('id', $order)->update(['status' => 'refunded']);
    $owner->hearCallbackQueryData('sales:subs:page:'.$order.':0')->reply()
        ->assertCalled('editMessageText')->assertCalled('sendMessage', 0);
    Http::assertNothingSent();
});

it('shows actual remote expiry, disability and exhausted traffic without a connection button', function (): void {
    $buyer = User::factory()->create();
    $order = sb06Order($buyer);
    sb06Activated($order);
    $bot = sb06Bot(976050, $buyer);

    $cases = [
        ['status' => 'EXPIRED', 'message' => 'منقضی شده'],
        ['status' => 'DISABLED', 'message' => 'غیرفعال'],
        ['status' => 'LIMITED', 'message' => 'محدود شده'],
        ['status' => 'ACTIVE', 'expireAt' => '2020-01-01T00:00:00.000Z', 'message' => 'منقضی شده'],
        ['status' => 'ACTIVE', 'userTraffic' => ['usedTrafficBytes' => 2147483648, 'lifetimeUsedTrafficBytes' => 2147483648], 'message' => 'حجم به پایان رسیده'],
    ];
    $sequence = Http::sequence();
    foreach ($cases as $case) {
        $overrides = $case;
        unset($overrides['message']);
        $payload = ['response' => sb06Provider($order, $overrides)];
        $sequence->push($payload)->push($payload);
    }
    Http::fake(['https://remna.test/api/users/42' => $sequence]);

    foreach ($cases as $case) {
        $expected = $case['message'];
        $bot->hearCallbackQueryData('sales:subs:view:'.$order.':0')->reply()
            ->assertRaw(static function (NutgramRequest $request) use ($expected): bool {
                $data = FakeNutgram::getActualData($request);
                $markup = json_encode($data['reply_markup'], JSON_THROW_ON_ERROR);

                return str_contains($data['text'], 'وضعیت سرویس: <b>')
                    && str_contains($data['text'], $expected)
                    && ! str_contains($data['text'], 'وضعیت ثبت‌شده: 🟢')
                    && ! str_contains($markup, 'sales:subs:link:')
                    && str_contains($markup, 'sales:buy');
            }, index: 2);
        $bot->hearCallbackQueryData('sales:subs:link:'.$order.':0')->reply()
            ->assertCalled('sendMessage', 0);
        Http::assertSent(static fn (Request $r): bool => $r->method() === 'GET');
    }
    expect(DB::table('provisioning_subscriptions')->where('remote_user_id', 42)->value('state'))->toBe('active');
});

it('shows an unknown status for transport failure, not a false active or expired label', function (): void {
    $buyer = User::factory()->create();
    $order = sb06Order($buyer);
    sb06Activated($order);
    $bot = sb06Bot(976051, $buyer);
    Http::fake(['https://remna.test/api/users/42' => Http::response([], 503)]);

    $bot->hearCallbackQueryData('sales:subs:view:'.$order.':0')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $data = FakeNutgram::getActualData($request);

            return str_contains($data['text'], 'فعلاً قابل بررسی نیست')
                && ! str_contains($data['text'], 'وضعیت ثبت‌شده: 🟢')
                && ! str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'sales:subs:link:');
        }, index: 2);
});

it('sends a private PNG QR after the existing link for authorized Sales accounts', function (string $role): void {
    Role::findOrCreate($role, 'web');

    $buyer = User::factory()->create();
    $buyer->syncRoles([$role]);
    $order = sb06Order($buyer);
    sb06Activated($order);
    $bot = sb06Bot(976080, $buyer);

    Http::fake([
        'https://remna.test/api/users/42' => Http::response(['response' => sb06Provider($order)]),
    ]);

    $bot->hearCallbackQueryData('sales:subs:link:'.$order.':0')->reply()
        ->assertCalled('sendPhoto')
        ->assertCalled('sendMessage', 0)
        ->assertCalled('editMessageText', 0);

    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET');

    expect(config('services.remnawave.writes_enabled'))->toBeFalse();
})->with(['member', 'reseller']);

it('renders an inline QR/photo and the full selectable URL in one Persian card', function (string $role): void {
    Role::findOrCreate($role, 'web');
    $buyer = User::factory()->create();
    $buyer->syncRoles([$role]);
    $order = sb06Order($buyer);
    sb06Activated($order);
    $bot = sb06Bot(976092, $buyer);
    Http::fake([
        'https://remna.test/api/users/42' => Http::response(['response' => sb06Provider($order)]),
    ]);

    // Nutgram's fake retains the stream reference, which is intentionally
    // closed after sending; do not reparse the expired multipart image body.
    $bot->hearCallbackQueryData('sales:subs:link:'.$order.':0')->reply()
        ->assertCalled('sendPhoto')
        ->assertCalled('sendMessage', 0);

    Http::assertSentCount(1);
})->with(['member', 'reseller']);

it('keeps a full text link and QR for URLs beyond Telegram media caption limits', function (): void {
    $buyer = User::factory()->create();
    $order = sb06Order($buyer);
    sb06Activated($order);
    $bot = sb06Bot(976093, $buyer);
    $url = 'https://test.example.test/'.str_repeat('a', 1100);
    Http::fake([
        'https://remna.test/api/users/42' => Http::response([
            'response' => sb06Provider($order, ['subscriptionUrl' => $url]),
        ]),
    ]);

    $bot->hearCallbackQueryData('sales:subs:link:'.$order.':0')->reply()
        ->assertCalled('sendPhoto')
        ->assertCalled('sendMessage')
        ->assertRaw(static function (NutgramRequest $request) use ($url): bool {
            $data = FakeNutgram::getActualData($request);

            return str_contains((string) ($data['text'] ?? ''), $url)
                && ! str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'copy_text');
        }, index: 2);

    Http::assertSentCount(1);
});

it('navigates QR photo Back to owned subscription without text-editing a photo', function (string $role): void {
    Role::findOrCreate($role, 'web');
    $buyer = User::factory()->create();
    $buyer->syncRoles([$role]);
    $order = sb06Order($buyer);
    sb06Activated($order);
    $bot = sb06Bot(976095, $buyer);
    Http::fake([
        'https://remna.test/api/users/42' => Http::response(['response' => sb06Provider($order)]),
    ]);

    // Exact kind of incoming update produced by tapping Back under the QR.
    $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
        'id' => 'qr-back-fixture',
        'data' => 'sales:subs:view:'.$order.':0',
        'message' => [
            'from' => [], 'date' => 1703892479,
            'photo' => [
                ['file_id' => 'qr-test', 'file_unique_id' => 'qr-test', 'width' => 480, 'height' => 480],
            ],
            'caption' => '🔐 لینک اتصال',
        ],
    ])->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('sendMessage')
        ->assertCalled('editMessageText', 0)
        ->assertCalled('sendPhoto', 0);

    Http::assertSentCount(1);
    expect(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('fulfilled');
})->with(['member', 'reseller']);

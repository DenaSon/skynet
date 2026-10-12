<?php

use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\GetMySubscription;
use App\Modules\Provisioning\Application\GetSubscriptionLink;
use App\Modules\Provisioning\Application\ListAccessibleNodes;
use App\Modules\Provisioning\Application\ListMySubscriptions;
use App\Modules\Sales\Application\CalculateQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Sales\Application\CreatePendingOrder;
use App\Modules\Sales\Application\OrderDetails;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

const E2_SQUAD_UUID = '550e8400-e29b-41d4-a716-446655440005';
const E2_NODE_UUID = '550e8400-e29b-41d4-a716-446655440012';

function e2Catalog(): void
{
    app(ConfigureProduct::class)->handle('e2-plan', 'Private Plan');
    app(ConfigureRate::class)->handle('e2-plan', 30, 100_000, 20_000);
}

function e2Order(User $buyer): OrderDetails
{
    $quote = app(CalculateQuote::class)->handle($buyer->id, 'e2-plan', 30, 10);

    return app(CreatePendingOrder::class)->handle($buyer->id, $quote, bin2hex(random_bytes(16)));
}

function e2Link(int $orderId, string $state = 'active', int $remoteId = 42): void
{
    $id = DB::table('provisioning_subscriptions')->insertGetId([
        'reference' => 'order-e2-'.$orderId,
        'remote_username' => 'sk_e2_'.$orderId,
        'remote_user_id' => $remoteId,
        'state' => $state,
        'last_operation_key' => 'create-e2-'.$orderId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('provisioning_order_links')->insert([
        'sales_order_id' => $orderId,
        'provisioning_subscription_id' => $id,
        'created_at' => now(),
    ]);
}

function e2PaidOrder(User $buyer): OrderDetails
{
    $order = e2Order($buyer);
    DB::table('sales_orders')->where('id', $order->id)->update(['status' => 'paid']);
    e2Link($order->id);

    return $order;
}

function e2Telegram(User $user, int $telegramId): SubscriptionViewer
{
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);

    return SubscriptionViewer::telegram($telegramId);
}

/** @return array<string, mixed> */
function e2Remote(int $orderId): array
{
    $reference = 'order-e2-'.$orderId;

    return [
        'id' => 42,
        'username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
        'shortUuid' => 'secret-short-id',
        'status' => 'ACTIVE',
        'expireAt' => '2027-10-12T00:00:00.000Z',
        'trafficLimitBytes' => 1073741824,
        'trafficLimitStrategy' => 'NO_RESET',
        'subscriptionUrl' => 'https://subscriptions.example.test/secret-link-123',
        'description' => 'SkyNetwork ref:'.hash('sha256', $reference),
        'activeInternalSquads' => [['uuid' => E2_SQUAD_UUID, 'name' => 'Squad']],
        'userTraffic' => ['usedTrafficBytes' => 128, 'lifetimeUsedTrafficBytes' => 256],
    ];
}

/** @param array<string, mixed> $override */
function e2FakeRemote(int $orderId, array $override = [], int $httpStatus = 200): void
{
    config()->set('services.remnawave.url', 'https://remna.test');
    config()->set('services.remnawave.token', 'test-token-no-log');
    config()->set('services.remnawave.timeout', 15);
    config()->set('services.remnawave.writes_enabled', false);

    Http::preventStrayRequests();
    Http::fake([
        'https://remna.test/api/users/42' => Http::response(['response' => [...e2Remote($orderId), ...$override]], $httpStatus),
    ]);
}

it('provides owner-scoped paginated local summaries from fresh Telegram identity and no HTTP calls', function (): void {
    e2Catalog();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $actor = e2Telegram($owner, 770001);
    $otherActor = e2Telegram($other, 770002);

    for ($i = 0; $i < 22; $i++) {
        e2Order($owner);
    }

    e2Order($other);
    Http::preventStrayRequests();
    Http::fake();

    $first = app(ListMySubscriptions::class)->handle($actor, 20);
    $second = app(ListMySubscriptions::class)->handle($actor, 20, $first->nextBeforeOrderId);

    expect($first->items)->toHaveCount(20)
        ->and($second->items)->toHaveCount(2)
        ->and($first->items[0]->status)->toBe('pending_payment')
        ->and($first->nextBeforeOrderId)->toBeInt()
        ->and($second->nextBeforeOrderId)->toBeNull()
        ->and(app(ListMySubscriptions::class)->handle($otherActor)->items)->toHaveCount(1);

    Http::assertNothingSent();
});

it('reauthorizes every action and blocks forged order IDs and disabled or unlinked actors', function (): void {
    e2Catalog();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $ownerActor = e2Telegram($owner, 770003);
    $foreignActor = e2Telegram($other, 770004);
    $order = e2Order($owner);

    expect(fn () => app(GetMySubscription::class)->handle($foreignActor, $order->id))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(ListMySubscriptions::class)->handle(SubscriptionViewer::telegram(999999)))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(GetMySubscription::class)->handle($ownerActor, $order->id)->summary->orderId)
        ->not->toThrow(AuthorizationException::class);

    $owner->forceFill(['is_active' => false])->save();

    expect(fn () => app(ListMySubscriptions::class)->handle($ownerActor))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(GetMySubscription::class)->handle($ownerActor, $order->id))
        ->toThrow(AuthorizationException::class);
});

it('derives web actors exclusively from the active session instead of user-submitted identifiers', function (): void {
    e2Catalog();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $order = e2Order($owner);
    $web = SubscriptionViewer::web();

    expect(fn () => app(ListMySubscriptions::class)->handle($web))
        ->toThrow(AuthorizationException::class);

    Auth::login($other);

    expect(fn () => app(GetMySubscription::class)->handle($web, $order->id))
        ->toThrow(ValidationException::class);

    Auth::login($owner);

    expect(app(GetMySubscription::class)->handle($web, $order->id)->summary->orderId)->toBe($order->id);

    $owner->forceFill(['is_active' => false])->save();

    expect(fn () => app(GetMySubscription::class)->handle($web, $order->id))
        ->toThrow(AuthorizationException::class);
});

it('never consults provider or releases link for an unsettled or unlinked order', function (): void {
    e2Catalog();
    $buyer = User::factory()->create();
    $viewer = e2Telegram($buyer, 770005);
    $order = e2Order($buyer);

    Http::preventStrayRequests();
    Http::fake();

    expect(fn () => app(GetSubscriptionLink::class)->handle($viewer, $order->id))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(ListAccessibleNodes::class)->handle($viewer, $order->id))
        ->toThrow(AuthorizationException::class);

    DB::table('sales_orders')->where('id', $order->id)->update(['status' => 'paid']);

    expect(fn () => app(GetSubscriptionLink::class)->handle($viewer, $order->id))
        ->toThrow(AuthorizationException::class);

    Http::assertNothingSent();
});

it('delivers one guarded credential to its exact owner and keeps regular details redacted', function (): void {
    e2Catalog();
    $buyer = User::factory()->create();
    $other = User::factory()->create();
    $viewer = e2Telegram($buyer, 770006);
    $otherViewer = e2Telegram($other, 770007);
    $order = e2PaidOrder($buyer);

    e2FakeRemote($order->id);
    $details = app(GetMySubscription::class)->handle($viewer, $order->id);
    $link = app(GetSubscriptionLink::class)->handle($viewer, $order->id);

    expect($link->orderId)->toBe($order->id)
        ->and($link->subscriptionUrl)->toBe('https://subscriptions.example.test/secret-link-123')
        ->and($details->canRequestLink)->toBeTrue()
        ->and(json_encode($details, JSON_THROW_ON_ERROR))->not->toContain('secret-link-123')
        ->and(json_encode(app(ListMySubscriptions::class)->handle($viewer), JSON_THROW_ON_ERROR))->not->toContain('secret-link-123');

    expect(fn () => app(GetSubscriptionLink::class)->handle($otherViewer, $order->id))
        ->toThrow(ValidationException::class);

    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://remna.test/api/users/42');
});

it('rejects spoofed ownership markers, disabled, expired or exhausted provider users without exposing data', function (): void {
    e2Catalog();
    $buyer = User::factory()->create();
    $viewer = e2Telegram($buyer, 770008);
    $order = e2PaidOrder($buyer);

    foreach ([
        ['description' => 'other-account'],
        ['id' => 999],
        ['status' => 'DISABLED'],
        ['expireAt' => '2026-01-01T00:00:00Z'],
        ['trafficLimitBytes' => 128],
    ] as $wrong) {
        e2FakeRemote($order->id, $wrong);

        expect(fn () => app(GetSubscriptionLink::class)->handle($viewer, $order->id))
            ->toThrow(RuntimeException::class, 'unavailable');
    }
});

it('blocks provider 401, 403, 429, 503 and malformed responses without a single write', function (): void {
    e2Catalog();
    $buyer = User::factory()->create();
    $viewer = e2Telegram($buyer, 770009);
    $order = e2PaidOrder($buyer);

    foreach ([401, 403, 429, 503] as $code) {
        e2FakeRemote($order->id, [], $code);

        expect(fn () => app(GetSubscriptionLink::class)->handle($viewer, $order->id))
            ->toThrow(RuntimeException::class, 'unavailable');
    }

    e2FakeRemote($order->id, ['subscriptionUrl' => 'http://insecure']);
    expect(fn () => app(GetSubscriptionLink::class)->handle($viewer, $order->id))
        ->toThrow(RuntimeException::class, 'unavailable');

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET');
});

it('reauthorizes after the HTTP response to prevent revoked or reassigned identity from leaking a link', function (): void {
    e2Catalog();
    $buyer = User::factory()->create();
    $viewer = e2Telegram($buyer, 770010);
    $order = e2PaidOrder($buyer);

    config()->set('services.remnawave.url', 'https://remna.test');
    config()->set('services.remnawave.token', 'test-token');
    Http::preventStrayRequests();
    Http::fake(['https://remna.test/api/users/42' => function () use ($buyer, $order) {
        DB::table('sales_orders')->where('id', $order->id)->update(['status' => 'refunded']);
        $buyer->forceFill(['is_active' => false])->save();

        return Http::response(['response' => e2Remote($order->id)]);
    }]);

    expect(fn () => app(GetSubscriptionLink::class)->handle($viewer, $order->id))
        ->toThrow(AuthorizationException::class);
    Http::assertSentCount(1);
});

it('returns only name and country for accessible nodes and verifies remote user association', function (): void {
    e2Catalog();
    $buyer = User::factory()->create();
    $viewer = e2Telegram($buyer, 770011);
    $order = e2PaidOrder($buyer);

    e2FakeRemote($order->id);
    Http::fake(['https://remna.test/api/users/42/accessible-nodes' => Http::response([
        'response' => [
            'userId' => 42,
            'activeNodes' => [[
                'uuid' => E2_NODE_UUID,
                'nodeName' => 'Canada East',
                'countryCode' => 'CA',
                'configProfileUuid' => E2_SQUAD_UUID,
                'configProfileName' => 'Private',
                'activeSquads' => [],
                'address' => '10.10.10.1',
                'privateSecret' => 'must-never-appear',
            ]],
        ],
    ])]);

    $nodes = app(ListAccessibleNodes::class)->handle($viewer, $order->id);
    $result = json_encode($nodes, JSON_THROW_ON_ERROR);

    expect($nodes)->toHaveCount(1)
        ->and($nodes[0]->name)->toBe('Canada East')
        ->and($nodes[0]->countryCode)->toBe('CA')
        ->and($result)->not->toContain(E2_NODE_UUID)
        ->not->toContain('10.10.10.1')
        ->not->toContain('must-never-appear');

    Http::assertSentCount(2);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET');
});

it('rejects accessible nodes belonging to a different provider user or an invalid schema', function (): void {
    e2Catalog();
    $buyer = User::factory()->create();
    $viewer = e2Telegram($buyer, 770012);
    $order = e2PaidOrder($buyer);

    foreach ([
        ['userId' => 43, 'activeNodes' => []],
        ['userId' => 42, 'activeNodes' => [['nodeName' => '', 'countryCode' => 'CA']]],
        ['userId' => 42, 'activeNodes' => 'not-list'],
        ['userId' => 42, 'activeNodes' => array_fill(0, 201, ['nodeName' => 'X', 'countryCode' => 'CA'])],
    ] as $response) {
        e2FakeRemote($order->id);
        Http::fake(['https://remna.test/api/users/42/accessible-nodes' => Http::response(['response' => $response])]);

        expect(fn () => app(ListAccessibleNodes::class)->handle($viewer, $order->id))
            ->toThrow(RuntimeException::class, 'unavailable');
    }
});

it('refuses link delivery if Sales ownership or lifecycle state changes mid-request', function (): void {
    e2Catalog();
    $buyer = User::factory()->create();
    $viewer = e2Telegram($buyer, 770013);
    $order = e2PaidOrder($buyer);

    config()->set('services.remnawave.url', 'https://remna.test');
    config()->set('services.remnawave.token', 'test-token');
    Http::preventStrayRequests();
    Http::fake(['https://remna.test/api/users/42' => function () use ($order) {
        DB::table('provisioning_subscriptions')->where('reference', 'order-e2-'.$order->id)
            ->update(['state' => 'needs_review']);

        return Http::response(['response' => e2Remote($order->id)]);
    }]);

    expect(fn () => app(GetSubscriptionLink::class)->handle($viewer, $order->id))
        ->toThrow(AuthorizationException::class);
});

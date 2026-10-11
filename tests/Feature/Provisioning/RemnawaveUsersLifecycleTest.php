<?php

use App\Modules\Provisioning\Application\RemnawaveSubscriptionLifecycle;
use App\Modules\Provisioning\Infrastructure\Remnawave\CreateRemnawaveUser;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClient;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveOperationCatalog;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveUsersApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const USER_EXPIRY = '2026-12-01T00:00:00.000Z';
const USER_SQUAD = '550e8400-e29b-41d4-a716-446655440005';

function lifecycleUserApi(): RemnawaveUsersApi
{
    return new RemnawaveUsersApi(new RemnawaveApiClient('https://remna.test', 'test-only-token'));
}

/** @return array<string, mixed> */
function userFixture(string $username = 'sk_demo', ?string $description = null): array
{
    return [
        'id' => 42,
        'shortUuid' => 'short-random-code',
        'username' => $username,
        'status' => 'ACTIVE',
        'expireAt' => USER_EXPIRY,
        'trafficLimitBytes' => 1073741824,
        'trafficLimitStrategy' => 'NO_RESET',
        'subscriptionUrl' => 'https://sub.example.test/short-random-code',
        'description' => $description,
        'userTraffic' => ['usedTrafficBytes' => 1024, 'lifetimeUsedTrafficBytes' => 2048],
    ];
}

function fakeWriteResponse(): void
{
    Http::preventStrayRequests();
    Http::fake([
        'https://remna.test/api/users*' => Http::response(['response' => userFixture()], 201),
    ]);
}

it('maps 17 users controller routes in pinned v3.4.4', function (): void {
    expect(array_filter(
        array_keys(RemnawaveOperationCatalog::OPERATIONS),
        static fn (string $id): bool => str_starts_with($id, 'UsersController_'),
    ))->toHaveCount(17);
});

it('validates safe creation fields, quotas, dates and squads', function (): void {
    $data = new CreateRemnawaveUser('sk_demo', USER_EXPIRY, 1073741824, [USER_SQUAD]);

    expect($data->body())->toMatchArray([
        'username' => 'sk_demo',
        'status' => 'ACTIVE',
        'trafficLimitBytes' => 1073741824,
        'trafficLimitStrategy' => 'NO_RESET',
        'activeInternalSquads' => [USER_SQUAD],
    ]);

    expect(fn () => new CreateRemnawaveUser('bad name', USER_EXPIRY, 10, [USER_SQUAD]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CreateRemnawaveUser('valid', '2026-02-30T00:00:00Z', 10, [USER_SQUAD]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CreateRemnawaveUser('valid', USER_EXPIRY, -1, [USER_SQUAD]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new CreateRemnawaveUser('valid', USER_EXPIRY, 10, []))
        ->toThrow(InvalidArgumentException::class);
});

it('creates with correct POST fields and returns only safe typed projection', function (): void {
    fakeWriteResponse();

    $created = lifecycleUserApi()->create(new CreateRemnawaveUser('sk_demo', USER_EXPIRY, 1073741824, [USER_SQUAD]));

    expect($created->id)->toBe(42)
        ->and($created->subscriptionUrl)->toBe('https://sub.example.test/short-random-code')
        ->and($created->usedTrafficBytes)->toBe(1024);

    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://remna.test/api/users'
        && $request['activeInternalSquads'] === [USER_SQUAD]
        && $request->hasHeader('Authorization', 'Bearer test-only-token'));
});

it('reads users by IDs, usernames and short codes, and paginates with API v3 start/size', function (): void {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'api/users?')) {
            return Http::response(['response' => ['users' => [userFixture()], 'total' => 1]], 200);
        }

        return Http::response(['response' => userFixture()], 200);
    });

    $api = lifecycleUserApi();
    expect($api->byId(42)->id)->toBe(42)
        ->and($api->byUsername('sk_demo')->username)->toBe('sk_demo')
        ->and($api->byShortUuid('short-random-code')->shortUuid)->toBe('short-random-code')
        ->and($api->list(0, 20)['total'])->toBe(1);

    Http::assertSentCount(4);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), '/api/users?')
        && str_contains($request->url(), 'size=20'));
});

it('implements disable, enable, extend, reset traffic, revoke and delete with correct bodies', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://remna.test/api/users/42/actions/*' => Http::response(['response' => userFixture()], 200),
        'https://remna.test/api/users/42' => Http::response('', 204),
    ]);

    $api = lifecycleUserApi();
    expect($api->disable(42)->id)->toBe(42)
        ->and($api->enable(42)->id)->toBe(42)
        ->and($api->extend(42, 30)->id)->toBe(42)
        ->and($api->resetTraffic(42)->id)->toBe(42)
        ->and($api->revoke(42, true)->id)->toBe(42);

    $api->delete(42);

    Http::assertSentCount(6);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/actions/extend')
        && $request['days'] === 30);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/actions/revoke')
        && $request['revokeOnlyPasswords'] === true);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://remna.test/api/users/42');
});

it('sends PATCH updates with numeric ID in JSON and no unspecified fields', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://remna.test/api/users' => Http::response(['response' => userFixture()], 200)]);

    $response = lifecycleUserApi()->update(42, [
        'expireAt' => USER_EXPIRY,
        'trafficLimitBytes' => 2048,
        'activeInternalSquads' => [USER_SQUAD],
    ]);

    expect($response->username)->toBe('sk_demo');
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'https://remna.test/api/users'
        && $request['id'] === 42
        && $request['trafficLimitBytes'] === 2048
        && $request['activeInternalSquads'] === [USER_SQUAD]);
});

it('blocks unsupported user updates and malformed provider responses without writing', function (): void {
    Http::preventStrayRequests();
    Http::fake();

    $api = lifecycleUserApi();

    expect(fn () => $api->update(42, ['id' => 99]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $api->update(42, ['trafficLimitBytes' => '-3']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $api->update(42, ['status' => 'EXPIRED']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $api->extend(0, 7))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $api->extend(42, 0))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('rejects incomplete provider projections rather than accepting missing subscription URLs', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://remna.test/api/users/42' => Http::response(['response' => [
        'id' => 42, 'username' => 'sk_demo', 'trafficLimitBytes' => 0,
    ]], 200)]);

    expect(fn () => lifecycleUserApi()->byId(42))->toThrow(UnexpectedValueException::class);
});

it('keeps all application mutations disabled unless explicitly enabled', function (): void {
    Http::preventStrayRequests();
    Http::fake();
    config()->set('services.remnawave.writes_enabled', false);

    expect(fn () => app(RemnawaveSubscriptionLifecycle::class)->create('order:8', 'purchase:8', USER_EXPIRY, 123, [USER_SQUAD]))
        ->toThrow(RuntimeException::class, 'disabled');

    expect(DB::table('provisioning_operations')->count())->toBe(0);
    Http::assertNothingSent();
});

function enableStaging(): void
{
    config()->set('services.remnawave.url', 'https://remna.test');
    config()->set('services.remnawave.token', 'test-only-token');
    config()->set('services.remnawave.timeout', 15);
    config()->set('services.remnawave.writes_enabled', true);
}

function ownedUser(string $reference): array
{
    $username = 'sk_'.substr(hash('sha256', $reference), 0, 24);
    $marker = 'SkyNetwork ref:'.hash('sha256', $reference);

    return userFixture($username, $marker);
}

it('reserves and completes first provisioning write exactly once per request key', function (): void {
    enableStaging();
    Http::preventStrayRequests();
    Http::fake(['https://remna.test/api/users' => function (Request $request) {
        return Http::response(['response' => [
            ...ownedUser('order:88'),
            'trafficLimitBytes' => $request['trafficLimitBytes'],
        ]], 201);
    }]);

    $lifecycle = app(RemnawaveSubscriptionLifecycle::class);
    expect($lifecycle->create('order:88', 'pay:88', USER_EXPIRY, 1073741824, [USER_SQUAD]))->toBe(42)
        ->and($lifecycle->create('order:88', 'pay:88', USER_EXPIRY, 1073741824, [USER_SQUAD]))->toBe(42);

    Http::assertSentCount(1);
    expect(DB::table('provisioning_subscriptions')->where('reference', 'order:88')->value('state'))->toBe('active')
        ->and(DB::table('provisioning_operations')->where('idempotency_key', 'pay:88')->value('state'))->toBe('completed');

    expect(fn () => $lifecycle->create('order:88', 'pay:88', USER_EXPIRY, 2048, [USER_SQUAD]))
        ->toThrow(RuntimeException::class, 'bound to a different operation');
});

it('locks uncertain provisioning after transport failures and reconciles by ownership marker', function (): void {
    enableStaging();
    Http::preventStrayRequests();
    Http::fake(['https://remna.test/api/users' => Http::response(['message' => 'temporarily unavailable'], 503)]);

    $lifecycle = app(RemnawaveSubscriptionLifecycle::class);
    expect(fn () => $lifecycle->create('order:97', 'pay:97', USER_EXPIRY, 100, [USER_SQUAD]))
        ->toThrow(RuntimeException::class, 'uncertain');

    expect(DB::table('provisioning_subscriptions')->where('reference', 'order:97')->value('state'))->toBe('needs_review');
    Http::assertSentCount(1);
    expect(fn () => $lifecycle->create('order:97', 'pay:97', USER_EXPIRY, 100, [USER_SQUAD]))
        ->toThrow(RuntimeException::class, 'reconciliation');
    Http::assertSentCount(1);

    Http::fake(['https://remna.test/api/users/by-username/*' => Http::response(['response' => [
        ...ownedUser('order:97'), 'trafficLimitBytes' => 100,
    ]], 200)]);
    expect($lifecycle->reconcileCreate('order:97', 'pay:97'))->toBe(42)
        ->and(DB::table('provisioning_operations')->where('idempotency_key', 'pay:97')->value('state'))->toBe('completed');

    // Http::fake() resets its recorded request list to the current stub sequence.
    Http::assertSentCount(1);
});

it('refuses reconciliation against a foreign user with the same username', function (): void {
    enableStaging();
    Http::preventStrayRequests();
    Http::fake(['https://remna.test/api/users' => Http::response('', 503)]);

    $lifecycle = app(RemnawaveSubscriptionLifecycle::class);
    try {
        $lifecycle->create('order:99', 'pay:99', USER_EXPIRY, 100, [USER_SQUAD]);
    } catch (RuntimeException) {
        // Deliberately left uncertain.
    }

    $foreign = ownedUser('order:99');
    $foreign['description'] = 'someone-else';

    Http::fake(['https://remna.test/api/users/by-username/*' => Http::response(['response' => $foreign], 200)]);

    expect(fn () => $lifecycle->reconcileCreate('order:99', 'pay:99'))
        ->toThrow(RuntimeException::class, 'not owned');

    expect(DB::table('provisioning_subscriptions')->where('reference', 'order:99')->value('state'))->toBe('needs_review');
});

it('reserves absolute quota and expiry updates and never repeats a completed mutation', function (): void {
    enableStaging();
    Http::preventStrayRequests();
    Http::fake(['https://remna.test/api/users' => function (Request $request) {
        $remote = ownedUser('order:100');
        $remote['trafficLimitBytes'] = $request['trafficLimitBytes'] ?? 100;
        $remote['expireAt'] = $request['expireAt'] ?? USER_EXPIRY;
        $remote['status'] = $request['status'] ?? 'ACTIVE';

        return Http::response(['response' => $remote], $request->method() === 'POST' ? 201 : 200);
    }]);

    $lifecycle = app(RemnawaveSubscriptionLifecycle::class);
    expect($lifecycle->create('order:100', 'pay:100', USER_EXPIRY, 100, [USER_SQUAD]))->toBe(42)
        ->and($lifecycle->setTrafficLimit('order:100', 'quota:100', 2048))->toBe(42)
        ->and($lifecycle->setTrafficLimit('order:100', 'quota:100', 2048))->toBe(42)
        ->and($lifecycle->setExpiration('order:100', 'extend:100', '2027-01-01T00:00:00Z'))->toBe(42)
        ->and($lifecycle->disable('order:100', 'disable:100'))->toBe(42)
        ->and($lifecycle->enable('order:100', 'enable:100'))->toBe(42);

    Http::assertSentCount(5);
    expect(DB::table('provisioning_operations')->where('state', 'completed')->count())->toBe(5);
});

it('protects subscription URL reads by local ownership and only permits active links', function (): void {
    enableStaging();
    Http::preventStrayRequests();
    Http::fake(['https://remna.test/api/users' => Http::response(['response' => [
        ...ownedUser('order:101'), 'trafficLimitBytes' => 100,
    ]], 201)]);

    $lifecycle = app(RemnawaveSubscriptionLifecycle::class);
    expect(fn () => $lifecycle->subscription('missing:101'))->toThrow(RuntimeException::class);

    $lifecycle->create('order:101', 'pay:101', USER_EXPIRY, 100, [USER_SQUAD]);
    Http::fake(['https://remna.test/api/users/42' => Http::response(['response' => ownedUser('order:101')], 200)]);

    expect($lifecycle->subscription('order:101')->subscriptionUrl)->toBe('https://sub.example.test/short-random-code');
    Http::assertSentCount(1);
});

it('reconciles uncertain absolute quota updates by remote state without a second PATCH', function (): void {
    enableStaging();
    Http::preventStrayRequests();
    Http::fake(['https://remna.test/api/users' => Http::response(['response' => [
        ...ownedUser('order:105'), 'trafficLimitBytes' => 100,
    ]], 201)]);

    $lifecycle = app(RemnawaveSubscriptionLifecycle::class);
    expect($lifecycle->create('order:105', 'pay:105', USER_EXPIRY, 100, [USER_SQUAD]))->toBe(42);

    Http::fake(['https://remna.test/api/users' => Http::response(['message' => 'timeout'], 503)]);

    expect(fn () => $lifecycle->setTrafficLimit('order:105', 'quota:105', 2048))
        ->toThrow(RuntimeException::class, 'uncertain');

    expect(DB::table('provisioning_subscriptions')->where('reference', 'order:105')->value('state'))->toBe('needs_review');

    Http::fake(['https://remna.test/api/users/42' => Http::response(['response' => [
        ...ownedUser('order:105'), 'trafficLimitBytes' => 2048,
    ]], 200)]);

    expect($lifecycle->reconcileUpdate('order:105', 'quota:105'))->toBe(42)
        ->and($lifecycle->setTrafficLimit('order:105', 'quota:105', 2048))->toBe(42);

    Http::assertSentCount(1);
});

it('refuses to mark a quota update complete if remote state does not match', function (): void {
    enableStaging();
    Http::preventStrayRequests();
    Http::fake(['https://remna.test/api/users' => Http::response(['response' => [
        ...ownedUser('order:106'), 'trafficLimitBytes' => 100,
    ]], 201)]);

    $lifecycle = app(RemnawaveSubscriptionLifecycle::class);
    $lifecycle->create('order:106', 'pay:106', USER_EXPIRY, 100, [USER_SQUAD]);

    Http::fake(['https://remna.test/api/users' => Http::response(['message' => 'unavailable'], 503)]);
    try {
        $lifecycle->setTrafficLimit('order:106', 'quota:106', 2048);
    } catch (RuntimeException) {
        // Uncertain outcome is intentional.
    }

    Http::fake(['https://remna.test/api/users/42' => Http::response(['response' => [
        ...ownedUser('order:106'), 'trafficLimitBytes' => 100,
    ]], 200)]);

    expect(fn () => $lifecycle->reconcileUpdate('order:106', 'quota:106'))
        ->toThrow(RuntimeException::class, 'differs');

    expect(DB::table('provisioning_operations')->where('idempotency_key', 'quota:106')->value('state'))->toBe('needs_review');
});


it('can reconcile a crash-left reservation with a read-only lookup and no replay', function (): void {
    enableStaging();
    Http::preventStrayRequests();
    Http::fake(['https://remna.test/api/users' => Http::response(['message' => 'failed'], 503)]);
    $lifecycle = app(RemnawaveSubscriptionLifecycle::class);

    try {
        $lifecycle->create('order:107', 'pay:107', USER_EXPIRY, 100, [USER_SQUAD]);
    } catch (RuntimeException) {
        // Simulate a process exit before the exception path can write needs_review.
    }

    DB::table('provisioning_subscriptions')->where('reference', 'order:107')->update(['state' => 'reserved']);
    DB::table('provisioning_operations')->where('idempotency_key', 'pay:107')->update(['state' => 'reserved']);

    Http::fake(['https://remna.test/api/users/by-username/*' => Http::response(['response' => [
        ...ownedUser('order:107'), 'trafficLimitBytes' => 100,
    ]], 200)]);

    expect($lifecycle->reconcileCreate('order:107', 'pay:107'))->toBe(42)
        ->and(DB::table('provisioning_operations')->where('idempotency_key', 'pay:107')->value('state'))->toBe('completed');

    Http::assertSentCount(1);
});

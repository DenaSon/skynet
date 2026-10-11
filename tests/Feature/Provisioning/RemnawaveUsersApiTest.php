<?php

use App\Modules\Provisioning\Infrastructure\Remnawave\CreateRemnawaveUser;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClient;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveUsersApi;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const USER_SQUAD_UUID = '550e8400-e29b-41d4-a716-446655440000';

function userApi(): RemnawaveUsersApi
{
    return new RemnawaveUsersApi(new RemnawaveApiClient('https://panel.example.test', 'testing-token'));
}

/** @return array<string, mixed> */
function remoteUser(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 42,
        'username' => 'sky_order_42',
        'shortUuid' => 'abcdefgh',
        'status' => 'ACTIVE',
        'trafficLimitBytes' => 1073741824,
        'trafficLimitStrategy' => 'NO_RESET',
        'expireAt' => '2026-12-01T00:00:00Z',
        'subscriptionUrl' => 'https://subscriptions.example.test/sub/abcdefgh',
        'userTraffic' => ['usedTrafficBytes' => 12345],
    ], $overrides);
}

function prepareUserResponse(int $status = 200): void
{
    Http::preventStrayRequests();
    Http::fake(['https://panel.example.test/*' => Http::response(['response' => remoteUser()], $status)]);
}

it('creates a subscriber using documented required fields and explicit safe limits', function (): void {
    prepareUserResponse(201);
    $payload = new CreateRemnawaveUser(
        username: 'sky_order_42',
        expireAt: '2026-12-01T00:00:00Z',
        trafficLimitBytes: 1073741824,
        internalSquadUuids: [USER_SQUAD_UUID],
    );

    $user = userApi()->create($payload);

    expect($user->id)->toBe(42)
        ->and($user->username)->toBe('sky_order_42')
        ->and($user->trafficLimitBytes)->toBe(1073741824)
        ->and($user->subscriptionUrl)->toBe('https://subscriptions.example.test/sub/abcdefgh');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://panel.example.test/api/users'
        && $request['activeInternalSquads'] === [USER_SQUAD_UUID]
        && $request['trafficLimitBytes'] === 1073741824
        && $request['expireAt'] === '2026-12-01T00:00:00Z'
        && $request->hasHeader('Authorization', 'Bearer testing-token'));
});

it('retrieves subscriber by numeric ID, username and short UUID using documented paths', function (): void {
    prepareUserResponse();

    $api = userApi();
    expect($api->byId(42)->id)->toBe(42)
        ->and($api->byUsername('sky_order_42')->shortUuid)->toBe('abcdefgh')
        ->and($api->byShortUuid('abcdefgh')->status)->toBe('ACTIVE');

    Http::assertSentCount(3);
    foreach ([
        '/api/users/42',
        '/api/users/by-username/sky_order_42',
        '/api/users/by-short-uuid/abcdefgh',
    ] as $path) {
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://panel.example.test'.$path
            && $request->method() === 'GET');
    }
});

it('retrieves paginated provider users without exposing password fields', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://panel.example.test/api/users*' => Http::response([
        'response' => ['users' => [remoteUser(['trojanPassword' => 'inbound-secret'])], 'total' => 1],
    ])]);

    $users = userApi()->list(start: 10, size: 20);

    expect($users['total'])->toBe(1)
        ->and($users['users'])->toHaveCount(1)
        ->and(array_keys(get_object_vars($users['users'][0])))->not->toContain('trojanPassword');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), 'start=10')
        && str_contains($request->url(), 'size=20'));
});

it('uses PATCH body id and validates whitelisted user mutations', function (): void {
    prepareUserResponse();

    $api = userApi();
    expect($api->update(42, [
        'trafficLimitBytes' => 5368709120,
        'expireAt' => '2027-01-01T00:00:00Z',
        'status' => 'ACTIVE',
    ])->id)->toBe(42);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'https://panel.example.test/api/users'
        && $request['id'] === 42
        && $request['trafficLimitBytes'] === 5368709120
        && $request['expireAt'] === '2027-01-01T00:00:00Z');

    Http::assertSentCount(1);
});

it('rejects invalid user payloads before making any provider request', function (): void {
    Http::preventStrayRequests();
    Http::fake();
    $api = userApi();

    expect(fn () => new CreateRemnawaveUser('bad username!', '2026-12-01T00:00:00Z', 100, [USER_SQUAD_UUID]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new CreateRemnawaveUser('valid', '2026-02-30T00:00:00Z', 100, [USER_SQUAD_UUID]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new CreateRemnawaveUser('valid', '2026-12-01T00:00:00Z', -1, [USER_SQUAD_UUID]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new CreateRemnawaveUser('valid', '2026-12-01T00:00:00Z', 100, []))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $api->byId(0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $api->update(42, []))->toThrow(InvalidArgumentException::class);
    expect(fn () => $api->update(42, ['id' => 900]))->toThrow(InvalidArgumentException::class);
    expect(fn () => $api->update(42, ['trafficLimitBytes' => -50]))->toThrow(InvalidArgumentException::class);
    expect(fn () => $api->update(42, ['status' => 'EXPIRED']))->toThrow(InvalidArgumentException::class);
    expect(fn () => $api->list(0, 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $api->extend(42, 0))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('sends enable, disable, extend, traffic reset and revoke with correct verbs', function (): void {
    prepareUserResponse();
    $api = userApi();

    $api->enable(42);
    $api->disable(42);
    $api->extend(42, 30);
    $api->resetTraffic(42);
    $api->revoke(42, true);

    Http::assertSentCount(5);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/users/42/actions/extend')
        && $request['days'] === 30);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/users/42/actions/revoke')
        && $request['revokeOnlyPasswords'] === true);
});

it('deletes users with an empty 204 response and does not retry', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://panel.example.test/api/users/42' => Http::response('', 204)]);

    userApi()->delete(42);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

it('fails closed when the provider returns a malformed user response', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://panel.example.test/*' => Http::response([
        'response' => ['id' => 42, 'username' => 'incomplete'],
    ])]);

    expect(fn () => userApi()->byId(42))->toThrow(UnexpectedValueException::class);
    Http::assertSentCount(1);
});

it('fetches read-only accessible nodes, tags and request history', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://panel.example.test/api/users/42/accessible-nodes' => Http::response([
            'response' => ['userId' => 42, 'activeNodes' => []],
        ]),
        'https://panel.example.test/api/users/42/subscription-request-history' => Http::response([
            'response' => ['total' => 0, 'records' => []],
        ]),
        'https://panel.example.test/api/users/tags' => Http::response([
            'response' => ['tags' => ['VIP']],
        ]),
    ]);

    $api = userApi();
    expect($api->accessibleNodes(42)['activeNodes'])->toBe([])
        ->and($api->requestHistory(42)['total'])->toBe(0)
        ->and($api->tags()['tags'])->toBe(['VIP']);

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
});

it('propagates remote errors to the trusted application layer without manufacturing success', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://panel.example.test/*' => Http::response(['message' => 'denied'], 403)]);

    expect(fn () => userApi()->byId(42))->toThrow(\Illuminate\Http\Client\RequestException::class);
    Http::assertSentCount(1);
});

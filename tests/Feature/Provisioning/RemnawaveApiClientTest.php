<?php

use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClient;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveOperationCatalog;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

function remnawaveClient(): RemnawaveApiClient
{
    return new RemnawaveApiClient('https://remnawave.test', 'test-secret-token');
}

it('pins all 218 Remnawave v3.4.4 OpenAPI operations without duplicates', function (): void {
    expect(RemnawaveOperationCatalog::API_VERSION)->toBe('3.4.4')
        ->and(RemnawaveOperationCatalog::OPERATIONS)->toHaveCount(218)
        ->and(RemnawaveOperationCatalog::find('UsersController_getUserById'))->toMatchArray([
            'method' => 'GET',
            'path' => '/api/users/{userId}',
        ]);
});

it('sends a mapped authorized request and preserves the response envelope', function (): void {
    Http::preventStrayRequests();
    Http::fake(['remnawave.test/*' => Http::response(['response' => ['id' => 42]], 200)]);

    $result = remnawaveClient()->call('UsersController_getUserById', ['userId' => 42]);

    expect($result)->toBe(['response' => ['id' => 42]]);

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://remnawave.test/api/users/42'
        && $request->hasHeader('Authorization', 'Bearer test-secret-token'));
    Http::assertSentCount(1);
});

it('maps POST JSON bodies without replaying writes', function (): void {
    Http::preventStrayRequests();
    Http::fake(['remnawave.test/*' => Http::response(['response' => ['id' => 52]], 201)]);

    remnawaveClient()->call('UsersController_createUser', body: ['username' => 'test_user']);

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://remnawave.test/api/users'
        && $request['username'] === 'test_user');
    Http::assertSentCount(1);
});

it('rejects unmapped operations and missing required parameters before HTTP', function (): void {
    Http::preventStrayRequests();
    Http::fake();

    expect(fn () => remnawaveClient()->call('UnknownController_destroyAll'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => remnawaveClient()->call('UsersController_getUserById'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => remnawaveClient()->call('SystemController_getStatsDigest'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => remnawaveClient()->call('UsersController_createUser'))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('encodes path parameters rather than allowing arbitrary path injection', function (): void {
    Http::preventStrayRequests();
    Http::fake(['remnawave.test/*' => Http::response(['response' => []], 200)]);

    remnawaveClient()->call('UsersController_getUserByUsername', ['username' => 'a/b c']);

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://remnawave.test/api/users/by-username/a%2Fb%20c');
});

it('propagates HTTP errors and never automatically retries a write', function (): void {
    Http::preventStrayRequests();
    Http::fake(['remnawave.test/*' => Http::response(['message' => 'Invalid input'], 422)]);

    expect(fn () => remnawaveClient()->call('UsersController_createUser', body: ['username' => 'test_user']))
        ->toThrow(RequestException::class);

    Http::assertSentCount(1);
});

it('rejects redirect responses rather than forwarding the API token', function (): void {
    Http::preventStrayRequests();
    Http::fake(['remnawave.test/*' => Http::response('', 302, ['Location' => 'https://external.test'])]);

    expect(fn () => remnawaveClient()->call('UsersController_getUsers'))->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
});

it('returns null for an empty success body', function (): void {
    Http::preventStrayRequests();
    Http::fake(['remnawave.test/*' => Http::response('', 204)]);

    expect(remnawaveClient()->call('UsersController_deleteUser', ['userId' => 42]))->toBeNull();
});

it('requires an HTTPS base URL and a non-empty token', function (): void {
    expect(fn () => new RemnawaveApiClient('http://remnawave.test', 'token'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new RemnawaveApiClient('https://remnawave.test', ''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new RemnawaveApiClient('https://remnawave.test?x=1', 'token'))->toThrow(InvalidArgumentException::class);
});

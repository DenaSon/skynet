<?php

use App\Modules\Provisioning\Infrastructure\Arianet\ArianetCloudReadClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.arianet_cloud.read_token', 'test-read-token');
    config()->set('services.arianet_cloud.timeout', 10);
    Http::preventStrayRequests();
});

it('reads a paginated Cloud server inventory with the independent bearer token', function (): void {
    $envelope = [
        'data' => [['id' => 42, 'name' => 'node-01']],
        'meta' => ['pagination' => ['page' => 2, 'per_page' => 10, 'last_page' => 3]],
    ];
    Http::fake(['https://api.ariaservice.net/cloud/v1/*' => Http::response($envelope)]);

    expect(app(ArianetCloudReadClient::class)->servers(2, 10))->toBe($envelope);

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.ariaservice.net/cloud/v1/servers?page=2&per_page=10'
        && $request->hasHeader('Authorization', 'Bearer test-read-token')
        && $request->hasHeader('Accept', 'application/json'));
    Http::assertSentCount(1);
});

it('keeps all scoped operations GET-only with fixed paths', function (): void {
    Http::fake(['https://api.ariaservice.net/cloud/v1/*' => Http::response(['data' => []])]);

    $cloud = app(ArianetCloudReadClient::class);
    $cloud->datacenters();
    $cloud->plans('ovh');
    $cloud->pricing();
    $cloud->limits();
    $cloud->servers();
    $cloud->server(42);
    $cloud->networking(42);
    $cloud->costs(42);
    $cloud->ipChanges(42);
    $cloud->actions();
    $cloud->serverActions(42);
    $cloud->action(12);

    $expectedPaths = [
        '/datacenters',
        '/plans?datacenter=ovh',
        '/pricing',
        '/limits',
        '/servers?page=1&per_page=25',
        '/servers/42',
        '/servers/42/networking',
        '/servers/42/costs',
        '/servers/42/ip-changes?page=1&per_page=25',
        '/actions?page=1&per_page=25',
        '/servers/42/actions?page=1&per_page=25',
        '/actions/12',
    ];
    $requests = Http::recorded()->map(
        static fn (array $pair): string => $pair[0]->method().' '.$pair[0]->url()
    )->all();

    expect($requests)->toBe(array_map(
        static fn (string $path): string => 'GET https://api.ariaservice.net/cloud/v1'.$path,
        $expectedPaths,
    ));
    Http::assertSentCount(12);
});

it('rejects untrusted IDs, pagination and datacenter codes before network access', function (): void {
    Http::fake();

    $cloud = app(ArianetCloudReadClient::class);
    foreach ([
        static fn () => $cloud->server(0),
        static fn () => $cloud->networking(-1),
        static fn () => $cloud->costs(0),
        static fn () => $cloud->ipChanges(0),
        static fn () => $cloud->serverActions(0),
        static fn () => $cloud->action(-2),
        static fn () => $cloud->servers(0),
        static fn () => $cloud->servers(1, 101),
        static fn () => $cloud->actions(10001),
        static fn () => $cloud->plans('OVH/../servers'),
        static fn () => $cloud->plans(''),
    ] as $invoke) {
        expect($invoke)->toThrow(InvalidArgumentException::class);
    }

    Http::assertNothingSent();
});

it('fails closed when read-only credentials or timeout are missing or invalid', function (): void {
    Http::fake();
    $cloud = app(ArianetCloudReadClient::class);

    config()->set('services.arianet_cloud.read_token', '  ');
    expect(fn () => $cloud->servers())->toThrow(RuntimeException::class, 'not configured');

    config()->set('services.arianet_cloud.read_token', 'test-read-token');
    foreach ([0, 31, '10', null] as $timeout) {
        config()->set('services.arianet_cloud.timeout', $timeout);
        expect(fn () => $cloud->servers())->toThrow(RuntimeException::class, 'Invalid');
    }

    Http::assertNothingSent();
});

it('rejects provider status errors and redirects without leaking response bodies', function (): void {
    $secret = 'sensitive-upstream-response';
    $sequence = Http::fakeSequence()
        ->push(['error' => $secret], 401)
        ->push(['error' => $secret], 403)
        ->push(['error' => $secret], 429, ['Retry-After' => '60'])
        ->push(['error' => $secret], 503, ['Retry-After' => '10'])
        ->push('', 302, ['Location' => 'https://evil.example/collect']);
    $cloud = app(ArianetCloudReadClient::class);

    foreach ([401, 403, 429, 503, 302] as $status) {
        try {
            $cloud->servers();
            test()->fail('Expected an API exception for HTTP '.$status);
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->not->toContain($secret);
        }
    }

    expect($sequence)->not->toBeNull();
    Http::assertSentCount(5);
    Http::assertNotSent(static fn (Request $request): bool => $request->method() !== 'GET');
});

it('rejects missing, malformed and oversized JSON envelopes', function (): void {
    Http::fakeSequence()
        ->push(['unexpected' => []])
        ->push('not json')
        ->push(['data' => null])
        ->push(['data' => ['notes' => str_repeat('x', 1048576)]]);

    $cloud = app(ArianetCloudReadClient::class);
    foreach (range(1, 4) as $attempt) {
        expect(fn () => $cloud->server(42))->toThrow(RuntimeException::class);
    }

    Http::assertSentCount(4);
});

it('sanitizes connection failures and never retries them', function (): void {
    $attempts = 0;
    Http::fake(static function () use (&$attempts): never {
        $attempts++;
        throw new ConnectionException('Authorization: Bearer test-read-token');
    });

    expect(fn () => app(ArianetCloudReadClient::class)->servers())
        ->toThrow(RuntimeException::class, 'Arianet Cloud connection failed.');

    expect($attempts)->toBe(1);
    // Laravel records only requests for which the fake returns a response.
    Http::assertNothingSent();
});

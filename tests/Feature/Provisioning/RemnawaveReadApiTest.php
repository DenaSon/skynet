<?php

use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClient;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveReadApi;
use Illuminate\Support\Facades\Http;

function remnawaveReadApi(): RemnawaveReadApi
{
    return new RemnawaveReadApi(new RemnawaveApiClient('https://rw.example.test', 'test-only-token'));
}

function fakeRemnawaveDiscovery(): void
{
    Http::preventStrayRequests();
    Http::fake([
        'https://rw.example.test/api/system/stats' => Http::response([
            'response' => [
                'cpu' => ['cores' => 2],
                'memory' => ['total' => 2060730368, 'used' => 1229987840, 'free' => 830742528],
                'uptime' => 23341.83,
                'users' => ['totalUsers' => 2],
                'onlineStats' => ['onlineNow' => 0],
            ],
        ]),
        'https://rw.example.test/api/system/health' => Http::response([
            'response' => ['runtimeMetrics' => [['instanceId' => 'api-1']]],
        ]),
        'https://rw.example.test/api/nodes' => Http::response([
            'response' => [
                ['uuid' => 'node-01', 'name' => 'Node 01', 'isConnected' => true, 'isDisabled' => false],
                ['uuid' => 'node-02', 'name' => 'Node 02', 'isConnected' => true, 'isDisabled' => true],
                ['uuid' => 'node-03', 'name' => 'Node 03', 'isConnected' => false, 'isDisabled' => false],
            ],
        ]),
        'https://rw.example.test/api/hosts' => Http::response([
            'response' => [['uuid' => 'host-01'], ['uuid' => 'host-02']],
        ]),
        'https://rw.example.test/api/internal-squads' => Http::response([
            'response' => ['total' => 2, 'internalSquads' => [['uuid' => 'sq-1'], ['uuid' => 'sq-2']]],
        ]),
        'https://rw.example.test/api/config-profiles' => Http::response([
            'response' => ['total' => 1, 'configProfiles' => [['uuid' => 'cfg-1']]],
        ]),
    ]);
}

it('reads health and inventory through five documented read-only endpoints', function (): void {
    fakeRemnawaveDiscovery();

    $read = remnawaveReadApi();
    $instances = $read->healthInstanceCount();
    $inventory = $read->inventory();

    expect($instances)->toBe(1)
        ->and($inventory->nodes)->toHaveCount(3)
        ->and($inventory->nodes[0]->name)->toBe('Node 01')
        ->and($inventory->connectedNodes())->toBe(1)
        ->and($inventory->hosts)->toBe(2)
        ->and($inventory->internalSquads)->toBe(2)
        ->and($inventory->configProfiles)->toBe(1);

    Http::assertSentCount(5);
    Http::assertSent(static fn ($request): bool => $request->url() === 'https://rw.example.test/api/nodes'
        && $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer test-only-token'));
});

it('rejects missing response envelopes rather than reporting healthy infrastructure', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://rw.example.test/api/system/health' => Http::response(['status' => 'ok'])]);

    expect(fn () => remnawaveReadApi()->healthInstanceCount())
        ->toThrow(UnexpectedValueException::class);

    Http::assertSentCount(1);
});

it('rejects malformed node flags and does not produce misleading inventory', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://rw.example.test/api/nodes' => Http::response([
            'response' => [['uuid' => 'node-01', 'name' => 'Node 01', 'isConnected' => 'yes', 'isDisabled' => false]],
        ]),
    ]);

    expect(fn () => remnawaveReadApi()->inventory())->toThrow(UnexpectedValueException::class);
    Http::assertSentCount(1);
});

it('fails closed when remote credentials are missing', function (): void {
    config()->set('services.remnawave.url', null);
    config()->set('services.remnawave.token', null);
    Http::preventStrayRequests();
    Http::fake();

    expect(fn () => app(RemnawaveApiClientFactory::class)->make())->toThrow(RuntimeException::class);

    $this->artisan('remnawave:inspect')->assertExitCode(1);
    Http::assertNothingSent();
});

it('runs a read-only operator inspection without exposing secrets or writing resources', function (): void {
    fakeRemnawaveDiscovery();

    config()->set('services.remnawave.url', 'https://rw.example.test');
    config()->set('services.remnawave.token', 'test-only-token');
    config()->set('services.remnawave.timeout', 15);

    $this->artisan('remnawave:inspect', ['--nodes' => true])
        ->expectsOutputToContain('Remnawave API inspection successful')
        ->expectsOutputToContain('connected and enabled: 1')
        ->expectsOutputToContain('Hosts: 2')
        ->assertExitCode(0);

    Http::assertSentCount(5);
    Http::assertSent(static fn ($request): bool => $request->method() === 'GET');
});

it('does not treat an HTTP authorization error as successful smoke testing', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://rw.example.test/*' => Http::response(['message' => 'denied'], 403)]);

    config()->set('services.remnawave.url', 'https://rw.example.test');
    config()->set('services.remnawave.token', 'test-only-token');
    config()->set('services.remnawave.timeout', 15);

    $this->artisan('remnawave:inspect')->assertExitCode(1);
    Http::assertSentCount(1);
});

it('reads and validates panel resource stats from the existing GET endpoint', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://rw.example.test/api/system/stats' => Http::response([
            'response' => [
                'cpu' => ['cores' => 2],
                'memory' => ['total' => 2060730368, 'used' => 1229987840, 'free' => 830742528],
                'uptime' => 23341.83,
                'users' => ['totalUsers' => 2],
                'onlineStats' => ['onlineNow' => 0],
            ],
        ]),
    ]);

    $stats = remnawaveReadApi()->systemStats();

    expect($stats['cpuCores'])->toBe(2)
        ->and($stats['memoryTotal'])->toBe(2060730368)
        ->and($stats['memoryUsed'])->toBe(1229987840)
        ->and($stats['memoryFree'])->toBe(830742528)
        ->and($stats['uptimeSeconds'])->toBe(23341.83)
        ->and($stats['totalUsers'])->toBe(2)
        ->and($stats['onlineUsers'])->toBe(0);

    Http::assertSentCount(1);
    Http::assertSent(static fn ($request): bool => $request->url() === 'https://rw.example.test/api/system/stats'
        && $request->method() === 'GET');
});

it('rejects malformed or negative panel resource stats without accepting fabricated numbers', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'https://rw.example.test/api/system/stats' => Http::response([
            'response' => [
                'cpu' => ['cores' => '2'],
                'memory' => ['total' => 2060730368, 'used' => 1229987840, 'free' => 830742528],
                'uptime' => 23341.83,
                'users' => ['totalUsers' => 2],
                'onlineStats' => ['onlineNow' => 0],
            ],
        ]),
    ]);

    expect(fn () => remnawaveReadApi()->systemStats())->toThrow(UnexpectedValueException::class);
    Http::assertSentCount(1);
});

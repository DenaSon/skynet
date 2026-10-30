<?php

declare(strict_types=1);

use App\Support\Health\NodeTcpProbe;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

const TCP02_NODE = '550e8400-e29b-41d4-a716-446655440010';

beforeEach(function (): void {
    config()->set('denagate_health.node_tcp_targets', '');
    config()->set('cache.default', 'array');
    config()->set('services.remnawave.url', 'https://tcp02.test');
    config()->set('services.remnawave.token', 'test-only-token');
    Http::preventStrayRequests();
    Http::fake();
});

it('is disabled by default and never performs network work from the scheduler command', function (): void {
    expect(app(NodeTcpProbe::class)->targets())->toBe([])
        ->and(app(NodeTcpProbe::class)->run())->toBe(0);

    expect(Artisan::call('network:tcp-probe'))->toBe(0);
    Http::assertNothingSent();
});

it('only accepts bounded public literal IPv4 targets tied to a unique Node UUID', function (): void {
    config()->set('denagate_health.node_tcp_targets', TCP02_NODE.'@1.1.1.1:443');
    expect(app(NodeTcpProbe::class)->targets())->toBe([
        TCP02_NODE => ['ip' => '1.1.1.1', 'port' => 443],
    ]);
});

it('rejects invalid and unsafe endpoint lists atomically', function (string $value): void {
    config()->set('denagate_health.node_tcp_targets', $value);
    expect(fn () => app(NodeTcpProbe::class)->targets())->toThrow(InvalidArgumentException::class);
    expect(app(NodeTcpProbe::class)->run())->toBe(0);
    Http::assertNothingSent();
})->with([
    TCP02_NODE.'@127.0.0.1:443',
    TCP02_NODE.'@10.0.0.1:443',
    TCP02_NODE.'@169.254.169.254:80',
    TCP02_NODE.'@192.168.1.8:443',
    TCP02_NODE.'@0.0.0.0:443',
    TCP02_NODE.'@224.0.0.1:443',
    TCP02_NODE.'@example.com:443',
    TCP02_NODE.'@1.1.1.1:0',
    TCP02_NODE.'@1.1.1.1:65536',
    TCP02_NODE.'@1.1.1.1:443,'.TCP02_NODE.'@8.8.8.8:8443',
    'invalid-id@1.1.1.1:443',
    implode(',', array_fill(0, 11, TCP02_NODE.'@1.1.1.1:443')),
]);

it('never probes an operator-configured target that is absent from current provider inventory', function (): void {
    config()->set('denagate_health.node_tcp_targets', TCP02_NODE.'@1.1.1.1:443');
    Http::fake(['https://tcp02.test/api/nodes' => Http::response(['response' => []])]);

    expect(app(NodeTcpProbe::class)->run())->toBe(0);
    Http::assertSentCount(1);
    Http::assertSent(static fn ($request): bool => $request->method() === 'GET');
    expect(Cache::get('denagate:node-tcp:v1:'.TCP02_NODE))->toBeNull();
});

it('separates cached success, failure, stale, and changed-endpoint states', function (): void {
    config()->set('denagate_health.node_tcp_targets', TCP02_NODE.'@1.1.1.1:443');
    $probe = app(NodeTcpProbe::class);
    $key = 'denagate:node-tcp:v1:'.TCP02_NODE;
    $endpoint = hash('sha256', '1.1.1.1:443');

    expect($probe->latest(TCP02_NODE)['status'])->toBe('unknown');

    Cache::put($key, [
        'endpoint' => $endpoint, 'status' => 'ok', 'ms' => 28.4,
        'checkedAt' => now()->timestamp,
    ], 300);
    expect($probe->latest(TCP02_NODE))->toMatchArray(['status' => 'ok', 'ms' => 28.4]);

    Cache::put($key, [
        'endpoint' => $endpoint, 'status' => 'failed', 'ms' => null,
        'checkedAt' => now()->timestamp,
    ], 300);
    expect($probe->latest(TCP02_NODE))->toMatchArray(['status' => 'failed', 'ms' => null]);

    Cache::put($key, [
        'endpoint' => $endpoint, 'status' => 'ok', 'ms' => 28.4,
        'checkedAt' => now()->timestamp - 181,
    ], 300);
    expect($probe->latest(TCP02_NODE)['status'])->toBe('unknown');

    Cache::put($key, [
        'endpoint' => $endpoint, 'status' => 'ok', 'ms' => 28.4,
        'checkedAt' => now()->timestamp,
    ], 300);
    config()->set('denagate_health.node_tcp_targets', TCP02_NODE.'@8.8.8.8:443');
    expect($probe->latest(TCP02_NODE)['status'])->toBe('unknown');
    Http::assertNothingSent();
});

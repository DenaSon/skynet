<?php

declare(strict_types=1);

use App\Support\Health\NodeTcpProbe;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const TCP02_NODE = '550e8400-e29b-41d4-a716-446655440010';
const TCP02_SECOND = '550e8400-e29b-41d4-a716-446655440011';
const TCP02_INBOUND = '550e8400-e29b-41d4-a716-446655440012';

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    config()->set('services.remnawave.url', 'https://tcp02.test');
    config()->set('services.remnawave.token', 'test-only-token');
    Http::preventStrayRequests();
});

/** @param list<array<string,mixed>> $nodes
 * @param  list<array<string,mixed>>  $hosts
 */
function tcp02Fake(array $nodes = [], array $hosts = []): void
{
    Http::fake([
        'https://tcp02.test/api/nodes' => Http::response(['response' => $nodes]),
        'https://tcp02.test/api/hosts' => Http::response(['response' => $hosts]),
    ]);
}

function tcp02Node(string $uuid = TCP02_NODE, string $ip = '1.1.1.1', bool $disabled = false): array
{
    return [
        'uuid' => $uuid, 'name' => 'Test Node', 'address' => $ip, 'port' => 2222,
        'isDisabled' => $disabled, 'isConnected' => true,
        'configProfile' => ['activeInbounds' => [TCP02_INBOUND]],
    ];
}

function tcp02Host(string $address = '1.1.1.1', int $port = 443, bool $disabled = false): array
{
    return [
        'address' => $address, 'port' => $port, 'isDisabled' => $disabled,
        'inbound' => ['configProfileInboundUuid' => TCP02_INBOUND],
    ];
}

it('discovers UUID, current public IPv4 and direct service port live from RW only', function (): void {
    tcp02Fake(
        [tcp02Node(), tcp02Node(TCP02_SECOND, '8.8.8.8')],
        [tcp02Host('1.1.1.1', 1234), tcp02Host('1.1.1.1', 443), tcp02Host('8.8.8.8', 1234)],
    );

    expect(app(NodeTcpProbe::class)->targets())->toBe([
        TCP02_NODE => ['ip' => '1.1.1.1', 'port' => 443],
        TCP02_SECOND => ['ip' => '8.8.8.8', 'port' => 1234],
    ]);
    Http::assertSentCount(2);
    Http::assertSent(static fn ($r): bool => $r->method() === 'GET');
});

it('follows a changed RW Node and matching Host IP without any ENV edit', function (): void {
    tcp02Fake([tcp02Node(ip: '8.8.8.8')], [tcp02Host(address: '8.8.8.8', port: 1234)]);

    expect(app(NodeTcpProbe::class)->targets()[TCP02_NODE])->toBe(['ip' => '8.8.8.8', 'port' => 1234]);
});

it('never uses the RW management port for client TCP measurements', function (): void {
    tcp02Fake([tcp02Node()], [tcp02Host('1.1.1.1', 2222)]);
    expect(app(NodeTcpProbe::class)->targets())->toBe([]);
});

it('does not guess a target from a CDN host, missing inbound or disabled Node', function (): void {
    tcp02Fake([tcp02Node()], [tcp02Host('8.8.8.8', 8443)]);
    expect(app(NodeTcpProbe::class)->targets())->toBe([]);

    tcp02Fake([tcp02Node()], [['address' => '1.1.1.1', 'port' => 443, 'isDisabled' => false]]);
    expect(app(NodeTcpProbe::class)->targets())->toBe([]);

    tcp02Fake([tcp02Node(disabled: true)], [tcp02Host()]);
    expect(app(NodeTcpProbe::class)->targets())->toBe([]);

    tcp02Fake([tcp02Node()], [tcp02Host(disabled: true)]);
    expect(app(NodeTcpProbe::class)->targets())->toBe([]);
});

it('rejects private, reserved, shared and non-public RW Node addresses', function (string $ip): void {
    tcp02Fake([tcp02Node(ip: $ip)], [tcp02Host(address: $ip)]);

    expect(app(NodeTcpProbe::class)->targets())->toBe([]);
})->with(['127.0.0.1', '10.0.0.1', '192.168.1.1', '169.254.169.254',
    '100.64.0.1', '198.18.0.1', '192.0.2.1', '203.0.113.1', '224.0.0.1']);

it('never opens a TCP socket when no direct RW Host matches the live Node', function (): void {
    tcp02Fake([tcp02Node()], [tcp02Host('8.8.8.8', 443)]);
    expect(app(NodeTcpProbe::class)->run())->toBe(0);
    expect(Artisan::call('network:tcp-probe'))->toBe(0);
    Http::assertSentCount(4);
    Http::assertSent(static fn ($r): bool => $r->method() === 'GET');
});

it('does not infer targets on a failed provider API call', function (): void {
    Http::fake(['https://tcp02.test/*' => Http::response(['error' => 'unavailable'], 503)]);
    expect(app(NodeTcpProbe::class)->run())->toBe(0);
});

it('reads cached success, failure and stale samples without any API or DNS call', function (): void {
    Http::fake();
    $probe = app(NodeTcpProbe::class);
    $key = 'denagate:node-tcp:v1:'.TCP02_NODE;
    $endpoint = hash('sha256', '1.1.1.1:443');

    expect($probe->latest(TCP02_NODE)['status'])->toBe('unknown');
    Cache::put($key, ['endpoint' => $endpoint, 'status' => 'ok', 'ms' => 28.4,
        'checkedAt' => now()->timestamp], 300);
    expect($probe->latest(TCP02_NODE))->toMatchArray(['status' => 'ok', 'ms' => 28.4]);

    Cache::put($key, ['endpoint' => $endpoint, 'status' => 'failed', 'ms' => null,
        'checkedAt' => now()->timestamp], 300);
    expect($probe->latest(TCP02_NODE))->toMatchArray(['status' => 'failed', 'ms' => null]);

    Cache::put($key, ['endpoint' => $endpoint, 'status' => 'ok', 'ms' => 28.4,
        'checkedAt' => now()->timestamp - 181], 300);
    expect($probe->latest(TCP02_NODE)['status'])->toBe('unknown');
    Http::assertNothingSent();
});

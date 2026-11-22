<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ReadCustomerServiceDiagnostics;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['975001', '975002']);
    config()->set('services.remnawave.url', 'https://rw-diag.test');
    config()->set('services.remnawave.token', 'diagnostic-private-token');
    Http::preventStrayRequests();
});

function supDiagRoot(): User
{
    $actor = User::factory()->create();
    $actor->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $actor->id, 'telegram_user_id' => 975001]);

    return $actor;
}

/** @return array{0:User,1:int,2:array<string,mixed>} */
function supDiagOrder(): array
{
    $buyer = User::factory()->create();
    $id = DB::table('sales_orders')->insertGetId([
        'buyer_user_id' => $buyer->id,
        'idempotency_key' => 'sup-diag-'.$buyer->id,
        'product_slug' => 'sup-diag',
        'product_name' => 'Diagnostic test',
        'duration_days' => 30, 'traffic_gb' => 20,
        'public_price_rials' => 1000000, 'discount_percent' => 0,
        'discount_rials' => 0, 'total_price_rials' => 1000000,
        'status' => 'fulfilled', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $reference = 'sup-diag-'.$id;
    $remote = [
        'id' => 42,
        'username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
        'shortUuid' => 'private-short-uuid',
        'status' => 'ACTIVE', 'expireAt' => now()->addDays(8)->utc()->toIso8601String(),
        'trafficLimitBytes' => 20 * 1073741824,
        'trafficLimitStrategy' => 'NO_RESET',
        'subscriptionUrl' => 'https://private.test/some-secret',
        'description' => 'SkyNetwork ref:'.hash('sha256', $reference),
        'activeInternalSquads' => [],
        'userTraffic' => ['usedTrafficBytes' => 1024],
    ];
    $sub = DB::table('provisioning_subscriptions')->insertGetId([
        'reference' => $reference,
        'remote_username' => $remote['username'],
        'remote_user_id' => 42, 'state' => 'active',
        'last_operation_key' => 'sup-diag-'.$id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('provisioning_order_links')->insert([
        'sales_order_id' => $id, 'provisioning_subscription_id' => $sub, 'created_at' => now(),
    ]);

    return [$buyer, $id, $remote];
}

it('reads only validated user-specific daily usage without leaking provider records', function (): void {
    $root = supDiagRoot();
    [$buyer, $order, $remote] = supDiagOrder();
    Http::fake(static function (Request $request) use ($remote) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/api/users/42' => Http::response(['response' => $remote]),
            '/api/bandwidth-stats/users/42' => Http::response(['response' => [
                'categories' => ['2026-11-21', '2026-11-22'],
                'sparklineData' => [1024, 2048], 'series' => [],
                'topNodes' => [[
                    'uuid' => '550e8400-e29b-41d4-a716-446655440000',
                    'name' => 'UAE-01', 'total' => 3072,
                    'countryCode' => 'AE', 'color' => '#00a',
                ]],
            ]]),
            default => Http::response(['error' => 'invalid'], 404),
        };
    });
    $result = app(ReadCustomerServiceDiagnostics::class)->usage($root->id, $buyer->id, $order, 7);

    expect($result)->toMatchArray(['state' => 'available', 'days' => 7, 'totalBytes' => 3072])
        ->and($result['nodes'])->toBe([['name' => 'UAE-01', 'bytes' => 3072]])
        ->and(json_encode($result))->not->toContain('private-short-uuid', 'some-secret', 'diagnostic-private-token');
    Http::assertSentCount(2);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), '/api/bandwidth-stats/users/42?')
        && str_contains($request->url(), 'topNodesLimit=5'));
});

it('returns only safe history fields, never IP addresses or user agents', function (): void {
    $root = supDiagRoot();
    [$buyer, $order, $remote] = supDiagOrder();
    Http::fake(static function (Request $request) use ($remote) {
        return parse_url($request->url(), PHP_URL_PATH) === '/api/users/42'
            ? Http::response(['response' => $remote])
            : Http::response(['response' => [
                'total' => 1,
                'records' => [[
                    'id' => 77, 'userId' => 42,
                    'requestAt' => '2026-11-22T09:20:00Z',
                    'srrResponseType' => 'XRAY_BASE64',
                    'requestIp' => '203.0.113.3', 'userAgent' => 'secret-device',
                ]],
            ]]);
    });

    $result = app(ReadCustomerServiceDiagnostics::class)->history($root->id, $buyer->id, $order);
    expect($result['state'])->toBe('available')
        ->and($result['records'])->toBe([['at' => '2026-11-22T09:20:00+00:00', 'type' => 'XRAY_BASE64']])
        ->and(json_encode($result))->not->toContain('203.0.113.3', 'secret-device');
    Http::assertSentCount(2);
});

it('links accessible nodes to existing monitoring evidence without guessing health', function (): void {
    $root = supDiagRoot();
    [$buyer, $order, $remote] = supDiagOrder();
    Http::fake(static function (Request $request) use ($remote) {
        return parse_url($request->url(), PHP_URL_PATH) === '/api/users/42'
            ? Http::response(['response' => $remote])
            : Http::response(['response' => [
                'userId' => 42,
                'activeNodes' => [[
                    'uuid' => '550e8400-e29b-41d4-a716-446655440000',
                    'nodeName' => 'UAE-01', 'countryCode' => 'AE',
                ]],
            ]]);
    });

    $result = app(ReadCustomerServiceDiagnostics::class)->nodes($root->id, $buyer->id, $order);
    expect($result['state'])->toBe('available')
        ->and($result['nodes'][0])->toMatchArray([
            'name' => 'UAE-01', 'state' => 'unknown', 'regional' => 'unknown',
        ]);
    Http::assertSentCount(2);
});

it('refuses ordinary support operators and cross-customer orders before any provider request', function (): void {
    $root = supDiagRoot();
    [$buyer, $order] = supDiagOrder();
    $ordinary = User::factory()->admin()->create();
    $other = User::factory()->create();
    Http::fake();

    expect(fn () => app(ReadCustomerServiceDiagnostics::class)->usage($ordinary->id, $buyer->id, $order, 7))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ReadCustomerServiceDiagnostics::class)->history($root->id, $other->id, $order))
        ->toThrow(ValidationException::class);
    expect(fn () => app(ReadCustomerServiceDiagnostics::class)->usage($root->id, $buyer->id, $order, 90))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('checks subscription ownership again after the provider analytics call', function (): void {
    $root = supDiagRoot();
    [$buyer, $order, $remote] = supDiagOrder();
    $other = User::factory()->create();
    Http::fake(static function (Request $request) use ($remote, $order, $other) {
        if (parse_url($request->url(), PHP_URL_PATH) === '/api/users/42') {
            return Http::response(['response' => $remote]);
        }
        DB::table('sales_orders')->where('id', $order)->update(['buyer_user_id' => $other->id]);

        return Http::response(['response' => [
            'categories' => [], 'sparklineData' => [], 'topNodes' => [], 'series' => [],
        ]]);
    });

    expect(fn () => app(ReadCustomerServiceDiagnostics::class)->usage($root->id, $buyer->id, $order, 1))
        ->toThrow(AuthorizationException::class);
    Http::assertSentCount(2);
});

it('fails safely when a provider analytics response is malformed', function (): void {
    $root = supDiagRoot();
    [$buyer, $order, $remote] = supDiagOrder();
    Http::fake(static function (Request $request) use ($remote) {
        return parse_url($request->url(), PHP_URL_PATH) === '/api/users/42'
            ? Http::response(['response' => $remote])
            : Http::response(['response' => [
                'categories' => [now('UTC')->toDateString()],
                'sparklineData' => [-100],
                'topNodes' => [], 'series' => [],
            ]]);
    });
    $result = app(ReadCustomerServiceDiagnostics::class)->usage($root->id, $buyer->id, $order, 1);

    expect($result['state'])->toBe('unavailable')
        ->and($result['totalBytes'])->toBeNull();
    Http::assertSentCount(2);
});

it('keeps connection diagnostics disabled without any provider call', function (): void {
    Cache::flush();
    $root = supDiagRoot();
    [$buyer, $order] = supDiagOrder();
    Http::fake();

    expect(app(ReadCustomerServiceDiagnostics::class)->startConnections($root->id, $buyer->id, $order)['state'])
        ->toBe('disabled');
    expect(app(ReadCustomerServiceDiagnostics::class)->connectionResult($root->id, $buyer->id, $order)['state'])
        ->toBe('disabled');
    Http::assertNothingSent();
});

it('deduplicates explicitly started provider jobs and returns only anonymous node counts', function (): void {
    Cache::flush();
    config()->set('services.remnawave.connections_diagnostics_enabled', true);
    $root = supDiagRoot();
    [$buyer, $order, $remote] = supDiagOrder();
    Http::fake(static function (Request $request) use ($remote) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match ($path) {
            '/api/users/42' => Http::response(['response' => $remote]),
            '/api/connections/by-user/42' => Http::response(['response' => ['jobId' => 'job-unique-42']], 201),
            '/api/connections/by-user/job-unique-42' => Http::response(['response' => [
                'isCompleted' => true, 'isFailed' => false,
                'progress' => ['total' => 1, 'completed' => 1, 'percent' => 100],
                'result' => [
                    'success' => true, 'userId' => 42,
                    'nodes' => [[
                        'nodeUuid' => '550e8400-e29b-41d4-a716-446655440000',
                        'nodeName' => 'UAE-01', 'countryCode' => 'AE',
                        'ips' => [
                            ['ip' => '203.0.113.42', 'lastSeen' => '2026-11-22T14:45:00+03:30'],
                            ['ip' => '198.51.100.20', 'lastSeen' => '2026-11-22T10:45:00Z'],
                        ],
                    ]],
                ],
            ]]),
            default => Http::response(['message' => 'invalid'], 404),
        };
    });

    $action = app(ReadCustomerServiceDiagnostics::class);
    expect($action->startConnections($root->id, $buyer->id, $order)['state'])->toBe('pending')
        ->and($action->startConnections($root->id, $buyer->id, $order)['state'])->toBe('pending');
    $result = $action->connectionResult($root->id, $buyer->id, $order);
    expect($result)->toBe(['state' => 'completed', 'nodes' => [
        ['name' => 'UAE-01', 'reports' => 2, 'lastSeenAt' => '2026-11-22T11:15:00Z', 'country' => 'AE',
            'ips' => [
                ['ip' => '203.0.113.42', 'lastSeenAt' => '2026-11-22T11:15:00Z'],
                ['ip' => '198.51.100.20', 'lastSeenAt' => '2026-11-22T10:45:00Z'],
            ],
        ],
    ]])->and(json_encode($result))->not->toContain('job-unique-42', 'diagnostic-private-token');
    Http::assertSentCount(5);
    expect(collect(Http::recorded())->filter(static fn (array $pair): bool => $pair[0]->method() === 'POST')->count())->toBe(1);
});

it('keeps empty connection observations unknown rather than inventing a last-seen time', function (): void {
    Cache::flush();
    config()->set('services.remnawave.connections_diagnostics_enabled', true);
    $root = supDiagRoot();
    [$buyer, $order, $remote] = supDiagOrder();
    Http::fake(static function (Request $request) use ($remote) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/api/users/42' => Http::response(['response' => $remote]),
            '/api/connections/by-user/42' => Http::response(['response' => ['jobId' => 'job-empty']], 201),
            '/api/connections/by-user/job-empty' => Http::response(['response' => [
                'isCompleted' => true, 'isFailed' => false,
                'result' => ['success' => true, 'userId' => 42, 'nodes' => [
                    ['nodeName' => 'German2', 'ips' => []],
                ]],
            ]]),
            default => Http::response([], 404),
        };
    });

    $action = app(ReadCustomerServiceDiagnostics::class);
    $action->startConnections($root->id, $buyer->id, $order);
    $result = $action->connectionResult($root->id, $buyer->id, $order);

    expect($result)->toBe(['state' => 'completed', 'nodes' => [
        ['name' => 'German2', 'reports' => 0, 'lastSeenAt' => null, 'country' => null, 'ips' => []],
    ]]);
    expect($action->connectionResult($root->id, $buyer->id, $order))->toBe($result);
    Http::assertSentCount(5);
});

it('rejects invalid provider observation timestamps instead of displaying a misleading date', function (string $lastSeen): void {
    Cache::flush();
    config()->set('services.remnawave.connections_diagnostics_enabled', true);
    $root = supDiagRoot();
    [$buyer, $order, $remote] = supDiagOrder();
    Http::fake(static function (Request $request) use ($remote, $lastSeen) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/api/users/42' => Http::response(['response' => $remote]),
            '/api/connections/by-user/42' => Http::response(['response' => ['jobId' => 'job-malformed']], 201),
            '/api/connections/by-user/job-malformed' => Http::response(['response' => [
                'isCompleted' => true, 'isFailed' => false,
                'result' => ['success' => true, 'userId' => 42, 'nodes' => [
                    ['nodeName' => 'German2', 'ips' => [
                        ['ip' => '203.0.113.2', 'lastSeen' => $lastSeen],
                    ]],
                ]],
            ]]),
            default => Http::response([], 404),
        };
    });

    $action = app(ReadCustomerServiceDiagnostics::class);
    $action->startConnections($root->id, $buyer->id, $order);
    $result = $action->connectionResult($root->id, $buyer->id, $order);

    expect($result)->toBe(['state' => 'unavailable', 'nodes' => []])
        ->and(json_encode($result))->not->toContain('203.0.113.2', $lastSeen);
})->with(['invalid', '2026-02-31T11:00:00Z', '2026-11-22T11:00:00']);

it('exposes at most ten recent validated IPs per node without exposing raw job metadata', function (): void {
    Cache::flush();
    config()->set('services.remnawave.connections_diagnostics_enabled', true);
    $root = supDiagRoot();
    [$buyer, $order, $remote] = supDiagOrder();
    $observations = [];
    for ($i = 0; $i < 12; $i++) {
        $observations[] = [
            'ip' => $i === 0 ? '2001:db8::1' : '198.51.100.'.($i + 10),
            'lastSeen' => now('UTC')->subMinutes($i)->toIso8601String(),
        ];
    }
    Http::fake(static function (Request $request) use ($remote, $observations) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/api/users/42' => Http::response(['response' => $remote]),
            '/api/connections/by-user/42' => Http::response(['response' => ['jobId' => 'job-ip-list']], 201),
            '/api/connections/by-user/job-ip-list' => Http::response(['response' => [
                'isCompleted' => true, 'isFailed' => false,
                'result' => ['success' => true, 'userId' => 42, 'nodes' => [
                    ['nodeName' => 'German2', 'countryCode' => 'DE', 'ips' => array_reverse($observations)],
                ]],
            ]]),
            default => Http::response([], 404),
        };
    });

    $action = app(ReadCustomerServiceDiagnostics::class);
    $action->startConnections($root->id, $buyer->id, $order);
    $result = $action->connectionResult($root->id, $buyer->id, $order);

    expect($result['state'])->toBe('completed')
        ->and($result['nodes'][0]['country'])->toBe('DE')
        ->and($result['nodes'][0]['reports'])->toBe(12)
        ->and($result['nodes'][0]['ips'])->toHaveCount(10)
        ->and($result['nodes'][0]['ips'][0]['ip'])->toBe('2001:db8::1')
        ->and(json_encode($result))->not->toContain('job-ip-list', 'diagnostic-private-token');
    expect($action->connectionResult($root->id, $buyer->id, $order))->toBe($result);
    expect(collect(Http::recorded())->filter(static fn (array $pair): bool => $pair[0]->method() === 'POST')->count())
        ->toBe(1);
});

it('rejects invalid IPs in a connection result instead of displaying untrusted provider values', function (string $ip): void {
    Cache::flush();
    config()->set('services.remnawave.connections_diagnostics_enabled', true);
    $root = supDiagRoot();
    [$buyer, $order, $remote] = supDiagOrder();
    Http::fake(static function (Request $request) use ($remote, $ip) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/api/users/42' => Http::response(['response' => $remote]),
            '/api/connections/by-user/42' => Http::response(['response' => ['jobId' => 'job-invalid-ip']], 201),
            '/api/connections/by-user/job-invalid-ip' => Http::response(['response' => [
                'isCompleted' => true, 'isFailed' => false,
                'result' => ['success' => true, 'userId' => 42, 'nodes' => [
                    ['nodeName' => 'German2', 'countryCode' => 'DE', 'ips' => [
                        ['ip' => $ip, 'lastSeen' => '2026-11-22T11:00:00Z'],
                    ]],
                ]],
            ]]),
            default => Http::response([], 404),
        };
    });
    $action = app(ReadCustomerServiceDiagnostics::class);
    $action->startConnections($root->id, $buyer->id, $order);
    $result = $action->connectionResult($root->id, $buyer->id, $order);
    expect($result)->toBe(['state' => 'unavailable', 'nodes' => []]);
})->with(['999.999.999.999', '<script>alert(1)</script>', '127.0.0.1'.PHP_EOL.'bad']);

it('does not replay an ambiguous provider job request', function (): void {
    Cache::flush();
    config()->set('services.remnawave.connections_diagnostics_enabled', true);
    $root = supDiagRoot();
    [$buyer, $order, $remote] = supDiagOrder();
    Http::fake(static function (Request $request) use ($remote) {
        return parse_url($request->url(), PHP_URL_PATH) === '/api/users/42'
            ? Http::response(['response' => $remote])
            : Http::response(['message' => 'uncertain'], 503);
    });

    $action = app(ReadCustomerServiceDiagnostics::class);
    expect($action->startConnections($root->id, $buyer->id, $order)['state'])->toBe('unavailable')
        ->and($action->startConnections($root->id, $buyer->id, $order)['state'])->toBe('unavailable')
        ->and($action->connectionResult($root->id, $buyer->id, $order)['state'])->toBe('unavailable');
    expect(collect(Http::recorded())->filter(static fn (array $pair): bool => $pair[0]->method() === 'POST')->count())->toBe(1);
});

it('rejects a completed connection report belonging to a different remote user', function (): void {
    Cache::flush();
    config()->set('services.remnawave.connections_diagnostics_enabled', true);
    $root = supDiagRoot();
    [$buyer, $order, $remote] = supDiagOrder();
    Http::fake(static function (Request $request) use ($remote) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/api/users/42' => Http::response(['response' => $remote]),
            '/api/connections/by-user/42' => Http::response(['response' => ['jobId' => 'job-user-42']], 201),
            '/api/connections/by-user/job-user-42' => Http::response(['response' => [
                'isCompleted' => true, 'isFailed' => false, 'progress' => [
                    'total' => 1, 'completed' => 1, 'percent' => 100,
                ], 'result' => ['success' => true, 'userId' => 99, 'nodes' => []],
            ]]),
            default => Http::response([], 404),
        };
    });

    $action = app(ReadCustomerServiceDiagnostics::class);
    $action->startConnections($root->id, $buyer->id, $order);
    expect($action->connectionResult($root->id, $buyer->id, $order)['state'])->toBe('unavailable');
    expect(collect(Http::recorded())->every(static fn (array $pair): bool => $pair[0]->method() === 'GET' || $pair[0]->url() === 'https://rw-diag.test/api/connections/by-user/42'))->toBeTrue();
});

<?php

declare(strict_types=1);

use App\Support\Network\CloudflareRadarClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    Cache::flush();
    config()->set('services.cloudflare_radar.token', 'radar-test-token');
    config()->set('services.cloudflare_radar.cache_seconds', 600);
    Http::preventStrayRequests();
});

/** @return array<string, mixed> */
function radarSeries(): array
{
    return [
        'meta' => ['normalization' => 'MIN0_MAX', 'lastUpdated' => '2026-11-15T09:00:00Z'],
        'serie_0' => [
            'timestamps' => ['2026-11-15T08:00:00Z'],
            'values' => ['73'],
        ],
    ];
}

/** @return array<string, mixed> */
function radarQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

it('reads and caches country traffic with Radar token and preserves metadata', function (): void {
    config()->set('services.cloudflare.token', 'dns-write-token');
    Http::fake([
        'https://api.cloudflare.com/client/v4/radar/netflows/timeseries*' => Http::response([
            'success' => true, 'result' => radarSeries(),
        ]),
    ]);

    $client = app(CloudflareRadarClient::class);
    expect($client->trafficSeries()['meta']['normalization'])->toBe('MIN0_MAX')
        ->and($client->trafficSeries()['serie_0']['values'])->toBe(['73']);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $query = radarQuery($request);

        return $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer radar-test-token')
            && ! $request->hasHeader('Authorization', 'Bearer dns-write-token')
            && ($query['location'] ?? null) === 'IR'
            && ($query['dateRange'] ?? null) === '1d'
            && ! isset($query['asn']);
    });
});

it('supports only the three named Iranian mobile ASNs and allowed time windows', function (): void {
    Http::fake([
        'https://api.cloudflare.com/*' => Http::response(['success' => true, 'result' => radarSeries()]),
    ]);

    $client = app(CloudflareRadarClient::class);
    foreach ([197207, 44244, 57218] as $asn) {
        $client->trafficSeries($asn, '30d');
    }

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => (radarQuery($request)['asn'] ?? null) === '197207'
        && (radarQuery($request)['dateRange'] ?? null) === '30d'
        && ! isset(radarQuery($request)['location']));

    expect(fn () => $client->trafficSeries(64500))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $client->trafficSeries(null, '400d'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $client->qualitySummary('UPTIME'))->toThrow(InvalidArgumentException::class);
    Http::assertSentCount(3);
});

it('reads bounded outage and anomaly events without inventing statuses', function (): void {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/annotations/outages')) {
            return Http::response(['success' => true, 'result' => [
                'annotations' => [['id' => '1', 'eventType' => 'OUTAGE']],
            ]]);
        }

        return Http::response(['success' => true, 'result' => [
            'trafficAnomalies' => [['uuid' => 'u1', 'status' => 'UNVERIFIED']],
        ]]);
    });

    $client = app(CloudflareRadarClient::class);
    expect($client->outages(197207)['annotations'][0]['eventType'])->toBe('OUTAGE')
        ->and($client->anomalies()['trafficAnomalies'][0]['status'])->toBe('UNVERIFIED');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/annotations/outages')
        && (radarQuery($request)['asn'] ?? null) === '197207'
        && (radarQuery($request)['limit'] ?? null) === '25');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/traffic_anomalies')
        && (radarQuery($request)['location'] ?? null) === 'IR'
        && (radarQuery($request)['dateRange'] ?? null) === '7d');
});

it('reads IQI percentiles by metric and retains their units and freshness', function (): void {
    Http::fake([
        'https://api.cloudflare.com/*' => Http::response([
            'success' => true,
            'result' => [
                'meta' => ['units' => [['name' => 'LATENCY', 'value' => 'ms']], 'lastUpdated' => '2026-11-15T08:00:00Z'],
                'summary_0' => ['p25' => '50', 'p50' => '100', 'p75' => '200'],
            ],
        ]),
    ]);

    $result = app(CloudflareRadarClient::class)->qualitySummary('LATENCY', 44244);
    expect($result['summary_0']['p50'])->toBe('100')
        ->and($result['meta']['units'][0]['value'])->toBe('ms');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/quality/iqi/summary')
        && (radarQuery($request)['metric'] ?? null) === 'LATENCY'
        && (radarQuery($request)['asn'] ?? null) === '44244');
});

it('fails closed without a dedicated token even when DNS credentials exist', function (): void {
    config()->set('services.cloudflare_radar.token', '');
    config()->set('services.cloudflare.token', 'dns-token');
    Http::fake();

    expect(fn () => app(CloudflareRadarClient::class)->trafficSeries())
        ->toThrow(RuntimeException::class, 'not configured');

    Http::assertNothingSent();
});

it('applies a shared cooldown to rate limits and does not repeatedly call upstream', function (): void {
    Http::fake(['https://api.cloudflare.com/*' => Http::response(['success' => false], 429)]);

    $client = app(CloudflareRadarClient::class);
    expect(fn () => $client->trafficSeries())->toThrow(RuntimeException::class, 'rate-limited')
        ->and(fn () => $client->outages())->toThrow(RuntimeException::class, 'rate-limited');
    Http::assertSentCount(1);
});

it('rejects malformed, failed, oversized and redirected replies without caching them', function (array $body, int $status): void {
    Http::fake(['https://api.cloudflare.com/*' => Http::response($body, $status)]);
    $client = app(CloudflareRadarClient::class);

    expect(fn () => $client->trafficSeries())->toThrow(RuntimeException::class);
    expect(fn () => $client->trafficSeries())->toThrow(RuntimeException::class);
    Http::assertSentCount(2);
})->with([
    'api error' => [['success' => false, 'result' => radarSeries()], 200],
    'missing result' => [['success' => true], 200],
    'wrong shape' => [['success' => true, 'result' => ['annotations' => []]], 200],
    'server failure' => [['success' => false], 503],
    'redirect' => [[], 302],
    'oversized body' => [['success' => true, 'result' => ['padding' => str_repeat('a', 530000)]], 200],
]);

it('rejects oversized event lists and mismatched series samples', function (): void {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/netflows/')) {
            return Http::response(['success' => true, 'result' => [
                'meta' => [], 'serie_0' => ['timestamps' => ['a'], 'values' => []],
            ]]);
        }

        return Http::response(['success' => true, 'result' => [
            'annotations' => array_fill(0, 26, ['id' => 'x']),
        ]]);
    });

    $client = app(CloudflareRadarClient::class);
    expect(fn () => $client->trafficSeries())->toThrow(RuntimeException::class)
        ->and(fn () => $client->outages())->toThrow(RuntimeException::class);
    Http::assertSentCount(2);
});

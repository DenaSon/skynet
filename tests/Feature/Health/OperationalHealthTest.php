<?php

declare(strict_types=1);

use App\Support\Health\DenaGateOperationalCheck;
use App\Support\Health\OperationalProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('registers nine named and independent health checks without duplicates', function (): void {
    $names = [];
    foreach (OperationalProbe::CHECKS as $name) {
        $check = DenaGateOperationalCheck::makeFor($name);
        $names[] = $check->getName();
    }

    expect($names)->toHaveCount(9)
        ->and(array_unique($names))->toHaveCount(9)
        ->and($names)->toContain('ops_cpu', 'ops_memory', 'ops_network_api');
});

it('probes existing network API via a read-only mapped health endpoint', function (): void {
    config()->set('services.remnawave.url', 'https://health-api.example');
    config()->set('services.remnawave.token', 'fake-private-key');
    config()->set('services.remnawave.timeout', 3);
    Http::fake(['https://health-api.example/*' => Http::response([
        'response' => ['runtimeMetrics' => [['status' => 'HEALTHY']]],
    ])]);

    $state = app(OperationalProbe::class)->inspect('network_api');
    expect($state['status'])->toBe('ok')
        ->and($state['summary'])->toBe('1 instances');

    Http::assertSentCount(1);
    Http::assertSent(static fn ($request): bool => $request->method() === 'GET'
        && str_ends_with($request->url(), '/api/system/health'));
});

it('probes both enabled Telegram bots but never saves their tokens or response bodies', function (): void {
    config()->set('nutgram.token', 'admin-secret-fixture');
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram.sales.token', 'sales-secret-fixture');
    Http::fake(['https://api.telegram.org/*' => Http::response([
        'ok' => true, 'result' => ['id' => 42, 'is_bot' => true],
    ])]);

    $state = app(OperationalProbe::class)->inspect('telegram_api');
    expect($state['status'])->toBe('ok')
        ->and($state['summary'])->toContain('2 bot');

    Http::assertSentCount(2);
    Http::assertSent(static fn ($request): bool => $request->method() === 'GET');
});

it('measures queue backlog and recent failed jobs without modifying jobs', function (): void {
    config()->set('queue.default', 'database');
    DB::table('jobs')->insert([
        'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
        'reserved_at' => null, 'available_at' => time(), 'created_at' => time(),
    ]);
    $before = DB::table('jobs')->count();
    $queue = app(OperationalProbe::class)->inspect('queue_backlog');

    expect($queue['status'])->toBe('ok')
        ->and($queue['summary'])->toBe('1 pending')
        ->and(DB::table('jobs')->count())->toBe($before);

    DB::table('failed_jobs')->insert([
        'uuid' => 'e8318ea5-c6ee-42c4-a1a1-20052f4d507a',
        'connection' => 'database', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'fixture', 'failed_at' => now(),
    ]);
    $failed = app(OperationalProbe::class)->inspect('failed_jobs');
    expect($failed['status'])->toBe('warning')
        ->and($failed['summary'])->toBe('1 / 24h');
});

it('distinguishes missing, valid Redis string, stale and invalid subscription heartbeats', function (): void {
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram_notifications.enabled', true);

    $probe = app(OperationalProbe::class);
    $key = OperationalProbe::SUBSCRIPTION_HEARTBEAT;
    expect($probe->inspect('subscription_jobs')['status'])->toBe('warning');

    Cache::put($key, now()->timestamp, now()->addHour());
    expect($probe->inspect('subscription_jobs')['status'])->toBe('ok');

    // Production Redis may return a numeric string rather than a PHP integer.
    Cache::put($key, (string) now()->subMinutes(3)->timestamp, now()->addHour());
    expect($probe->inspect('subscription_jobs'))
        ->toBe(['status' => 'ok', 'summary' => '3 min ago']);

    Cache::put($key, (string) now()->subMinutes(12)->timestamp, now()->addHour());
    expect($probe->inspect('subscription_jobs')['status'])->toBe('warning');

    Cache::put($key, (string) now()->subMinutes(20)->timestamp, now()->addHour());
    expect($probe->inspect('subscription_jobs')['status'])->toBe('failed');

    foreach (['', '-1', 'not-a-time', '1793694914x', str_repeat('9', 30)] as $invalid) {
        Cache::put($key, $invalid, now()->addHour());
        expect($probe->inspect('subscription_jobs'))
            ->toBe(['status' => 'warning', 'summary' => 'no heartbeat']);
    }

    Cache::put($key, (string) now()->addMinutes(2)->timestamp, now()->addHour());
    expect($probe->inspect('subscription_jobs')['status'])->toBe('warning');
});

it('calculates container CPU share from fractional cgroup quotas and CPU time', function (): void {
    $quota = "45000 100000\n";
    $before = "usage_usec 1000000\nuser_usec 600000\n";
    $elapsedNs = 1_000_000_000;
    $pressure = "some avg10=6.90 avg60=5.00 avg300=4.00 total=200\n";

    $ok = OperationalProbe::cpuFromSamples(
        $quota, $before, "usage_usec 1090000\n", $elapsedNs, $pressure,
    );
    $warning = OperationalProbe::cpuFromSamples(
        $quota, $before, "usage_usec 1382500\n", $elapsedNs,
    );
    $failed = OperationalProbe::cpuFromSamples(
        $quota, $before, "usage_usec 1441000\n", $elapsedNs,
    );
    $fractional = OperationalProbe::cpuFromSamples(
        "43000 100000\n", $before, "usage_usec 1086000\n", $elapsedNs,
    );

    expect($ok)->toBe(['status' => 'ok', 'summary' => '20.0% / 0.45 CPU / 6.9% wait'])
        ->and($warning)->toBe(['status' => 'warning', 'summary' => '85.0% / 0.45 CPU'])
        ->and($failed)->toBe(['status' => 'failed', 'summary' => '98.0% / 0.45 CPU'])
        ->and($fractional)->toBe(['status' => 'ok', 'summary' => '20.0% / 0.43 CPU']);
});

it('reports unknown rather than host load when cgroup CPU samples are invalid', function (): void {
    $valid = "usage_usec 1000000\n";
    $later = "usage_usec 1100000\n";
    $invalid = [
        ['max 100000', $valid, $later, 1_000_000_000],
        ['45000 0', $valid, $later, 1_000_000_000],
        [str_repeat('9', 25).' 100000', $valid, $later, 1_000_000_000],
        ['45000 100000', 'missing usage', $later, 1_000_000_000],
        ['45000 100000', $later, $valid, 1_000_000_000],
        ['45000 100000', $valid, $later, 0],
    ];

    foreach ($invalid as [$quota, $before, $after, $elapsedNs]) {
        expect(OperationalProbe::cpuFromSamples($quota, $before, $after, $elapsedNs))
            ->toBe(['status' => 'warning', 'summary' => 'unavailable']);
    }
});

it('does not fabricate numeric resource percentages when metrics are unavailable', function (): void {
    $probe = app(OperationalProbe::class);
    foreach (['cpu', 'memory'] as $name) {
        $result = $probe->inspect($name);
        expect($result['status'])->toBeIn(['ok', 'warning', 'failed']);
        expect($result['summary'])->not->toContain('secret');
    }

    Http::assertNothingSent();
});

it('marks configured network API outages failed without exposing credentials', function (): void {
    config()->set('services.remnawave.url', 'https://offline-api.example');
    config()->set('services.remnawave.token', 'invisible-secret-fixture');
    config()->set('services.remnawave.timeout', 2);
    Http::fake(['https://offline-api.example/*' => Http::response(['error' => 'secret'], 503)]);

    $snapshot = app(OperationalProbe::class)->inspect('network_api');
    expect($snapshot['status'])->toBe('failed')
        ->and($snapshot['summary'])->toBe('API unreachable')
        ->and(json_encode($snapshot, JSON_THROW_ON_ERROR))->not->toContain('invisible-secret-fixture');
});

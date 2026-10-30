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
    Http::assertSent(static fn ($request): bool =>
        $request->method() === 'GET'
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

it('distinguishes absent and recent subscription job heartbeats', function (): void {
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram_notifications.enabled', true);

    $probe = app(OperationalProbe::class);
    expect($probe->inspect('subscription_jobs')['status'])->toBe('warning');

    Cache::put(OperationalProbe::SUBSCRIPTION_HEARTBEAT, now()->timestamp, now()->addHour());
    expect($probe->inspect('subscription_jobs')['status'])->toBe('ok');

    Cache::put(OperationalProbe::SUBSCRIPTION_HEARTBEAT, now()->subMinutes(20)->timestamp, now()->addHour());
    expect($probe->inspect('subscription_jobs')['status'])->toBe('failed');
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

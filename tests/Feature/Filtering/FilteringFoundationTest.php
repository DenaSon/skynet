<?php

declare(strict_types=1);

use App\Modules\Filtering\Application\FilteringFoundation;
use App\Modules\Filtering\Application\PruneFilteringHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

const FLT01_NODE = '550e8400-e29b-41d4-a716-446655440001';
const FLT01_OTHER = '550e8400-e29b-41d4-a716-446655440002';

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-07 12:00:00 UTC'));
});

it('migrates both independent tables and guards evidence on rollback', function (): void {
    expect(Schema::hasTable('filtering_checks'))->toBeTrue()
        ->and(Schema::hasTable('filtering_incidents'))->toBeTrue()
        ->and(Schema::hasColumn('filtering_checks', 'provider_observed_at'))->toBeTrue()
        ->and(Schema::hasColumn('filtering_incidents', 'active_node_uuid'))->toBeTrue();

    $migration = require database_path('migrations/2026_11_07_000001_create_filtering_monitoring_tables.php');
    $migration->down();
    expect(Schema::hasTable('filtering_checks'))->toBeFalse()
        ->and(Schema::hasTable('filtering_incidents'))->toBeFalse();
    $migration->up();

    app(FilteringFoundation::class)->record(FLT01_NODE, 'node', 'unknown', now());
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect(Schema::hasTable('filtering_checks'))->toBeTrue();
});

it('deduplicates an active composite incident and resolves only after both modules recover', function (): void {
    $foundation = app(FilteringFoundation::class);
    $base = now()->subMinutes(10);
    $first = $foundation->record(FLT01_NODE, 'node', 'disrupted', $base, [
        'causes' => ['online_zero', 'traffic_zero'], 'online_users' => 0,
        'rx_bytes_per_sec' => 0, 'tx_bytes_per_sec' => 0,
    ]);
    $foundation->record(FLT01_NODE, 'node', 'disrupted', $base, ['causes' => ['online_zero']]);
    expect(DB::table('filtering_checks')->count())->toBe(1)
        ->and(DB::table('filtering_incidents')->count())->toBe(1)
        ->and($first['state'])->toBe('disrupted');

    $foundation->record(FLT01_NODE, 'regional', 'suspicious', $base->copy()->addMinute(), [
        'causes' => ['regional_possible'], 'completed_probes' => 4, 'failed_probes' => 2,
    ]);
    expect($foundation->read(FLT01_NODE)['state'])->toBe('disrupted')
        ->and(DB::table('filtering_incidents')->count())->toBe(1);

    $foundation->record(FLT01_NODE, 'node', 'healthy', $base->copy()->addMinutes(2));
    $remaining = $foundation->read(FLT01_NODE);
    expect($remaining['state'])->toBe('suspicious')
        ->and(array_keys($remaining['affected_modules']))->toBe(['regional']);

    $foundation->record(FLT01_NODE, 'regional', 'unknown', $base->copy()->addMinutes(3));
    expect($foundation->read(FLT01_NODE)['state'])->toBe('suspicious');

    $foundation->record(FLT01_NODE, 'regional', 'healthy', $base->copy()->addMinutes(4));
    expect($foundation->read(FLT01_NODE)['incident_id'])->toBeNull()
        ->and(DB::table('filtering_incidents')->whereNotNull('resolved_at')->count())->toBe(1)
        ->and(DB::table('filtering_incidents')->whereNotNull('active_node_uuid')->count())->toBe(0);

    $foundation->record(FLT01_NODE, 'node', 'suspicious', $base->copy()->addMinutes(5), ['causes' => ['online_zero']]);
    expect(DB::table('filtering_incidents')->count())->toBe(2)
        ->and(DB::table('filtering_incidents')->whereNotNull('active_node_uuid')->count())->toBe(1);
});

it('retains unknown and latest-valid state across new service instances and ignores delayed samples', function (): void {
    $monitor = app(FilteringFoundation::class);
    expect($monitor->read(FLT01_NODE)['state'])->toBe('unknown');
    $monitor->record(FLT01_NODE, 'node', 'healthy', now()->subMinutes(2));
    $monitor->record(FLT01_NODE, 'node', 'disrupted', now()->subMinute(), ['causes' => ['online_zero']]);
    $id = $monitor->read(FLT01_NODE)['incident_id'];

    // Simulate a worker restart. SQL rows, not cache, carry the incident.
    $fresh = new FilteringFoundation;
    $fresh->record(FLT01_NODE, 'node', 'healthy', now()->subMinutes(3));
    $fresh->record(FLT01_NODE, 'node', 'unknown', now(), ['online_users' => 0]);
    expect($fresh->read(FLT01_NODE)['incident_id'])->toBe($id)
        ->and($fresh->read(FLT01_NODE)['state'])->toBe('disrupted');

    $fresh->record(FLT01_OTHER, 'node', 'unknown', now());
    expect($fresh->read(FLT01_OTHER)['state'])->toBe('unknown')
        ->and(DB::table('filtering_incidents')->count())->toBe(1);
});

it('rejects untrusted observations, secret evidence and incoherent outcomes', function (): void {
    $service = app(FilteringFoundation::class);
    foreach ([
        fn () => $service->record('not-uuid', 'node', 'healthy', now()),
        fn () => $service->record(FLT01_NODE, 'malicious', 'healthy', now()),
        fn () => $service->record(FLT01_NODE, 'node', 'fail', now()),
        fn () => $service->record(FLT01_NODE, 'node', 'disrupted', now()),
        fn () => $service->record(FLT01_NODE, 'node', 'healthy', now(), ['causes' => ['online_zero']]),
        fn () => $service->record(FLT01_NODE, 'node', 'unknown', now(), ['token' => 'secret']),
        fn () => $service->record(FLT01_NODE, 'node', 'unknown', now(), ['rx_bytes_per_sec' => -1]),
        fn () => $service->record(FLT01_NODE, 'node', 'unknown', now()->addMinutes(10)),
        fn () => $service->record(FLT01_NODE, 'node', 'unknown', now(), [], now()->addMinutes(10)),
        fn () => $service->record(FLT01_NODE, 'regional', 'suspicious', now(), ['causes' => ['traffic_zero']]),
    ] as $invalid) {
        expect($invalid)->toThrow(InvalidArgumentException::class);
    }
    expect(DB::table('filtering_checks')->count())->toBe(0)
        ->and(DB::table('filtering_incidents')->count())->toBe(0);
});

it('prunes only strictly older than 24 hours and never removes active incidents', function (): void {
    $now = now();
    DB::table('filtering_checks')->insert([
        ['node_uuid' => FLT01_NODE, 'module' => 'node', 'outcome' => 'unknown',
            'observed_at' => $now->copy()->subDay()->subSecond(), 'created_at' => $now],
        ['node_uuid' => FLT01_NODE, 'module' => 'node', 'outcome' => 'unknown',
            'observed_at' => $now->copy()->subDay(), 'created_at' => $now],
        ['node_uuid' => FLT01_NODE, 'module' => 'node', 'outcome' => 'unknown',
            'observed_at' => $now->copy()->subDay()->addSecond(), 'created_at' => $now],
    ]);
    $old = $now->copy()->subDays(3);
    $recent = $now->copy()->subHours(23);
    foreach ([
        ['node_uuid' => FLT01_NODE, 'active_node_uuid' => FLT01_NODE, 'state' => 'disrupted',
            'opened_at' => $old, 'last_observed_at' => $old, 'updated_at' => $old, 'resolved_at' => null],
        ['node_uuid' => FLT01_NODE, 'active_node_uuid' => null, 'state' => 'resolved',
            'opened_at' => $old, 'last_observed_at' => $old, 'updated_at' => $recent,
            'resolved_at' => $now->copy()->subDay()->subSecond()],
        ['node_uuid' => FLT01_NODE, 'active_node_uuid' => null, 'state' => 'resolved',
            'opened_at' => $old, 'last_observed_at' => $old, 'updated_at' => $recent,
            'resolved_at' => $now->copy()->subDay()],
        ['node_uuid' => FLT01_NODE, 'active_node_uuid' => null, 'state' => 'resolved',
            'opened_at' => $old, 'last_observed_at' => $old, 'updated_at' => $recent,
            'resolved_at' => $recent],
    ] as $incident) {
        DB::table('filtering_incidents')->insert($incident + ['affected_modules' => '{}']);
    }

    DB::table('health_check_result_history_items')->insert([
        'check_name' => 'unrelated', 'check_label' => 'unrelated', 'status' => 'ok',
        'meta' => '{}', 'ended_at' => $old, 'batch' => '550e8400-e29b-41d4-a716-446655440003',
        'created_at' => $old, 'updated_at' => $old,
    ]);
    expect(app(PruneFilteringHistory::class)->run())->toBe(['checks' => 1, 'incidents' => 1])
        ->and(DB::table('filtering_checks')->count())->toBe(2)
        ->and(DB::table('filtering_incidents')->count())->toBe(3)
        ->and(DB::table('filtering_incidents')->whereNull('resolved_at')->count())->toBe(1)
        ->and(DB::table('health_check_result_history_items')->count())->toBe(1);
});

it('bounds each invocation to 2000 deletes per table and makes progress on the next run', function (): void {
    $batch = [];
    for ($i = 0; $i < 2050; $i++) {
        $batch[] = [
            'node_uuid' => FLT01_NODE, 'module' => 'node', 'outcome' => 'unknown',
            'observed_at' => now()->subDays(2)->subSeconds($i),
            'created_at' => now(),
        ];
        if (count($batch) === 200) {
            DB::table('filtering_checks')->insert($batch);
            $batch = [];
        }
    }
    if ($batch !== []) {
        DB::table('filtering_checks')->insert($batch);
    }

    expect(app(PruneFilteringHistory::class)->run()['checks'])->toBe(2000)
        ->and(DB::table('filtering_checks')->count())->toBe(50)
        ->and(app(PruneFilteringHistory::class)->run()['checks'])->toBe(50);
});

it('registers safe five-minute pruning without triggering monitoring, provider or Telegram calls', function (): void {
    Artisan::call('list', ['--raw' => true]);
    expect(Artisan::output())->toContain('filtering:prune');
    $event = collect(Schedule::events())->first(
        static fn ($event): bool => str_contains((string) $event->command, 'filtering:prune'),
    );
    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();

    expect(Artisan::call('filtering:prune'))->toBe(0);
    expect(Artisan::output())->toContain('Filtering history pruned');
});

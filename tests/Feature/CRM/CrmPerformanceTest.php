<?php

declare(strict_types=1);

use App\Modules\Identity\Application\ExportCrmDirectory;
use App\Modules\Identity\Application\ReadCrmDirectory;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-27 12:00:00', 'Asia/Tehran')->utc());
    config()->set('denagate_crm.host', 'crm.denagate.ir');
    config()->set('super_admin.telegram_ids', ['997101', '997102']);
    foreach (['member', 'reseller', 'admin', 'super-admin'] as $role) {
        Role::findOrCreate($role, 'web');
    }
    Http::preventStrayRequests();
});

/**
 * @param  list<array{table:string,access:string|null,key:string|null,rows:int|null}>  $tables
 */
function crmPerfCollectPlanTables(mixed $node, array &$tables): void
{
    if (! is_array($node)) {
        return;
    }

    if (isset($node['table_name']) && is_string($node['table_name'])) {
        $tables[] = [
            'table' => $node['table_name'],
            'access' => isset($node['access_type']) ? (string) $node['access_type'] : null,
            'key' => isset($node['key']) ? (string) $node['key'] : null,
            'rows' => isset($node['rows_examined_per_scan']) ? (int) $node['rows_examined_per_scan'] : null,
        ];
    }

    foreach ($node as $value) {
        if (is_array($value)) {
            crmPerfCollectPlanTables($value, $tables);
        }
    }
}

it('measures CRM query counts, p95 and EXPLAIN plans on disposable MySQL fixtures', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('CRM performance baseline runs only against disposable MySQL.');
    }

    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 997101]);

    $memberRoleId = (int) Role::findByName('member', 'web')->id;
    $resellerRoleId = (int) Role::findByName('reseller', 'web')->id;
    $modelType = $root->getMorphClass();
    $now = Carbon::now('UTC');
    $nowString = $now->toDateTimeString();
    $tenureDate = $now->copy()->subDays(90)->toDateTimeString();
    $recentDate = $now->copy()->subDay()->toDateTimeString();

    $makeAccounts = static function (string $prefix, int $count, int $roleId, string $createdAt) use ($modelType): array {
        $rows = [];
        for ($index = 1; $index <= $count; $index++) {
            $rows[] = [
                'name' => $prefix === 'crm-perf-customer'
                    ? 'Benchmark Customer '.str_pad((string) $index, 4, '0', STR_PAD_LEFT)
                    : 'Benchmark Reseller '.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'email' => $prefix.'-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT).'@example.test',
                'password' => 'benchmark-fixture-password',
                'is_active' => true,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        $ids = DB::table('users')->where('email', 'like', $prefix.'-%@example.test')
            ->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $roles = array_map(static fn (int $id): array => [
            'role_id' => $roleId,
            'model_type' => $modelType,
            'model_id' => $id,
        ], $ids);
        foreach (array_chunk($roles, 500) as $chunk) {
            DB::table('model_has_roles')->insert($chunk);
        }

        return $ids;
    };

    // 1,999 direct customers exercise the 2,000-row export boundary; the
    // reseller cohort mirrors the current operating scale.
    $customerIds = $makeAccounts('crm-perf-customer', 1999, $memberRoleId, $nowString);
    $resellerIds = $makeAccounts('crm-perf-reseller', 25, $resellerRoleId, $tenureDate);

    $auditRows = array_map(static fn (int $id): array => [
        'actor_user_id' => $root->id,
        'event' => 'identity.roles.assigned',
        'subject_type' => 'user',
        'subject_id' => $id,
        'details' => json_encode(['before' => ['member'], 'after' => ['reseller']], JSON_THROW_ON_ERROR),
        'created_at' => $tenureDate,
    ], $resellerIds);
    DB::table('identity_audit_events')->insert($auditRows);

    $orderRows = [];
    $sequence = 0;
    $appendOrder = static function (int $buyerId, string $category) use (&$orderRows, &$sequence, $recentDate): void {
        $orderRows[] = [
            'buyer_user_id' => $buyerId,
            'buyer_category' => $category,
            'idempotency_key' => 'crm-perf-order-'.$sequence,
            'product_slug' => 'crm-perf',
            'product_name' => 'CRM Performance Fixture',
            'duration_days' => 30,
            'traffic_gb' => 20,
            'public_price_rials' => 100_000,
            'discount_percent' => 0,
            'discount_rials' => 0,
            'total_price_rials' => 100_000,
            'status' => 'fulfilled',
            'created_at' => $recentDate,
            'updated_at' => $recentDate,
        ];
        $sequence++;
    };
    foreach ($customerIds as $id) {
        $appendOrder($id, 'direct');
    }
    foreach ($resellerIds as $id) {
        for ($index = 0; $index < 5; $index++) {
            $appendOrder($id, 'reseller');
        }
    }
    foreach (array_chunk($orderRows, 500) as $chunk) {
        DB::table('sales_orders')->insert($chunk);
    }

    $orders = DB::table('sales_orders')->where('idempotency_key', 'like', 'crm-perf-order-%')
        ->orderBy('id')->get(['id', 'buyer_category']);
    $settlementJournals = [];
    foreach ($orders as $index => $order) {
        $settlementJournals[] = [
            'wallet_id' => null,
            'kind' => 'purchase',
            'idempotency_key' => 'crm-perf-settlement-'.$index,
            'created_at' => $recentDate,
        ];
    }
    foreach (array_chunk($settlementJournals, 500) as $chunk) {
        DB::table('billing_journals')->insert($chunk);
    }
    $settlementJournalIds = DB::table('billing_journals')
        ->where('idempotency_key', 'like', 'crm-perf-settlement-%')
        ->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

    $settlements = [];
    $contactRows = [];
    $contactIndex = 0;
    foreach ($orders as $index => $order) {
        $settlements[] = [
            'sales_order_id' => (int) $order->id,
            'billing_journal_id' => $settlementJournalIds[$index],
            'method' => 'wallet',
            'amount_rials' => 100_000,
            'settled_at' => $recentDate,
        ];
        if ($order->buyer_category === 'reseller') {
            $contactRows[] = [
                'sales_order_id' => (int) $order->id,
                'label' => 'Benchmark contact '.$contactIndex,
                'phone' => '+98912'.str_pad((string) $contactIndex, 7, '0', STR_PAD_LEFT),
                'created_at' => $recentDate,
                'updated_at' => $recentDate,
            ];
            $contactIndex++;
        }
    }
    foreach (array_chunk($settlements, 500) as $chunk) {
        DB::table('sales_order_settlements')->insert($chunk);
    }
    foreach (array_chunk($contactRows, 500) as $chunk) {
        DB::table('sales_service_customer_labels')->insert($chunk);
    }

    $walletRows = array_map(static fn (int $id): array => [
        'user_id' => $id,
        'created_at' => $tenureDate,
        'updated_at' => $nowString,
    ], $resellerIds);
    DB::table('billing_wallets')->insert($walletRows);
    $walletMap = DB::table('billing_wallets')->whereIn('user_id', $resellerIds)->pluck('id', 'user_id')->all();

    $walletJournals = [];
    $walletPostingMeta = [];
    $walletJournalSequence = 0;
    foreach ($resellerIds as $userId) {
        $walletId = (int) $walletMap[$userId];
        for ($day = 1; $day <= 60; $day += 6) {
            $walletJournals[] = [
                'wallet_id' => $walletId,
                'kind' => 'topup',
                'idempotency_key' => 'crm-perf-wallet-'.$walletJournalSequence,
                'created_at' => $now->copy()->subDays($day)->toDateTimeString(),
            ];
            $walletPostingMeta[] = ['wallet_id' => $walletId, 'amount_rials' => 100_000];
            $walletJournalSequence++;
        }
    }
    foreach (array_chunk($walletJournals, 500) as $chunk) {
        DB::table('billing_journals')->insert($chunk);
    }
    $walletJournalIds = DB::table('billing_journals')->where('idempotency_key', 'like', 'crm-perf-wallet-%')
        ->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
    $postings = [];
    foreach ($walletJournalIds as $index => $journalId) {
        $postings[] = [
            'journal_id' => $journalId,
            'wallet_id' => $walletPostingMeta[$index]['wallet_id'],
            'account_code' => 'wallet_liability',
            'side' => 'credit',
            'amount_rials' => $walletPostingMeta[$index]['amount_rials'],
        ];
    }
    foreach (array_chunk($postings, 500) as $chunk) {
        DB::table('billing_postings')->insert($chunk);
    }

    $directory = app(ReadCrmDirectory::class);
    $exporter = app(ExportCrmDirectory::class);
    $baseFilters = [
        'period' => 'all',
        'search' => '',
        'status' => 'all',
        'telegram' => 'all',
        'purchase' => 'all',
        'phone' => 'all',
        'orderStatus' => 'all',
        'sort' => 'id',
        'direction' => 'desc',
        'page' => 1,
        'segment' => 'off',
        'advancedMode' => 'and',
        'advancedConditions' => [],
    ];

    $operations = [
        'customers_rfm_off' => static fn () => $directory->handle($root->id, 'customers', $baseFilters),
        'customers_rfm_on' => static fn () => $directory->handle($root->id, 'customers', [...$baseFilters, 'segment' => 'all']),
        'customers_advanced_filters' => static fn () => $directory->handle($root->id, 'customers', [
            ...$baseFilters,
            'advancedConditions' => [
                ['field' => 'orders', 'operator' => 'gte', 'value' => '1'],
                ['field' => 'revenue', 'operator' => 'gte', 'value' => '10000'],
            ],
        ]),
        'resellers_rank' => static fn () => $directory->handle($root->id, 'resellers', [
            ...$baseFilters,
            'sort' => 'performance_rank',
            'direction' => 'asc',
        ]),
        'resellers_search_pagination' => static fn () => $directory->handle($root->id, 'resellers', [
            ...$baseFilters,
            'search' => 'Benchmark Reseller 0012',
            'sort' => 'performance_rank',
            'direction' => 'asc',
        ]),
        'customers_export_near_cap' => static fn () => $exporter->handle($root->id, 'customers', $baseFilters, 'json'),
    ];

    $capturing = false;
    $currentQueries = [];
    DB::listen(static function (QueryExecuted $event) use (&$capturing, &$currentQueries): void {
        if (! $capturing || preg_match('/^\s*(select|with)\b/i', $event->sql) !== 1) {
            return;
        }
        $currentQueries[] = [
            'sql' => $event->sql,
            'bindings' => $event->bindings,
            'time_ms' => (float) $event->time,
        ];
    });

    $reports = [];
    foreach ($operations as $scenario => $operation) {
        // Warm the connection and buffer pool, then report a repeatable warm-read baseline.
        for ($warmup = 0; $warmup < 2; $warmup++) {
            $operation();
        }

        $durations = [];
        $queryCounts = [];
        $databaseTimes = [];
        $slowestQuery = null;
        for ($iteration = 0; $iteration < 10; $iteration++) {
            $currentQueries = [];
            $capturing = true;
            $startedAt = microtime(true);
            try {
                $operation();
            } finally {
                $capturing = false;
            }
            $durations[] = (microtime(true) - $startedAt) * 1000;
            $queryCounts[] = count($currentQueries);
            $databaseTimes[] = array_sum(array_column($currentQueries, 'time_ms'));
            foreach ($currentQueries as $query) {
                if ($slowestQuery === null || $query['time_ms'] > $slowestQuery['time_ms']) {
                    $slowestQuery = $query;
                }
            }
        }

        sort($durations, SORT_NUMERIC);
        $p95Index = (int) max(0, ceil(count($durations) * 0.95) - 1);
        $medianIndex = (int) floor((count($durations) - 1) / 2);
        $planTables = [];
        $planError = null;
        if ($slowestQuery !== null) {
            try {
                $plan = DB::selectOne('EXPLAIN FORMAT=JSON '.$slowestQuery['sql'], $slowestQuery['bindings']);
                if ($plan !== null) {
                    $planValue = array_values((array) $plan)[0] ?? null;
                    if (is_string($planValue)) {
                        $decoded = json_decode($planValue, true, flags: JSON_THROW_ON_ERROR);
                        crmPerfCollectPlanTables($decoded, $planTables);
                    }
                }
            } catch (Throwable $exception) {
                $planError = class_basename($exception);
            }
        }

        $reports[] = [
            'scenario' => $scenario,
            'iterations' => count($durations),
            'median_ms' => round($durations[$medianIndex], 2),
            'p95_ms' => round($durations[$p95Index], 2),
            'average_select_queries' => round(array_sum($queryCounts) / count($queryCounts), 1),
            'average_select_time_ms' => round(array_sum($databaseTimes) / count($databaseTimes), 2),
            'slowest_select_ms' => round((float) ($slowestQuery['time_ms'] ?? 0), 2),
            'plan_tables' => $planTables,
            'plan_error' => $planError,
        ];
    }

    fwrite(STDERR, 'CRM_PERF_BASELINE='.json_encode($reports, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
    expect($reports)->toHaveCount(6);
    foreach ($reports as $report) {
        expect($report['average_select_queries'])->toBeGreaterThan(0)
            ->and($report['p95_ms'])->toBeGreaterThan(0);
    }
    Http::assertNothingSent();
});

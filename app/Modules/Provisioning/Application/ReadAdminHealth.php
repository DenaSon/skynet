<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Support\Health\OperationalProbe;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Root-only, bounded, read-only projection of Spatie's last persisted batch.
 * Never run health probes, expose raw health meta, or treat stale data as healthy.
 */
final readonly class ReadAdminHealth
{
    private const TABLE = 'health_check_result_history_items';

    public function __construct(private AssertCatalogManagementAccess $access) {}

    /**
     * @return array{state:string,enabled:bool,checkedAt:string|null,items:list<array{label:string,status:string,summary:string,group:string}>,total:int}
     */
    public function handle(int $actorUserId): array
    {
        $this->access->handle($actorUserId);

        $enabled = config('denagate_health.enabled') === true;
        $empty = [
            'state' => $enabled ? 'waiting' : 'disabled',
            'enabled' => $enabled,
            'checkedAt' => null,
            'items' => [],
            'total' => 0,
        ];

        if (! $enabled) {
            return $empty;
        }

        try {
            if (! Schema::hasTable(self::TABLE)) {
                return [...$empty, 'state' => 'unavailable'];
            }

            $latest = DB::table(self::TABLE)->orderByDesc('ended_at')->orderByDesc('id')
                ->first(['batch', 'ended_at']);
            if ($latest === null) {
                return $empty;
            }

            // Read only the latest atomic batch; earlier OK rows must not mask
            // later failures or partial/incomplete runs.
            $query = DB::table(self::TABLE)->where('batch', $latest->batch);
            $total = (int) (clone $query)->count();
            $rows = $query->orderBy('check_name')->limit(24)
                ->get(['check_name', 'status', 'short_summary']);

            $items = [];
            foreach ($rows as $row) {
                $status = (string) $row->status;
                $name = (string) $row->check_name;
                // Only summaries written by our named probes may enter Telegram;
                // even those must match a strict numeric/status alphabet.
                $rawSummary = $row->short_summary;
                $summary = str_starts_with($name, 'ops_')
                    && is_string($rawSummary) && strlen($rawSummary) <= 60
                    && preg_match('/\\A[0-9A-Za-z .%\\/:-]+\\z/D', $rawSummary) === 1
                        ? $rawSummary : '';
                $items[] = [
                    'label' => self::label($name),
                    'status' => in_array($status, ['ok', 'warning', 'failed', 'crashed', 'skipped'], true)
                        ? $status : 'unknown',
                    'summary' => $summary,
                    'group' => str_starts_with($name, 'ops_') ? 'operations' : 'basic',
                ];
            }

            $checkedAt = (string) $latest->ended_at;
            $instant = Carbon::parse($checkedAt);
            $current = now();
            $fresh = $instant->betweenIncluded($current->copy()->subMinutes(3), $current->copy()->addMinute());

            // An incomplete basic or operational batch must never show green.
            // Redis is required only with the same runtime condition as registration.
            $expectedBasic = ['DatabaseCheck', 'QueueCheck', 'ScheduleCheck', 'UsedDiskSpaceCheck'];
            if (config('cache.default') === 'redis' || config('queue.default') === 'redis') {
                $expectedBasic[] = 'RedisCheck';
            }
            $expectedOps = array_map(
                static fn (string $key): string => 'ops_'.$key, OperationalProbe::CHECKS,
            );
            $expected = [...$expectedBasic, ...$expectedOps];
            $observed = array_map(
                static fn (object $row): string => (string) $row->check_name, $rows->all(),
            );
            $incomplete = $total < 4 || $total > 24 || count($items) !== $total
                || count(array_unique($observed)) !== $total
                || count(array_intersect($expected, $observed)) !== count($expected);
            $state = 'healthy';
            if (! $fresh) {
                $state = 'stale';
            } elseif (array_any($items, static fn (array $item): bool => in_array(
                $item['status'], ['failed', 'crashed'], true,
            ))) {
                $state = 'failed';
            } elseif ($incomplete) {
                $state = 'unknown';
            } elseif (array_any($items, static fn (array $item): bool => $item['status'] === 'warning')) {
                $state = 'warning';
            } elseif (array_any($items, static fn (array $item): bool => in_array(
                $item['status'], ['skipped', 'unknown'], true,
            ))) {
                $state = 'unknown';
            }

            return compact('state', 'enabled', 'checkedAt', 'items', 'total');
        } catch (Throwable) {
            // A broken database must never be reported as healthy.
            return [...$empty, 'state' => 'unavailable'];
        }
    }

    /**
     * Bounded 24h timeline from persisted named Spatie checks, never an on-demand probe.
     *
     * @return array{state:string,rows:list<array{label:string,status:string,when:string,duration:?int}>,page:int,pages:int,total:int,recurrences:int}
     */
    public function history(int $actorUserId, int $page = 1): array
    {
        $this->access->handle($actorUserId);

        $empty = ['state' => 'available', 'rows' => [], 'page' => 1,
            'pages' => 1, 'total' => 0, 'recurrences' => 0];
        if (config('denagate_health.enabled') !== true) {
            return [...$empty, 'state' => 'disabled'];
        }

        try {
            if (! Schema::hasTable(self::TABLE)) {
                return [...$empty, 'state' => 'unavailable'];
            }

            // Use the indexed created_at retention boundary in addition to
            // the observation timestamp. The cap prevents an unbounded
            // Telegram read if the schedule unexpectedly ran too often.
            $expected = ['DatabaseCheck', 'QueueCheck', 'ScheduleCheck', 'UsedDiskSpaceCheck'];
            if (config('cache.default') === 'redis' || config('queue.default') === 'redis') {
                $expected[] = 'RedisCheck';
            }
            foreach (OperationalProbe::CHECKS as $name) {
                $expected[] = 'ops_'.$name;
            }

            $rows = DB::table(self::TABLE)->where('created_at', '>=', now()->subDay()->subMinutes(5))
                ->where('ended_at', '>=', now()->subDay())
                ->whereIn('check_name', $expected)
                ->orderBy('check_name')->orderBy('ended_at')->orderBy('id')
                ->limit(12001)->get(['check_name', 'status', 'ended_at']);
            if ($rows->count() > 12000) {
                return [...$empty, 'state' => 'unavailable'];
            }
            if ($rows->isEmpty()) {
                return [...$empty, 'state' => 'waiting'];
            }

            $last = [];
            $started = [];
            $events = [];
            $recurrences = 0;

            foreach ($rows as $row) {
                $name = (string) $row->check_name;
                $at = (string) $row->ended_at;
                $status = match ((string) $row->status) {
                    'ok' => 'ok',
                    'warning' => 'warning',
                    'failed', 'crashed' => 'failed',
                    default => 'unknown',
                };

                if (! isset($last[$name])) {
                    $last[$name] = $status;
                    $started[$name] = null;
                    // First observation of an already degraded state is not
                    // proof that the incident started inside the 24h window.
                    if (in_array($status, ['failed', 'warning'], true)) {
                        $events[] = ['label' => self::label($name), 'status' => $status,
                            'when' => $at, 'duration' => null];
                    }

                    continue;
                }
                if ($last[$name] === $status) {
                    continue;
                }

                $duration = null;
                if ($status === 'ok' && $started[$name] !== null) {
                    $duration = max(0, (int) Carbon::parse($started[$name], 'UTC')
                        ->diffInMinutes(Carbon::parse($at, 'UTC')));
                }
                if (in_array($status, ['failed', 'warning'], true)) {
                    $recurrences++;
                    $started[$name] = $at;
                } else {
                    $started[$name] = null;
                }

                $last[$name] = $status;
                $events[] = ['label' => self::label($name), 'status' => $status,
                    'when' => $at, 'duration' => $duration];
            }

            usort($events, static fn (array $a, array $b): int => strcmp($b['when'], $a['when']));
            $total = min(count($events), 120);
            $pages = max(1, (int) ceil($total / 6));
            $page = max(1, min($page, $pages));

            return ['state' => 'available',
                'rows' => array_slice($events, ($page - 1) * 6, 6),
                'page' => $page, 'pages' => $pages,
                'total' => $total, 'recurrences' => $recurrences];
        } catch (Throwable) {
            return [...$empty, 'state' => 'unavailable'];
        }
    }

    private static function label(string $name): string
    {
        return match (strtolower($name)) {
            'databasecheck', 'database' => 'MySQL',
            'redischeck', 'redis' => 'Redis',
            'queuecheck', 'queue' => 'Queue Worker',
            'schedulecheck', 'schedule' => 'Scheduler',
            'useddiskspacecheck', 'disk', 'useddiskspace' => 'فضای دیسک',
            'ops_cpu' => 'مصرف CPU',
            'ops_memory' => 'RAM',
            'ops_network_api' => 'API شبکه',
            'ops_telegram_api' => 'Telegram API',
            'ops_queue_backlog' => 'صف در انتظار',
            'ops_failed_jobs' => 'Jobهای ناموفق',
            'ops_notification_outbox' => 'اعلان‌های معوق',
            'ops_sales_fulfillment' => 'فعال‌سازی سفارش‌ها',
            'ops_subscription_jobs' => 'زمان‌بندی اشتراک‌ها',
            default => 'سنجش دیگر',
        };
    }
}

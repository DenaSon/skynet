<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
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

            // Four configured checks are always expected (database, queue,
            // scheduler, disk). A fifth, Redis, is optional in local test env.
            $incomplete = $total < 4 || $total > 24 || count($items) !== $total;
            $state = 'healthy';
            if (! $fresh) {
                $state = 'stale';
            } elseif ($incomplete) {
                $state = 'unknown';
            } elseif (array_any($items, static fn (array $item): bool => in_array(
                $item['status'], ['failed', 'crashed'], true,
            ))) {
                $state = 'failed';
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

    private static function label(string $name): string
    {
        return match (strtolower($name)) {
            'databasecheck', 'database' => 'MySQL',
            'redischeck', 'redis' => 'Redis',
            'queuecheck', 'queue' => 'Queue Worker',
            'schedulecheck', 'schedule' => 'Scheduler',
            'useddiskspacecheck', 'disk', 'useddiskspace' => 'فضای دیسک',
            'ops_cpu' => 'CPU Load',
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

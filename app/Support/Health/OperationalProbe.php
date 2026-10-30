<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveReadApi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Bounded operational probes, never write to customer, billing, provider,
 * outbox or job state. All summaries are composed from local fixed text/numbers.
 */
final class OperationalProbe
{
    public const CHECKS = [
        'cpu', 'memory', 'network_api', 'telegram_api', 'queue_backlog',
        'failed_jobs', 'notification_outbox', 'sales_fulfillment',
        'subscription_jobs',
    ];

    public const SUBSCRIPTION_HEARTBEAT = 'denagate:health:subscription-alerts:success';

    /** @return array{status:string,summary:string} */
    public function inspect(string $name): array
    {
        return match ($name) {
            'cpu' => $this->cpu(),
            'memory' => $this->memory(),
            'network_api' => $this->networkApi(),
            'telegram_api' => $this->telegramApi(),
            'queue_backlog' => $this->queueBacklog(),
            'failed_jobs' => $this->failedJobs(),
            'notification_outbox' => $this->notifications(),
            'sales_fulfillment' => $this->fulfillment(),
            'subscription_jobs' => $this->subscriptionJobs(),
            default => ['status' => 'warning', 'summary' => 'unknown'],
        };
    }

    /** @return array{status:string,summary:string} */
    private function cpu(): array
    {
        $load = sys_getloadavg();
        if (! is_array($load) || ! is_finite($load[0])) {
            return $this->unknown();
        }

        // Linux load average measures runnable tasks, NOT instantaneous CPU%.
        $cores = $this->cpuCapacity();
        if ($cores <= 0) {
            return $this->unknown();
        }
        $oneMinute = max(0.0, (float) $load[0]);
        $ratio = $oneMinute / $cores;

        return [
            'status' => $ratio >= 2 ? 'failed' : ($ratio >= 1 ? 'warning' : 'ok'),
            'summary' => number_format($oneMinute, 2, '.', '').' load / '
                .number_format($cores, 1, '.', '').' CPU',
        ];
    }

    private function cpuCapacity(): float
    {
        // Prefer container quota over host CPU count (Docker/Kubernetes).
        $quota = @file_get_contents('/sys/fs/cgroup/cpu.max');
        if (is_string($quota) && preg_match('/\A([0-9]+) ([0-9]+)\s*\z/', $quota, $m) === 1
            && (int) $m[2] > 0) {
            return max(0.1, (int) $m[1] / (int) $m[2]);
        }

        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if (! is_string($cpuinfo)) {
            return 0;
        }

        return (float) preg_match_all('/^processor\s*:/m', $cpuinfo);
    }

    /** @return array{status:string,summary:string} */
    private function memory(): array
    {
        // cgroup v2 describes the PHP container, including its page cache.
        $maximum = trim((string) @file_get_contents('/sys/fs/cgroup/memory.max'));
        $current = trim((string) @file_get_contents('/sys/fs/cgroup/memory.current'));
        if (ctype_digit($maximum) && ctype_digit($current)
            && strlen($maximum) < 18 && strlen($current) < 18
            && (int) $maximum > 0) {
            return $this->memoryResult((int) $current, (int) $maximum);
        }

        // Host fallback, explicitly using MemAvailable rather than MemFree.
        $data = @file_get_contents('/proc/meminfo');
        if (! is_string($data)
            || preg_match('/^MemTotal:\s+(\d+) kB/m', $data, $total) !== 1
            || preg_match('/^MemAvailable:\s+(\d+) kB/m', $data, $available) !== 1) {
            return $this->unknown();
        }

        $max = (int) $total[1] * 1024;
        $used = $max - (int) $available[1] * 1024;

        return $this->memoryResult($used, $max);
    }

    /** @return array{status:string,summary:string} */
    private function memoryResult(int $used, int $max): array
    {
        if ($max <= 0 || $used < 0 || $used > $max) {
            return $this->unknown();
        }

        $percent = round(100 * $used / $max, 1);

        return [
            'status' => $percent >= 95 ? 'failed' : ($percent >= 80 ? 'warning' : 'ok'),
            'summary' => number_format($percent, 1, '.', '').'% / '
                .number_format($max / 1048576, 0, '.', '').' MiB',
        ];
    }

    /** @return array{status:string,summary:string} */
    private function networkApi(): array
    {
        // Existing pinned ReadApi performs only GET; no new provider permissions.
        $instances = (new RemnawaveReadApi(app(RemnawaveApiClientFactory::class)->make()))
            ->healthInstanceCount();

        return ['status' => $instances > 0 ? 'ok' : 'warning',
            'summary' => $instances.' instances'];
    }

    /** @return array{status:string,summary:string} */
    private function telegramApi(): array
    {
        $tokens = ['admin' => config('nutgram.token')];
        if (config('telegram.sales.enabled') === true) {
            $tokens['sales'] = config('telegram.sales.token');
        }

        $checked = 0;
        foreach ($tokens as $token) {
            if (! is_string($token) || trim($token) === '') {
                return ['status' => 'warning', 'summary' => 'not configured'];
            }

            // Never log the token-bearing URL, response body or exceptions.
            $response = Http::acceptJson()->timeout(3)->connectTimeout(2)
                ->withOptions(['allow_redirects' => false])
                ->get('https://api.telegram.org/bot'.$token.'/getMe');

            if (! $response->successful() || $response->json('ok') !== true
                || ! is_array($response->json('result'))) {
                return ['status' => 'failed', 'summary' => 'API unreachable'];
            }
            $checked++;
        }

        return ['status' => 'ok', 'summary' => $checked.' bots'];
    }

    /** @return array{status:string,summary:string} */
    private function queueBacklog(): array
    {
        $connection = (string) config('queue.default');
        $driver = config('queue.connections.'.$connection.'.driver');
        if (in_array($driver, ['sync', 'null', 'deferred', 'background'], true)) {
            return ['status' => 'warning', 'summary' => 'no worker queue'];
        }

        $queue = (string) config('queue.connections.'.$connection.'.queue', 'default');
        $count = Queue::connection($connection)->size($queue);
        if ($count < 0) {
            return $this->unknown();
        }

        return ['status' => $count >= 100 ? 'failed' : ($count >= 20 ? 'warning' : 'ok'),
            'summary' => $count.' pending'];
    }

    /** @return array{status:string,summary:string} */
    private function failedJobs(): array
    {
        $count = (int) DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

        return ['status' => $count >= 10 ? 'failed' : ($count > 0 ? 'warning' : 'ok'),
            'summary' => $count.' / 24h'];
    }

    /** @return array{status:string,summary:string} */
    private function notifications(): array
    {
        if (config('telegram_notifications.enabled') !== true) {
            return ['status' => 'warning', 'summary' => 'disabled'];
        }

        $stalled = (int) DB::table('telegram_notification_deliveries')
            ->where(function ($q): void {
                $q->where(function ($sub): void {
                    $sub->where('state', 'pending')
                        ->where('available_at', '<=', now()->subMinutes(10));
                })->orWhere(function ($sub): void {
                    $sub->where('state', 'processing')
                        ->where('leased_until', '<', now());
                });
            })->count();

        $terminal = (int) DB::table('telegram_notification_deliveries')
            ->whereIn('state', ['dead', 'unavailable'])
            ->where('updated_at', '>=', now()->subDay())
            ->where(function ($q): void {
                $q->whereNull('last_failure')
                    ->orWhere('last_failure', '!=', 'superseded_by_activation');
            })->count();

        $total = $stalled + $terminal;

        return ['status' => $stalled >= 10 ? 'failed' : ($total > 0 ? 'warning' : 'ok'),
            'summary' => $stalled.' stale / '.$terminal.' failed'];
    }

    /** @return array{status:string,summary:string} */
    private function fulfillment(): array
    {
        if (config('sales.auto_fulfillment_enabled') !== true) {
            return ['status' => 'warning', 'summary' => 'disabled'];
        }

        $review = (int) DB::table('sales_fulfillment_outbox')
            ->where('state', 'needs_review')->count();

        $stalled = (int) DB::table('sales_fulfillment_outbox as outbox')
            ->join('sales_orders as orders', 'orders.id', '=', 'outbox.sales_order_id')
            ->where('orders.status', 'paid')->where('outbox.state', 'pending')
            ->where('outbox.created_at', '<', now()->subMinutes(15))
            ->count();

        return ['status' => $review >= 5 || $stalled >= 10 ? 'failed'
            : (($review + $stalled) > 0 ? 'warning' : 'ok'),
            'summary' => $review.' review / '.$stalled.' stale'];
    }

    /** @return array{status:string,summary:string} */
    private function subscriptionJobs(): array
    {
        if (config('telegram.sales.enabled') !== true
            || config('telegram_notifications.enabled') !== true) {
            return ['status' => 'warning', 'summary' => 'disabled'];
        }

        $last = Cache::get(self::SUBSCRIPTION_HEARTBEAT);
        if (! is_int($last) || $last <= 0 || $last > now()->timestamp + 60) {
            return ['status' => 'warning', 'summary' => 'no heartbeat'];
        }

        $age = max(0, now()->timestamp - $last);
        return [
            'status' => $age >= 900 ? 'failed' : ($age >= 600 ? 'warning' : 'ok'),
            'summary' => intdiv($age, 60).' min ago',
        ];
    }

    /** @return array{status:string,summary:string} */
    private function unknown(): array
    {
        return ['status' => 'warning', 'summary' => 'unavailable'];
    }
}

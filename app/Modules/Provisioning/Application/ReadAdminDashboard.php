<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Billing\Application\ValidBankSettlements;
use App\Modules\Identity\Application\AdminCapabilityAccess;
use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveUsersApi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * STAT-01: live on-demand secret-free counts; only the compact Denaban projection caches provider totals.
 * Remote totals are displayed only when the full bounded result is consistent.
 */
final readonly class ReadAdminDashboard
{
    private const DENABAN_PROVIDER_TTL = 30;

    public function __construct(
        private AssertCatalogManagementAccess $access,
        private ReadAdminProviderOverview $overview,
        private RemnawaveApiClientFactory $factory,
        private ReviewPaidFulfillment $fulfillment,
    ) {}

    /**
     * @return array{
     *   nodes:?int, connected:?int, hosts:?int, panelUsers:?int, online:?int,
     *   subscriptionsActive:?int, subscriptionsExpired:?int, subscriptionsLimited:?int,
     *   localUsers:int, localActive:int, telegramLinked:int, salesReachable:int,
     *   adminReachable:int, salesRecent:int, salesTodayRials:int,
     *   provisioningReview:int, fulfillmentReview:int, fulfillmentOverdue:int, receiptsPending:int,
     *   failedJobs:int, notificationIssues:int, receivedAt:string,
     *   nodeSummaries:list<array<string,int|string|bool|null>>|null
     * }
     */
    public function handle(int $actorId): array
    {
        $this->access->handle($actorId);

        // Local data and remote data fail independently.
        $users = (int) DB::table('users')->count();
        $active = (int) DB::table('users')->where('is_active', true)->count();
        $linked = (int) DB::table('telegram_accounts')->count();
        $sales = (int) DB::table('telegram_bot_contacts')
            ->where('bot_key', 'sales')->where('delivery_status', 'reachable')->count();
        $admin = (int) DB::table('telegram_bot_contacts')
            ->where('bot_key', 'admin')->where('delivery_status', 'reachable')->count();
        $recent = (int) DB::table('telegram_bot_contacts')->where('bot_key', 'sales')
            ->where('last_seen_at', '>=', now()->subDays(30))->count();

        $report = [
            'nodes' => null, 'connected' => null, 'hosts' => null,
            'nodeSummaries' => null,
            'panelUsers' => null, 'online' => null,
            'subscriptionsActive' => null, 'subscriptionsExpired' => null,
            'subscriptionsLimited' => null,
            'localUsers' => $users, 'localActive' => $active, 'telegramLinked' => $linked,
            'salesReachable' => $sales, 'adminReachable' => $admin, 'salesRecent' => $recent,
            'salesTodayRials' => $this->salesTodayRials(),
            'provisioningReview' => (int) DB::table('provisioning_operations')->where('state', 'needs_review')->count(),
            'fulfillmentReview' => (int) DB::table('sales_fulfillment_outbox')->where('state', 'needs_review')->count(),
            'fulfillmentOverdue' => $this->fulfillment->attentionCounts($actorId)['overdue'],
            'receiptsPending' => (int) DB::table('sales_bank_payment_claims')->where('status', 'submitted')->count(),
            'failedJobs' => (int) DB::table('failed_jobs')->count(),
            'notificationIssues' => (int) DenabanNotificationIssues::apply(
                DB::table('telegram_notification_deliveries'),
            )->count(),
            'receivedAt' => now()->timezone('Asia/Tehran')->format('Y-m-d H:i'),
        ];

        $provider = $this->overview->dashboard($actorId);
        if ($provider->state === 'available') {
            $report['nodes'] = $provider->nodes;
            $report['connected'] = $provider->connectedNodes;
            $report['hosts'] = $provider->hosts;
            $report['nodeSummaries'] = $provider->nodeSummaries;
            $report['panelUsers'] = $provider->systemStats?->totalUsers;
            $report['online'] = $provider->systemStats?->onlineUsers;
        }

        return [...$report, ...($this->subscriptionCounts() ?? [])];
    }

    /**
     * Compact Denaban projection; preserve the full STAT-01 report for other callers.
     *
     * @return array{localUsers:int,salesTodayRials:int,subscriptionsActive:?int,nodesOnline:?int,providerObservedAt:?int}
     */
    public function denaban(int $actorId): array
    {
        $this->access->handle($actorId);

        $users = (int) DB::table('users')->count();
        $sales = $this->salesTodayRials();
        return [
            'localUsers' => $users,
            'salesTodayRials' => $sales,
            ...$this->denabanProvider($actorId),
        ];
    }

    /** @return array{subscriptionsActive:?int,nodesOnline:?int,providerObservedAt:?int} */
    private function denabanProvider(int $actorId): array
    {
        // Recheck the existing server permission before exposing even cached counts.
        app(AdminCapabilityAccess::class)->require($actorId, 'servers.view');

        $url = config('services.remnawave.url');
        $token = config('services.remnawave.token');
        if (! is_string($url) || trim($url) === '' || ! is_string($token) || trim($token) === '') {
            return ['subscriptionsActive' => null, 'nodesOnline' => null, 'providerObservedAt' => null];
        }

        // Store only two aggregates and a timestamp; never cache credentials,
        // raw user lists, node metadata, financial values or authorization.
        $key = 'denagate:denaban:provider:v1:'.hash('sha256', $url);
        try {
            $cached = Cache::get($key);
            $now = now()->timestamp;
            if (is_array($cached)
                && is_int($cached['subscriptionsActive'] ?? null) && $cached['subscriptionsActive'] >= 0
                && is_int($cached['nodesOnline'] ?? null) && $cached['nodesOnline'] >= 0
                && is_int($cached['providerObservedAt'] ?? null)
                && $cached['providerObservedAt'] <= $now
                && $cached['providerObservedAt'] > $now - self::DENABAN_PROVIDER_TTL) {
                return [
                    'subscriptionsActive' => $cached['subscriptionsActive'],
                    'nodesOnline' => $cached['nodesOnline'],
                    'providerObservedAt' => $cached['providerObservedAt'],
                ];
            }
        } catch (Throwable) {
            // An unavailable cache must not prevent a bounded live read.
        }

        // Timestamp the start conservatively so the first observation is never
        // presented as fresher than the two/three sequential provider reads.
        $observedAt = now()->timestamp;
        $online = $this->overview->denabanOnline($actorId);
        $active = $this->subscriptionCounts(2)['subscriptionsActive'] ?? null;
        $result = [
            'subscriptionsActive' => $active,
            'nodesOnline' => $online,
            'providerObservedAt' => $active !== null || $online !== null ? $observedAt : null,
        ];
        if ($active !== null && $online !== null) {
            try {
                Cache::put($key, $result, self::DENABAN_PROVIDER_TTL);
            } catch (Throwable) {
                // Cache is an optimization, never a prerequisite for the report.
            }
        }

        return $result;
    }

    private function salesTodayRials(): int
    {
        // Date range: today's settled purchase revenue in Tehran, not wallet topups.
        $from = Carbon::now('Asia/Tehran')->startOfDay()->utc();
        $until = Carbon::now('Asia/Tehran')->addDay()->startOfDay()->utc();
        $bank = ValidBankSettlements::query()->where('settled_at', '>=', $from)
            ->where('settled_at', '<', $until)->sum('amount_rials');
        $wallet = DB::table('sales_order_settlements')->where('settled_at', '>=', $from)
            ->where('settled_at', '<', $until)->sum('amount_rials');

        return (int) $bank + (int) $wallet;
    }

    /** @return array{subscriptionsActive:int,subscriptionsExpired:int,subscriptionsLimited:int,panelUsers:int}|null */
    private function subscriptionCounts(?int $timeoutCap = null): ?array
    {
        // A partial list is NOT a reliable count of all active subscriptions.
        // Maximum two reads/1000 users per on-demand refresh; no unbounded API scan.
        try {
            $api = new RemnawaveUsersApi($this->factory->make($timeoutCap));
            $page = $api->list(0, 500);
            $total = $page['total'];
            if ($total > 1000 || count($page['users']) !== min(500, $total)) {
                return null;
            }

            $all = $page['users'];
            if ($total > 500) {
                $next = $api->list(500, 500);
                if ($next['total'] !== $total || count($next['users']) !== $total - 500) {
                    return null;
                }
                $all = [...$all, ...$next['users']];
            }

            $ids = array_map(static fn ($user): int => $user->id, $all);
            if (count($all) !== $total || count(array_unique($ids)) !== $total) {
                return null;
            }

            $status = array_count_values(array_map(static fn ($user): string => $user->status, $all));

            return [
                'subscriptionsActive' => $status['ACTIVE'] ?? 0,
                'subscriptionsExpired' => $status['EXPIRED'] ?? 0,
                'subscriptionsLimited' => $status['LIMITED'] ?? 0,
                'panelUsers' => $total,
            ];
        } catch (Throwable) {
            // Never disguise stale, incomplete or unavailable provider counts as zero.
        }

        return null;
    }
}

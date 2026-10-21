<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveUsersApi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * STAT-01: live on-demand secret-free counts; no writes, cache or analytics store.
 * Remote totals are displayed only when the full bounded result is consistent.
 */
final readonly class ReadAdminDashboard
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private ReadAdminProviderOverview $overview,
        private RemnawaveApiClientFactory $factory,
    ) {}

    /**
     * @return array{
     *   nodes:?int, connected:?int, hosts:?int, panelUsers:?int, online:?int,
     *   subscriptionsActive:?int, subscriptionsExpired:?int, subscriptionsLimited:?int,
     *   localUsers:int, localActive:int, telegramLinked:int, salesReachable:int,
     *   adminReachable:int, salesRecent:int, salesTodayRials:int,
     *   provisioningReview:int, fulfillmentReview:int, receiptsPending:int,
     *   failedJobs:int, notificationIssues:int, receivedAt:string
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

        // Date range: today's settled purchase revenue in Tehran, not wallet topups.
        $from = Carbon::now('Asia/Tehran')->startOfDay()->utc();
        $until = Carbon::now('Asia/Tehran')->addDay()->startOfDay()->utc();
        $bank = DB::table('sales_bank_settlements')->where('settled_at', '>=', $from)
            ->where('settled_at', '<', $until)->sum('amount_rials');
        $wallet = DB::table('sales_order_settlements')->where('settled_at', '>=', $from)
            ->where('settled_at', '<', $until)->sum('amount_rials');

        $report = [
            'nodes' => null, 'connected' => null, 'hosts' => null,
            'panelUsers' => null, 'online' => null,
            'subscriptionsActive' => null, 'subscriptionsExpired' => null,
            'subscriptionsLimited' => null,
            'localUsers' => $users, 'localActive' => $active, 'telegramLinked' => $linked,
            'salesReachable' => $sales, 'adminReachable' => $admin, 'salesRecent' => $recent,
            'salesTodayRials' => (int) $bank + (int) $wallet,
            'provisioningReview' => (int) DB::table('provisioning_operations')->where('state', 'needs_review')->count(),
            'fulfillmentReview' => (int) DB::table('sales_fulfillment_outbox')->where('state', 'needs_review')->count(),
            'receiptsPending' => (int) DB::table('sales_bank_payment_claims')->where('status', 'submitted')->count(),
            'failedJobs' => (int) DB::table('failed_jobs')->count(),
            'notificationIssues' => (int) DB::table('telegram_notification_deliveries')
                ->whereIn('state', ['dead', 'unavailable'])->count(),
            'receivedAt' => now()->timezone('Asia/Tehran')->format('Y-m-d H:i'),
        ];

        $provider = $this->overview->handle($actorId);
        if ($provider->state === 'available') {
            $report['nodes'] = $provider->nodes;
            $report['connected'] = $provider->connectedNodes;
            $report['hosts'] = $provider->hosts;
            $report['panelUsers'] = $provider->systemStats?->totalUsers;
            $report['online'] = $provider->systemStats?->onlineUsers;
        }

        // A partial list is NOT a reliable count of all active subscriptions.
        // Maximum two reads/1000 users per on-demand refresh; no unbounded API scan.
        try {
            $api = new RemnawaveUsersApi($this->factory->make());
            $page = $api->list(0, 500);
            $total = $page['total'];
            if ($total > 1000 || count($page['users']) !== min(500, $total)) {
                return $report;
            }

            $all = $page['users'];
            if ($total > 500) {
                $next = $api->list(500, 500);
                if ($next['total'] !== $total || count($next['users']) !== $total - 500) {
                    return $report;
                }
                $all = [...$all, ...$next['users']];
            }

            $ids = array_map(static fn ($user): int => $user->id, $all);
            if (count($all) !== $total || count(array_unique($ids)) !== $total) {
                return $report;
            }

            $status = array_count_values(array_map(static fn ($user): string => $user->status, $all));
            $report['subscriptionsActive'] = $status['ACTIVE'] ?? 0;
            $report['subscriptionsExpired'] = $status['EXPIRED'] ?? 0;
            $report['subscriptionsLimited'] = $status['LIMITED'] ?? 0;
            $report['panelUsers'] = $total;
        } catch (Throwable) {
            // Never disguise stale, incomplete or unavailable provider counts as zero.
        }

        return $report;
    }
}

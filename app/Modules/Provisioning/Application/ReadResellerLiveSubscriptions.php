<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\ResolveSalesBuyer;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveUsersApi;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Reseller-only, bounded, read-only live service status report. */
final readonly class ReadResellerLiveSubscriptions
{
    private const MAX_OWNED = 50;
    private const MAX_PROVIDER_USERS = 1000;
    private const LOW_TRAFFIC_BYTES = 350 * 1024 * 1024;

    public function __construct(
        private ResolveSalesBuyer $buyers,
        private RemnawaveApiClientFactory $factory,
    ) {}

    /**
     * @return array{available:bool,active:?int,expired:?int,limited:?int,
     *     expiringSoon:?int,lowTraffic:?int,attentionOrderIds:list<int>,checked:?int}
     */
    public function handle(int $buyerId): array
    {
        if (! $this->buyers->isReseller($buyerId)) {
            throw new AuthorizationException;
        }
        $unknown = [
            'available' => false, 'active' => null, 'expired' => null,
            'limited' => null, 'expiringSoon' => null, 'lowTraffic' => null,
            'attentionOrderIds' => [], 'checked' => null,
        ];

        // Read only linked and paid reseller-owned orders. Renewals keep their
        // source subscription; no external IDs, URLs or credentials reach UI.
        $owned = DB::table('sales_orders as orders')
            ->join('provisioning_order_links as links', 'links.sales_order_id', '=', 'orders.id')
            ->join('provisioning_subscriptions as sub', 'sub.id', '=', 'links.provisioning_subscription_id')
            ->where('orders.buyer_user_id', $buyerId)
            ->whereIn('orders.status', ['paid', 'fulfilled'])
            ->orderByDesc('orders.id')
            ->limit(self::MAX_OWNED + 1)
            ->get(['orders.id as order_id', 'sub.remote_user_id', 'sub.remote_username']);

        if ($owned->count() > self::MAX_OWNED) {
            return $unknown;
        }
        if ($owned->isEmpty()) {
            return [...$unknown, 'available' => true, 'active' => 0,
                'expired' => 0, 'limited' => 0, 'expiringSoon' => 0,
                'lowTraffic' => 0, 'checked' => 0];
        }

        foreach ($owned as $service) {
            if ($service->remote_user_id === null || ! is_numeric($service->remote_user_id)
                || (int) $service->remote_user_id < 1
                || ! is_string($service->remote_username) || $service->remote_username === '') {
                return $unknown;
            }
        }

        try {
            $api = new RemnawaveUsersApi($this->factory->make());
            $first = $api->list(0, 500);
            $total = $first['total'];
            if ($total > self::MAX_PROVIDER_USERS || count($first['users']) !== min(500, $total)) {
                return $unknown;
            }
            $remote = $first['users'];
            if ($total > 500) {
                $next = $api->list(500, 500);
                if ($next['total'] !== $total || count($next['users']) !== $total - 500) {
                    return $unknown;
                }
                $remote = [...$remote, ...$next['users']];
            }
            $byId = [];
            foreach ($remote as $user) {
                if (isset($byId[$user->id])) {
                    return $unknown;
                }
                $byId[$user->id] = $user;
            }
            if (count($byId) !== $total) {
                return $unknown;
            }

            $now = new DateTimeImmutable('now');
            $soon = $now->modify('+7 days');
            $active = $expired = $limited = $expiring = $low = 0;
            $attention = [];
            foreach ($owned as $service) {
                $user = $byId[(int) $service->remote_user_id] ?? null;
                if ($user === null || $user->username !== $service->remote_username) {
                    return $unknown;
                }
                $expires = new DateTimeImmutable($user->expireAt);
                $exhausted = $user->trafficLimitBytes > 0
                    && $user->usedTrafficBytes >= $user->trafficLimitBytes;
                $isExpired = $user->status === 'EXPIRED' || $expires <= $now;
                $isLimited = ! $isExpired && ($user->status === 'LIMITED' || $exhausted);
                $isActive = ! $isExpired && ! $isLimited && $user->status === 'ACTIVE';

                if ($isExpired) {
                    $expired++;
                } elseif ($isLimited) {
                    $limited++;
                } elseif ($isActive) {
                    $active++;
                }
                $needsAttention = false;
                if ($isActive && $expires <= $soon) {
                    $expiring++;
                    $needsAttention = true;
                }
                if ($isActive && $user->trafficLimitBytes > 0
                    && ($user->trafficLimitBytes - $user->usedTrafficBytes) < self::LOW_TRAFFIC_BYTES) {
                    $low++;
                    $needsAttention = true;
                }
                if ($needsAttention && count($attention) < 6) {
                    $attention[] = (int) $service->order_id;
                }
            }

            return [
                'available' => true, 'active' => $active, 'expired' => $expired,
                'limited' => $limited, 'expiringSoon' => $expiring,
                'lowTraffic' => $low, 'attentionOrderIds' => $attention,
                'checked' => $owned->count(),
            ];
        } catch (Throwable) {
            // Incomplete or unreachable Remote status is never shown as zero.
            return $unknown;
        }
    }
}

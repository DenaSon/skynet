<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AuthorizeSubscriptionViewer;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveUserSnapshot;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Same owner, immutable Sales order, exact linked provider identity.
 * Delivers a vetted provider snapshot only INSIDE an authenticated use case.
 * No writes. Revalidates after the network call (revocation/unlink race).
 */
final readonly class ActiveSubscriptionAccess
{
    public function __construct(
        private AuthorizeSubscriptionViewer $authorize,
        private SubscriptionOwnershipQuery $subscriptions,
        private RemnawaveSubscriptionLifecycle $lifecycle,
    ) {}

    /**
     * @template TResult
     *
     * @param  \Closure(RemnawaveUserSnapshot): TResult  $read
     * @param  bool  $requireUsable  Credential reads stay strict; status/usage may display remote inactive states.
     * @return TResult
     */
    public function read(SubscriptionViewer $viewer, int $orderId, \Closure $read, bool $requireUsable = true): mixed
    {
        $actorId = $this->authorize->userId($viewer);
        $binding = $this->binding($actorId, $orderId);

        try {
            $remote = $this->lifecycle->subscription((string) $binding->reference);

            if ($remote->id !== (int) $binding->remote_user_id
                || ($requireUsable && ($remote->status !== 'ACTIVE'
                || $remote->trafficLimitBytes > 0 && $remote->usedTrafficBytes >= $remote->trafficLimitBytes
                || (new DateTimeImmutable($remote->expireAt)) <= new DateTimeImmutable('now')))) {
                throw new RuntimeException('Remote subscription is not usable.');
            }

            $result = $read($remote);
        } catch (Throwable) {
            // Do not leak provider bodies, credentials, URLs or exception chains.
            throw new RuntimeException('Subscription connection information is unavailable.');
        }

        if ($this->authorize->userId($viewer) !== $actorId) {
            throw new AuthorizationException;
        }

        $fresh = $this->binding($actorId, $orderId);

        if ($fresh->reference !== $binding->reference
            || (int) $fresh->remote_user_id !== (int) $binding->remote_user_id) {
            throw new AuthorizationException;
        }

        return $result;
    }

    private function binding(int $buyerId, int $orderId): object
    {
        $detail = $this->subscriptions->detail($buyerId, $orderId);

        if (! $detail->canRequestLink) {
            throw new AuthorizationException;
        }

        $link = DB::table('provisioning_order_links as links')
            ->join('provisioning_subscriptions as sub', 'sub.id', '=', 'links.provisioning_subscription_id')
            ->join('sales_orders as orders', 'orders.id', '=', 'links.sales_order_id')
            ->where('links.sales_order_id', $orderId)
            ->where('orders.buyer_user_id', $buyerId)
            ->whereIn('orders.status', ['paid', 'fulfilled'])
            ->where('sub.state', 'active')
            ->whereNotNull('sub.remote_user_id')
            ->select('sub.reference', 'sub.remote_user_id')
            ->first();

        if ($link === null) {
            throw new AuthorizationException;
        }

        return $link;
    }
}

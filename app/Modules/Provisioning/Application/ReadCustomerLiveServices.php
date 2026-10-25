<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\BrowseUsers;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * CUS-360-02: one owner-scoped support read, using the existing provider
 * identity-verified GET path. Never accepts a remote ID or returns credentials.
 */
final readonly class ReadCustomerLiveServices
{
    public function __construct(
        private BrowseUsers $users,
        private RemnawaveSubscriptionLifecycle $lifecycle,
    ) {}

    /**
     * @return array{items:list<array{id:int,name:string,state:string}>,page:int,hasNext:bool}
     */
    public function page(int $actorId, int $subjectId, int $page): array
    {
        $this->authorize($actorId, $subjectId);
        if ($page < 1 || $page > 1000) {
            throw ValidationException::withMessages(['page' => 'Invalid service page.']);
        }

        $rows = $this->services($subjectId)->orderByDesc('orders.id')
            ->offset(($page - 1) * 5)->limit(6)
            ->get(['orders.id', 'orders.product_name', 'subscriptions.state']);
        $items = $rows->take(5)->map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'name' => (string) $row->product_name,
            'state' => (string) $row->state,
        ])->all();

        return ['items' => array_values($items), 'page' => $page, 'hasNext' => $rows->count() > 5];
    }

    /**
     * @return array{
     *   orderId:int,name:string,localState:string,state:string,remoteStatus:?string,
     *   usedBytes:?int,limitBytes:?int,remainingBytes:?int,expiresAt:?string,daysLeft:?int,
     *   identityMatched:bool
     * }
     */
    public function detail(int $actorId, int $subjectId, int $orderId): array
    {
        $this->authorize($actorId, $subjectId);
        if ($orderId < 1) {
            throw ValidationException::withMessages(['order' => 'Invalid service.']);
        }

        $binding = $this->binding($subjectId, $orderId);
        if ($binding === null) {
            throw ValidationException::withMessages(['order' => 'Service not found.']);
        }

        $result = [
            'orderId' => $orderId, 'name' => (string) $binding->product_name,
            'localState' => (string) $binding->state,
            'state' => 'unavailable', 'remoteStatus' => null,
            'usedBytes' => null, 'limitBytes' => null, 'remainingBytes' => null,
            'expiresAt' => null, 'daysLeft' => null, 'identityMatched' => false,
        ];

        // A pending or unlinked local provisioning record cannot authorize a
        // provider lookup. This is not evidence that the remote user is absent.
        if ($binding->state !== 'active' || ! is_numeric($binding->remote_user_id)) {
            return $result;
        }

        try {
            // Existing lifecycle checks deterministic username/ownership marker;
            // this Admin path also verifies the exact linked numeric remote ID.
            $remote = $this->lifecycle->subscription((string) $binding->reference);
            if ($remote->id !== (int) $binding->remote_user_id) {
                throw new \UnexpectedValueException('Remote user identity mismatch.');
            }
            $expiry = new DateTimeImmutable($remote->expireAt);
            $timestamp = $expiry->getTimestamp();
            if ($timestamp < 0 || $timestamp > 253402300799) {
                throw new \UnexpectedValueException('Invalid expiry.');
            }

            $result['state'] = 'available';
            $result['remoteStatus'] = $remote->status;
            $result['usedBytes'] = $remote->usedTrafficBytes;
            $result['limitBytes'] = $remote->trafficLimitBytes;
            $result['remainingBytes'] = $remote->trafficLimitBytes === 0
                ? null : max(0, $remote->trafficLimitBytes - $remote->usedTrafficBytes);
            $result['expiresAt'] = $expiry->format('Y-m-d\TH:i:sP');
            $result['daysLeft'] = max(0, (int) ceil(($timestamp - time()) / 86400));
            $result['identityMatched'] = true;
        } catch (Throwable) {
            // Provider transport, missing user, malformed body or marker
            // mismatch are all UNKNOWN, never "inactive" or "deleted".
        }

        // A callback could race with account revocation or a reassigned order.
        // Recheck both grants and exact mapping AFTER the remote request.
        $this->authorize($actorId, $subjectId);
        $fresh = $this->binding($subjectId, $orderId);
        if ($fresh === null
            || $fresh->state !== $binding->state
            || $fresh->reference !== $binding->reference
            || (string) $fresh->remote_user_id !== (string) $binding->remote_user_id) {
            throw new AuthorizationException;
        }

        return $result;
    }

    private function authorize(int $actorId, int $subjectId): void
    {
        if ($subjectId < 1 || $this->users->find($actorId, $subjectId) === null) {
            throw ValidationException::withMessages(['user' => 'Customer not found.']);
        }
    }

    private function services(int $subjectId): Builder
    {
        // The exact local linked-service ownership rule used by CUS-360-01.
        return DB::table('sales_orders as orders')
            ->join('provisioning_order_links as links', 'links.sales_order_id', '=', 'orders.id')
            ->join('provisioning_subscriptions as subscriptions', 'subscriptions.id', '=', 'links.provisioning_subscription_id')
            ->where('orders.buyer_user_id', $subjectId)
            ->whereIn('orders.status', ['paid', 'fulfilled']);
    }

    private function binding(int $subjectId, int $orderId): ?object
    {
        return $this->services($subjectId)->where('orders.id', $orderId)
            ->first(['orders.product_name', 'subscriptions.state', 'subscriptions.reference', 'subscriptions.remote_user_id']);
    }
}

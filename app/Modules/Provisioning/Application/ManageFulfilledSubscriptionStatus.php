<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * MVP-04: status changes only for fulfilled, locally owned ParsaGate orders.
 * Remnawave transport, idempotency and uncertain-write recovery stay in the
 * existing lifecycle; this action never takes a remote user ID from Telegram.
 */
final readonly class ManageFulfilledSubscriptionStatus
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private RemnawaveSubscriptionLifecycle $lifecycle,
    ) {}

    /** @return 'active'|'disabled'|'review'|'unavailable' */
    public function status(int $actorId, int $orderId): string
    {
        $linked = $this->linked($actorId, $orderId);

        if ($linked->state === 'needs_review') {
            $this->uncertainKey($linked);

            return 'review';
        }

        try {
            $remote = $this->lifecycle->subscription((string) $linked->reference);
            if ($remote->id !== (int) $linked->remote_user_id
                || ! in_array($remote->status, ['ACTIVE', 'DISABLED'], true)
                || (new DateTimeImmutable($remote->expireAt)) <= new DateTimeImmutable('now')) {
                return 'unavailable';
            }

            return $remote->status === 'ACTIVE' ? 'active' : 'disabled';
        } catch (Throwable) {
            return 'unavailable';
        }
    }

    public function authorizeChange(int $actorId, int $orderId, string $mode): void
    {
        if (! in_array($mode, ['enable', 'disable'], true)
            || config('services.remnawave.writes_enabled') !== true) {
            throw ValidationException::withMessages(['subscription' => 'Status changes unavailable.']);
        }

        $state = $this->status($actorId, $orderId);
        if (($mode === 'disable' && $state !== 'active')
            || ($mode === 'enable' && $state !== 'disabled')) {
            throw ValidationException::withMessages(['subscription' => 'Subscription state changed.']);
        }
    }

    public function change(int $actorId, int $orderId, string $mode, string $operationKey): void
    {
        $this->authorizeChange($actorId, $orderId, $mode);
        // Recheck order status and root rights after the remote status GET.
        $this->linked($actorId, $orderId);
        if (preg_match('/\Amvp04:[1-9][0-9]{0,14}:[a-f0-9]{32}\z/D', $operationKey) !== 1
            || ! str_starts_with($operationKey, 'mvp04:'.$orderId.':')) {
            throw ValidationException::withMessages(['subscription' => 'Invalid request.']);
        }

        $reference = 'sales-order:'.$orderId;
        if ($mode === 'disable') {
            $this->lifecycle->disable($reference, $operationKey);
        } else {
            $this->lifecycle->enable($reference, $operationKey);
        }
    }

    public function authorizeReconcile(int $actorId, int $orderId): void
    {
        $this->uncertainKey($this->linked($actorId, $orderId));
    }

    public function reconcile(int $actorId, int $orderId): void
    {
        $key = $this->uncertainKey($this->linked($actorId, $orderId));
        // GET-only: never issue a second PATCH when the first outcome is unknown.
        $this->lifecycle->reconcileUpdate('sales-order:'.$orderId, $key);
    }

    private function linked(int $actorId, int $orderId): object
    {
        $this->access->handle($actorId);
        if ($orderId < 1) {
            throw ValidationException::withMessages(['subscription' => 'Invalid order.']);
        }

        $row = DB::table('sales_orders as orders')
            ->join('sales_fulfillment_outbox as intent', 'intent.sales_order_id', '=', 'orders.id')
            ->join('provisioning_order_links as links', 'links.sales_order_id', '=', 'orders.id')
            ->join('provisioning_subscriptions as sub', 'sub.id', '=', 'links.provisioning_subscription_id')
            ->where('orders.id', $orderId)
            ->whereNotNull('orders.plan_id')
            ->where('orders.status', 'fulfilled')
            ->where('intent.state', 'completed')
            ->where('sub.reference', 'sales-order:'.$orderId)
            ->whereNotNull('sub.remote_user_id')
            ->whereIn('sub.state', ['active', 'needs_review'])
            ->select('sub.id', 'sub.reference', 'sub.state', 'sub.remote_user_id', 'sub.last_operation_key')
            ->first();

        if ($row === null) {
            throw ValidationException::withMessages(['subscription' => 'Subscription unavailable.']);
        }

        return $row;
    }

    private function uncertainKey(object $linked): string
    {
        if ($linked->state !== 'needs_review') {
            throw ValidationException::withMessages(['subscription' => 'Nothing to verify.']);
        }

        $op = DB::table('provisioning_operations')
            ->where('subscription_id', $linked->id)
            ->where('idempotency_key', $linked->last_operation_key)
            ->first();

        if ($op === null || ! in_array($op->kind, ['enable', 'disable'], true)
            || ! in_array($op->state, ['reserved', 'needs_review'], true)) {
            throw new RuntimeException('Only uncertain status changes can be reconciled here.');
        }

        return (string) $op->idempotency_key;
    }
}

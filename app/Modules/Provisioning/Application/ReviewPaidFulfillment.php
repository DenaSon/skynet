<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * OP-03 read and queue-authorization boundary. Billing and actual provider
 * operations are exclusively verified by the existing FulfillPaidPlanOrder.
 */
final readonly class ReviewPaidFulfillment
{
    public function __construct(private AssertCatalogManagementAccess $access) {}

    /** @return list<object> */
    public function pending(int $actorId): array
    {
        $this->access->handle($actorId);

        return $this->base()->whereIn('intent.state', ['pending', 'processing', 'needs_review'])
            ->orderByDesc('orders.id')->limit(20)->get()->all();
    }

    public function detail(int $actorId, int $orderId): object
    {
        $this->access->handle($actorId);
        if ($orderId < 1) {
            throw ValidationException::withMessages(['order' => 'Invalid order.']);
        }

        $row = $this->base()->where('orders.id', $orderId)->first();
        if ($row === null) {
            throw ValidationException::withMessages(['order' => 'Order unavailable.']);
        }

        return $row;
    }

    public function authorizeRequest(int $actorId, int $orderId, bool $reconcile): object
    {
        $row = $this->detail($actorId, $orderId);
        $expected = $reconcile ? ['needs_review', 'processing'] : ['pending'];
        if (! in_array($row->intent_state, $expected, true)) {
            throw ValidationException::withMessages(['order' => 'Order is no longer eligible.']);
        }

        if (! $reconcile && (config('services.remnawave.writes_enabled') !== true
            || config('services.remnawave.squad_bindings_live_verified') !== true)) {
            throw ValidationException::withMessages(['order' => 'Provider activation disabled.']);
        }

        return $row;
    }

    private function base(): Builder
    {
        return DB::table('sales_orders as orders')
            ->join('sales_fulfillment_outbox as intent', 'intent.sales_order_id', '=', 'orders.id')
            ->whereNotNull('orders.plan_id')
            ->where('orders.status', 'paid')
            ->select(
                'orders.id',
                'orders.product_name',
                'orders.total_price_rials',
                'orders.duration_days',
                'orders.traffic_gb',
                'intent.state as intent_state',
                'intent.attempts as attempt_count',
            );
    }
}

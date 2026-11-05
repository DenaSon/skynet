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

        return $this->base()->where('orders.status', 'paid')
            ->whereIn('intent.state', ['pending', 'processing', 'needs_review'])
            ->orderByDesc('orders.id')->limit(20)->get()->all();
    }

    /**
     * A paid order is overdue only after the existing 15-minute fulfillment
     * threshold. A processing order uses its last update, not its creation.
     *
     * @return array{review:int,overdue:int}
     */
    public function attentionCounts(int $actorId): array
    {
        $this->access->handle($actorId);

        $query = $this->attentionQuery();

        return [
            'review' => (int) (clone $query)->where('intent.state', 'needs_review')->count(),
            'overdue' => (int) (clone $query)->whereIn('intent.state', ['pending', 'processing'])->count(),
        ];
    }

    /**
     * Ordered and paginated: older review cases cannot disappear behind recent
     * orders, even when more than twenty cases are outstanding.
     *
     * @return array{total:int,page:int,pages:int,items:list<object>}
     */
    public function attention(int $actorId, string $requestedPage): array
    {
        $this->access->handle($actorId);
        if (preg_match('/\\A[1-9][0-9]{0,3}\\z/D', $requestedPage) !== 1
            || (int) $requestedPage > 1000) {
            throw ValidationException::withMessages(['page' => 'Invalid page.']);
        }

        $query = $this->attentionQuery();
        $total = (int) (clone $query)->count();
        $pages = max(1, intdiv($total + 19, 20));
        $page = min((int) $requestedPage, $pages);
        $items = $query
            ->orderByRaw("CASE WHEN intent.state = 'needs_review' THEN 0 ELSE 1 END")
            ->orderBy('orders.id')
            ->offset(($page - 1) * 20)->limit(20)->get()->all();

        return compact('total', 'page', 'pages', 'items');
    }

    private function attentionQuery(): Builder
    {
        $threshold = now()->subMinutes(15);

        return $this->base()->where('orders.status', 'paid')
            ->where(function (Builder $query) use ($threshold): void {
                $query->where('intent.state', 'needs_review')
                    ->orWhere(function (Builder $query) use ($threshold): void {
                        $query->where('intent.state', 'pending')
                            ->where('intent.created_at', '<', $threshold);
                    })
                    ->orWhere(function (Builder $query) use ($threshold): void {
                        $query->where('intent.state', 'processing')
                            ->where('intent.updated_at', '<', $threshold);
                    });
            });
    }

    /** @return list<object> */
    public function completed(int $actorId): array
    {
        $this->access->handle($actorId);

        return $this->base()->where('orders.status', 'fulfilled')
            ->where('intent.state', 'completed')
            ->orderByDesc('orders.id')->limit(20)->get()->all();
    }

    public function detail(int $actorId, int $orderId): object
    {
        $this->access->handle($actorId);
        if ($orderId < 1) {
            throw ValidationException::withMessages(['order' => 'Invalid order.']);
        }

        $row = $this->base()->where('orders.id', $orderId)->first();
        if ($row === null || ! (
            ($row->order_status === 'paid' && in_array($row->intent_state, ['pending', 'processing', 'needs_review'], true))
            || ($row->order_status === 'fulfilled' && $row->intent_state === 'completed')
        )) {
            throw ValidationException::withMessages(['order' => 'Order unavailable.']);
        }

        return $row;
    }

    public function authorizeRequest(int $actorId, int $orderId, bool $reconcile): object
    {
        $row = $this->detail($actorId, $orderId);
        $expected = $reconcile ? ['needs_review', 'processing'] : ['pending'];
        if ($row->order_status !== 'paid' || ! in_array($row->intent_state, $expected, true)) {
            throw ValidationException::withMessages(['order' => 'Order is no longer eligible.']);
        }

        if ($reconcile && $row->create_operation_id === null) {
            throw ValidationException::withMessages(['order' => 'No recorded provider operation to reconcile.']);
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
            ->leftJoin('provisioning_operations as operation', 'operation.idempotency_key', '=', 'intent.event_key')
            ->whereNotNull('orders.plan_id')
            ->select(
                'orders.id',
                'orders.status as order_status',
                'orders.product_name',
                'orders.total_price_rials',
                'orders.duration_days',
                'orders.traffic_gb',
                'intent.state as intent_state',
                'intent.attempts as attempt_count',
                'operation.id as create_operation_id',
            );
    }
}

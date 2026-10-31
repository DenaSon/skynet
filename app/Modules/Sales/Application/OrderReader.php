<?php

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\ResolveSalesBuyer;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrderReader
{
    /**
     * @return list<OrderDetails>
     */
    public function recent(int $buyerId): array
    {
        app(ResolveSalesBuyer::class)->isReseller($buyerId);

        return DB::table('sales_orders')->where('buyer_user_id', $buyerId)
            ->orderByDesc('id')->limit(10)->get()
            ->map(fn (object $row): OrderDetails => $this->map($row))
            ->all();
    }

    /** @return list<OrderDetails> */
    public function recentPlans(int $buyerId): array
    {
        app(ResolveSalesBuyer::class)->isReseller($buyerId);

        return DB::table('sales_orders')->where('buyer_user_id', $buyerId)
            ->whereNotNull('plan_id')->orderByDesc('id')->limit(10)->get()
            ->map(fn (object $row): OrderDetails => $this->map($row))->all();
    }

    /** @return list<OrderDetails> */
    public function recentNewPlans(int $buyerId): array
    {
        app(ResolveSalesBuyer::class)->isReseller($buyerId);

        return DB::table('sales_orders as orders')
            ->where('orders.buyer_user_id', $buyerId)
            ->whereNotNull('orders.plan_id')
            ->whereNotExists(static function (Builder $query): void {
                $query->selectRaw('1')->from('sales_renewal_orders as renewals')
                    ->whereColumn('renewals.sales_order_id', 'orders.id');
            })
            ->orderByDesc('orders.id')->limit(10)->select('orders.*')->get()
            ->map(fn (object $row): OrderDetails => $this->map($row))->all();
    }

    /** Reject renewals even when a caller supplies a valid owned order ID. */
    public function findNewPlan(int $buyerId, int $orderId): OrderDetails
    {
        $order = $this->findPlan($buyerId, $orderId);

        if (DB::table('sales_renewal_orders')->where('sales_order_id', $order->id)->exists()) {
            throw ValidationException::withMessages(['order' => 'Order is not available here.']);
        }

        return $order;
    }

    /**
     * Latest renewal of this exact owned source order. No unrelated renewal/order
     * can be selected through browser-supplied identifiers.
     */
    public function latestRenewalForSource(int $buyerId, int $sourceOrderId): ?OrderDetails
    {
        app(ResolveSalesBuyer::class)->isReseller($buyerId);

        if ($sourceOrderId < 1) {
            throw ValidationException::withMessages(['order' => 'Source order not found.']);
        }

        $row = DB::table('sales_renewal_orders as renewal')
            ->join('sales_orders as orders', 'orders.id', '=', 'renewal.sales_order_id')
            ->join('sales_orders as source', 'source.id', '=', 'renewal.source_order_id')
            ->where('renewal.source_order_id', $sourceOrderId)
            ->where('source.buyer_user_id', $buyerId)
            ->where('orders.buyer_user_id', $buyerId)
            ->orderByDesc('orders.id')
            ->select('orders.*')
            ->first();

        return $row === null ? null : $this->map($row);
    }

    /**
     * Reuse an already payable snapshot when the same selection is tapped again.
     * The explicit new-purchase route bypasses this lookup.
     */
    public function pendingForQuote(int $buyerId, PlanPriceQuote $quote): ?OrderDetails
    {
        app(ResolveSalesBuyer::class)->isReseller($buyerId);

        $row = DB::table('sales_orders')
            ->where('buyer_user_id', $buyerId)
            ->where('status', 'pending_payment')
            ->where('plan_id', $quote->planId)
            ->where('plan_revision', $quote->planRevision)
            ->where('pricing_revision', $quote->pricingRevision)
            ->where('duration_days', $quote->durationDays)
            ->where('traffic_gb', $quote->trafficGb)
            ->where('total_price_rials', $quote->totalPriceRials)
            ->orderByDesc('id')->first();

        return $row === null ? null : $this->map($row);
    }

    public function findPlan(int $buyerId, int $orderId): OrderDetails
    {
        $order = $this->find($buyerId, $orderId);
        if ($order->planId === null) {
            throw ValidationException::withMessages(['order' => 'Order not found.']);
        }

        return $order;
    }

    /**
     * Bounded, owner-scoped keyset pagination for future shared presenters.
     * Zero provider lookups; no cross-tenant rows are ever returned.
     */
    public function page(int $buyerId, int $limit = 20, ?int $beforeOrderId = null): OrderCursorPage
    {
        app(ResolveSalesBuyer::class)->isReseller($buyerId);

        if ($limit < 1 || $limit > 50 || ($beforeOrderId !== null && $beforeOrderId < 1)) {
            throw ValidationException::withMessages(['page' => 'Invalid order pagination.']);
        }

        $query = DB::table('sales_orders')->where('buyer_user_id', $buyerId);

        if ($beforeOrderId !== null) {
            $query->where('id', '<', $beforeOrderId);
        }

        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
        $hasNext = $rows->count() > $limit;
        $orders = $rows->take($limit)->map(fn (object $row): OrderDetails => $this->map($row))->all();

        return new OrderCursorPage(
            orders: array_values($orders),
            nextBeforeOrderId: $hasNext ? $orders[array_key_last($orders)]->id : null,
        );
    }

    public function find(int $buyerId, int $orderId): OrderDetails
    {
        app(ResolveSalesBuyer::class)->isReseller($buyerId);

        $row = DB::table('sales_orders')->where('buyer_user_id', $buyerId)
            ->where('id', $orderId)->first();

        if ($row === null) {
            throw ValidationException::withMessages(['order' => 'Order not found.']);
        }

        return $this->map($row);
    }

    public function byKey(int $buyerId, string $idempotencyKey): ?OrderDetails
    {
        app(ResolveSalesBuyer::class)->isReseller($buyerId);

        $row = DB::table('sales_orders')->where('buyer_user_id', $buyerId)
            ->where('idempotency_key', $idempotencyKey)->first();

        return $row === null ? null : $this->map($row);
    }

    private function map(object $row): OrderDetails
    {
        return new OrderDetails(
            id: (int) $row->id,
            buyerUserId: (int) $row->buyer_user_id,
            productSlug: (string) $row->product_slug,
            productName: (string) $row->product_name,
            durationDays: (int) $row->duration_days,
            trafficGb: $row->traffic_gb === null ? null : (int) $row->traffic_gb,
            publicPriceRials: (int) $row->public_price_rials,
            discountPercent: (int) $row->discount_percent,
            discountRials: (int) $row->discount_rials,
            totalPriceRials: (int) $row->total_price_rials,
            status: (string) $row->status,
            createdAt: (string) $row->created_at,
            planId: $row->plan_id === null ? null : (int) $row->plan_id,
            planSlug: ($row->plan_slug ?? null) === null ? null : (string) $row->plan_slug,
            planKind: ($row->plan_kind ?? null) === null ? null : (string) $row->plan_kind,
            planRevision: ($row->plan_revision ?? null) === null ? null : (int) $row->plan_revision,
            pricingRevision: ($row->pricing_revision ?? null) === null ? null : (int) $row->pricing_revision,
            internalSquadUuid: ($row->internal_squad_uuid ?? null) === null ? null : (string) $row->internal_squad_uuid,
        );
    }
}

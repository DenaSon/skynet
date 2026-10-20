<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Sales\Application\OrderDetails;
use App\Modules\Sales\Application\OrderReader;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * Internal F10-E1 read boundary. Delegates authentication/ownership to Sales.
 *
 * This class deliberately exposes NO provider IDs, subscription references,
 * credentials or mutating operations. Future Telegram/Web entry points must
 * authenticate the actor and re-check ownership in an F10-E2 use case.
 */
final readonly class SubscriptionOwnershipQuery
{
    public function __construct(private OrderReader $orders) {}

    public function page(int $buyerId, int $limit = 20, ?int $beforeOrderId = null): SubscriptionCursorPage
    {
        $orderPage = $this->orders->page($buyerId, $limit, $beforeOrderId);
        $links = $this->statesFor($orderPage->orders);
        $items = [];

        foreach ($orderPage->orders as $order) {
            $items[] = $this->summary($order, $links[$order->id] ?? null);
        }

        return new SubscriptionCursorPage($items, $orderPage->nextBeforeOrderId);
    }

    /**
     * Service directory includes only linked subscriptions, not Sales orders
     * awaiting payment or a remote CREATE. All pages and totals are owner-bound.
     */
    public function servicePage(int $buyerId, int $limit = 6, ?int $beforeOrderId = null): SubscriptionCursorPage
    {
        if ($limit < 1 || $limit > 50 || ($beforeOrderId !== null && $beforeOrderId < 1)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['page' => 'Invalid service pagination.']);
        }

        // Uses the existing OrderReader owner/active-account authority.
        $query = $this->serviceQuery($buyerId);
        if ($beforeOrderId !== null) {
            $query->where('orders.id', '<', $beforeOrderId);
        }

        $ids = $query->orderByDesc('orders.id')->limit($limit + 1)
            ->pluck('orders.id')->map(static fn (mixed $id): int => (int) $id)->all();
        $hasNext = count($ids) > $limit;
        $ids = array_slice($ids, 0, $limit);

        $orders = array_map(fn (int $id): OrderDetails => $this->orders->find($buyerId, $id), $ids);
        $states = $this->statesFor($orders);
        $items = array_map(fn (OrderDetails $order): SubscriptionSummary => $this->summary($order, $states[$order->id] ?? null), $orders);

        return new SubscriptionCursorPage($items, $hasNext ? end($ids) : null);
    }

    /** @return array{total:int, activeRecorded:int, needsReview:int} */
    public function serviceCounts(int $buyerId): array
    {
        // Force the existing active-buyer access check even when the query is empty.
        $this->orders->page($buyerId, 1);
        $row = $this->serviceQuery($buyerId)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN subscriptions.state = 'active' THEN 1 ELSE 0 END) AS active_recorded")
            ->selectRaw("SUM(CASE WHEN subscriptions.state = 'needs_review' THEN 1 ELSE 0 END) AS needs_review")
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'activeRecorded' => (int) ($row->active_recorded ?? 0),
            'needsReview' => (int) ($row->needs_review ?? 0),
        ];
    }

    private function serviceQuery(int $buyerId): Builder
    {
        return DB::table('sales_orders as orders')
            ->join('provisioning_order_links as links', 'links.sales_order_id', '=', 'orders.id')
            ->join('provisioning_subscriptions as subscriptions', 'subscriptions.id', '=', 'links.provisioning_subscription_id')
            ->where('orders.buyer_user_id', $buyerId)
            ->whereIn('orders.status', ['paid', 'fulfilled']);
    }

    public function detail(int $buyerId, int $orderId): SubscriptionDetails
    {
        // Owner-scoped lookup must happen BEFORE querying any provider mapping.
        $order = $this->orders->find($buyerId, $orderId);
        $links = $this->statesFor([$order]);
        $summary = $this->summary($order, $links[$orderId] ?? null);

        return new SubscriptionDetails(
            summary: $summary,
            canRequestLink: $summary->status === 'active',
        );
    }

    /**
     * @param  list<OrderDetails>  $orders
     * @return array<int, string>
     */
    private function statesFor(array $orders): array
    {
        if ($orders === []) {
            return [];
        }

        $ids = array_map(static fn (OrderDetails $order): int => $order->id, $orders);

        $rows = DB::table('provisioning_order_links as links')
            ->join('provisioning_subscriptions as subscriptions', 'subscriptions.id', '=', 'links.provisioning_subscription_id')
            ->whereIn('links.sales_order_id', $ids)
            ->select('links.sales_order_id', 'subscriptions.state')
            ->get();

        $states = [];

        foreach ($rows as $row) {
            $states[(int) $row->sales_order_id] = (string) $row->state;
        }

        return $states;
    }

    private function summary(OrderDetails $order, ?string $providerState): SubscriptionSummary
    {
        // No order is eligible for provider fulfillment merely by existing.
        if ($order->status === 'pending_payment') {
            if ($providerState !== null) {
                throw new UnexpectedValueException('An unpaid order has a provider subscription mapping.');
            }

            $status = 'pending_payment';
        } elseif (in_array($order->status, ['cancelled', 'refunded', 'failed'], true)) {
            // Fail closed. Previously paid orders must never provide access after reversal.
            $status = 'failed';
        } elseif (in_array($order->status, ['paid', 'fulfilled'], true)) {
            $status = match ($providerState) {
                null, 'reserved' => 'provisioning',
                'active' => 'active',
                'needs_review' => 'needs_review',
                'failed' => 'failed',
                default => throw new UnexpectedValueException('Unrecognized provisioning state.'),
            };
        } else {
            throw new UnexpectedValueException('Unrecognized Sales order status.');
        }

        return new SubscriptionSummary(
            orderId: $order->id,
            productName: $order->productName,
            durationDays: $order->durationDays,
            trafficGb: $order->trafficGb,
            totalPriceRials: $order->totalPriceRials,
            status: $status,
            orderedAt: $order->createdAt,
        );
    }
}

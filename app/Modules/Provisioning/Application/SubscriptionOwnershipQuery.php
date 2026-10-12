<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Sales\Application\OrderDetails;
use App\Modules\Sales\Application\OrderReader;
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

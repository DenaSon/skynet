<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Provisioning\Application\FulfillPaidPlanOrder;
use Illuminate\Support\Facades\DB;

/** Internal local evidence only. Never executes provider operations or accepts UI payment claims. */
final class ReadReferralPurchase
{
    public function buyerId(int $orderId, string $start): ?int
    {
        $order = DB::table('sales_orders')->where('id', $orderId)->first();
        if ($order === null || $order->status !== 'fulfilled' || $order->plan_id === null
            || (int) $order->total_price_rials < 1 || (int) $order->discount_percent !== 0
            || $order->created_at < $start
            || DB::table('sales_renewal_orders')->where('sales_order_id', $orderId)->exists()) {
            return null;
        }
        $completion = DB::table('sales_fulfillment_outbox')->where('sales_order_id', $orderId)
            ->where('event_key', 'sales-order:'.$orderId.':create')->where('state', 'completed')->first();
        $verified = DB::table('provisioning_order_links as links')
            ->join('provisioning_subscriptions as sub', 'sub.id', '=', 'links.provisioning_subscription_id')
            ->join('provisioning_operations as op', 'op.subscription_id', '=', 'sub.id')
            ->where('links.sales_order_id', $orderId)->where('sub.reference', 'sales-order:'.$orderId)
            ->whereNotNull('sub.remote_user_id')->where('sub.remote_user_id', '>', 0)
            ->where('op.idempotency_key', 'sales-order:'.$orderId.':create')
            ->where('op.kind', 'create')->where('op.state', 'completed')->exists();
        if ($completion === null || ! $verified) {
            return null;
        }
        // First successful activation, not first job to happen to run. Failed/unpaid
        // earlier orders do not disqualify; old successful purchases do.
        $earlier = DB::table('sales_orders as orders')
            ->leftJoin('sales_fulfillment_outbox as outbox', 'outbox.sales_order_id', '=', 'orders.id')
            ->where('orders.buyer_user_id', $order->buyer_user_id)->where('orders.status', 'fulfilled')
            ->where('orders.id', '!=', $orderId)
            ->where(function ($query) use ($completion, $orderId): void {
                $query->whereNull('outbox.id')->orWhere('outbox.updated_at', '<', $completion->updated_at)
                    ->orWhere(function ($query) use ($completion, $orderId): void {
                        $query->where('outbox.updated_at', $completion->updated_at)->where('orders.id', '<', $orderId);
                    });
            })->exists();
        if ($earlier) {
            return null;
        }
        // Reuse the existing exact bank/wallet ledger attestation. Status alone is insufficient.
        app(FulfillPaidPlanOrder::class)->assertSettlement($order);

        return (int) $order->buyer_user_id;
    }
}

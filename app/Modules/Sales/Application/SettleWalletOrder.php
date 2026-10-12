<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Billing\Application\DebitWalletForOrder;
use App\Modules\Billing\Application\VerifyWalletOrderDebit;
use App\Modules\Identity\Application\AuthorizeSubscriptionViewer;
use App\Modules\Identity\Application\ResolveSalesBuyer;
use App\Modules\Identity\Application\SubscriptionViewer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * F11-A1: internal, feature-disabled-by-default purchase settlement.
 *
 * The authenticated customer may only settle THEIR pending order using an
 * already credited reseller wallet. Wallet debit, paid status and one outbox
 * intent commit atomically. No provider/queue/Telegram side effects here.
 */
final readonly class SettleWalletOrder
{
    public function __construct(
        private AuthorizeSubscriptionViewer $authorize,
        private ResolveSalesBuyer $buyers,
        private DebitWalletForOrder $debit,
        private VerifyWalletOrderDebit $verifyDebit,
        private OrderReader $orders,
    ) {}

    public function handle(SubscriptionViewer $viewer, int $orderId): OrderDetails
    {
        if (config('sales.wallet_settlement_enabled') !== true) {
            throw new AuthorizationException;
        }

        if ($orderId < 1) {
            throw ValidationException::withMessages(['order' => 'Invalid order.']);
        }

        return DB::transaction(function () use ($viewer, $orderId): OrderDetails {
            $buyerId = $this->authorize->userId($viewer);

            if (! $this->buyers->isReseller($buyerId)) {
                throw new AuthorizationException;
            }

            $order = DB::table('sales_orders')
                ->where('id', $orderId)
                ->where('buyer_user_id', $buyerId)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                throw ValidationException::withMessages(['order' => 'Order not found.']);
            }

            if ($order->status === 'paid') {
                // A mismatched/externally marked paid order is NOT idempotent.
                $settlement = DB::table('sales_order_settlements')
                    ->where('sales_order_id', $orderId)->first();
                $outbox = DB::table('sales_fulfillment_outbox')
                    ->where('sales_order_id', $orderId)->first();
                // The journal and its exact balanced postings are Billing-owned.
                $balanced = $settlement !== null
                    && $this->verifyDebit->handle(
                        $buyerId, $orderId, (int) $settlement->billing_journal_id,
                        (int) $order->total_price_rials,
                    );

                if ($settlement === null || $outbox === null || ! $balanced || $settlement->method !== 'reseller_wallet'
                    || (int) $settlement->amount_rials !== (int) $order->total_price_rials
                    || $outbox->event_key !== 'sales-order:'.$orderId.':create') {
                    throw ValidationException::withMessages(['order' => 'Paid order requires reconciliation.']);
                }

                return $this->orders->find($buyerId, $orderId);
            }

            if ($order->status !== 'pending_payment') {
                throw ValidationException::withMessages(['order' => 'Order is not payable.']);
            }

            $amount = (int) $order->total_price_rials;

            // Billing locks the wallet and posts exactly two entries.
            // Everything rolls back if any subsequent Sales write fails.
            $journalId = $this->debit->handle($buyerId, $orderId, $amount);

            DB::table('sales_order_settlements')->insert([
                'sales_order_id' => $orderId,
                'billing_journal_id' => $journalId,
                'method' => 'reseller_wallet',
                'amount_rials' => $amount,
                'settled_at' => now(),
            ]);

            $changed = DB::table('sales_orders')
                ->where('id', $orderId)
                ->where('buyer_user_id', $buyerId)
                ->where('status', 'pending_payment')
                ->update(['status' => 'paid', 'updated_at' => now()]);

            if ($changed !== 1) {
                throw ValidationException::withMessages(['order' => 'Order status changed; nothing was charged.']);
            }

            DB::table('sales_fulfillment_outbox')->insert([
                'sales_order_id' => $orderId,
                'event_key' => 'sales-order:'.$orderId.':create',
                'state' => 'pending',
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->orders->find($buyerId, $orderId);
        }, 3);
    }
}

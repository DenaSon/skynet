<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Interfaces\Telegram\Notifications\PaymentNotificationOutbox;
use App\Modules\Billing\Application\DebitWalletForOrder;
use App\Modules\Billing\Application\VerifyWalletOrderDebit;
use App\Modules\Identity\Application\AuthorizeSubscriptionViewer;
use App\Modules\Identity\Application\ResolveSalesBuyer;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Provisioning\Application\CheckPlanSquadReadiness;
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
        private PaymentNotificationOutbox $notifications,
        private CheckPlanSquadReadiness $squads,
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

            // Existing reseller checkout and ordinary active members share
            // the same ledger, but only managed plans allow member payment.
            $reseller = $this->buyers->isReseller($buyerId);

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

                $renewal = DB::table('sales_renewal_orders')->where('sales_order_id', $orderId)->exists();
                if ($settlement === null || $outbox === null || ! $balanced
                    || ! in_array($settlement->method, ['reseller_wallet', 'customer_wallet'], true)
                    || (int) $settlement->amount_rials !== (int) $order->total_price_rials
                    || $outbox->event_key !== 'sales-order:'.$orderId.($renewal ? ':renew' : ':create')) {
                    throw ValidationException::withMessages(['order' => 'Paid order requires reconciliation.']);
                }

                return $this->orders->find($buyerId, $orderId);
            }

            if ($order->status !== 'pending_payment') {
                throw ValidationException::withMessages(['order' => 'Order is not payable.']);
            }

            if (! $reseller && $order->plan_id === null) {
                throw new AuthorizationException;
            }
            $renewal = DB::table('sales_renewal_orders')->where('sales_order_id', $orderId)->first();
            if ($renewal !== null && config('sales.renewal_enabled', false) !== true) {
                throw ValidationException::withMessages(['activation' => 'Renewal payment is not enabled.']);
            }
            if ($renewal !== null && ! DB::table('sales_orders as source')
                ->join('provisioning_order_links as links', 'links.sales_order_id', '=', 'source.id')
                ->join('provisioning_subscriptions as sub', 'sub.id', '=', 'links.provisioning_subscription_id')
                ->where('source.id', $renewal->source_order_id)
                ->where('source.buyer_user_id', $buyerId)->where('source.status', 'fulfilled')
                ->where('sub.id', $renewal->provisioning_subscription_id)->where('sub.state', 'active')
                ->exists()) {
                throw ValidationException::withMessages(['activation' => 'Original subscription is not available.']);
            }
            if ($order->plan_id !== null) {
                // Legacy reseller settlements retain their original contract.
                // Customer purchases may debit only when automatic delivery
                // and customer notices are enabled. No network call here.
                if (config('sales.auto_fulfillment_enabled') !== true
                    || config('services.remnawave.writes_enabled') !== true
                    || config('services.remnawave.squad_bindings_live_verified') !== true
                    || config('telegram_notifications.enabled') !== true
                    || config('telegram.sales.enabled') !== true
                    || ! is_string(config('telegram.sales.token'))
                    || config('telegram.sales.token') === ''
                    || config('telegram.sales.token') === config('nutgram.token')) {
                    throw ValidationException::withMessages(['activation' => 'Automatic activation and customer notices are not ready.']);
                }
                $this->squads->assertOrderReady(
                    (int) $order->plan_id, (int) $order->plan_revision, (string) $order->internal_squad_uuid,
                );
            }
            // A pending/approved bank receipt must never race a wallet debit;
            // rejected receipts are eligible for the alternative full payment.
            if (DB::table('sales_bank_payment_claims')
                ->where('sales_order_id', $orderId)->whereIn('status', ['submitted', 'approved'])->exists()
                || DB::table('sales_bank_settlements')->where('sales_order_id', $orderId)->exists()) {
                throw ValidationException::withMessages(['payment' => 'Bank payment already submitted; cannot use wallet.']);
            }

            $amount = (int) $order->total_price_rials;

            // Billing locks the wallet and posts exactly two entries.
            // Everything rolls back if any subsequent Sales write fails.
            $journalId = $this->debit->handle($buyerId, $orderId, $amount);

            DB::table('sales_order_settlements')->insert([
                'sales_order_id' => $orderId,
                'billing_journal_id' => $journalId,
                'method' => $order->plan_id === null ? 'reseller_wallet' : 'customer_wallet',
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
                'event_key' => 'sales-order:'.$orderId.($renewal === null ? ':create' : ':renew'),
                'state' => 'pending',
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($order->plan_id !== null) {
                // Dispatch only after verified settlement + fulfillment intent
                // have been committed. No provider operation in a bot callback.
                $this->notifications->walletSettled($orderId, $buyerId);
            }

            return $this->orders->find($buyerId, $orderId);
        }, 3);
    }
}

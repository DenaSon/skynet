<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Interfaces\Telegram\Notifications\PaymentNotificationOutbox;
use App\Modules\Billing\Application\VerifyWalletOrderDebit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * SB-05: internal, explicitly invoked consumer of the EXISTING paid-order outbox.
 * Never dispatched by a webhook, payment callback, cron or Telegram handler.
 * A single uncertain remote write permanently blocks automatic replay.
 */
final readonly class FulfillPaidPlanOrder
{
    public function __construct(
        private CheckPlanSquadReadiness $squads,
        private VerifyWalletOrderDebit $wallet,
        private RemnawaveSubscriptionLifecycle $lifecycle,
        private PaymentNotificationOutbox $notifications,
    ) {}

    public function handle(int $orderId): int
    {
        if (config('services.remnawave.writes_enabled') !== true
            || config('services.remnawave.squad_bindings_live_verified') !== true) {
            throw new RuntimeException('Paid-order provisioning is disabled.');
        }

        try {
            $order = $this->claim($orderId);
        } catch (ValidationException $error) {
            $this->holdUnreadyOrder($orderId);
            throw new RuntimeException('Managed-plan snapshot or verified Squad binding is not ready; operator review required.', 0, $error);
        }
        $reference = self::reference($orderId);

        try {
            $remoteId = $this->lifecycle->create(
                $reference,
                self::operationKey($orderId),
                now()->utc()->addDays((int) $order->duration_days)->format('Y-m-d\TH:i:s\Z'),
                $order->traffic_gb === null ? 0 : (int) $order->traffic_gb * 1073741824,
                [(string) $order->internal_squad_uuid],
            );

            return $this->complete($orderId, $remoteId);
        } catch (Throwable $error) {
            $this->needsReview($orderId);

            throw new RuntimeException('Provisioning outcome requires operator reconciliation; do not retry CREATE.', 0, $error);
        }
    }

    /**
     * Explicit operator-only recovery: remote GET, never another provider POST.
     * The existing lifecycle validates both remote username AND ownership marker
     * and matches quota, expiry, status and Squad before accepting the result.
     */
    public function reconcile(int $orderId): int
    {
        $this->assertOrderId($orderId);
        $state = DB::transaction(function () use ($orderId): string {
            $order = DB::table('sales_orders')->where('id', $orderId)->lockForUpdate()->first();
            $outbox = DB::table('sales_fulfillment_outbox')->where('sales_order_id', $orderId)->lockForUpdate()->first();

            if ($order === null || $outbox === null || $order->status !== 'paid'
                || ! in_array($outbox->state, ['processing', 'needs_review'], true)
                || $outbox->event_key !== self::operationKey($orderId)) {
                throw new RuntimeException('Order is not eligible for explicit provisioning reconciliation.');
            }

            $this->assertSettlement($order);

            $local = DB::table('provisioning_subscriptions')->where('reference', self::reference($orderId))->first();
            $op = DB::table('provisioning_operations')->where('idempotency_key', self::operationKey($orderId))->first();

            if ($local === null || $op === null || (int) $op->subscription_id !== (int) $local->id
                || $op->kind !== 'create'
                || ! in_array($op->state, ['reserved', 'needs_review', 'completed'], true)) {
                throw new RuntimeException('No recorded create operation to reconcile; operator investigation required.');
            }

            return (string) $op->state;
        });

        $remoteId = $state === 'completed'
            ? $this->lifecycle->subscription(self::reference($orderId))->id
            : $this->lifecycle->reconcileCreate(self::reference($orderId), self::operationKey($orderId));

        return $this->complete($orderId, $remoteId);
    }

    private function claim(int $orderId): object
    {
        $this->assertOrderId($orderId);

        return DB::transaction(function () use ($orderId): object {
            $order = DB::table('sales_orders')->where('id', $orderId)->lockForUpdate()->first();
            $outbox = DB::table('sales_fulfillment_outbox')->where('sales_order_id', $orderId)->lockForUpdate()->first();

            if ($order === null || $order->status !== 'paid' || $outbox === null
                || $outbox->state !== 'pending' || $outbox->event_key !== self::operationKey($orderId)) {
                throw new RuntimeException('Order is unpaid, already claimed, or lacks its immutable fulfillment intent.');
            }

            if ($order->plan_id === null || $order->plan_revision === null || $order->pricing_revision === null
                || ! in_array($order->plan_kind, ['volume', 'hybrid', 'time'], true)
                || (int) $order->duration_days < 1 || (int) $order->duration_days > 3650
                || (int) $order->total_price_rials < 1
                || ($order->traffic_gb === null && $order->plan_kind !== 'time')
                || ($order->traffic_gb !== null && (int) $order->traffic_gb < 1)
                || ! is_string($order->internal_squad_uuid)) {
                throw ValidationException::withMessages(['plan' => 'Invalid managed-plan snapshot.']);
            }
            $this->squads->assertOrderReady(
                (int) $order->plan_id, (int) $order->plan_revision, $order->internal_squad_uuid,
            );

            $this->assertSettlement($order);

            if (DB::table('provisioning_order_links')->where('sales_order_id', $orderId)->exists()
                || DB::table('provisioning_subscriptions')->where('reference', self::reference($orderId))->exists()
                || DB::table('provisioning_operations')->where('idempotency_key', self::operationKey($orderId))->exists()) {
                throw new RuntimeException('Provisioning already reserved; manual reconciliation required.');
            }

            DB::table('sales_fulfillment_outbox')->where('id', $outbox->id)->update([
                'state' => 'processing',
                'attempts' => (int) $outbox->attempts + 1,
                'updated_at' => now(),
            ]);

            return $order;
        });
    }

    /** Verify an actual, settled Billing journal, never the Sales status alone. */
    private function assertSettlement(object $order): void
    {
        $orderId = (int) $order->id;
        $amount = (int) $order->total_price_rials;
        $wallet = DB::table('sales_order_settlements')->where('sales_order_id', $orderId)->first();
        $bank = DB::table('sales_bank_settlements')->where('sales_order_id', $orderId)->first();

        if (($wallet === null) === ($bank === null)) {
            throw new RuntimeException('Order has no unique verified settlement.');
        }

        if ($wallet !== null) {
            if (! in_array($wallet->method, ['reseller_wallet', 'customer_wallet'], true)
                || (int) $wallet->amount_rials !== $amount
                || ! $this->wallet->handle((int) $order->buyer_user_id, $orderId, (int) $wallet->billing_journal_id, $amount)) {
                throw new RuntimeException('Wallet settlement integrity check failed.');
            }

            return;
        }

        if ($bank === null) {
            throw new RuntimeException('Bank settlement is missing.');
        }

        $journal = DB::table('billing_journals')->where('id', $bank->billing_journal_id)
            ->where('kind', 'direct_bank_sale')
            ->where('idempotency_key', 'direct-bank:order:'.$orderId)->first();
        $claim = DB::table('sales_bank_payment_claims')->where('id', $bank->payment_claim_id)
            ->where('sales_order_id', $orderId)->where('buyer_user_id', $order->buyer_user_id)
            ->where('status', 'approved')->first();
        $verified = DB::table('billing_bank_reference_claims')
            ->where('source_type', 'sales_order')->where('source_id', $orderId)
            ->where('payment_reference', $bank->payment_reference)->exists();
        $postings = DB::table('billing_postings')->where('journal_id', $bank->billing_journal_id)->get();

        if ($journal === null || $claim === null || ! $verified
            || (int) $bank->amount_rials !== $amount || $postings->count() !== 2
            || ! $postings->contains(fn (object $p): bool => $p->account_code === 'cash_clearing'
                && $p->side === 'debit' && $p->wallet_id === null && (int) $p->amount_rials === $amount)
            || ! $postings->contains(fn (object $p): bool => $p->account_code === 'order_liability'
                && $p->side === 'credit' && $p->wallet_id === null && (int) $p->amount_rials === $amount)) {
            throw new RuntimeException('Bank settlement integrity check failed.');
        }
    }

    private function complete(int $orderId, int $remoteId): int
    {
        return DB::transaction(function () use ($orderId, $remoteId): int {
            $order = DB::table('sales_orders')->where('id', $orderId)->lockForUpdate()->first();
            $outbox = DB::table('sales_fulfillment_outbox')->where('sales_order_id', $orderId)->lockForUpdate()->first();
            $local = DB::table('provisioning_subscriptions')->where('reference', self::reference($orderId))->lockForUpdate()->first();
            $op = DB::table('provisioning_operations')->where('idempotency_key', self::operationKey($orderId))->first();

            if ($order === null || $order->status !== 'paid' || $outbox === null
                || ! in_array($outbox->state, ['processing', 'needs_review'], true)
                || $local === null || $local->state !== 'active' || (int) $local->remote_user_id !== $remoteId
                || $op === null || (int) $op->subscription_id !== (int) $local->id || $op->state !== 'completed') {
                throw new RuntimeException('Fulfillment mapping requires operator review.');
            }

            $this->assertSettlement($order);
            $existing = DB::table('provisioning_order_links')->where('sales_order_id', $orderId)->first();
            if ($existing !== null && (int) $existing->provisioning_subscription_id !== (int) $local->id) {
                throw new RuntimeException('Order already links to another provider subscription.');
            }

            if ($existing === null) {
                DB::table('provisioning_order_links')->insert([
                    'sales_order_id' => $orderId,
                    'provisioning_subscription_id' => $local->id,
                    'created_at' => now(),
                ]);
            }

            DB::table('sales_orders')->where('id', $orderId)->update(['status' => 'fulfilled', 'updated_at' => now()]);
            DB::table('sales_fulfillment_outbox')->where('id', $outbox->id)->update(['state' => 'completed', 'updated_at' => now()]);

            // No success intent until both the verified provider response AND
            // the order-to-owned-subscription linkage are committed.
            $this->notifications->activated($orderId, (int) $order->buyer_user_id);

            return $remoteId;
        });
    }

    /** A paid order blocked before CREATE needs a human, not repeated dispatch. */
    private function holdUnreadyOrder(int $orderId): void
    {
        DB::transaction(function () use ($orderId): void {
            $order = DB::table('sales_orders')->where('id', $orderId)->lockForUpdate()->first();
            $outbox = DB::table('sales_fulfillment_outbox')->where('sales_order_id', $orderId)->lockForUpdate()->first();
            if ($order === null || $order->status !== 'paid' || $outbox === null || $outbox->state !== 'pending'
                || DB::table('provisioning_operations')->where('idempotency_key', self::operationKey($orderId))->exists()) {
                return;
            }
            $this->assertSettlement($order);
            DB::table('sales_fulfillment_outbox')->where('id', $outbox->id)->update([
                'state' => 'needs_review', 'updated_at' => now(),
            ]);
            $this->notifications->planBlocked($orderId);
        });
    }

    private function needsReview(int $orderId): void
    {
        DB::table('sales_fulfillment_outbox')->where('sales_order_id', $orderId)
            ->where('state', 'processing')->update(['state' => 'needs_review', 'updated_at' => now()]);
    }

    private function assertOrderId(int $orderId): void
    {
        if ($orderId < 1) {
            throw new RuntimeException('Invalid paid order identifier.');
        }
    }

    private static function reference(int $orderId): string
    {
        return 'sales-order:'.$orderId;
    }

    private static function operationKey(int $orderId): string
    {
        return self::reference($orderId).':create';
    }
}

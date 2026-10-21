<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Interfaces\Telegram\Notifications\PaymentNotificationOutbox;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * REN-02: single absolute provider UPDATE for one genuinely settled renewal.
 * No CREATE, no subscription/link duplication and no automatic uncertain retry.
 */
final readonly class FulfillPaidRenewalOrder
{
    public function __construct(
        private FulfillPaidPlanOrder $paid,
        private CheckPlanSquadReadiness $squads,
        private RemnawaveSubscriptionLifecycle $lifecycle,
        private PaymentNotificationOutbox $notices,
    ) {}

    public function handle(int $orderId): int
    {
        if (config('sales.renewal_enabled', false) !== true
            || config('sales.auto_fulfillment_enabled') !== true
            || config('services.remnawave.writes_enabled') !== true
            || config('services.remnawave.squad_bindings_live_verified') !== true) {
            throw new RuntimeException('Renewal fulfillment is disabled.');
        }

        $source = $this->claim($orderId);

        try {
            $remote = $this->lifecycle->subscription($source->reference);
            if (! in_array($remote->status, ['ACTIVE', 'LIMITED', 'EXPIRED'], true)
                || $remote->trafficLimitStrategy !== 'NO_RESET'
                || $remote->activeInternalSquadUuids !== [strtolower((string) $source->internal_squad_uuid)]
                || $remote->id !== (int) $source->remote_user_id) {
                throw new RuntimeException('Remote renewal target differs; manual inspection required.');
            }

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $oldExpiry = new DateTimeImmutable($remote->expireAt);
            $base = $oldExpiry > $now ? $oldExpiry : $now;
            $expiry = $base->modify('+'.(int) $source->duration_days.' days')->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
            $gb = $source->traffic_gb === null ? null : (int) $source->traffic_gb;
            if (($gb === null) !== ($remote->trafficLimitBytes === 0)
                || ($gb !== null && $gb > intdiv(PHP_INT_MAX - $remote->trafficLimitBytes, 1073741824))) {
                throw new RuntimeException('Incompatible or overflowing quota; manual inspection required.');
            }

            // Preserve every consumed byte: credit purchased GB to the absolute
            // existing cap. Unlimited time plans remain unlimited (zero).
            $limit = $gb === null ? 0 : $remote->trafficLimitBytes + $gb * 1073741824;
            DB::transaction(function () use ($source, $orderId, $expiry, $limit): void {
                $row = DB::table('sales_renewal_orders')->where('sales_order_id', $orderId)->lockForUpdate()->first();
                $sub = DB::table('provisioning_subscriptions')->where('id', $source->subscription_id)->lockForUpdate()->first();
                $intent = DB::table('sales_fulfillment_outbox')->where('sales_order_id', $orderId)->lockForUpdate()->first();
                if ($row === null || $row->target_expire_at !== null || $row->target_traffic_limit_bytes !== null
                    || $sub === null || $sub->state !== 'active' || (int) $sub->remote_user_id !== (int) $source->remote_user_id
                    || $intent === null || $intent->state !== 'processing') {
                    throw new RuntimeException('Concurrent renewal or provider operation; operator review required.');
                }
                DB::table('sales_renewal_orders')->where('sales_order_id', $orderId)->update([
                    'target_expire_at' => $expiry,
                    'target_traffic_limit_bytes' => $limit,
                    'updated_at' => now(),
                ]);
            });

            $remoteId = $this->lifecycle->renew($source->reference, self::key($orderId), $expiry, $limit);

            return $this->complete($orderId, $remoteId);
        } catch (Throwable $error) {
            $this->needsReview($orderId);
            throw new RuntimeException('Renewal outcome requires manual reconciliation; do not retry UPDATE.', 0, $error);
        }
    }

    /** Root-only caller: reconcile exact frozen absolute targets with GET; no PATCH replay. */
    public function reconcile(int $orderId): int
    {
        $row = DB::table('sales_renewal_orders as renewal')
            ->join('sales_orders as orders', 'orders.id', '=', 'renewal.sales_order_id')
            ->join('sales_fulfillment_outbox as outbox', 'outbox.sales_order_id', '=', 'orders.id')
            ->join('provisioning_subscriptions as sub', 'sub.id', '=', 'renewal.provisioning_subscription_id')
            ->where('orders.id', $orderId)->where('orders.status', 'paid')
            ->whereIn('outbox.state', ['processing', 'needs_review'])
            ->where('outbox.event_key', self::key($orderId))
            ->first(['renewal.*', 'sub.reference']);
        if ($row === null || $row->target_expire_at === null || $row->target_traffic_limit_bytes === null) {
            throw new RuntimeException('No frozen renewal update to reconcile.');
        }

        $op = DB::table('provisioning_operations')->where('idempotency_key', self::key($orderId))->first();
        if ($op === null || (int) $op->subscription_id !== (int) $row->provisioning_subscription_id
            || $op->kind !== 'renewal') {
            throw new RuntimeException('No recorded remote renewal operation to reconcile.');
        }

        if ($op->state === 'completed') {
            $remote = $this->lifecycle->subscription((string) $row->reference);
            $id = $remote->id;
            if ((new DateTimeImmutable($remote->expireAt))->getTimestamp()
                    !== (new DateTimeImmutable($row->target_expire_at))->getTimestamp()
                || $remote->trafficLimitBytes !== (int) $row->target_traffic_limit_bytes
                || $remote->status !== 'ACTIVE') {
                throw new RuntimeException('Remote state diverged; operator investigation required.');
            }
        } else {
            // Internal lifecycle requires precise matching of both absolute
            // fields and ACTIVE status, and never issues another provider write.
            $id = $this->lifecycle->reconcileUpdate((string) $row->reference, self::key($orderId));
        }

        return $this->complete($orderId, $id);
    }

    /** @return object */
    private function claim(int $orderId): object
    {
        return DB::transaction(function () use ($orderId): object {
            $order = DB::table('sales_orders')->where('id', $orderId)->lockForUpdate()->first();
            $intent = DB::table('sales_fulfillment_outbox')->where('sales_order_id', $orderId)->lockForUpdate()->first();
            $mapping = DB::table('sales_renewal_orders')->where('sales_order_id', $orderId)->lockForUpdate()->first();
            if ($order === null || $intent === null || $mapping === null
                || $order->status !== 'paid' || $intent->state !== 'pending'
                || (int) $intent->attempts !== 0 || $intent->event_key !== self::key($orderId)
                || $mapping->target_expire_at !== null || $mapping->target_traffic_limit_bytes !== null) {
                throw new RuntimeException('Renewal already claimed, unpaid or unlinked.');
            }
            $this->paid->assertSettlement($order);

            $source = DB::table('sales_orders as source')
                ->join('provisioning_order_links as links', 'links.sales_order_id', '=', 'source.id')
                ->join('provisioning_subscriptions as sub', 'sub.id', '=', 'links.provisioning_subscription_id')
                ->where('source.id', $mapping->source_order_id)
                ->where('source.status', 'fulfilled')
                ->where('sub.id', $mapping->provisioning_subscription_id)
                ->where('sub.state', 'active')->whereNotNull('sub.remote_user_id')
                ->first(['source.id', 'source.buyer_user_id', 'source.plan_id',
                    'source.internal_squad_uuid', 'sub.id as subscription_id',
                    'sub.reference', 'sub.remote_user_id']);
            if ($source === null || (int) $source->buyer_user_id !== (int) $order->buyer_user_id
                || (int) $source->plan_id !== (int) $order->plan_id
                || $source->internal_squad_uuid !== $order->internal_squad_uuid
                || (int) $order->duration_days < 1 || (int) $order->duration_days > 3650
                || $order->plan_revision === null
                || ($order->traffic_gb !== null && (int) $order->traffic_gb < 1)) {
                throw new RuntimeException('Original service or immutable renewal terms changed.');
            }

            $this->squads->assertOrderReady(
                (int) $order->plan_id, (int) $order->plan_revision, (string) $order->internal_squad_uuid,
            );
            DB::table('sales_fulfillment_outbox')->where('id', $intent->id)->update([
                'state' => 'processing', 'attempts' => 1, 'updated_at' => now(),
            ]);

            return (object) [
                'reference' => (string) $source->reference,
                'subscription_id' => (int) $source->subscription_id,
                'remote_user_id' => (int) $source->remote_user_id,
                'internal_squad_uuid' => (string) $order->internal_squad_uuid,
                'duration_days' => (int) $order->duration_days,
                'traffic_gb' => $order->traffic_gb === null ? null : (int) $order->traffic_gb,
            ];
        }, 3);
    }

    private function complete(int $orderId, int $remoteId): int
    {
        return DB::transaction(function () use ($orderId, $remoteId): int {
            $order = DB::table('sales_orders')->where('id', $orderId)->lockForUpdate()->first();
            $intent = DB::table('sales_fulfillment_outbox')->where('sales_order_id', $orderId)->lockForUpdate()->first();
            $mapping = DB::table('sales_renewal_orders')->where('sales_order_id', $orderId)->lockForUpdate()->first();
            $sub = $mapping === null ? null : DB::table('provisioning_subscriptions')
                ->where('id', $mapping->provisioning_subscription_id)->lockForUpdate()->first();
            $op = DB::table('provisioning_operations')->where('idempotency_key', self::key($orderId))->first();
            if ($order === null || $order->status !== 'paid' || $intent === null
                || ! in_array($intent->state, ['processing', 'needs_review'], true)
                || $mapping === null || $mapping->target_expire_at === null || $mapping->target_traffic_limit_bytes === null
                || $sub === null || $sub->state !== 'active' || (int) $sub->remote_user_id !== $remoteId
                || $op === null || $op->state !== 'completed' || (int) $op->subscription_id !== (int) $sub->id
                || DB::table('provisioning_order_links')->where('sales_order_id', $orderId)->exists()) {
                throw new RuntimeException('Renewal completion requires operator inspection.');
            }
            $this->paid->assertSettlement($order);
            DB::table('sales_orders')->where('id', $orderId)->update(['status' => 'fulfilled', 'updated_at' => now()]);
            DB::table('sales_fulfillment_outbox')->where('id', $intent->id)->update([
                'state' => 'completed', 'updated_at' => now(),
            ]);
            $this->notices->renewed($orderId, (int) $order->buyer_user_id);

            return $remoteId;
        }, 3);
    }

    private function needsReview(int $orderId): void
    {
        DB::table('sales_fulfillment_outbox')->where('sales_order_id', $orderId)
            ->where('state', 'processing')->update(['state' => 'needs_review', 'updated_at' => now()]);
    }

    public static function key(int $orderId): string
    {
        return 'sales-order:'.$orderId.':renew';
    }
}

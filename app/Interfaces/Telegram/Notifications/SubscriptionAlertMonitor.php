<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Notifications;

use App\Modules\Provisioning\Application\RemnawaveSubscriptionLifecycle;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only Remnawave checks for locally owned, paid services.
 * All network calls happen outside SQL transactions; no provider writes.
 */
final readonly class SubscriptionAlertMonitor
{
    private const LOW_BYTES = 350 * 1024 * 1024;

    public function __construct(private RemnawaveSubscriptionLifecycle $lifecycle) {}

    public function scan(): int
    {
        if (config('telegram_notifications.enabled', false) !== true
            || config('telegram.sales.enabled', false) !== true
            || ! is_string(config('telegram.sales.token'))
            || config('telegram.sales.token') === ''
            || config('telegram.sales.token') === config('nutgram.token')) {
            return 0;
        }

        $checked = 0;
        DB::table('provisioning_order_links as links')
            ->join('provisioning_subscriptions as sub', 'sub.id', '=', 'links.provisioning_subscription_id')
            ->join('sales_orders as orders', 'orders.id', '=', 'links.sales_order_id')
            ->join('users as buyer', 'buyer.id', '=', 'orders.buyer_user_id')
            ->join('telegram_bot_contacts as contact', 'contact.user_id', '=', 'buyer.id')
            ->where('sub.state', 'active')
            ->whereIn('orders.status', ['paid', 'fulfilled'])
            ->where('buyer.is_active', true)
            ->where('contact.bot_key', 'sales')
            ->where('contact.delivery_status', 'reachable')
            ->select('links.id as link_id', 'orders.id as order_id', 'buyer.id as buyer_id')
            ->chunkById(40, function ($rows) use (&$checked): void {
                foreach ($rows as $row) {
                    $live = $this->live((int) $row->order_id, (int) $row->buyer_id);
                    if ($live === null) {
                        continue;
                    }

                    $this->record($live);
                    $checked++;
                }
            }, 'links.id', 'link_id');

        return $checked;
    }

    /**
     * Delivery rechecks the remote source, local owner and the exact warning
     * version; queued warnings cannot survive renewal or quota recharge.
     */
    public function messageFor(object $delivery): ?string
    {
        $type = (string) $delivery->event_type;
        if (! in_array($type, ['SubscriptionExpiry24h', 'SubscriptionExpiry1h', 'SubscriptionLowTraffic'], true)) {
            return null;
        }

        $live = $this->live((int) $delivery->order_id, (int) $delivery->recipient_user_id, retryRemoteFailure: true);
        if ($live === null || $live['expires'] <= now()->getTimestamp()) {
            return null;
        }

        $seconds = $live['expires'] - now()->getTimestamp();
        $subscription = $live['subscription'];
        $order = $live['order'];
        $key = (string) $delivery->event_key;

        if ($type === 'SubscriptionExpiry24h'
            && $live['status'] === 'ACTIVE'
            && $seconds > 3600 && $seconds <= 86400
            && hash_equals(self::eventKey('expiry24', $subscription, $live['expires']), $key)) {
            return "<b>⏳ یک روز تا پایان اشتراک</b>\n\nسرویس #{$order} تا حدود ۲۴ ساعت آینده منقضی می‌شود.\nبرای جلوگیری از قطع اتصال، تمدید سرویس را بررسی کنید.";
        }

        if ($type === 'SubscriptionExpiry1h'
            && $live['status'] === 'ACTIVE'
            && $seconds > 0 && $seconds <= 3600
            && hash_equals(self::eventKey('expiry1', $subscription, $live['expires']), $key)) {
            return "<b>🚨 اعتبار اشتراک رو به پایان است</b>\n\nکمتر از یک ساعت تا پایان سرویس #{$order} باقی مانده است.";
        }

        if ($type === 'SubscriptionLowTraffic'
            && in_array($live['status'], ['ACTIVE', 'LIMITED'], true)
            && $live['limit'] > 0 && $live['remaining'] < self::LOW_BYTES) {
            $generation = DB::table('subscription_alert_states')
                ->where('provisioning_subscription_id', $subscription)->value('quota_generation');

            if ($generation !== null
                && hash_equals(self::eventKey('low350', $subscription, (int) $generation), $key)) {
                $megabytes = intdiv($live['remaining'], 1024 * 1024);

                return "<b>📉 حجم اشتراک رو به پایان است</b>\n\nحجم باقی‌مانده سرویس #{$order}: <b>{$megabytes} مگابایت</b>.\nبرای جلوگیری از قطع اتصال، شارژ یا تمدید سرویس را بررسی کنید.";
            }
        }

        return null;
    }

    /**
     * @return array{order:int,buyer:int,subscription:int,remote:int,reference:string,expires:int,used:int,limit:int,remaining:int,status:string}|null
     */
    private function live(int $orderId, int $buyerId, bool $retryRemoteFailure = false): ?array
    {
        if ($orderId < 1 || $buyerId < 1) {
            return null;
        }

        $binding = $this->binding($orderId, $buyerId);
        if ($binding === null) {
            return null;
        }

        try {
            // This existing GET action validates the remote username and
            // immutable ownership marker before returning the snapshot.
            $remote = $this->lifecycle->subscription((string) $binding->reference);
            $expires = (new DateTimeImmutable($remote->expireAt))->getTimestamp();
        } catch (Throwable $exception) {
            // The scanner skips a failed GET and tries on its next run.
            // The delivery worker MUST use its existing backoff/recovery
            // instead of permanently discarding a queued warning on a 503.
            if ($retryRemoteFailure) {
                throw new \RuntimeException('Subscription alert provider read failed.', 0, $exception);
            }

            return null;
        }

        $fresh = $this->binding($orderId, $buyerId);
        if ($fresh === null
            || (int) $fresh->subscription_id !== (int) $binding->subscription_id
            || (int) $fresh->remote_user_id !== (int) $binding->remote_user_id
            || $fresh->reference !== $binding->reference
            || $remote->id !== (int) $binding->remote_user_id) {
            return null;
        }

        return [
            'order' => $orderId,
            'buyer' => $buyerId,
            'subscription' => (int) $binding->subscription_id,
            'remote' => (int) $binding->remote_user_id,
            'reference' => (string) $binding->reference,
            'expires' => $expires,
            'used' => $remote->usedTrafficBytes,
            'limit' => $remote->trafficLimitBytes,
            'remaining' => max(0, $remote->trafficLimitBytes - $remote->usedTrafficBytes),
            'status' => $remote->status,
        ];
    }

    private function binding(int $orderId, int $buyerId): ?object
    {
        return DB::table('sales_orders as orders')
            ->join('provisioning_order_links as links', 'links.sales_order_id', '=', 'orders.id')
            ->join('provisioning_subscriptions as sub', 'sub.id', '=', 'links.provisioning_subscription_id')
            ->join('users as buyer', 'buyer.id', '=', 'orders.buyer_user_id')
            ->join('telegram_bot_contacts as contact', 'contact.user_id', '=', 'buyer.id')
            ->where('orders.id', $orderId)->where('buyer.id', $buyerId)
            ->whereIn('orders.status', ['paid', 'fulfilled'])
            ->where('sub.state', 'active')->whereNotNull('sub.remote_user_id')
            ->where('buyer.is_active', true)
            ->where('contact.bot_key', 'sales')->where('contact.delivery_status', 'reachable')
            ->select('sub.id as subscription_id', 'sub.reference', 'sub.remote_user_id')
            ->first();
    }

    /** @param array{order:int,buyer:int,subscription:int,remote:int,reference:string,expires:int,used:int,limit:int,remaining:int,status:string} $live */
    private function record(array $live): void
    {
        DB::transaction(function () use ($live): void {
            // Authority is checked once more before committing an intent.
            $binding = $this->binding($live['order'], $live['buyer']);
            if ($binding === null || (int) $binding->subscription_id !== $live['subscription']
                || (int) $binding->remote_user_id !== $live['remote']
                || $binding->reference !== $live['reference']) {
                return;
            }

            DB::table('subscription_alert_states')->insertOrIgnore([
                'provisioning_subscription_id' => $live['subscription'],
                'last_used_bytes' => $live['used'],
                'last_limit_bytes' => $live['limit'],
                'quota_generation' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $state = DB::table('subscription_alert_states')
                ->where('provisioning_subscription_id', $live['subscription'])->lockForUpdate()->first();

            if ($state === null) {
                return;
            }

            $generation = (int) $state->quota_generation;
            $oldLimit = (int) $state->last_limit_bytes;
            $oldUsed = (int) $state->last_used_bytes;
            $oldRemaining = $oldLimit > 0 ? max(0, $oldLimit - $oldUsed) : 0;

            // A genuine remote traffic reset or increased cap must restore
            // available quota above the threshold before another low alert.
            if ($oldLimit > 0 && $oldRemaining < self::LOW_BYTES
                && $live['remaining'] >= self::LOW_BYTES
                && ($live['used'] < $oldUsed || $live['limit'] > $oldLimit)) {
                $generation++;
            }

            DB::table('subscription_alert_states')->where('provisioning_subscription_id', $live['subscription'])
                ->update([
                    'last_used_bytes' => $live['used'],
                    'last_limit_bytes' => $live['limit'],
                    'quota_generation' => $generation,
                    'updated_at' => now(),
                ]);

            if ($live['expires'] <= now()->getTimestamp()
                || ! in_array($live['status'], ['ACTIVE', 'LIMITED'], true)) {
                return;
            }

            $seconds = $live['expires'] - now()->getTimestamp();
            if ($live['status'] === 'ACTIVE' && $seconds <= 86400) {
                if ($seconds > 3600) {
                    $this->intent($live, 'SubscriptionExpiry24h', 'expiry24', $live['expires']);
                } else {
                    $this->intent($live, 'SubscriptionExpiry1h', 'expiry1', $live['expires']);
                }
            }
            if ($live['limit'] > 0 && $live['remaining'] < self::LOW_BYTES) {
                $this->intent($live, 'SubscriptionLowTraffic', 'low350', $generation);
            }
        });
    }

    /** @param array{order:int,buyer:int,subscription:int} $live */
    private function intent(array $live, string $type, string $keyType, int $version): void
    {
        $key = self::eventKey($keyType, $live['subscription'], $version);
        $inserted = DB::table('telegram_notification_deliveries')->insertOrIgnore([
            'event_key' => $key,
            'event_type' => $type,
            'recipient_user_id' => $live['buyer'],
            'bot_key' => 'sales',
            'order_id' => $live['order'],
            'state' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 1) {
            $id = (int) DB::table('telegram_notification_deliveries')->where('event_key', $key)->value('id');
            DB::afterCommit(static function () use ($id): void {
                DispatchTelegramNotification::dispatch($id);
            });
        }
    }

    private static function eventKey(string $kind, int $subscriptionId, int $version): string
    {
        return "subalert:{$kind}:{$subscriptionId}:{$version}";
    }
}

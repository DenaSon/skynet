<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\SuperAdminTrust;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class AdminSubscriptionLinkResend
{
    public const EVENT = 'SubscriptionLinkResent';

    public function __construct(private SuperAdminTrust $trust) {}

    /** Check whether a trusted root can currently request this delivery. */
    public function canRequest(int $actorId, int $orderId): bool
    {
        $this->authorize($actorId);
        if ($orderId < 1 || ! $this->configured()) {
            return false;
        }

        $order = $this->eligibleOrder($orderId);

        return $order !== null && $this->contactReady($order);
    }

    /** Reserve one confirmed resend intent; no provider or Telegram call occurs here. */
    public function enqueue(int $actorId, int $orderId, string $token): int
    {
        $this->authorize($actorId);
        if ($orderId < 1 || preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1 || ! $this->configured()) {
            throw ValidationException::withMessages(['subscription' => 'Subscription link resend unavailable.']);
        }

        return DB::transaction(function () use ($actorId, $orderId, $token): int {
            $locked = DB::table('sales_orders')->where('id', $orderId)
                ->lockForUpdate()->first(['id']);
            if ($locked === null) {
                throw ValidationException::withMessages(['subscription' => 'Order unavailable.']);
            }

            $order = $this->eligibleOrder($orderId);
            if ($order === null || ! $this->contactReady($order)) {
                throw ValidationException::withMessages(['subscription' => 'Subscription or Sales Bot contact unavailable.']);
            }

            return (int) DB::table('telegram_notification_deliveries')->insertGetId([
                'event_key' => 'subscription:link-resent:order:'.$orderId
                    .':buyer:'.$order->buyer_user_id.':actor:'.$actorId.':'.$token,
                'event_type' => self::EVENT,
                'recipient_user_id' => $order->buyer_user_id,
                'bot_key' => 'sales',
                'order_id' => $orderId,
                'requested_by_user_id' => $actorId,
                'state' => 'pending',
                'attempts' => 0,
                'available_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
    }

    /** Recheck the requester, order, active mapping and exact private Sales destination at send time. */
    public function canDeliver(object $delivery): bool
    {
        $orderId = (int) ($delivery->order_id ?? 0);
        $buyerId = (int) ($delivery->recipient_user_id ?? 0);
        $actorId = (int) ($delivery->requested_by_user_id ?? 0);

        if ($orderId < 1 || $buyerId < 1 || $actorId < 1
            || ($delivery->event_type ?? null) !== self::EVENT
            || ($delivery->bot_key ?? null) !== 'sales'
            || (int) ($delivery->attempts ?? 0) !== 1
            || ! $this->configured()) {
            return false;
        }

        $pattern = '/\Asubscription:link-resent:order:'.$orderId
            .':buyer:'.$buyerId.':actor:'.$actorId.':[a-f0-9]{32}\z/D';
        if (preg_match($pattern, (string) ($delivery->event_key ?? '')) !== 1) {
            return false;
        }

        try {
            $this->authorize($actorId);
        } catch (AuthorizationException) {
            return false;
        }

        $order = $this->eligibleOrder($orderId);

        return $order !== null
            && (int) $order->buyer_user_id === $buyerId
            && $this->contactReady($order);
    }

    private function authorize(int $actorId): void
    {
        $user = $actorId > 0
            ? User::query()->with('telegramAccount')->find($actorId)
            : null;

        if ($user === null || ! $this->trust->eligible($user)) {
            throw new AuthorizationException;
        }
    }

    private function configured(): bool
    {
        return config('telegram_notifications.enabled', false) === true
            && config('telegram.sales.enabled', false) === true
            && is_string(config('telegram.sales.token'))
            && config('telegram.sales.token') !== ''
            && config('telegram.sales.token') !== config('nutgram.token');
    }

    private function eligibleOrder(int $orderId): ?object
    {
        return DB::table('sales_orders as orders')
            ->join('users as buyer', 'buyer.id', '=', 'orders.buyer_user_id')
            ->leftJoin('telegram_accounts as account', 'account.user_id', '=', 'buyer.id')
            ->leftJoin('telegram_bot_contacts as contact', function ($join): void {
                $join->on('contact.user_id', '=', 'buyer.id')->where('contact.bot_key', 'sales');
            })
            ->join('sales_fulfillment_outbox as intent', 'intent.sales_order_id', '=', 'orders.id')
            ->join('provisioning_order_links as links', 'links.sales_order_id', '=', 'orders.id')
            ->join('provisioning_subscriptions as sub', 'sub.id', '=', 'links.provisioning_subscription_id')
            ->where('orders.id', $orderId)
            ->where('orders.status', 'fulfilled')
            ->whereNotNull('orders.plan_id')
            ->where('intent.state', 'completed')
            ->where('sub.reference', 'sales-order:'.$orderId)
            ->where('sub.state', 'active')
            ->where('sub.remote_user_id', '>', 0)
            ->select(
                'orders.buyer_user_id',
                'buyer.is_active',
                'account.telegram_user_id',
                'contact.chat_id',
                'contact.delivery_status',
            )
            ->first();
    }

    private function contactReady(object $order): bool
    {
        return (bool) $order->is_active
            && is_numeric($order->telegram_user_id)
            && (int) $order->telegram_user_id > 0
            && $order->delivery_status === 'reachable'
            && (string) $order->chat_id === (string) $order->telegram_user_id;
    }
}

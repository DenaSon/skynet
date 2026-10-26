<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Notifications;

use Illuminate\Support\Facades\DB;

/**
 * Award and notification intent are committed together by ReferralRewardOutbox.
 * Telegram transport never participates in a wallet/ledger transaction.
 */
final class ReferralRewardNotificationOutbox
{
    public function credited(int $rewardId, int $orderId): void
    {
        $reward = DB::table('referral_rewards')
            ->where('id', $rewardId)
            ->where('sales_order_id', $orderId)
            ->first(['referrer_user_id']);

        if ($reward === null) {
            throw new \RuntimeException('Missing committed referral reward evidence.');
        }

        $enabled = config('telegram_notifications.enabled', false) === true;
        $key = 'referral:reward:'.$rewardId.':credited';
        $inserted = DB::table('telegram_notification_deliveries')->insertOrIgnore([
            'event_key' => $key,
            'event_type' => 'ReferralRewardCredited',
            'recipient_user_id' => (int) $reward->referrer_user_id,
            'bot_key' => 'sales',
            'order_id' => $orderId,
            'state' => $enabled ? 'pending' : 'suppressed',
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 1 && $enabled) {
            $deliveryId = (int) DB::table('telegram_notification_deliveries')
                ->where('event_key', $key)->value('id');
            DB::afterCommit(static function () use ($deliveryId): void {
                DispatchTelegramNotification::dispatch($deliveryId);
            });
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Notifications;

use Illuminate\Support\Facades\DB;

/** Trial intents share the existing delivery table, dispatcher and transport. */
final class TrialNotificationOutbox
{
    public function activated(int $trialId, int $userId): void
    {
        $this->intent($trialId, $userId, 'TrialActivated', 'activated');
    }

    public function ended(int $trialId, int $userId): void
    {
        $this->intent($trialId, $userId, 'TrialEnded', 'ended');
    }

    private function intent(int $trialId, int $userId, string $type, string $suffix): void
    {
        $key = 'free-trial:'.$trialId.':'.$suffix;
        $inserted = DB::table('telegram_notification_deliveries')->insertOrIgnore([
            'event_key' => $key, 'event_type' => $type, 'trial_claim_id' => $trialId,
            'recipient_user_id' => $userId, 'bot_key' => 'sales', 'state' => 'pending',
            'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($inserted === 1) {
            $id = (int) DB::table('telegram_notification_deliveries')->where('event_key', $key)->value('id');
            DB::afterCommit(static fn () => DispatchTelegramNotification::dispatch($id));
        }
    }
}

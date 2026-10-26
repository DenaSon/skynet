<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Notifications;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Trial intents share the existing delivery table, dispatcher and transport. */
final class TrialNotificationOutbox
{
    public function activated(int $trialId, int $userId): void
    {
        $this->intent($trialId, $userId, 'TrialActivated', 'activated');
        $expiresAt = DB::table('trial_claims')->where('id', $trialId)->value('expires_at');
        if (is_string($expiresAt)) {
            // Wake the existing worker at expiry minus five minutes; minute scans
            // and the existing dispatcher recover a lost/delayed enqueue.
            $type = TrialWarningNotifications::EXPIRING;
            $this->intent($trialId, $userId, $type, $type, Carbon::parse($expiresAt, 'UTC')->subMinutes(5));
        }
    }

    public function ended(int $trialId, int $userId): void
    {
        $this->intent($trialId, $userId, 'TrialEnded', 'ended');
    }

    public function warning(int $trialId, int $userId, string $type): void
    {
        if (! in_array($type, [TrialWarningNotifications::LOW_TRAFFIC, TrialWarningNotifications::EXPIRING], true)) {
            throw new \InvalidArgumentException('Invalid trial warning.');
        }
        $this->intent($trialId, $userId, $type, $type);
    }

    private function intent(int $trialId, int $userId, string $type, string $suffix, ?Carbon $availableAt = null): void
    {
        $availableAt ??= now();
        $key = 'free-trial:'.$trialId.':'.$suffix;
        $inserted = DB::table('telegram_notification_deliveries')->insertOrIgnore([
            'event_key' => $key, 'event_type' => $type, 'trial_claim_id' => $trialId,
            'recipient_user_id' => $userId, 'bot_key' => 'sales', 'state' => 'pending',
            'available_at' => $availableAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($inserted === 1) {
            $id = (int) DB::table('telegram_notification_deliveries')->where('event_key', $key)->value('id');
            DB::afterCommit(static fn () => DispatchTelegramNotification::dispatch($id)->delay($availableAt));
        }
    }
}

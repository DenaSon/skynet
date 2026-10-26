<?php

declare(strict_types=1);

namespace App\Modules\Trial\Application;

use App\Interfaces\Telegram\Notifications\TrialNotificationOutbox;
use Illuminate\Support\Facades\DB;

/** Provider-verified terminal transition and at-most-one terminal send attempt. */
final readonly class EndFreeTrial
{
    public function __construct(private ReadFreeTrial $trials, private TrialNotificationOutbox $notifications) {}

    public function handle(int $userId, int $telegramId): bool
    {
        $live = $this->trials->status($userId, $telegramId);
        if (! $live->ended()) {
            return false;
        }

        return DB::transaction(function () use ($live, $userId, $telegramId): bool {
            $row = DB::table('trial_claims')->where('id', $live->id)->where('user_id', $userId)
                ->where('telegram_user_id', $telegramId)->lockForUpdate()->first();
            if ($row === null || $row->state !== 'active') {
                return false;
            }
            DB::table('trial_claims')->where('id', $live->id)->update([
                'state' => 'ended', 'end_reason' => $live->remainingBytes === 0 ? 'traffic' : 'time',
                'ended_at' => now(), 'updated_at' => now(),
            ]);
            $this->notifications->ended($live->id, $userId);

            return true;
        });
    }

    /** A read outage happens before the durable send fence, so delivery can retry. */
    public function claimNotice(int $trialId, int $userId, int $telegramId): bool
    {
        $live = $this->trials->status($userId, $telegramId);
        if ($live->id !== $trialId || ! $live->ended()) {
            return false;
        }

        return DB::table('trial_claims')->where('id', $trialId)->where('user_id', $userId)
            ->where('telegram_user_id', $telegramId)->where('state', 'ended')
            ->whereNull('end_notice_started_at')->update([
                'end_notice_started_at' => now(), 'updated_at' => now(),
            ]) === 1;
    }

    /** Only a positively rejected Telegram request (e.g. HTTP 429) may retry. */
    public function releaseRejectedNotice(int $trialId): void
    {
        DB::table('trial_claims')->where('id', $trialId)->whereNull('end_notice_sent_at')
            ->update(['end_notice_started_at' => null, 'updated_at' => now()]);
    }

    public function noticeSent(int $trialId): void
    {
        DB::table('trial_claims')->where('id', $trialId)->whereNotNull('end_notice_started_at')
            ->update(['end_notice_sent_at' => now(), 'updated_at' => now()]);
    }
}

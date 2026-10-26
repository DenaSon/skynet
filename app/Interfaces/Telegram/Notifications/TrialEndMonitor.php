<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Notifications;

use App\Modules\Trial\Application\EndFreeTrial;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Same GET-only scan pattern as paid alerts; only permanent trial claims qualify. */
final readonly class TrialEndMonitor
{
    public function __construct(private EndFreeTrial $trials) {}

    public function scan(): int
    {
        if (config('trial.enabled') !== true || config('telegram_notifications.enabled') !== true
            || config('telegram.sales.enabled') !== true) {
            return 0;
        }
        $ended = 0;
        DB::table('trial_claims as trial')
            ->join('users as owner', 'owner.id', '=', 'trial.user_id')
            ->join('telegram_bot_contacts as contact', 'contact.user_id', '=', 'trial.user_id')
            ->where('trial.state', 'active')->where('owner.is_active', true)
            ->where('contact.bot_key', 'sales')->where('contact.delivery_status', 'reachable')
            ->whereColumn('contact.chat_id', 'trial.telegram_user_id')
            ->select('trial.id', 'trial.user_id', 'trial.telegram_user_id')
            ->chunkById(40, function ($rows) use (&$ended): void {
                foreach ($rows as $row) {
                    try {
                        if ($this->trials->handle((int) $row->user_id, (int) $row->telegram_user_id)) {
                            $ended++;
                        }
                    } catch (Throwable) {
                        // An unavailable/mismatched provider or revoked owner is unknown,
                        // never a terminal state. The next scheduled scan can recover.
                    }
                }
            }, 'trial.id', 'id');

        return $ended;
    }
}

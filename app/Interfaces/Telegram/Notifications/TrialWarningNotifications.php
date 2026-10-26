<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Notifications;

use App\Modules\Trial\Application\FreeTrialStatus;
use App\Modules\Trial\Application\ReadFreeTrial;
use Illuminate\Support\Facades\DB;

/** Trial-only thresholds; paid warning rules remain independent. */
final readonly class TrialWarningNotifications
{
    public const LOW_TRAFFIC = 'TrialLowTraffic';

    public const EXPIRING = 'TrialExpiring5m';

    public function __construct(private ReadFreeTrial $trials, private TrialNotificationOutbox $outbox) {}

    public function record(int $userId, FreeTrialStatus $live): void
    {
        foreach ([self::LOW_TRAFFIC, self::EXPIRING] as $type) {
            if ($this->due($live, $type)
                && DB::table('trial_claims')->where('id', $live->id)->where('user_id', $userId)
                    ->where('state', 'active')->exists()) {
                $this->outbox->warning($live->id, $userId, $type);
            }
        }
    }

    /** A delayed worker must never send an obsolete warning after completion. */
    public function messageFor(object $delivery, int $telegramId): ?string
    {
        $type = (string) $delivery->event_type;
        $trialId = (int) $delivery->trial_claim_id;
        if (! in_array($type, [self::LOW_TRAFFIC, self::EXPIRING], true)
            || $delivery->event_key !== self::eventKey($trialId, $type)
            || ! DB::table('trial_claims')->where('id', $trialId)
                ->where('user_id', $delivery->recipient_user_id)->where('state', 'active')->exists()) {
            return null;
        }
        $live = $this->trials->status((int) $delivery->recipient_user_id, $telegramId);
        if ($live->id !== $trialId || ! $this->due($live, $type)) {
            return null;
        }

        $warning = $type === self::LOW_TRAFFIC
            ? '📉 کمتر از ۱۰ مگابایت از حجم تست شما باقی مانده است.'
            : '⏳ حداکثر ۵ دقیقه تا پایان تست شما باقی مانده است.';

        return '<b>'.$warning.'</b>'."\n\nبرای ادامه اتصال، می‌توانید همین حالا یک پلن اصلی تهیه کنید. تست رایگان قابل تمدید نیست.";
    }

    public static function eventKey(int $trialId, string $type): string
    {
        return 'free-trial:'.$trialId.':'.$type;
    }

    private function due(FreeTrialStatus $live, string $type): bool
    {
        return ! $live->ended() && $live->providerStatus === 'ACTIVE' && match ($type) {
            self::LOW_TRAFFIC => $live->remainingBytes < 10 * 1024 * 1024,
            self::EXPIRING => $live->remainingSeconds <= 300,
            default => false,
        };
    }
}

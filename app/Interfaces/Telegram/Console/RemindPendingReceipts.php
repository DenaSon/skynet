<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Console;

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use App\Interfaces\Telegram\Notifications\PendingReceiptSummary;
use App\Modules\Identity\Application\AdminCapabilityAccess;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\SuperAdminTrust;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Fifteen-minute safety net for unreviewed receipts; never posts to Billing. */
final class RemindPendingReceipts extends Command
{
    protected $signature = 'telegram:receipts:remind';

    protected $description = 'Remind authorized Admin Bot recipients only about outstanding receipt reviews';

    public function handle(
        PendingReceiptSummary $receipts,
        AdminCapabilityAccess $capabilities,
    ): int {
        if (config('telegram_notifications.enabled', false) !== true) {
            return self::SUCCESS;
        }

        // The scheduler already uses withoutOverlapping; also serialize manual runs.
        $lock = Cache::lock('telegram:pending-receipts:reminder', 60);
        if (! $lock->get()) {
            return self::SUCCESS;
        }

        try {
            if ($receipts->overdue() === null) {
                // No unresolved aged receipts: no message, no outbox entry, no report.
                return self::SUCCESS;
            }

            $ids = array_unique(array_merge(
                $capabilities->reviewerIds('purchases.review'),
                $capabilities->reviewerIds('topups.review'),
            ));
            foreach ($ids as $userId) {
                $user = User::query()->with('telegramAccount')->find((int) $userId);
                if ($user === null || $user->telegramAccount === null
                    || $receipts->overdue(
                        $capabilities->allowed($user, 'purchases.review'),
                        $capabilities->allowed($user, 'topups.review'),
                    ) === null) {
                    continue;
                }

                // A queued, failed or delivered reminder all impose the same
                // minimum quiet period: at most one aggregate per root per hour.
                if (DB::table('telegram_notification_deliveries')
                    ->where('event_type', 'ReceiptReviewReminder')
                    ->where('recipient_user_id', $userId)
                    ->where('created_at', '>', now()->subHour())->exists()) {
                    continue;
                }

                $key = 'receipts:review:reminder:root:'.$userId.':'.now()->format('YmdHi');
                $inserted = DB::table('telegram_notification_deliveries')->insertOrIgnore([
                    'event_key' => $key,
                    'event_type' => 'ReceiptReviewReminder',
                    'recipient_user_id' => $userId,
                    'bot_key' => 'admin',
                    'state' => 'pending',
                    'attempts' => 0,
                    'available_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($inserted === 1) {
                    $id = (int) DB::table('telegram_notification_deliveries')
                        ->where('event_key', $key)->value('id');
                    DispatchTelegramNotification::dispatch($id);
                }
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}

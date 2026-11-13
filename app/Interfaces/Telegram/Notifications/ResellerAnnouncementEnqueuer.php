<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Notifications;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Queue only durable, committed reseller announcement deliveries. */
final class ResellerAnnouncementEnqueuer
{
    public function afterCommit(int $announcementId): void
    {
        DB::afterCommit(static function () use ($announcementId): void {
            try {
                DB::table('telegram_notification_deliveries')
                    ->where('event_type', 'ResellerAnnouncement')
                    ->where('event_key', 'like', "reseller:ann:{$announcementId}:user:%")
                    ->select(['id', 'available_at'])
                    ->chunkById(100, static function ($rows): void {
                        foreach ($rows as $row) {
                            DispatchTelegramNotification::dispatch((int) $row->id)
                                ->delay(Carbon::parse((string) $row->available_at));
                        }
                    });
            } catch (Throwable $exception) {
                // A queue failure cannot undo committed outbox records.
                // The existing minute dispatcher will recover due pending IDs.
                Log::warning('Reseller announcement initial enqueue failed; scheduled recovery retained.', [
                    'announcement_id' => $announcementId,
                    'exception' => $exception::class,
                ]);
            }
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DispatchTelegramNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(TelegramNotificationSender $sender): void
    {
        if (config('telegram_notifications.enabled', false) !== true) {
            return;
        }

        $claimed = DB::table('telegram_notification_deliveries')
            ->where('id', $this->deliveryId)
            ->where(function ($query): void {
                $query->where('state', 'pending')
                    ->orWhere(function ($expired): void {
                        $expired->where('state', 'processing')->where('leased_until', '<', now());
                    });
            })
            ->where('available_at', '<=', now())
            ->update([
                'state' => 'processing',
                'leased_until' => now()->addMinutes(2),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return;
        }

        $row = DB::table('telegram_notification_deliveries')->where('id', $this->deliveryId)->first();

        if ($row === null) {
            return;
        }

        try {
            $sender->send($row);

            DB::table('telegram_notification_deliveries')
                ->where('id', $this->deliveryId)->where('state', 'processing')->update([
                    'state' => 'sent',
                    'sent_at' => now(),
                    'leased_until' => null,
                    'last_failure' => null,
                    'updated_at' => now(),
                ]);
        } catch (NotificationUnavailable $e) {
            DB::table('telegram_notification_deliveries')
                ->where('id', $this->deliveryId)->where('state', 'processing')->update([
                    'state' => 'unavailable', 'leased_until' => null,
                    'last_failure' => $e->category,
                    'updated_at' => now(),
                ]);
        } catch (Throwable) {
            $attempts = (int) $row->attempts;
            $terminal = $attempts >= (int) config('telegram_notifications.max_attempts', 5);

            DB::table('telegram_notification_deliveries')
                ->where('id', $this->deliveryId)->where('state', 'processing')->update([
                    'state' => $terminal ? 'dead' : 'pending',
                    'available_at' => now()->addSeconds(min(300, 5 * (4 ** min($attempts - 1, 3)))),
                    'leased_until' => null,
                    'last_failure' => 'transport',
                    'updated_at' => now(),
                ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Console;

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class DispatchPendingNotifications extends Command
{
    protected $signature = 'telegram:notifications:dispatch {--limit=50}';

    protected $description = 'Enqueue due Telegram notifications for bounded recovery';

    public function handle(): int
    {
        if (config('telegram_notifications.enabled', false) !== true) {
            return self::SUCCESS;
        }

        $limit = max(1, min(100, (int) $this->option('limit')));
        $ids = DB::table('telegram_notification_deliveries')
            ->where('available_at', '<=', now())
            ->where(function ($query): void {
                $query->where('state', 'pending')->orWhere(function ($expired): void {
                    $expired->where('state', 'processing')->where('leased_until', '<', now());
                });
            })
            ->orderBy('id')->limit($limit)->pluck('id');

        foreach ($ids as $id) {
            DispatchTelegramNotification::dispatch((int) $id);
        }

        $this->info('Enqueued '.count($ids).' due notification(s).');

        return self::SUCCESS;
    }
}

<?php

namespace App\Interfaces\Telegram\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneUpdates extends Command
{
    protected $signature = 'telegram:prune-updates';

    protected $description = 'Prune old processed Telegram webhook receipts';

    public function handle(): int
    {
        $count = DB::table('telegram_updates')
            ->where('status', 'processed')
            ->where('processed_at', '<', now()->subDays(7))
            ->delete();

        $this->info("Removed {$count} processed Telegram updates.");

        return self::SUCCESS;
    }
}

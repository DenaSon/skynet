<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-only local database snapshot: no network calls, tokens, PII or writes.
 * Counts are diagnostic evidence, not proof that a webhook/worker is healthy.
 */
final class InspectTelegramOperations extends Command
{
    protected $signature = 'telegram:ops:status';

    protected $description = 'Show sanitized Telegram queue, webhook receipt and provisioning backlog counts';

    public function handle(): int
    {
        $required = [
            'telegram_updates',
            'telegram_bot_contacts',
            'telegram_notification_deliveries',
            'jobs',
            'provisioning_operations',
            'sales_fulfillment_outbox',
        ];

        $missing = array_values(array_filter($required, static fn (string $table): bool => ! Schema::hasTable($table)));

        if ($missing !== []) {
            // Never guess queue health when the schema has not been migrated.
            $this->warn('Schema incomplete: '.implode(', ', $missing));

            return self::FAILURE;
        }

        if (! Schema::hasColumn('telegram_updates', 'bot_key')) {
            $this->warn('Telegram update scoping migration pending.');

            return self::FAILURE;
        }

        $this->line('Telegram delivery: '.(config('telegram_notifications.enabled', false) === true ? 'enabled' : 'disabled'));
        $this->line('Sales Bot runtime: '.(config('telegram.sales.enabled', false) === true ? 'enabled' : 'disabled'));
        $this->line('Provider writes: '.(config('services.remnawave.writes_enabled', false) === true ? 'enabled' : 'disabled'));
        $this->line('Queue jobs awaiting worker: '.DB::table('jobs')->count());

        foreach (['admin', 'sales'] as $bot) {
            $updates = DB::table('telegram_updates')->where('bot_key', $bot);
            $this->line("{$bot} webhook update failures: ".(clone $updates)->where('status', 'failed')->count());
            $this->line("{$bot} stale webhook leases: ".(clone $updates)->where('status', 'processing')
                ->where('processing_until', '<', now())->count());
            $this->line("{$bot} reachable contacts: ".DB::table('telegram_bot_contacts')
                ->where('bot_key', $bot)->where('delivery_status', 'reachable')->count());
        }

        foreach (['pending', 'processing', 'sent', 'dead', 'unavailable', 'suppressed'] as $state) {
            $this->line("Notification {$state}: ".DB::table('telegram_notification_deliveries')
                ->where('state', $state)->count());
        }

        $this->line('Notifications due for recovery: '.DB::table('telegram_notification_deliveries')
            ->where('available_at', '<=', now())
            ->where(function ($query): void {
                $query->where('state', 'pending')->orWhere(function ($expired): void {
                    $expired->where('state', 'processing')->where('leased_until', '<', now());
                });
            })->count());

        $this->line('Provisioning needs review: '.DB::table('provisioning_operations')->where('state', 'needs_review')->count());
        $this->line('Unconsumed fulfillment intents: '.DB::table('sales_fulfillment_outbox')->where('state', 'pending')->count());

        if (Schema::hasTable('failed_jobs')) {
            $this->line('Failed queue jobs: '.DB::table('failed_jobs')->count());
        }

        $this->comment('Counts only. This does not verify live Telegram delivery, worker uptime or Remnawave health.');

        return self::SUCCESS;
    }
}

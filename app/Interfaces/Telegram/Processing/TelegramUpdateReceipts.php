<?php

namespace App\Interfaces\Telegram\Processing;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class TelegramUpdateReceipts
{
    public function claim(int $updateId, string $botKey = TelegramBotContext::ADMIN): ClaimState
    {
        $scoped = $this->isScoped($botKey);

        try {
            return DB::transaction(function () use ($updateId, $botKey, $scoped): ClaimState {
                $query = DB::table('telegram_updates')->where('update_id', $updateId);

                if ($scoped) {
                    $query->where('bot_key', $botKey);
                }

                $row = $query->lockForUpdate()->first();

                if ($row === null) {
                    $values = [
                        'update_id' => $updateId,
                        'status' => 'processing',
                        'processing_until' => now()->addSeconds(90),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    if ($scoped) {
                        $values['bot_key'] = $botKey;
                    }

                    DB::table('telegram_updates')->insert($values);

                    return ClaimState::Claimed;
                }

                if ($row->status === 'processed') {
                    return ClaimState::Processed;
                }

                if ($row->status === 'processing' && $row->processing_until !== null
                    && Carbon::parse($row->processing_until)->isFuture()) {
                    return ClaimState::Busy;
                }

                $query = DB::table('telegram_updates')->where('update_id', $updateId);

                if ($scoped) {
                    $query->where('bot_key', $botKey);
                }

                $query->update([
                    'status' => 'processing',
                    'processing_until' => now()->addSeconds(90),
                    'updated_at' => now(),
                ]);

                return ClaimState::Claimed;
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505', '19'], true)) {
                return ClaimState::Busy;
            }

            throw $exception;
        }
    }

    public function processed(int $updateId, string $botKey = TelegramBotContext::ADMIN): void
    {
        $scoped = $this->isScoped($botKey);
        $query = DB::table('telegram_updates')->where('update_id', $updateId);

        if ($scoped) {
            $query->where('bot_key', $botKey);
        }

        $query->update([
            'status' => 'processed',
            'processing_until' => null,
            'processed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function failed(int $updateId, string $botKey = TelegramBotContext::ADMIN): void
    {
        $scoped = $this->isScoped($botKey);
        $query = DB::table('telegram_updates')->where('update_id', $updateId);

        if ($scoped) {
            $query->where('bot_key', $botKey);
        }

        $query->update([
            'status' => 'failed',
            'processing_until' => null,
            'updated_at' => now(),
        ]);
    }

    private function isScoped(string $botKey): bool
    {
        $context = new TelegramBotContext($botKey);
        $scoped = Schema::hasColumn('telegram_updates', 'bot_key');

        // A code-first deploy keeps the original Admin webhook operational.
        // Sales must not use the old global update ID primary key.
        if (! $scoped && $context->key !== TelegramBotContext::ADMIN) {
            throw new RuntimeException('Telegram bot-key schema migration is required.');
        }

        return $scoped;
    }
}

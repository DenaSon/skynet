<?php

namespace App\Interfaces\Telegram\Processing;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class TelegramUpdateReceipts
{
    public function claim(int $updateId, string $botKey = TelegramBotContext::ADMIN): ClaimState
    {
        $context = new TelegramBotContext($botKey);

        try {
            return DB::transaction(function () use ($updateId, $context): ClaimState {
                $row = DB::table('telegram_updates')
                    ->where('bot_key', $context->key)
                    ->where('update_id', $updateId)
                    ->lockForUpdate()
                    ->first();

                if ($row === null) {
                    DB::table('telegram_updates')->insert([
                        'bot_key' => $context->key,
                        'update_id' => $updateId,
                        'status' => 'processing',
                        'processing_until' => now()->addSeconds(90),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return ClaimState::Claimed;
                }

                if ($row->status === 'processed') {
                    return ClaimState::Processed;
                }

                if ($row->status === 'processing' && $row->processing_until !== null
                    && Carbon::parse($row->processing_until)->isFuture()) {
                    return ClaimState::Busy;
                }

                DB::table('telegram_updates')
                    ->where('bot_key', $context->key)
                    ->where('update_id', $updateId)
                    ->update([
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
        DB::table('telegram_updates')->where('bot_key', (new TelegramBotContext($botKey))->key)
            ->where('update_id', $updateId)->update([
                'status' => 'processed',
                'processing_until' => null,
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function failed(int $updateId, string $botKey = TelegramBotContext::ADMIN): void
    {
        DB::table('telegram_updates')->where('bot_key', (new TelegramBotContext($botKey))->key)
            ->where('update_id', $updateId)->update([
                'status' => 'failed',
                'processing_until' => null,
                'updated_at' => now(),
            ]);
    }
}

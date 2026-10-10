<?php

namespace App\Interfaces\Telegram\Processing;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class TelegramUpdateReceipts
{
    public function claim(int $updateId): ClaimState
    {
        try {
            return DB::transaction(function () use ($updateId): ClaimState {
                $row = DB::table('telegram_updates')
                    ->where('update_id', $updateId)
                    ->lockForUpdate()
                    ->first();

                if ($row === null) {
                    DB::table('telegram_updates')->insert([
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
                    // A concurrent request is already processing this update. Ask Telegram to retry.
                    return ClaimState::Busy;
                }

                DB::table('telegram_updates')->where('update_id', $updateId)->update([
                    'status' => 'processing',
                    'processing_until' => now()->addSeconds(90),
                    'updated_at' => now(),
                ]);

                return ClaimState::Claimed;
            });
        } catch (QueryException $exception) {
            // A race between two inserts on a missing receipt must not execute twice.
            if (in_array((string) $exception->getCode(), ['23000', '23505', '19'], true)) {
                return ClaimState::Busy;
            }

            throw $exception;
        }
    }

    public function processed(int $updateId): void
    {
        DB::table('telegram_updates')->where('update_id', $updateId)->update([
            'status' => 'processed',
            'processing_until' => null,
            'processed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function failed(int $updateId): void
    {
        DB::table('telegram_updates')->where('update_id', $updateId)->update([
            'status' => 'failed',
            'processing_until' => null,
            'updated_at' => now(),
        ]);
    }
}

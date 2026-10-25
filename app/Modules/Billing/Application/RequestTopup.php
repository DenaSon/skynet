<?php

namespace App\Modules\Billing\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RequestTopup
{
    public function handle(int $userId, int $amountRials, string $requestKey): int
    {
        if ($amountRials < 100000 || $amountRials > 100000000) {
            throw ValidationException::withMessages(['amount' => 'Top-up must be between 10,000 and 10,000,000 tomans.']);
        }

        if (preg_match('/^[a-zA-Z0-9:_-]{6,128}$/D', $requestKey) !== 1) {
            throw ValidationException::withMessages(['key' => 'Invalid request identifier.']);
        }

        return DB::transaction(function () use ($userId, $amountRials, $requestKey): int {
            $walletId = app(WalletAccess::class)->customerWalletId($userId);

            $existing = DB::table('billing_topups')->where('request_key', $requestKey)->first();

            if ($existing !== null) {
                if ((int) $existing->wallet_id !== $walletId || (int) $existing->amount_rials !== $amountRials) {
                    throw ValidationException::withMessages(['key' => 'Request identifier already used.']);
                }

                return (int) $existing->id;
            }

            // Unique index protects against duplicate webhook deliveries under concurrency.
            DB::table('billing_topups')->insertOrIgnore([
                'wallet_id' => $walletId,
                'request_key' => $requestKey,
                'amount_rials' => $amountRials,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $request = DB::table('billing_topups')->where('request_key', $requestKey)->first();

            if ($request === null || (int) $request->wallet_id !== $walletId || (int) $request->amount_rials !== $amountRials) {
                throw ValidationException::withMessages(['key' => 'Request identifier already used.']);
            }

            return (int) $request->id;
        });
    }
}

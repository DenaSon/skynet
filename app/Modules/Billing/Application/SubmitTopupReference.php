<?php

namespace App\Modules\Billing\Application;

use App\Modules\Identity\Application\AssertBillingAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SubmitTopupReference
{
    public function handle(int $userId, int $topupId, string $reference): void
    {
        app(AssertBillingAccess::class)->reseller($userId);
        $reference = strtoupper(trim($reference));

        if (preg_match('/^[A-Z0-9_-]{6,64}$/D', $reference) !== 1) {
            throw ValidationException::withMessages(['reference' => 'Invalid payment reference.']);
        }

        DB::transaction(function () use ($userId, $topupId, $reference): void {
            $topup = DB::table('billing_topups')->where('id', $topupId)->lockForUpdate()->first();
            $walletUserId = $topup === null ? null : DB::table('billing_wallets')->where('id', $topup->wallet_id)->value('user_id');

            if ($topup === null || (int) $walletUserId !== $userId) {
                throw ValidationException::withMessages(['topup' => 'Request not found.']);
            }

            if ($topup->status === 'submitted' && $topup->payment_reference === $reference) {
                return; // Repeated Telegram update is idempotent.
            }

            if ($topup->status !== 'pending') {
                throw ValidationException::withMessages(['topup' => 'This request cannot be modified.']);
            }

            DB::table('billing_topups')->where('id', $topupId)->update([
                'status' => 'submitted',
                'payment_reference' => $reference,
                'updated_at' => now(),
            ]);
        });
    }
}

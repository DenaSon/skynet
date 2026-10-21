<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Persist customer photo evidence only. Never credit a wallet on upload. */
final readonly class SubmitTopupPhoto
{
    public function __construct(private WalletAccess $wallets) {}

    public function handle(int $userId, int $topupId, int $telegramId, string $fileId, string $fileUniqueId, int $messageId): void
    {
        if (config('billing.topups_enabled') !== true || ! Schema::hasTable('billing_topup_photos')) {
            throw new AuthorizationException;
        }

        if ($topupId < 1 || $telegramId < 1 || $messageId < 1 || strlen($fileId) < 5 || strlen($fileId) > 1024
            || strlen($fileUniqueId) < 5 || strlen($fileUniqueId) > 255) {
            throw ValidationException::withMessages(['receipt' => 'Invalid top-up photo.']);
        }

        $walletId = $this->wallets->customerWalletId($userId);
        $fingerprint = hash('sha256', $fileUniqueId);
        $messageKey = 'tg:sales:topup:'.$telegramId.':'.$messageId;

        DB::transaction(function () use ($topupId, $walletId, $fileId, $fingerprint, $messageKey): void {
            $topup = DB::table('billing_topups')->where('id', $topupId)->lockForUpdate()->first();

            if ($topup === null || (int) $topup->wallet_id !== $walletId) {
                throw ValidationException::withMessages(['topup' => 'Top-up not owned.']);
            }

            $existing = DB::table('billing_topup_photos')->where('topup_id', $topupId)->first();
            if ($topup->status === 'submitted' && $existing !== null
                && $existing->photo_fingerprint === $fingerprint && $existing->telegram_message_key === $messageKey) {
                return; // The same Telegram update was already recorded.
            }

            if ($topup->status !== 'pending' || $existing !== null
                || DB::table('billing_topup_photos')->where('photo_fingerprint', $fingerprint)->exists()
                || DB::table('billing_topup_photos')->where('telegram_message_key', $messageKey)->exists()
                || DB::table('sales_bank_payment_claims')->where('photo_fingerprint', $fingerprint)->exists()) {
                throw ValidationException::withMessages(['receipt' => 'Receipt already submitted or request unavailable.']);
            }

            DB::table('billing_topup_photos')->insert([
                'topup_id' => $topupId,
                'file_encrypted' => Crypt::encryptString($fileId),
                'photo_fingerprint' => $fingerprint,
                'telegram_message_key' => $messageKey,
                'source_bot_key' => 'sales',
                'created_at' => now(),
            ]);
            DB::table('billing_topups')->where('id', $topupId)->where('status', 'pending')->update([
                'status' => 'submitted',
                'updated_at' => now(),
            ]);
        }, 3);
    }
}

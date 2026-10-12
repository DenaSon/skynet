<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Internal F11-A1 ledger operation, used ONLY inside the locked Sales settlement
 * transaction. Never call from Telegram, HTTP, CLI or a standalone worker.
 */
final readonly class DebitWalletForOrder
{
    public function __construct(private WalletAccess $wallets) {}

    public function handle(int $buyerId, int $orderId, int $amountRials): int
    {
        if ($orderId < 1 || $amountRials < 1 || $amountRials > 1_000_000_000_000) {
            throw ValidationException::withMessages(['payment' => 'Invalid wallet purchase amount.']);
        }

        // WalletAccess freshly checks the active reseller role. Lock the wallet row
        // before measuring liability so two different orders cannot overspend it.
        $walletId = $this->wallets->walletId($buyerId);
        $wallet = DB::table('billing_wallets')->where('id', $walletId)->lockForUpdate()->first();

        if ($wallet === null) {
            throw ValidationException::withMessages(['wallet' => 'Wallet not available.']);
        }

        $key = 'purchase:order:'.$orderId;

        if (DB::table('billing_journals')->where('idempotency_key', $key)->exists()) {
            throw ValidationException::withMessages(['payment' => 'Order debit already exists; reconcile the settlement.']);
        }

        $credits = (int) DB::table('billing_postings')
            ->where('wallet_id', $walletId)
            ->where('account_code', 'wallet_liability')
            ->where('side', 'credit')->sum('amount_rials');
        $debits = (int) DB::table('billing_postings')
            ->where('wallet_id', $walletId)
            ->where('account_code', 'wallet_liability')
            ->where('side', 'debit')->sum('amount_rials');

        if ($credits < $debits || $credits - $debits < $amountRials) {
            throw ValidationException::withMessages(['wallet' => 'Insufficient verified wallet credit.']);
        }

        $journalId = (int) DB::table('billing_journals')->insertGetId([
            'wallet_id' => $walletId,
            'kind' => 'purchase',
            'idempotency_key' => $key,
            'created_at' => now(),
        ]);

        // Transfer liability from prepaid wallet to unfulfilled order obligations.
        // Both sides are integer rials, inside the surrounding Sales DB transaction.
        DB::table('billing_postings')->insert([
            [
                'journal_id' => $journalId,
                'wallet_id' => $walletId,
                'account_code' => 'wallet_liability',
                'side' => 'debit',
                'amount_rials' => $amountRials,
            ],
            [
                'journal_id' => $journalId,
                'wallet_id' => null,
                'account_code' => 'order_liability',
                'side' => 'credit',
                'amount_rials' => $amountRials,
            ],
        ]);

        return $journalId;
    }
}

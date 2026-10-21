<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use Illuminate\Auth\Access\AuthorizationException;
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
        // Enforce default-deny even when called outside the Sales orchestrator.
        if (config('sales.wallet_settlement_enabled') !== true || DB::transactionLevel() < 1) {
            throw new AuthorizationException;
        }

        if ($orderId < 1 || $amountRials < 1 || $amountRials > 1_000_000_000_000) {
            throw ValidationException::withMessages(['payment' => 'Invalid wallet purchase amount.']);
        }

        // WalletAccess freshly checks the active member/reseller role. Lock the wallet row
        // before measuring liability so two different orders cannot overspend it.
        $walletId = $this->wallets->customerWalletId($buyerId);
        $wallet = DB::table('billing_wallets')->where('id', $walletId)->lockForUpdate()->first();

        if ($wallet === null) {
            throw ValidationException::withMessages(['wallet' => 'Wallet not available.']);
        }

        $key = 'purchase:order:'.$orderId;

        if (DB::table('billing_journals')->where('idempotency_key', $key)->exists()) {
            throw ValidationException::withMessages(['payment' => 'Order debit already exists; reconcile the settlement.']);
        }

        // A plain SUM can reuse the snapshot established by earlier Identity
        // reads under MySQL REPEATABLE READ, even after waiting for the wallet
        // lock. Read the actual postings with a current locking read instead.
        $postings = DB::table('billing_postings')
            ->where('wallet_id', $walletId)
            ->where('account_code', 'wallet_liability')
            ->orderBy('id')->lockForUpdate()->get(['side', 'amount_rials']);
        $credits = 0;
        $debits = 0;
        foreach ($postings as $posting) {
            if ($posting->side === 'credit') {
                $credits += (int) $posting->amount_rials;
            } else {
                $debits += (int) $posting->amount_rials;
            }
        }

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

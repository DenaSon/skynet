<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use Illuminate\Support\Facades\DB;

/**
 * Read-only replay attestation: a recorded wallet-funded Sales order is paid
 * only when its Billing journal and both exact postings are intact.
 */
final readonly class VerifyWalletOrderDebit
{
    public function handle(int $buyerId, int $orderId, int $journalId, int $amountRials): bool
    {
        if ($buyerId < 1 || $orderId < 1 || $journalId < 1 || $amountRials < 1) {
            return false;
        }

        $journal = DB::table('billing_journals as journals')
            ->join('billing_wallets as wallets', 'wallets.id', '=', 'journals.wallet_id')
            ->where('journals.id', $journalId)
            ->where('wallets.user_id', $buyerId)
            ->where('journals.kind', 'purchase')
            ->where('journals.idempotency_key', 'purchase:order:'.$orderId)
            ->first(['journals.id', 'journals.wallet_id']);

        if ($journal === null) {
            return false;
        }

        return DB::table('billing_postings')->where('journal_id', $journalId)->count() === 2
            && DB::table('billing_postings')->where('journal_id', $journalId)
                ->where('wallet_id', $journal->wallet_id)
                ->where('account_code', 'wallet_liability')
                ->where('side', 'debit')
                ->where('amount_rials', $amountRials)->exists()
            && DB::table('billing_postings')->where('journal_id', $journalId)
                ->whereNull('wallet_id')
                ->where('account_code', 'order_liability')
                ->where('side', 'credit')
                ->where('amount_rials', $amountRials)->exists();
    }
}

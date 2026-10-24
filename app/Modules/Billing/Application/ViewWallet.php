<?php

namespace App\Modules\Billing\Application;

use Illuminate\Support\Facades\DB;

final class ViewWallet
{
    /** Current locking read for a decision concurrent with wallet settlement. */
    public function hasAtLeast(int $userId, int $minimumRials, bool $lockForUpdate = false): bool
    {
        if (! $lockForUpdate) {
            return $this->handle($userId)->balanceRials >= $minimumRials;
        }
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('A locked wallet balance check requires a transaction.');
        }

        $walletId = app(WalletAccess::class)->customerWalletId($userId);
        if (DB::table('billing_wallets')->where('id', $walletId)->lockForUpdate()->first() === null) {
            return false;
        }

        // A locking read avoids a stale MySQL REPEATABLE READ snapshot after
        // another transaction has spent wallet credit on an order.
        $postings = DB::table('billing_postings')
            ->where('wallet_id', $walletId)->where('account_code', 'wallet_liability')
            ->orderBy('id')->lockForUpdate()->get(['side', 'amount_rials']);

        $balance = 0;
        foreach ($postings as $posting) {
            $balance += $posting->side === 'credit'
                ? (int) $posting->amount_rials
                : -((int) $posting->amount_rials);
        }

        return $balance >= $minimumRials;
    }

    public function handle(int $userId): WalletSnapshot
    {
        $walletId = app(WalletAccess::class)->customerWalletId($userId);

        // The wallet liability postings, not a mutable user.balance, are authoritative.
        $credits = (int) DB::table('billing_postings')
            ->where('wallet_id', $walletId)->where('account_code', 'wallet_liability')
            ->where('side', 'credit')->sum('amount_rials');
        $debits = (int) DB::table('billing_postings')
            ->where('wallet_id', $walletId)->where('account_code', 'wallet_liability')
            ->where('side', 'debit')->sum('amount_rials');

        $topups = DB::table('billing_topups')->where('wallet_id', $walletId)
            ->orderByDesc('id')->limit(10)->get(['id', 'amount_rials', 'status', 'created_at'])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'amount_rials' => (int) $row->amount_rials,
                'status' => (string) $row->status,
                'created_at' => (string) $row->created_at,
            ])->all();

        return new WalletSnapshot($credits - $debits, array_values($topups));
    }
}

<?php

namespace App\Modules\Billing\Application;

use Illuminate\Support\Facades\DB;

final class ViewWallet
{
    public function handle(int $userId): WalletSnapshot
    {
        $walletId = app(WalletAccess::class)->walletId($userId);

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

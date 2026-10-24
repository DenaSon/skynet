<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Billing\Application\WalletAccess;
use Illuminate\Support\Facades\DB;

/** Balanced ledger fixtures for eligibility tests; no external bank transfer. */
final class PanelWalletFunding
{
    public static function credit(int $userId, int $rials): void
    {
        self::post($userId, $rials, true);
    }

    public static function spend(int $userId, int $rials): void
    {
        self::post($userId, $rials, false);
    }

    private static function post(int $userId, int $rials, bool $credit): void
    {
        $walletId = app(WalletAccess::class)->customerWalletId($userId);
        $journalId = DB::table('billing_journals')->insertGetId([
            'wallet_id' => $walletId,
            'kind' => $credit ? 'test_topup' : 'test_purchase',
            'idempotency_key' => 'test:panel:'.bin2hex(random_bytes(12)),
            'created_at' => now(),
        ]);

        DB::table('billing_postings')->insert([
            [
                'journal_id' => $journalId,
                'wallet_id' => $walletId,
                'account_code' => 'wallet_liability',
                'side' => $credit ? 'credit' : 'debit',
                'amount_rials' => $rials,
            ],
            [
                'journal_id' => $journalId,
                'wallet_id' => null,
                'account_code' => $credit ? 'cash_clearing' : 'sales_revenue',
                'side' => $credit ? 'debit' : 'credit',
                'amount_rials' => $rials,
            ],
        ]);
    }
}

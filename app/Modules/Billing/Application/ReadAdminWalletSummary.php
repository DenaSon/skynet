<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Identity\Application\AssertBillingAccess;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * FIN-STAT-01: global balances from canonical wallet liability postings.
 *
 * Read only, includes empty and inactive-user wallets, never writes balances
 * and never mistakes bank receipts / top-up requests for verified credit.
 */
final readonly class ReadAdminWalletSummary
{
    public function __construct(private AssertBillingAccess $access) {}

    /**
     * @return array{
     *     balanceRials:int,
     *     walletsTotal:int,
     *     walletsPositive:int,
     *     walletsNegative:int,
     *     pendingTopups:int
     * }
     */
    public function handle(int $rootId): array
    {
        $this->access->reviewer($rootId);

        // One grouped SQL query for ALL customer wallets, not the eight wallets
        // displayed on a page. The account-code filter is part of the LEFT JOIN
        // so a wallet without postings still counts as a zero-balance wallet.
        $perWallet = DB::table('billing_wallets as w')
            ->leftJoin('billing_postings as p', static function (JoinClause $join): void {
                $join->on('p.wallet_id', '=', 'w.id')
                    ->where('p.account_code', '=', 'wallet_liability');
            })
            ->groupBy('w.id')
            ->selectRaw("w.id as wallet_id,
                COALESCE(SUM(CASE WHEN p.side = 'credit' THEN p.amount_rials ELSE 0 END), 0) as credits,
                COALESCE(SUM(CASE WHEN p.side = 'debit' THEN p.amount_rials ELSE 0 END), 0) as debits");

        $totals = DB::query()->fromSub($perWallet, 'wallet_balances')
            ->selectRaw('COUNT(*) as wallets_total,
                COALESCE(SUM(credits), 0) as credits_total,
                COALESCE(SUM(debits), 0) as debits_total,
                COALESCE(SUM(CASE WHEN credits > debits THEN 1 ELSE 0 END), 0) as wallets_positive,
                COALESCE(SUM(CASE WHEN credits < debits THEN 1 ELSE 0 END), 0) as wallets_negative')
            ->first();

        return [
            'balanceRials' => (int) ($totals->credits_total ?? 0) - (int) ($totals->debits_total ?? 0),
            'walletsTotal' => (int) ($totals->wallets_total ?? 0),
            'walletsPositive' => (int) ($totals->wallets_positive ?? 0),
            'walletsNegative' => (int) ($totals->wallets_negative ?? 0),
            'pendingTopups' => (int) DB::table('billing_topups')->where('status', 'submitted')->count(),
        ];
    }
}

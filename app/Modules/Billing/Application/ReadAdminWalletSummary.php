<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Identity\Application\AssertBillingAccess;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/** Read-only totals for all wallets, including inactive users and empty wallets. */
final readonly class ReadAdminWalletSummary
{
    public function __construct(private AssertBillingAccess $access) {}

    /**
     * Reuse one ledger-derived, one-row-per-wallet read model in both summary
     * and directory. Role priority: staff/other, reseller, member, other.
     * Grouping role assignments avoids multiplying wallet balances.
     */
    public static function walletBalancesQuery(): Builder
    {
        $postings = DB::table('billing_postings')
            ->where('account_code', 'wallet_liability')
            ->groupBy('wallet_id')
            ->selectRaw("wallet_id,
                SUM(CASE WHEN side = 'credit' THEN amount_rials ELSE 0 END)
                - SUM(CASE WHEN side = 'debit' THEN amount_rials ELSE 0 END) as balance_rials");

        $roles = DB::table('model_has_roles as mr')
            ->join('roles as r', 'r.id', '=', 'mr.role_id')
            ->where('mr.model_type', User::class)
            ->groupBy('mr.model_id')
            ->selectRaw("mr.model_id,
                MAX(CASE WHEN r.name IN ('admin', 'super-admin') THEN 1 ELSE 0 END) as is_staff,
                MAX(CASE WHEN r.name = 'reseller' THEN 1 ELSE 0 END) as is_reseller,
                MAX(CASE WHEN r.name = 'member' THEN 1 ELSE 0 END) as is_member");

        return DB::table('billing_wallets as w')
            ->join('users as u', 'u.id', '=', 'w.user_id')
            ->leftJoinSub($postings, 'p', static function (JoinClause $join): void {
                $join->on('p.wallet_id', '=', 'w.id');
            })
            ->leftJoinSub($roles, 'ur', static function (JoinClause $join): void {
                $join->on('ur.model_id', '=', 'w.user_id');
            })
            ->select(['w.id as wallet_id', 'w.user_id', 'u.name'])
            ->selectRaw('COALESCE(p.balance_rials, 0) as balance_rials')
            ->selectRaw("CASE
                WHEN COALESCE(ur.is_staff, 0) = 1 THEN 'other'
                WHEN COALESCE(ur.is_reseller, 0) = 1 THEN 'reseller'
                WHEN COALESCE(ur.is_member, 0) = 1 THEN 'member'
                ELSE 'other' END as account_type");
    }

    /**
     * @return array{
     *     balanceRials:int,memberBalanceRials:int,resellerBalanceRials:int,otherBalanceRials:int,
     *     walletsTotal:int,walletsPositive:int,walletsNegative:int,pendingTopups:int
     * }
     */
    public function handle(int $rootId): array
    {
        $this->access->reviewer($rootId);

        $totals = DB::query()->fromSub(self::walletBalancesQuery(), 'balances')
            ->selectRaw("COUNT(*) as wallets_total,
                COALESCE(SUM(balance_rials), 0) as balance_rials,
                COALESCE(SUM(CASE WHEN balance_rials > 0 THEN 1 ELSE 0 END), 0) as wallets_positive,
                COALESCE(SUM(CASE WHEN balance_rials < 0 THEN 1 ELSE 0 END), 0) as wallets_negative,
                COALESCE(SUM(CASE WHEN account_type = 'member' THEN balance_rials ELSE 0 END), 0) as member_balance_rials,
                COALESCE(SUM(CASE WHEN account_type = 'reseller' THEN balance_rials ELSE 0 END), 0) as reseller_balance_rials,
                COALESCE(SUM(CASE WHEN account_type = 'other' THEN balance_rials ELSE 0 END), 0) as other_balance_rials")
            ->first();

        return [
            'balanceRials' => (int) ($totals->balance_rials ?? 0),
            'memberBalanceRials' => (int) ($totals->member_balance_rials ?? 0),
            'resellerBalanceRials' => (int) ($totals->reseller_balance_rials ?? 0),
            'otherBalanceRials' => (int) ($totals->other_balance_rials ?? 0),
            'walletsTotal' => (int) ($totals->wallets_total ?? 0),
            'walletsPositive' => (int) ($totals->wallets_positive ?? 0),
            'walletsNegative' => (int) ($totals->wallets_negative ?? 0),
            'pendingTopups' => (int) DB::table('billing_topups')->where('status', 'submitted')->count(),
        ];
    }
}

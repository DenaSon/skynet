<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Identity\Application\AssertBillingAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Mean of the last thirty Tehran end-of-day balances, including idle days. */
final readonly class ReadWalletDailyAverage
{
    public function __construct(private AssertBillingAccess $access) {}

    /**
     * @param  list<int>  $userIds
     * @return array<int, array{averageRials:float,closingRials:int}>
     */
    public function handle(int $actorId, array $userIds, Carbon $end): array
    {
        $this->access->financeViewer($actorId);
        if ($userIds === []) {
            return [];
        }

        $end = $end->copy()->timezone('Asia/Tehran')->startOfDay();
        $start = $end->copy()->subDays(30);
        // Each posting contributes to all subsequent closing balances.
        // Opening postings count thirty times; the last day counts once.
        $weight = 'CASE';
        $bindings = [];
        for ($day = 1; $day <= 30; $day++) {
            $weight .= ' WHEN journals.created_at < ? THEN '.(31 - $day);
            $bindings[] = $start->copy()->addDays($day)->utc();
        }
        $weight .= ' ELSE 0 END';
        $net = "CASE WHEN postings.side = 'credit' THEN CAST(postings.amount_rials AS DECIMAL(30,0)) WHEN postings.side = 'debit' THEN -CAST(postings.amount_rials AS DECIMAL(30,0)) ELSE 0 END";
        $postings = DB::table('billing_postings as postings')
            ->join('billing_journals as journals', 'journals.id', '=', 'postings.journal_id')
            ->join('billing_wallets as wallets', 'wallets.id', '=', 'postings.wallet_id')
            ->whereIntegerInRaw('wallets.user_id', $userIds)
            ->where('postings.account_code', 'wallet_liability')
            ->where('journals.created_at', '<', $end->copy()->utc())
            ->groupBy('postings.wallet_id')
            ->select('postings.wallet_id')
            ->selectRaw("SUM(($net) * ($weight)) as balance_days, SUM($net) as closing_rials", $bindings);
        $rows = DB::table('billing_wallets as wallets')
            ->whereIntegerInRaw('wallets.user_id', $userIds)
            ->leftJoinSub($postings, 'balances', 'balances.wallet_id', '=', 'wallets.id')
            ->select('wallets.user_id', 'balances.balance_days', 'balances.closing_rials')->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->user_id] = [
                'averageRials' => (float) ($row->balance_days ?? 0) / 30,
                'closingRials' => (int) ($row->closing_rials ?? 0),
            ];
        }

        return $result;
    }
}

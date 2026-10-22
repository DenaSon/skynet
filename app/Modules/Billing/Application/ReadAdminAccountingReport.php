<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Identity\Application\AssertBillingAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ACC-02: read-only business snapshot. Gross verified sales are NOT bank cash;
 * profit is indicative until all costs and reversals have been recorded.
 */
final readonly class ReadAdminAccountingReport
{
    public function __construct(private AssertBillingAccess $access) {}

    /**
     * @return array{
     *   period:string,start:string,end:string,salesRials:int,expensesRials:int,
     *   drawsRials:int,profitRials:int,afterDrawsRials:int,
     *   expenseEntries:int,drawEntries:int,generatedAt:string
     * }
     */
    public function handle(int $actorId, string $period): array
    {
        $this->access->reviewer($actorId);

        if (! in_array($period, ['7', '30', 'month'], true)) {
            throw ValidationException::withMessages(['period' => 'Invalid accounting report period.']);
        }

        // Calendar days in Tehran including today; use UTC bounds for stored timestamps.
        $today = Carbon::now('Asia/Tehran')->startOfDay();
        $start = match ($period) {
            '7' => $today->copy()->subDays(6),
            '30' => $today->copy()->subDays(29),
            'month' => $today->copy()->startOfMonth(),
        };
        $from = $start->copy()->utc();
        $until = $today->copy()->addDay()->utc();

        // Same immutable verified settlement sources as STAT-02, without
        // counting wallet topups, pending invoices or created-but-unpaid orders.
        $bank = (int) DB::table('sales_bank_settlements')
            ->where('settled_at', '>=', $from)->where('settled_at', '<', $until)
            ->sum('amount_rials');
        $wallet = (int) DB::table('sales_order_settlements')
            ->where('settled_at', '>=', $from)->where('settled_at', '<', $until)
            ->sum('amount_rials');
        $sales = $bank + $wallet;

        // Signed compensating entries reverse their ORIGINAL kind, but at
        // their own posting date. The original entry remains immutable.
        $book = DB::table('accounting_entries')
            ->where('created_at', '>=', $from)->where('created_at', '<', $until)
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'expense' THEN
                CASE WHEN reversal_of_id IS NULL THEN amount_rials ELSE -amount_rials END
                ELSE 0 END), 0) as expenses_rials")
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'owner_draw' THEN
                CASE WHEN reversal_of_id IS NULL THEN amount_rials ELSE -amount_rials END
                ELSE 0 END), 0) as draws_rials")
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'expense' AND reversal_of_id IS NULL
                THEN 1 ELSE 0 END), 0) as expense_entries")
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'owner_draw' AND reversal_of_id IS NULL
                THEN 1 ELSE 0 END), 0) as draw_entries")
            ->first();

        $expenses = (int) ($book->expenses_rials ?? 0);
        $draws = (int) ($book->draws_rials ?? 0);
        $profit = $sales - $expenses;

        return [
            'period' => $period,
            'start' => $start->format('Y-m-d'),
            'end' => $today->format('Y-m-d'),
            'salesRials' => $sales,
            'expensesRials' => $expenses,
            'drawsRials' => $draws,
            'profitRials' => $profit,
            'afterDrawsRials' => $profit - $draws,
            'expenseEntries' => (int) ($book->expense_entries ?? 0),
            'drawEntries' => (int) ($book->draw_entries ?? 0),
            'generatedAt' => Carbon::now('Asia/Tehran')->format('Y-m-d H:i'),
        ];
    }
}

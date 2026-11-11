<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Identity\Application\AssertBillingAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * FIN-CASH-01: verified recorded incoming transfers, not a live bank statement.
 * Wallet-funded purchases are internal reuse of prior top-ups, never new cash.
 */
final readonly class ReadAdminCashReceipts
{
    public function __construct(
        private AssertBillingAccess $access,
        private ReadAdminWalletSummary $wallets,
    ) {}

    /**
     * @return array{
     *   period:string,start:?string,end:string,
     *   topupsRials:int,topupsCount:int,customerTopupsRials:int,customerTopupsCount:int,
     *   adminTopupsRials:int,adminTopupsCount:int,bankSalesRials:int,bankSalesCount:int,
     *   cashInRials:int,walletSalesRials:int,walletSalesCount:int,
     *   salesRials:int,currentWalletBalanceRials:int,generatedAt:string
     * }
     */
    public function handle(int $actorId, string $period, ?Carbon $asOf = null): array
    {
        $this->access->reviewer($actorId);

        if (! in_array($period, ['today', 'yesterday_same_time', '7', '30', 'all'], true)) {
            throw ValidationException::withMessages(['period' => 'Invalid cash report period.']);
        }

        // Daily comparison uses identical Tehran clock cutoffs on consecutive days.
        // The extra second includes records at the current second with SQL's < bound.
        $now = ($asOf ?? Carbon::now('Asia/Tehran'))->copy()->timezone('Asia/Tehran');
        $today = $now->copy()->startOfDay();
        $start = match ($period) {
            'today' => $today->copy(),
            'yesterday_same_time' => $today->copy()->subDay(),
            '7' => $today->copy()->subDays(6),
            '30' => $today->copy()->subDays(29),
            default => null,
        };
        $from = $start?->copy()->utc();
        $until = match ($period) {
            'today' => $now->copy()->addSecond()->utc(),
            'yesterday_same_time' => $now->copy()->subDay()->addSecond()->utc(),
            default => $today->copy()->addDay()->utc(),
        };

        // The unique verified receipt is the final accepted bank proof for
        // each approved top-up. Pending/rejected requests and wallet spending
        // never count again as bank inflows.
        $topups = DB::table('billing_verified_receipts as receipt')
            ->join('billing_topups as topup', 'topup.id', '=', 'receipt.topup_id')
            ->where('topup.status', 'approved')
            ->where('receipt.verified_at', '<', $until);
        if ($from !== null) {
            $topups->where('receipt.verified_at', '>=', $from);
        }
        // A bank-attested partial correction changes the confirmed cash amount,
        // not the immutable original receipt or the number of incoming transfers.
        $netTopup = 'topup.amount_rials';
        if (Schema::hasTable('billing_topup_amount_corrections')) {
            $topups->leftJoin('billing_topup_amount_corrections as correction', 'correction.topup_id', '=', 'topup.id');
            $netTopup = 'topup.amount_rials - COALESCE(correction.adjustment_amount_rials, 0)';
        }
        $topupTotals = $topups->selectRaw("COUNT(*) as receipts_count,
                COALESCE(SUM($netTopup), 0) as amount_rials,
                COALESCE(SUM(CASE WHEN topup.origin = 'admin_verified_bank' THEN $netTopup ELSE 0 END), 0) as admin_amount_rials,
                COALESCE(SUM(CASE WHEN topup.origin = 'admin_verified_bank' THEN 1 ELSE 0 END), 0) as admin_count")->first();

        // Direct bank settlements represent genuinely new recorded inflows.
        $bank = DB::table('sales_bank_settlements')->where('settled_at', '<', $until);
        if ($from !== null) {
            $bank->where('settled_at', '>=', $from);
        }
        $bankTotals = $bank->selectRaw('COUNT(*) as receipts_count, COALESCE(SUM(amount_rials), 0) as amount_rials')->first();

        // Wallet order settlement is sales activity, not another deposit.
        $wallet = DB::table('sales_order_settlements as payment')
            ->join('sales_orders as orders', 'orders.id', '=', 'payment.sales_order_id')
            ->where('orders.status', '!=', 'refunded')
            ->whereIn('payment.method', ['wallet', 'customer_wallet', 'reseller_wallet'])
            ->where('payment.settled_at', '<', $until);
        if ($from !== null) {
            $wallet->where('payment.settled_at', '>=', $from);
        }
        $walletTotals = $wallet->selectRaw('COUNT(*) as receipts_count, COALESCE(SUM(payment.amount_rials), 0) as amount_rials')->first();

        $topupRials = (int) ($topupTotals->amount_rials ?? 0);
        $adminTopupRials = (int) ($topupTotals->admin_amount_rials ?? 0);
        $adminTopupCount = (int) ($topupTotals->admin_count ?? 0);
        $bankRials = (int) ($bankTotals->amount_rials ?? 0);
        $walletRials = (int) ($walletTotals->amount_rials ?? 0);

        return [
            'period' => $period,
            'start' => $start?->format('Y-m-d'),
            'end' => $period === 'yesterday_same_time'
                ? $start->format('Y-m-d') : $today->format('Y-m-d'),
            'topupsRials' => $topupRials,
            'topupsCount' => (int) ($topupTotals->receipts_count ?? 0),
            'customerTopupsRials' => $topupRials - $adminTopupRials,
            'customerTopupsCount' => (int) ($topupTotals->receipts_count ?? 0) - $adminTopupCount,
            'adminTopupsRials' => $adminTopupRials,
            'adminTopupsCount' => $adminTopupCount,
            'bankSalesRials' => $bankRials,
            'bankSalesCount' => (int) ($bankTotals->receipts_count ?? 0),
            'cashInRials' => $topupRials + $bankRials,
            'walletSalesRials' => $walletRials,
            'walletSalesCount' => (int) ($walletTotals->receipts_count ?? 0),
            'salesRials' => $bankRials + $walletRials,
            // Current snapshot; intentionally not restricted to the period.
            'currentWalletBalanceRials' => $this->wallets->handle($actorId)['balanceRials'],
            'generatedAt' => Carbon::now('Asia/Tehran')->format('Y-m-d H:i'),
        ];
    }
}

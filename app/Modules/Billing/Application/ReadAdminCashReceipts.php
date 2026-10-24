<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Identity\Application\AssertBillingAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
     *   topupsRials:int,topupsCount:int,bankSalesRials:int,bankSalesCount:int,
     *   cashInRials:int,walletSalesRials:int,walletSalesCount:int,
     *   salesRials:int,currentWalletBalanceRials:int,generatedAt:string
     * }
     */
    public function handle(int $actorId, string $period): array
    {
        $this->access->reviewer($actorId);

        if (! in_array($period, ['7', '30', 'all'], true)) {
            throw ValidationException::withMessages(['period' => 'Invalid cash report period.']);
        }

        // Include whole Tehran calendar days, not rolling 24-hour windows.
        $today = Carbon::now('Asia/Tehran')->startOfDay();
        $start = match ($period) {
            '7' => $today->copy()->subDays(6),
            '30' => $today->copy()->subDays(29),
            default => null,
        };
        $from = $start?->copy()->utc();
        $until = $today->copy()->addDay()->utc();

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
        $topupTotals = $topups->selectRaw('COUNT(*) as receipts_count, COALESCE(SUM(topup.amount_rials), 0) as amount_rials')->first();

        // Direct bank settlements represent genuinely new recorded inflows.
        $bank = DB::table('sales_bank_settlements')->where('settled_at', '<', $until);
        if ($from !== null) {
            $bank->where('settled_at', '>=', $from);
        }
        $bankTotals = $bank->selectRaw('COUNT(*) as receipts_count, COALESCE(SUM(amount_rials), 0) as amount_rials')->first();

        // Wallet order settlement is sales activity, not another deposit.
        $wallet = DB::table('sales_order_settlements')
            ->where('method', 'wallet')
            ->where('settled_at', '<', $until);
        if ($from !== null) {
            $wallet->where('settled_at', '>=', $from);
        }
        $walletTotals = $wallet->selectRaw('COUNT(*) as receipts_count, COALESCE(SUM(amount_rials), 0) as amount_rials')->first();

        $topupRials = (int) ($topupTotals->amount_rials ?? 0);
        $bankRials = (int) ($bankTotals->amount_rials ?? 0);
        $walletRials = (int) ($walletTotals->amount_rials ?? 0);

        return [
            'period' => $period,
            'start' => $start?->format('Y-m-d'),
            'end' => $today->format('Y-m-d'),
            'topupsRials' => $topupRials,
            'topupsCount' => (int) ($topupTotals->receipts_count ?? 0),
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

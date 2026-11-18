<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Billing\Application\ValidBankSettlements;
use App\Modules\Identity\Application\ResolveSalesBuyer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only, reseller-owned on-demand sales and customer counters. */
final readonly class ReadResellerSalesReport
{
    public function __construct(private ResolveSalesBuyer $buyers) {}

    /**
     * @return array{days:int,from:string,to:string,services:int,namedServices:int,pending:int,sales:int,renewals:int,transactions:int,revenueRials:int,generatedAt:string}
     */
    public function handle(int $buyerId, int $days): array
    {
        if (! $this->buyers->isReseller($buyerId)) {
            throw new AuthorizationException;
        }
        if (! in_array($days, [0, 7, 30], true)) {
            throw ValidationException::withMessages(['days' => 'Unsupported report window.']);
        }

        $today = Carbon::now('Asia/Tehran')->startOfDay();
        $from = $days === 0 ? null : $today->copy()->subDays($days - 1)->utc();
        $until = $today->copy()->addDay()->utc();

        // Match the trusted Admin report's settled bank/wallet sources, not
        // order prices, top-ups or incomplete checkout attempts.
        $bank = ValidBankSettlements::query()
            ->join('sales_orders as orders', 'orders.id', '=', 'payment.sales_order_id')
            ->leftJoin('sales_renewal_orders as renewal', 'renewal.sales_order_id', '=', 'orders.id')
            ->where('orders.buyer_user_id', $buyerId)
            ->where('payment.settled_at', '<', $until)
            ->selectRaw('payment.amount_rials as amount_rials, payment.settled_at as settled_at, CASE WHEN renewal.sales_order_id IS NULL THEN 0 ELSE 1 END as renewed');

        $wallet = DB::table('sales_order_settlements as payment')
            ->join('sales_orders as orders', 'orders.id', '=', 'payment.sales_order_id')
            ->leftJoin('sales_renewal_orders as renewal', 'renewal.sales_order_id', '=', 'orders.id')
            ->where('orders.buyer_user_id', $buyerId)
            ->where('orders.status', '!=', 'refunded')
            ->where('payment.settled_at', '<', $until)
            ->selectRaw('payment.amount_rials as amount_rials, payment.settled_at as settled_at, CASE WHEN renewal.sales_order_id IS NULL THEN 0 ELSE 1 END as renewed');

        if ($from !== null) {
            $bank->where('payment.settled_at', '>=', $from);
            $wallet->where('payment.settled_at', '>=', $from);
        }

        $totals = DB::query()->fromSub($bank->unionAll($wallet), 'settled')
            ->selectRaw('COUNT(*) as transactions, COALESCE(SUM(amount_rials), 0) as revenue_rials, COALESCE(SUM(renewed), 0) as renewals, MIN(settled_at) as first_settled_at')
            ->first();
        $transactions = (int) ($totals->transactions ?? 0);
        $renewals = (int) ($totals->renewals ?? 0);
        $firstSettledAt = $totals->first_settled_at ?? null;
        $reportFrom = $from === null && is_string($firstSettledAt)
            ? Carbon::parse($firstSettledAt, 'UTC')->timezone('Asia/Tehran')->format('Y-m-d')
            : ($from ?? $today)->copy()->timezone('Asia/Tehran')->format('Y-m-d');

        $services = DB::table('sales_orders as orders')
            ->join('provisioning_order_links as links', 'links.sales_order_id', '=', 'orders.id')
            ->leftJoin('sales_service_customer_labels as customer', 'customer.sales_order_id', '=', 'orders.id')
            ->where('orders.buyer_user_id', $buyerId)
            ->whereIn('orders.status', ['paid', 'fulfilled'])
            ->selectRaw('COUNT(*) as services, SUM(CASE WHEN customer.label IS NOT NULL THEN 1 ELSE 0 END) as named')
            ->first();

        return [
            'days' => $days,
            'from' => $reportFrom,
            'to' => $today->format('Y-m-d'),
            'services' => (int) ($services->services ?? 0),
            'namedServices' => (int) ($services->named ?? 0),
            'pending' => (int) DB::table('sales_orders')
                ->where('buyer_user_id', $buyerId)->where('status', 'pending_payment')->count(),
            'sales' => $transactions - $renewals,
            'renewals' => $renewals,
            'transactions' => $transactions,
            'revenueRials' => (int) ($totals->revenue_rials ?? 0),
            'generatedAt' => Carbon::now('Asia/Tehran')->format('Y-m-d H:i'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** STAT-02: read-only, on-demand aggregates from canonical settlement and identity tables. */
final readonly class ReadAdminBusinessReport
{
    public function __construct(private AssertCatalogManagementAccess $access) {}

    /**
     * @return array{
     *     days:int, start:string, end:string, revenueRials:int, settled:int,
     *     newPurchases:int, renewals:int, buyers:int, fulfilled:int, pendingNow:int,
     *     newUsers:int, linkedTelegram:int, salesContacts:int, adminContacts:int,
     *     salesReachable:int, adminReachable:int, salesEngaged:int, adminEngaged:int,
     *     salesNewContacts:int, adminNewContacts:int, generatedAt:string
     * }
     */
    public function handle(int $actorId, int $days): array
    {
        $this->access->handle($actorId);
        if (! in_array($days, [7, 30], true)) {
            throw ValidationException::withMessages(['days' => 'Unsupported report window.']);
        }

        // Tehran calendar days, including today; SQL boundaries are UTC.
        $today = Carbon::now('Asia/Tehran')->startOfDay();
        $from = $today->copy()->subDays($days - 1)->utc();
        $until = $today->copy()->addDay()->utc();
        $totals = $this->settledTotalsBetween($actorId, $from, $until);

        $contacts = static fn (string $botKey) => DB::table('telegram_bot_contacts')->where('bot_key', $botKey);
        $sales = $contacts('sales');
        $admin = $contacts('admin');

        $settled = (int) ($totals['settled']);
        $renewals = (int) ($totals['renewals']);

        return [
            'days' => $days,
            'start' => $from->copy()->timezone('Asia/Tehran')->format('Y-m-d'),
            'end' => $today->format('Y-m-d'),
            'revenueRials' => (int) ($totals['revenueRials']),
            'settled' => $settled,
            'newPurchases' => $settled - $renewals,
            'renewals' => $renewals,
            'buyers' => (int) ($totals['buyers']),
            'fulfilled' => (int) ($totals['fulfilled']),
            'pendingNow' => (int) DB::table('sales_orders')->where('status', 'pending_payment')->count(),
            'newUsers' => (int) DB::table('users')->where('created_at', '>=', $from)
                ->where('created_at', '<', $until)->count(),
            'linkedTelegram' => (int) DB::table('telegram_accounts')->count(),
            'salesContacts' => (int) (clone $sales)->count(),
            'adminContacts' => (int) (clone $admin)->count(),
            'salesReachable' => (int) (clone $sales)->where('delivery_status', 'reachable')->count(),
            'adminReachable' => (int) (clone $admin)->where('delivery_status', 'reachable')->count(),
            'salesEngaged' => (int) (clone $sales)->where('last_seen_at', '>=', $from)
                ->where('last_seen_at', '<', $until)->count(),
            'adminEngaged' => (int) (clone $admin)->where('last_seen_at', '>=', $from)
                ->where('last_seen_at', '<', $until)->count(),
            'salesNewContacts' => (int) (clone $sales)->where('created_at', '>=', $from)
                ->where('created_at', '<', $until)->count(),
            'adminNewContacts' => (int) (clone $admin)->where('created_at', '>=', $from)
                ->where('created_at', '<', $until)->count(),
            'generatedAt' => Carbon::now('Asia/Tehran')->format('Y-m-d H:i'),
        ];
    }

    /**
     * Shared canonical purchase-settlement totals for the existing 7/30-day
     * report and management KPIs. Authenticated, half-open UTC bounds.
     *
     * Verified bank settlements remain recorded inflows; corrected/refunded
     * wallet orders are excluded exactly as in STAT-02. This is gross settled
     * purchase activity, not net profit or bank account balance.
     *
     * @return array{revenueRials:int,settled:int,renewals:int,buyers:int,fulfilled:int}
     */
    public function settledTotalsBetween(int $actorId, Carbon $from, Carbon $until): array
    {
        $this->access->handle($actorId);
        if ($until->lessThanOrEqualTo($from)) {
            throw ValidationException::withMessages(['period' => 'Invalid reporting interval.']);
        }

        // Preserve the original verified bank/wallet journal query and its business rules.
        $totals = DB::query()->fromSub($this->settledRowsBetween($from, $until), 'settled')
            ->selectRaw('COUNT(*) as settled_count, COUNT(DISTINCT buyer_id) as buyers_count, COALESCE(SUM(amount_rials), 0) as revenue_rials, COALESCE(SUM(CASE WHEN renewed = 1 THEN 1 ELSE 0 END), 0) as renewal_count, COALESCE(SUM(CASE WHEN order_status = \'fulfilled\' THEN 1 ELSE 0 END), 0) as fulfilled_count')
            ->first();

        return [
            'revenueRials' => (int) ($totals->revenue_rials ?? 0),
            'settled' => (int) ($totals->settled_count ?? 0),
            'renewals' => (int) ($totals->renewal_count ?? 0),
            'buyers' => (int) ($totals->buyers_count ?? 0),
            'fulfilled' => (int) ($totals->fulfilled_count ?? 0),
        ];
    }

    /**
     * Groups only canonical settled purchases by their immutable order category.
     * Historical NULL values are unknown, not inferred from current roles or price.
     *
     * @return array{channels:array{direct:array{revenueRials:int,transactions:int,uniqueBuyers:int},reseller:array{revenueRials:int,transactions:int,uniqueBuyers:int},unknown:array{revenueRials:int,transactions:int,uniqueBuyers:int}},topBuyers:list<array{buyerUserId:int,revenueRials:int,transactions:int}>,topThreeRevenueRials:int,topThreeSharePercent:?float}
     */
    public function settledChannelBreakdownBetween(int $actorId, Carbon $from, Carbon $until): array
    {
        $this->access->handle($actorId);
        if ($until->lessThanOrEqualTo($from)) {
            throw ValidationException::withMessages(['period' => 'Invalid reporting interval.']);
        }

        $categorySql = "CASE WHEN buyer_category IN ('direct', 'reseller') THEN buyer_category ELSE 'unknown' END";
        $groups = DB::query()->fromSub($this->settledRowsBetween($from, $until), 'settled')
            ->selectRaw("$categorySql as category, COUNT(*) as transactions, COUNT(DISTINCT buyer_id) as buyers, COALESCE(SUM(amount_rials), 0) as revenue_rials")
            ->groupByRaw($categorySql)->get();

        $empty = ['revenueRials' => 0, 'transactions' => 0, 'uniqueBuyers' => 0];
        $channels = ['direct' => $empty, 'reseller' => $empty, 'unknown' => $empty];
        foreach ($groups as $group) {
            $channels[$group->category] = [
                'revenueRials' => (int) $group->revenue_rials,
                'transactions' => (int) $group->transactions,
                'uniqueBuyers' => (int) $group->buyers,
            ];
        }

        // One bounded SQL top-three, never materialize the full buyer ledger.
        $top = DB::query()->fromSub($this->settledRowsBetween($from, $until), 'settled')
            ->selectRaw('buyer_id, COUNT(*) as transactions, COALESCE(SUM(amount_rials), 0) as revenue_rials')
            ->groupBy('buyer_id')->orderByDesc('revenue_rials')->orderBy('buyer_id')
            ->limit(3)->get()
            ->map(static fn (object $buyer): array => [
                'buyerUserId' => (int) $buyer->buyer_id,
                'revenueRials' => (int) $buyer->revenue_rials,
                'transactions' => (int) $buyer->transactions,
            ])->all();
        $revenue = array_sum(array_column($channels, 'revenueRials'));
        $topRevenue = array_sum(array_column($top, 'revenueRials'));

        return [
            'channels' => $channels,
            'topBuyers' => $top,
            'topThreeRevenueRials' => $topRevenue,
            'topThreeSharePercent' => $revenue === 0 ? null : round($topRevenue / $revenue * 100, 1),
        ];
    }

    /** Shared bank/wallet purchase rows for both existing and channel aggregates. */
    private function settledRowsBetween(Carbon $from, Carbon $until): \Illuminate\Database\Query\Builder
    {
        $bank = DB::table('sales_bank_settlements as payment')
            ->join('sales_orders as orders', 'orders.id', '=', 'payment.sales_order_id')
            ->leftJoin('sales_renewal_orders as renewal', 'renewal.sales_order_id', '=', 'orders.id')
            ->where('payment.settled_at', '>=', $from)
            ->where('payment.settled_at', '<', $until)
            ->selectRaw('orders.buyer_user_id as buyer_id, orders.buyer_category as buyer_category, orders.status as order_status, payment.amount_rials as amount_rials, CASE WHEN renewal.sales_order_id IS NULL THEN 0 ELSE 1 END as renewed');

        $wallet = DB::table('sales_order_settlements as payment')
            ->join('sales_orders as orders', 'orders.id', '=', 'payment.sales_order_id')
            ->leftJoin('sales_renewal_orders as renewal', 'renewal.sales_order_id', '=', 'orders.id')
            ->where('orders.status', '!=', 'refunded')
            ->where('payment.settled_at', '>=', $from)
            ->where('payment.settled_at', '<', $until)
            ->selectRaw('orders.buyer_user_id as buyer_id, orders.buyer_category as buyer_category, orders.status as order_status, payment.amount_rials as amount_rials, CASE WHEN renewal.sales_order_id IS NULL THEN 0 ELSE 1 END as renewed');

        // Same canonical UNION ALL; no wallet top-ups or pending orders.
        return $bank->unionAll($wallet);
    }
}

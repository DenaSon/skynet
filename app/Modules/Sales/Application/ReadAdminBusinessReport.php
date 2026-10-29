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
        $bank = DB::table('sales_bank_settlements as payment')
            ->join('sales_orders as orders', 'orders.id', '=', 'payment.sales_order_id')
            ->leftJoin('sales_renewal_orders as renewal', 'renewal.sales_order_id', '=', 'orders.id')
            ->where('orders.status', '!=', 'refunded')
            ->where('payment.settled_at', '>=', $from)
            ->where('payment.settled_at', '<', $until)
            ->selectRaw('orders.buyer_user_id as buyer_id, orders.status as order_status, payment.amount_rials as amount_rials, CASE WHEN renewal.sales_order_id IS NULL THEN 0 ELSE 1 END as renewed');

        $wallet = DB::table('sales_order_settlements as payment')
            ->join('sales_orders as orders', 'orders.id', '=', 'payment.sales_order_id')
            ->leftJoin('sales_renewal_orders as renewal', 'renewal.sales_order_id', '=', 'orders.id')
            ->where('orders.status', '!=', 'refunded')
            ->where('payment.settled_at', '>=', $from)
            ->where('payment.settled_at', '<', $until)
            ->selectRaw('orders.buyer_user_id as buyer_id, orders.status as order_status, payment.amount_rials as amount_rials, CASE WHEN renewal.sales_order_id IS NULL THEN 0 ELSE 1 END as renewed');

        // UNION ALL retains the original verified bank/wallet journal rows;
        // no wallet topup, pending invoice, or provisioned-user count is revenue.
        $totals = DB::query()->fromSub($bank->unionAll($wallet), 'settled')
            ->selectRaw('COUNT(*) as settled_count, COUNT(DISTINCT buyer_id) as buyers_count, COALESCE(SUM(amount_rials), 0) as revenue_rials, COALESCE(SUM(CASE WHEN renewed = 1 THEN 1 ELSE 0 END), 0) as renewal_count, COALESCE(SUM(CASE WHEN order_status = \'fulfilled\' THEN 1 ELSE 0 END), 0) as fulfilled_count')
            ->first();

        $contacts = static fn (string $botKey) => DB::table('telegram_bot_contacts')->where('bot_key', $botKey);
        $sales = $contacts('sales');
        $admin = $contacts('admin');

        $settled = (int) ($totals->settled_count ?? 0);
        $renewals = (int) ($totals->renewal_count ?? 0);

        return [
            'days' => $days,
            'start' => $from->copy()->timezone('Asia/Tehran')->format('Y-m-d'),
            'end' => $today->format('Y-m-d'),
            'revenueRials' => (int) ($totals->revenue_rials ?? 0),
            'settled' => $settled,
            'newPurchases' => $settled - $renewals,
            'renewals' => $renewals,
            'buyers' => (int) ($totals->buyers_count ?? 0),
            'fulfilled' => (int) ($totals->fulfilled_count ?? 0),
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
}

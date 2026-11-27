<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class ReadCrmSales
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private ReadAdminBusinessReport $business,
    ) {}

    public function purchases(int $actorId, Carbon $from, Carbon $until, bool $resellerOnly = false): Builder
    {
        $rows = DB::query()->fromSub($this->business->crmSettledRows($actorId, $from, $until), 'settled');
        if ($resellerOnly) {
            $rows->where('buyer_category', 'reseller');
        }

        return $rows->selectRaw('buyer_id, COUNT(*) as transactions, COUNT(DISTINCT order_id) as order_count, SUM(amount_rials) as revenue_rials')
            ->groupBy('buyer_id');
    }

    public function contacts(int $actorId, Carbon $from, Carbon $until): Builder
    {
        $this->access->handle($actorId);

        return DB::table('sales_service_customer_labels as labels')
            ->join('sales_orders as orders', 'orders.id', '=', 'labels.sales_order_id')
            ->where('orders.created_at', '>=', $from)
            ->where('orders.created_at', '<', $until)
            ->where(static function (Builder $query): void {
                $query->whereNotNull('labels.phone')->where('labels.phone', '!=', '')
                    ->orWhere(static function (Builder $named): void {
                        $named->whereNotNull('labels.label')->where('labels.label', '!=', '');
                    });
            });
    }

    public function contactCounts(int $actorId, Carbon $from, Carbon $until): Builder
    {
        return $this->contacts($actorId, $from, $until)
            ->leftJoinSub($this->settledOrderIds($actorId, Carbon::parse('1970-01-01', 'UTC'), Carbon::now('UTC')->addDay()), 'valid_purchase', 'valid_purchase.order_id', '=', 'orders.id')
            ->selectRaw("orders.buyer_user_id as buyer_id, COUNT(DISTINCT NULLIF(labels.phone, '')) as phones, SUM(CASE WHEN NULLIF(labels.phone, '') IS NULL AND NULLIF(labels.label, '') IS NOT NULL THEN 1 ELSE 0 END) as name_only,
                COUNT(DISTINCT CASE WHEN valid_purchase.order_id IS NOT NULL THEN NULLIF(labels.phone, '') END) as purchased_phones,
                SUM(CASE WHEN valid_purchase.order_id IS NOT NULL AND NULLIF(labels.phone, '') IS NULL AND NULLIF(labels.label, '') IS NOT NULL THEN 1 ELSE 0 END) as purchased_name_only")
            ->groupBy('orders.buyer_user_id');
    }

    public function settledOrderIds(int $actorId, Carbon $from, Carbon $until): Builder
    {
        return DB::query()->fromSub($this->business->crmSettledRows($actorId, $from, $until), 'settled')
            ->select('order_id')->distinct();
    }
}

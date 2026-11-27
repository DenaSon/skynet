<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class ReadCrmAccountHistory
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private ReadAdminBusinessReport $business,
        private ReadCrmSales $sales,
    ) {}

    private function settlements(int $actorId, int $subjectId, Carbon $from, Carbon $until): Builder
    {
        return DB::query()->fromSub($this->business->crmSettledRows($actorId, $from, $until), 'settled')
            ->where('buyer_id', $subjectId);
    }

    private function orders(int $actorId, int $subjectId, Carbon $from, Carbon $until): Builder
    {
        $this->access->handle($actorId);

        return DB::table('sales_orders as orders')->where('orders.buyer_user_id', $subjectId)
            ->where('orders.created_at', '>=', $from)->where('orders.created_at', '<', $until);
    }

    /** @return array<string, int> */
    public function summary(int $actorId, int $subjectId, Carbon $from, Carbon $until, bool $reseller): array
    {
        $groups = $this->settlements($actorId, $subjectId, $from, $until)
            ->selectRaw("CASE WHEN buyer_category IN ('direct', 'reseller') THEN buyer_category ELSE 'unknown' END as category, COUNT(*) as transactions, SUM(amount_rials) as rials")
            ->groupByRaw("CASE WHEN buyer_category IN ('direct', 'reseller') THEN buyer_category ELSE 'unknown' END")
            ->get()->keyBy('category');
        $orders = $this->orders($actorId, $subjectId, $from, $until);
        $result = [
            'direct' => (int) ($groups->get('direct')->rials ?? 0),
            'reseller' => (int) ($groups->get('reseller')->rials ?? 0),
            'unknown' => (int) ($groups->get('unknown')->rials ?? 0),
            'transactions' => (int) $groups->sum('transactions'),
            'orders' => (clone $orders)->count(),
            'pending' => (clone $orders)->where('orders.status', 'pending_payment')->count(),
        ];
        if ($reseller) {
            $contacts = $this->sales->contactCounts($actorId, $from, $until)->where('orders.buyer_user_id', $subjectId)->first();
            $result['phones'] = (int) ($contacts->phones ?? 0);
            $result['nameOnly'] = (int) ($contacts->name_only ?? 0);
            $result['purchasedPhones'] = (int) ($contacts->purchased_phones ?? 0);
            $result['noContact'] = (clone $orders)->whereNotExists(static function (Builder $query): void {
                $query->selectRaw('1')->from('sales_service_customer_labels as labels')
                    ->whereColumn('labels.sales_order_id', 'orders.id')
                    ->where(static function (Builder $fields): void {
                        $fields->whereNotNull('labels.phone')->where('labels.phone', '!=', '')
                            ->orWhere(static function (Builder $name): void {
                                $name->whereNotNull('labels.label')->where('labels.label', '!=', '');
                            });
                    });
            })->count();
        }

        return $result;
    }

    /** @return LengthAwarePaginator<int, \stdClass> */
    public function orderPage(int $actorId, int $subjectId, Carbon $from, Carbon $until, string $status, string $search, int $page): LengthAwarePaginator
    {
        $query = $this->orders($actorId, $subjectId, $from, $until)
            ->leftJoin('sales_renewal_orders as renewal', 'renewal.sales_order_id', '=', 'orders.id')
            ->select('orders.id', 'orders.product_name', 'orders.status', 'orders.buyer_category', 'orders.total_price_rials', 'orders.created_at')
            ->selectRaw('CASE WHEN renewal.sales_order_id IS NULL THEN 0 ELSE 1 END as renewed');
        if ($status !== 'all') {
            $query->where('orders.status', $status);
        }
        if ($search !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(static function (Builder $nested) use ($search, $like): void {
                $nested->whereRaw("orders.product_name LIKE ? ESCAPE '!'", [$like]);
                if (ctype_digit($search) && strlen($search) <= 18) {
                    $nested->orWhere('orders.id', $search);
                }
            });
        }

        return $query->orderByDesc('orders.id')->paginate(25, page: $page);
    }

    /** @return LengthAwarePaginator<int, \stdClass> */
    public function settlementPage(int $actorId, int $subjectId, Carbon $from, Carbon $until, int $page): LengthAwarePaginator
    {
        return $this->settlements($actorId, $subjectId, $from, $until)
            ->select('order_id as id', 'order_id', 'buyer_category', 'order_status', 'amount_rials', 'settled_at', 'payment_method', 'renewed')
            ->orderByDesc('settled_at')->orderByDesc('order_id')->orderBy('payment_method')
            ->paginate(25, page: $page);
    }
}

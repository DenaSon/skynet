<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final readonly class ReadCrmLocalServices
{
    public function __construct(private AssertCatalogManagementAccess $access) {}

    /** @return LengthAwarePaginator<int, \stdClass> */
    public function page(int $actorId, int $subjectId, int $page): LengthAwarePaginator
    {
        $this->access->handle($actorId);

        // Match the existing support profile's local ownership rule.
        // Never return credentials, remote identifiers or a live provider state.
        return DB::table('sales_orders as orders')
            ->join('provisioning_order_links as links', 'links.sales_order_id', '=', 'orders.id')
            ->join('provisioning_subscriptions as subscriptions', 'subscriptions.id', '=', 'links.provisioning_subscription_id')
            ->where('orders.buyer_user_id', $subjectId)
            ->whereIn('orders.status', ['paid', 'fulfilled'])
            ->select('orders.id', 'orders.product_name', 'subscriptions.state', 'orders.created_at')
            ->orderByDesc('orders.id')->paginate(50, page: $page);
    }
}

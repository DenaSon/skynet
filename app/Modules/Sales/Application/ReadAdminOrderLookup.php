<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\SuperAdminTrust;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Trusted-root-only local order read; never initiates payment or provisioning. */
final readonly class ReadAdminOrderLookup
{
    public function __construct(private SuperAdminTrust $trust) {}

    /**
     * @return array{
     *   id:int, buyerId:int, buyerName:string, buyerCategory:?string,
     *   customerLabel:?string, productName:string, durationDays:int, trafficGb:?int,
     *   totalPriceRials:int, status:string, createdAt:string, planId:?int,
     *   fulfillmentState:?string, sourceOrderId:?int
     * }
     */
    public function handle(int $actorId, int $orderId): array
    {
        $actor = User::query()->with('telegramAccount')->find($actorId);
        if ($actor === null || ! $this->trust->eligible($actor)) {
            throw new AuthorizationException;
        }
        if ($orderId < 1) {
            throw ValidationException::withMessages(['order' => 'Invalid order ID.']);
        }

        $row = DB::table('sales_orders as orders')
            ->join('users as buyers', 'buyers.id', '=', 'orders.buyer_user_id')
            ->leftJoin('sales_service_customer_labels as labels', 'labels.sales_order_id', '=', 'orders.id')
            ->leftJoin('sales_fulfillment_outbox as intent', 'intent.sales_order_id', '=', 'orders.id')
            ->leftJoin('sales_renewal_orders as renewal', 'renewal.sales_order_id', '=', 'orders.id')
            ->where('orders.id', $orderId)
            ->select(
                'orders.id', 'orders.buyer_user_id', 'buyers.name as buyer_name',
                'orders.buyer_category', 'labels.label as customer_label',
                'orders.product_name', 'orders.duration_days', 'orders.traffic_gb',
                'orders.total_price_rials', 'orders.status', 'orders.created_at',
                'orders.plan_id', 'intent.state as fulfillment_state',
                'renewal.source_order_id',
            )->first();

        if ($row === null) {
            throw ValidationException::withMessages(['order' => 'Order not found.']);
        }

        return [
            'id' => (int) $row->id,
            'buyerId' => (int) $row->buyer_user_id,
            'buyerName' => (string) $row->buyer_name,
            'buyerCategory' => $row->buyer_category === null ? null : (string) $row->buyer_category,
            'customerLabel' => $row->customer_label === null ? null : (string) $row->customer_label,
            'productName' => (string) $row->product_name,
            'durationDays' => (int) $row->duration_days,
            'trafficGb' => $row->traffic_gb === null ? null : (int) $row->traffic_gb,
            'totalPriceRials' => (int) $row->total_price_rials,
            'status' => (string) $row->status,
            'createdAt' => (string) $row->created_at,
            'planId' => $row->plan_id === null ? null : (int) $row->plan_id,
            'fulfillmentState' => $row->fulfillment_state === null ? null : (string) $row->fulfillment_state,
            'sourceOrderId' => $row->source_order_id === null ? null : (int) $row->source_order_id,
        ];
    }
}

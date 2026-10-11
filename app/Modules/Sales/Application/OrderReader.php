<?php

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\ResolveSalesBuyer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrderReader
{
    /**
     * @return list<OrderDetails>
     */
    public function recent(int $buyerId): array
    {
        app(ResolveSalesBuyer::class)->isReseller($buyerId);

        return DB::table('sales_orders')->where('buyer_user_id', $buyerId)
            ->orderByDesc('id')->limit(10)->get()
            ->map(fn (object $row): OrderDetails => $this->map($row))
            ->all();
    }

    public function find(int $buyerId, int $orderId): OrderDetails
    {
        app(ResolveSalesBuyer::class)->isReseller($buyerId);

        $row = DB::table('sales_orders')->where('buyer_user_id', $buyerId)
            ->where('id', $orderId)->first();

        if ($row === null) {
            throw ValidationException::withMessages(['order' => 'Order not found.']);
        }

        return $this->map($row);
    }

    public function byKey(int $buyerId, string $idempotencyKey): ?OrderDetails
    {
        app(ResolveSalesBuyer::class)->isReseller($buyerId);

        $row = DB::table('sales_orders')->where('buyer_user_id', $buyerId)
            ->where('idempotency_key', $idempotencyKey)->first();

        return $row === null ? null : $this->map($row);
    }

    private function map(object $row): OrderDetails
    {
        return new OrderDetails(
            id: (int) $row->id,
            buyerUserId: (int) $row->buyer_user_id,
            productSlug: (string) $row->product_slug,
            productName: (string) $row->product_name,
            durationDays: (int) $row->duration_days,
            trafficGb: (int) $row->traffic_gb,
            publicPriceRials: (int) $row->public_price_rials,
            discountPercent: (int) $row->discount_percent,
            discountRials: (int) $row->discount_rials,
            totalPriceRials: (int) $row->total_price_rials,
            status: (string) $row->status,
            createdAt: (string) $row->created_at,
        );
    }
}

<?php

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\ResolveSalesBuyer;
use Illuminate\Support\Facades\DB;

final class ListProducts
{
    /**
     * @return list<CatalogProduct>
     */
    public function handle(int $buyerId): array
    {
        app(ResolveSalesBuyer::class)->isReseller($buyerId);

        $products = DB::table('sales_products')->where('is_active', true)->orderBy('id')->get();

        if ($products->isEmpty()) {
            return [];
        }

        $rates = DB::table('sales_product_rates')
            ->whereIn('product_id', $products->pluck('id'))
            ->where('is_active', true)
            ->orderBy('duration_days')
            ->get()
            ->groupBy('product_id');

        $catalog = [];

        foreach ($products as $product) {
            $available = $rates->get($product->id);

            if ($available === null || $available->isEmpty()) {
                continue;
            }

            $catalog[] = new CatalogProduct(
                id: (int) $product->id,
                slug: (string) $product->slug,
                name: (string) $product->name,
                minGb: (int) $product->min_gb,
                maxGb: (int) $product->max_gb,
                rates: $available->map(static fn (object $rate): ProductRate => new ProductRate(
                    durationDays: (int) $rate->duration_days,
                    basePriceRials: (int) $rate->base_price_rials,
                    perGbRials: (int) $rate->per_gb_rials,
                ))->all(),
            );
        }

        return $catalog;
    }
}

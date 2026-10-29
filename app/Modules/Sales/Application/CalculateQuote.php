<?php

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\ResolveSalesBuyer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CalculateQuote
{
    public function handle(int $buyerId, string $productSlug, int $durationDays, int $trafficGb): PriceQuote
    {
        $isReseller = app(ResolveSalesBuyer::class)->isReseller($buyerId);

        $product = DB::table('sales_products')->where('slug', $productSlug)->where('is_active', true)->first();

        if ($product === null) {
            throw ValidationException::withMessages(['product' => 'Product unavailable.']);
        }

        if ($trafficGb < 1 || $trafficGb > 100 || $trafficGb < (int) $product->min_gb || $trafficGb > (int) $product->max_gb) {
            throw ValidationException::withMessages(['traffic' => 'Requested traffic exceeds product limits.']);
        }

        $rate = DB::table('sales_product_rates')->where('product_id', $product->id)
            ->where('duration_days', $durationDays)->where('is_active', true)->first();

        if ($rate === null || ! in_array($durationDays, ConfigureRate::DURATIONS, true)) {
            throw ValidationException::withMessages(['duration' => 'Duration unavailable.']);
        }

        $public = (int) $rate->base_price_rials + $trafficGb * (int) $rate->per_gb_rials;
        $discountPercent = $isReseller ? (int) config('sales.reseller_discount_percent', 35) : 0;

        if (! in_array($discountPercent, [0, 35], true)) {
            throw ValidationException::withMessages(['discount' => 'Invalid configured reseller discount.']);
        }

        // Round half-up to the nearest rial, no floats and no downstream dependency on a provider.
        $discount = intdiv($public * $discountPercent + 50, 100);

        return new PriceQuote(
            productSlug: (string) $product->slug,
            productName: (string) $product->name,
            durationDays: $durationDays,
            trafficGb: $trafficGb,
            publicPriceRials: $public,
            discountPercent: $discountPercent,
            discountRials: $discount,
            totalPriceRials: $public - $discount,
        );
    }
}

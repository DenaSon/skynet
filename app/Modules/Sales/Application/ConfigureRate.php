<?php

namespace App\Modules\Sales\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConfigureRate
{
    public const DURATIONS = [15, 30, 60, 90];

    public function handle(string $productSlug, int $durationDays, int $baseRials, int $perGbRials, bool $active = true): void
    {
        if (! in_array($durationDays, self::DURATIONS, true)) {
            throw ValidationException::withMessages(['duration' => 'Allowed durations: 15, 30, 60, 90 days.']);
        }

        // Bound rates so publicPrice * 100 for discount rounding cannot overflow a 64-bit PHP integer.
        if ($baseRials < 0 || $baseRials > 100_000_000_000 || $perGbRials < 0 || $perGbRials > 1_000_000_000 || ($baseRials === 0 && $perGbRials === 0)) {
            throw ValidationException::withMessages(['price' => 'Use positive, bounded integer-rial prices.']);
        }

        $productId = DB::table('sales_products')->where('slug', $productSlug)->value('id');

        if ($productId === null) {
            throw ValidationException::withMessages(['product' => 'Create the product before assigning rates.']);
        }

        DB::table('sales_product_rates')->updateOrInsert(
            ['product_id' => $productId, 'duration_days' => $durationDays],
            [
                'base_price_rials' => $baseRials,
                'per_gb_rials' => $perGbRials,
                'is_active' => $active,
                'updated_at' => now(),
            ],
        );
    }
}

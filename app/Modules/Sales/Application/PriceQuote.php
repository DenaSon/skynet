<?php

namespace App\Modules\Sales\Application;

final readonly class PriceQuote
{
    public function __construct(
        public string $productSlug,
        public string $productName,
        public int $durationDays,
        public int $trafficGb,
        public int $publicPriceRials,
        public int $discountPercent,
        public int $discountRials,
        public int $totalPriceRials,
    ) {}
}

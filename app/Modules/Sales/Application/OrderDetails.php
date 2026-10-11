<?php

namespace App\Modules\Sales\Application;

final readonly class OrderDetails
{
    public function __construct(
        public int $id,
        public int $buyerUserId,
        public string $productSlug,
        public string $productName,
        public int $durationDays,
        public int $trafficGb,
        public int $publicPriceRials,
        public int $discountPercent,
        public int $discountRials,
        public int $totalPriceRials,
        public string $status,
        public string $createdAt,
    ) {}
}

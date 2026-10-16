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
        public ?int $trafficGb,
        public int $publicPriceRials,
        public int $discountPercent,
        public int $discountRials,
        public int $totalPriceRials,
        public string $status,
        public string $createdAt,
        public ?int $planId = null,
        public ?string $planSlug = null,
        public ?string $planKind = null,
        public ?int $planRevision = null,
        public ?int $pricingRevision = null,
        public ?string $internalSquadUuid = null,
    ) {}
}

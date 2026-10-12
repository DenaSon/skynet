<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

/**
 * Immutable, buyer-bound quote snapshot. NOT a paid order, price lock or
 * provider reservation; future checkout must revalidate every field.
 */
final readonly class PlanPriceQuote
{
    public function __construct(
        public int $buyerUserId,
        public int $planId,
        public int $planRevision,
        public int $pricingRevision,
        public string $planSlug,
        public string $planName,
        public string $kind,
        public string $mode,
        public int $durationDays,
        public ?int $trafficGb,
        public string $pricingMethod,
        public int $publicPriceRials,
        public int $discountPercent,
        public int $discountRials,
        public int $totalPriceRials,
    ) {}
}

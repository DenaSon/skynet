<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

/**
 * Safe, provider-neutral purchase/subscription summary.
 * Never add Remnawave IDs, URLs, usernames or credentials here.
 */
final readonly class SubscriptionSummary
{
    public function __construct(
        public int $orderId,
        public string $productName,
        public int $durationDays,
        public int $trafficGb,
        public int $totalPriceRials,
        public string $status,
        public string $orderedAt,
    ) {}
}

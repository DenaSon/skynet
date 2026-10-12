<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

/**
 * Current local-only projection; actual provider connection info is F10-E2.
 */
final readonly class SubscriptionDetails
{
    public function __construct(
        public SubscriptionSummary $summary,
        public bool $canRequestLink,
    ) {}
}

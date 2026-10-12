<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

/** Buyer-bound, non-reserved preview. NEVER a checkout authorization. */
final readonly class QuotedPreset
{
    public function __construct(
        public PlanSummary $plan,
        public PlanPriceQuote $quote,
    ) {}
}

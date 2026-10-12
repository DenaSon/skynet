<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

final readonly class PlanTariffSummary
{
    public function __construct(
        public int $planId,
        public string $method,
        public string $status,
        public int $revision,
        public int $configuredPlanRevision,
        public ?int $fixedPriceRials,
        public int $basePriceRials,
        public int $perGbRials,
        public int $perDayRials,
        public int $minimumTotalRials,
        public int $resellerDiscountPercent,
    ) {}

    public static function fromRecord(object $row): self
    {
        return new self(
            planId: (int) $row->plan_id,
            method: (string) $row->method,
            status: (string) $row->status,
            revision: (int) $row->revision,
            configuredPlanRevision: (int) $row->configured_plan_revision,
            fixedPriceRials: $row->fixed_price_rials === null ? null : (int) $row->fixed_price_rials,
            basePriceRials: (int) $row->base_price_rials,
            perGbRials: (int) $row->per_gb_rials,
            perDayRials: (int) $row->per_day_rials,
            minimumTotalRials: (int) $row->minimum_total_rials,
            resellerDiscountPercent: (int) $row->reseller_discount_percent,
        );
    }
}

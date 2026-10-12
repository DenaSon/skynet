<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\ResolveSalesBuyer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Only reseller-scoped, active builder plans with a CURRENT published tariff
 * and at least one valid selectable price are discoverable. No money writes.
 */
final readonly class ListResellerBuilders
{
    public function __construct(
        private ResolveSalesBuyer $buyers,
        private ListVisiblePlans $plans,
        private CalculatePlanQuote $quotes,
    ) {}

    /** @return list<PlanSummary> */
    public function handle(int $buyerId): array
    {
        if (! $this->buyers->isReseller($buyerId)) {
            throw new AuthorizationException;
        }

        $results = [];

        foreach ($this->plans->handle($buyerId) as $plan) {
            if ($plan->mode !== 'builder' || $plan->audience !== 'reseller') {
                continue;
            }

            try {
                // The published formula must clear its minimum selling floor
                // for at least the maximum legal configuration.
                $this->quotes->handle($buyerId, $plan->id, $plan->maxDays, $plan->maxGb);
            } catch (ValidationException) {
                continue;
            }

            $results[] = $plan;
        }

        return $results;
    }

    public function find(int $buyerId, int $planId): PlanSummary
    {
        foreach ($this->handle($buyerId) as $plan) {
            if ($plan->id === $planId) {
                return $plan;
            }
        }

        throw ValidationException::withMessages(['plan' => 'Builder not available to this reseller.']);
    }
}

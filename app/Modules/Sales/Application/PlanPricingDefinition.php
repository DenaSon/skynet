<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Illuminate\Validation\ValidationException;

/**
 * Root-authored tariff specification. No monetary floats or provider details.
 * Partner discount replaces (never stacks with) the legacy F09 discount.
 */
final readonly class PlanPricingDefinition
{
    public const MAX_COMPONENT_RIALS = 1_000_000_000_000;

    public function __construct(
        public string $method,
        public ?int $fixedPriceRials,
        public int $basePriceRials,
        public int $perGbRials,
        public int $perDayRials,
        public int $resellerDiscountPercent,
        public int $minimumTotalRials,
    ) {}

    public function validatedFor(PlanSummary $plan): self
    {
        if (! in_array($this->method, ['fixed', 'formula'], true)
            || $this->resellerDiscountPercent < 0 || $this->resellerDiscountPercent > 50
            || $this->minimumTotalRials < 1 || $this->minimumTotalRials > self::MAX_COMPONENT_RIALS) {
            throw ValidationException::withMessages(['pricing' => 'Invalid method, discount or minimum.']);
        }

        foreach ([$this->basePriceRials, $this->perGbRials, $this->perDayRials] as $component) {
            if ($component < 0 || $component > self::MAX_COMPONENT_RIALS) {
                throw ValidationException::withMessages(['pricing' => 'Prices must be bounded integer rials.']);
            }
        }

        if ($this->method === 'fixed') {
            if ($plan->mode !== 'preset' || $this->fixedPriceRials === null
                || $this->fixedPriceRials < 1 || $this->fixedPriceRials > self::MAX_COMPONENT_RIALS
                || $this->basePriceRials !== 0 || $this->perGbRials !== 0 || $this->perDayRials !== 0) {
                throw ValidationException::withMessages(['pricing' => 'Fixed price applies only to a preset.']);
            }
        } elseif ($this->fixedPriceRials !== null || ($this->basePriceRials === 0
            && $this->perGbRials === 0 && $this->perDayRials === 0)) {
            throw ValidationException::withMessages(['pricing' => 'A formula needs at least one positive component.']);
        }

        if ($plan->kind === 'time' && $this->perGbRials !== 0) {
            throw ValidationException::withMessages(['pricing' => 'Unlimited time plans cannot charge per GB.']);
        }

        if ($plan->kind === 'volume' && $this->perDayRials !== 0) {
            throw ValidationException::withMessages(['pricing' => 'Volume-only plans do not charge per day.']);
        }

        // Price arithmetic uses only integer operations and must never overflow
        // even at the plan's largest selectable GB/day combination.
        $maximum = $this->method === 'fixed'
            ? (int) $this->fixedPriceRials
            : $this->basePriceRials
                + ($plan->maxGb ?? 0) * $this->perGbRials
                + $plan->maxDays * $this->perDayRials;

        if ($maximum < 1 || $maximum > self::MAX_COMPONENT_RIALS) {
            throw ValidationException::withMessages(['pricing' => 'Maximum computed price is invalid.']);
        }

        // Publishing a rule that can never clear the floor is forbidden. The
        // quote engine checks the selected price again for custom builders.
        if ($this->minimumTotalRials > $maximum
            || $maximum - intdiv($maximum * $this->resellerDiscountPercent + 50, 100) < $this->minimumTotalRials) {
            throw ValidationException::withMessages(['pricing' => 'Minimum price exceeds the available selling price.']);
        }

        return $this;
    }
}

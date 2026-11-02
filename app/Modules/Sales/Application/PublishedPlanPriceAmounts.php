<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Illuminate\Validation\ValidationException;

/**
 * Pure published-plan arithmetic shared by checkout quotes and the Demo.
 * No buyer identity, order, wallet or Provider operations.
 */
final class PublishedPlanPriceAmounts
{
    /** @return array{public:int,discount:int,total:int} */
    public static function calculate(
        string $method,
        ?int $fixedRials,
        int $baseRials,
        int $perGbRials,
        int $perDayRials,
        int $durationDays,
        ?int $trafficGb,
        int $discountPercent,
        int $minimumTotalRials,
    ): array {
        if (! in_array($method, ['fixed', 'formula'], true)
            || $discountPercent < 0 || $discountPercent > 50
            || $minimumTotalRials < 1 || $minimumTotalRials > PlanPricingDefinition::MAX_COMPONENT_RIALS) {
            throw ValidationException::withMessages(['pricing' => 'Invalid published price.']);
        }

        $public = $method === 'fixed'
            ? $fixedRials
            : $baseRials + ($trafficGb ?? 0) * $perGbRials + $durationDays * $perDayRials;

        if ($public === null || $public < 1 || $public > PlanPricingDefinition::MAX_COMPONENT_RIALS) {
            throw ValidationException::withMessages(['pricing' => 'Calculated price is out of bounds.']);
        }

        // Integer rial rounding half-up; the selling floor is checked after discount.
        $discount = intdiv($public * $discountPercent + 50, 100);
        $total = $public - $discount;

        if ($total < $minimumTotalRials) {
            throw ValidationException::withMessages(['pricing' => 'Selected configuration is below the selling floor.']);
        }

        return ['public' => $public, 'discount' => $discount, 'total' => $total];
    }
}

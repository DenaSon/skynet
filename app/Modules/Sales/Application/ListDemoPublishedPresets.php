<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bounded, read-only public projection of already published reseller presets.
 * Explicitly not an authenticated quote, order, reservation or buyer role.
 */
final readonly class ListDemoPublishedPresets
{
    /**
     * @return list<array{name:string,kind:string,trafficGb:?int,durationDays:int,publicRials:int,discountPercent:int,resellerRials:int}>
     */
    public function handle(): array
    {
        $rows = DB::table('sales_plans as plans')
            ->join('sales_products as products', 'products.id', '=', 'plans.product_id')
            ->join('sales_plan_prices as prices', 'prices.plan_id', '=', 'plans.id')
            ->where('products.is_active', true)
            ->where('plans.status', 'active')
            ->where('plans.mode', 'preset')
            ->whereIn('plans.audience', ['public', 'both', 'reseller'])
            ->where('prices.status', 'active')
            ->whereColumn('prices.configured_plan_revision', 'plans.revision')
            ->select('plans.*', 'products.slug as product_slug',
                'prices.method as price_method',
                'prices.fixed_price_rials as price_fixed',
                'prices.base_price_rials as price_base',
                'prices.per_gb_rials as price_per_gb',
                'prices.per_day_rials as price_per_day',
                'prices.reseller_discount_percent as price_discount',
                'prices.minimum_total_rials as price_floor',
            )
            ->orderByDesc('plans.is_featured')
            ->orderBy('plans.sort_order')
            ->orderBy('plans.id')
            ->limit(48)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $plan = PlanSummary::fromRecord($row);
            if ($plan->durationDays === null || $plan->durationDays < $plan->minDays
                || $plan->durationDays > $plan->maxDays
                || ($plan->kind === 'time' && $plan->trafficGb !== null)
                || ($plan->kind !== 'time' && ($plan->trafficGb === null
                    || $plan->trafficGb < (int) $plan->minGb || $plan->trafficGb > (int) $plan->maxGb))) {
                continue;
            }

            $rule = new PlanPricingDefinition(
                method: (string) $row->price_method,
                fixedPriceRials: $row->price_fixed === null ? null : (int) $row->price_fixed,
                basePriceRials: (int) $row->price_base,
                perGbRials: (int) $row->price_per_gb,
                perDayRials: (int) $row->price_per_day,
                resellerDiscountPercent: (int) $row->price_discount,
                minimumTotalRials: (int) $row->price_floor,
            );

            try {
                $rule->validatedFor($plan);
                $amounts = PublishedPlanPriceAmounts::calculate(
                    $rule->method, $rule->fixedPriceRials, $rule->basePriceRials,
                    $rule->perGbRials, $rule->perDayRials,
                    $plan->durationDays, $plan->trafficGb,
                    $rule->resellerDiscountPercent, $rule->minimumTotalRials,
                );
            } catch (ValidationException) {
                // Withdrawn, incompatible or below-floor configurations are not advertised.
                continue;
            }

            $result[] = [
                'name' => $plan->name,
                'kind' => $plan->kind,
                'trafficGb' => $plan->trafficGb,
                'durationDays' => $plan->durationDays,
                'publicRials' => $amounts['public'],
                'discountPercent' => $rule->resellerDiscountPercent,
                'resellerRials' => $amounts['total'],
            ];
        }

        return $result;
    }
}

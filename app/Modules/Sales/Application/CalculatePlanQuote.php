<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\ResolveSalesBuyer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Canonical buyer-authorized, read-only quote engine for managed plans.
 * No order/ledger writes, payment transitions or Remnawave activity.
 */
final readonly class CalculatePlanQuote
{
    public function __construct(private ListVisiblePlans $plans, private ResolveSalesBuyer $buyers) {}

    public function handle(int $buyerId, int $planId, int $durationDays, ?int $trafficGb): PlanPriceQuote
    {
        // Fresh Identity/role/status and product + plan availability on each
        // attempt; never trust a Nutgram callback or previously returned DTO.
        $plan = $this->plans->find($buyerId, $planId);

        if ($durationDays < $plan->minDays || $durationDays > $plan->maxDays
            || ($plan->mode === 'preset' && $durationDays !== $plan->durationDays)) {
            throw ValidationException::withMessages(['duration' => 'Duration is not available for this plan.']);
        }

        if ($plan->kind === 'time') {
            if ($trafficGb !== null) {
                throw ValidationException::withMessages(['traffic' => 'Unlimited plans have no GB selection.']);
            }
        } elseif ($trafficGb === null || $trafficGb < (int) $plan->minGb
            || $trafficGb > (int) $plan->maxGb
            || ($plan->mode === 'preset' && $trafficGb !== $plan->trafficGb)) {
            throw ValidationException::withMessages(['traffic' => 'Traffic is not available for this plan.']);
        }

        $price = DB::table('sales_plan_prices')->where('plan_id', $planId)
            ->where('status', 'active')->first();

        if ($price === null || (int) $price->configured_plan_revision !== $plan->revision) {
            throw ValidationException::withMessages(['pricing' => 'This plan has no current published price.']);
        }

        $discountPercent = $this->buyers->isReseller($buyerId) ? (int) $price->reseller_discount_percent : 0;
        $amounts = PublishedPlanPriceAmounts::calculate(
            method: (string) $price->method,
            fixedRials: $price->fixed_price_rials === null ? null : (int) $price->fixed_price_rials,
            baseRials: (int) $price->base_price_rials,
            perGbRials: (int) $price->per_gb_rials,
            perDayRials: (int) $price->per_day_rials,
            durationDays: $durationDays,
            trafficGb: $trafficGb,
            discountPercent: $discountPercent,
            minimumTotalRials: (int) $price->minimum_total_rials,
        );

        return new PlanPriceQuote(
            buyerUserId: $buyerId,
            planId: $plan->id,
            planRevision: $plan->revision,
            pricingRevision: (int) $price->revision,
            planSlug: $plan->slug,
            planName: $plan->name,
            kind: $plan->kind,
            mode: $plan->mode,
            durationDays: $durationDays,
            trafficGb: $trafficGb,
            pricingMethod: (string) $price->method,
            publicPriceRials: $amounts['public'],
            discountPercent: $discountPercent,
            discountRials: $amounts['discount'],
            totalPriceRials: $amounts['total'],
        );
    }
}

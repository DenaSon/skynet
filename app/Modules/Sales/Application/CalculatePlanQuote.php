<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Canonical buyer-authorized, read-only quote engine for managed plans.
 * No order/ledger writes, payment transitions or Remnawave activity.
 */
final readonly class CalculatePlanQuote
{
    public function __construct(private ListVisiblePlans $plans) {}

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

        $public = $price->method === 'fixed'
            ? (int) $price->fixed_price_rials
            : (int) $price->base_price_rials
                + ($trafficGb ?? 0) * (int) $price->per_gb_rials
                + $durationDays * (int) $price->per_day_rials;

        if ($public < 1 || $public > PlanPricingDefinition::MAX_COMPONENT_RIALS) {
            throw ValidationException::withMessages(['pricing' => 'Calculated price is out of bounds.']);
        }

        // Reseller access includes public/both plans. Exactly one discount
        // applies, the explicitly published plan discount. Never stack F09.
        $discountPercent = $this->isReseller($buyerId) ? (int) $price->reseller_discount_percent : 0;
        $discount = intdiv($public * $discountPercent + 50, 100);
        $total = $public - $discount;

        if ($total < (int) $price->minimum_total_rials) {
            throw ValidationException::withMessages(['pricing' => 'Selected configuration is below the selling floor.']);
        }

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
            publicPriceRials: $public,
            discountPercent: $discountPercent,
            discountRials: $discount,
            totalPriceRials: $total,
        );
    }

    private function isReseller(int $buyerId): bool
    {
        return app(\App\Modules\Identity\Application\ResolveSalesBuyer::class)->isReseller($buyerId);
    }
}

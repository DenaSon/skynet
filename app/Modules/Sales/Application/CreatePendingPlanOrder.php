<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\ResolveSalesBuyer;
use App\Modules\Provisioning\Application\CheckPlanSquadReadiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SB-03 creates pending-payment managed-plan orders only.
 * No bank ledger, provider allocation, notification or payment settlement.
 */
final readonly class CreatePendingPlanOrder
{
    public function __construct(
        private ResolveSalesBuyer $buyers,
        private RevalidatePlanQuote $revalidate,
        private ListVisiblePlans $plans,
        private CheckPlanSquadReadiness $squads,
        private OrderReader $orders,
    ) {}

    public function handle(int $buyerId, PlanPriceQuote $expected, string $idempotencyKey): OrderDetails
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $idempotencyKey) !== 1
            || $expected->buyerUserId !== $buyerId) {
            throw ValidationException::withMessages(['checkout' => 'Invalid buyer or checkout token.']);
        }

        return DB::transaction(function () use ($buyerId, $expected, $idempotencyKey): OrderDetails {
            $this->buyers->isReseller($buyerId);

            // A retry of a previously accepted order is read-only and preserves its
            // original immutable snapshot even if the current price later changes.
            $existing = $this->orders->byKey($buyerId, $idempotencyKey);
            if ($existing !== null) {
                $this->assertMatches($existing, $expected);

                return $existing;
            }

            // Revalidate while holding plan/price locks so a concurrent operator
            // edit cannot change their revisions before the snapshot is inserted.
            $planRow = DB::table('sales_plans')->where('id', $expected->planId)->lockForUpdate()->first();
            $priceRow = DB::table('sales_plan_prices')->where('plan_id', $expected->planId)->lockForUpdate()->first();

            if ($planRow === null || $priceRow === null) {
                throw ValidationException::withMessages(['plan' => 'Plan is unavailable.']);
            }

            $quote = $this->revalidate->handle($buyerId, $expected);
            $plan = $this->plans->find($buyerId, $quote->planId);
            $squadUuid = $this->squads->verifiedSquadForCheckout($quote->planId);
            if ($squadUuid === null) {
                throw ValidationException::withMessages(['squad' => 'Plan is not ready for checkout.']);
            }

            DB::table('sales_orders')->insertOrIgnore([
                'buyer_user_id' => $buyerId,
                'idempotency_key' => $idempotencyKey,
                'product_slug' => $plan->productSlug,
                'product_name' => $quote->planName,
                'plan_id' => $quote->planId,
                'plan_slug' => $quote->planSlug,
                'plan_kind' => $quote->kind,
                'plan_revision' => $quote->planRevision,
                'pricing_revision' => $quote->pricingRevision,
                'internal_squad_uuid' => $squadUuid,
                'duration_days' => $quote->durationDays,
                'traffic_gb' => $quote->trafficGb,
                'public_price_rials' => $quote->publicPriceRials,
                'discount_percent' => $quote->discountPercent,
                'discount_rials' => $quote->discountRials,
                'total_price_rials' => $quote->totalPriceRials,
                'status' => 'pending_payment',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $order = $this->orders->byKey($buyerId, $idempotencyKey);
            if ($order === null) {
                throw ValidationException::withMessages(['checkout' => 'Checkout identifier already used.']);
            }

            $this->assertMatches($order, $expected);

            return $order;
        }, 3);
    }

    private function assertMatches(OrderDetails $order, PlanPriceQuote $quote): void
    {
        if ($order->buyerUserId !== $quote->buyerUserId
            || $order->planId !== $quote->planId
            || $order->planRevision !== $quote->planRevision
            || $order->pricingRevision !== $quote->pricingRevision
            || $order->planSlug !== $quote->planSlug
            || $order->planKind !== $quote->kind
            || $order->productName !== $quote->planName
            || $order->durationDays !== $quote->durationDays
            || $order->trafficGb !== $quote->trafficGb
            || $order->publicPriceRials !== $quote->publicPriceRials
            || $order->discountPercent !== $quote->discountPercent
            || $order->discountRials !== $quote->discountRials
            || $order->totalPriceRials !== $quote->totalPriceRials
            || $order->internalSquadUuid === null) {
            throw ValidationException::withMessages(['checkout' => 'Checkout token already used for another quote.']);
        }
    }
}

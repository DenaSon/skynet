<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Root-only and audited tariff lifecycle. Configuration always produces a
 * DRAFT: price changes never silently affect buyers until reconfirmed.
 */
final readonly class ManagePlanPricing
{
    public function __construct(private AssertCatalogManagementAccess $access) {}

    public function find(int $rootId, int $planId): ?PlanTariffSummary
    {
        $this->access->handle($rootId);

        $row = DB::table('sales_plan_prices')->where('plan_id', $planId)->first();

        return $row === null ? null : PlanTariffSummary::fromRecord($row);
    }

    public function configure(
        int $rootId, int $planId, int $expectedPlanRevision,
        int $expectedPriceRevision, PlanPricingDefinition $definition,
    ): int {
        $this->access->handle($rootId);

        return DB::transaction(function () use (
            $rootId, $planId, $expectedPlanRevision, $expectedPriceRevision, $definition,
        ): int {
            $plan = DB::table('sales_plans')->where('id', $planId)->lockForUpdate()->first();

            if ($plan === null || $plan->status === 'archived' || $expectedPlanRevision < 1
                || (int) $plan->revision !== $expectedPlanRevision) {
                throw ValidationException::withMessages(['plan' => 'Plan changed or cannot be priced.']);
            }

            $definition->validatedFor($this->summary($plan));
            $current = DB::table('sales_plan_prices')->where('plan_id', $planId)->lockForUpdate()->first();
            $revision = $current === null ? 1 : (int) $current->revision + 1;

            if (($current === null && $expectedPriceRevision !== 0)
                || ($current !== null && (int) $current->revision !== $expectedPriceRevision)) {
                throw ValidationException::withMessages(['pricing' => 'Tariff changed; refresh before editing.']);
            }

            $values = [
                'method' => $definition->method,
                'fixed_price_rials' => $definition->fixedPriceRials,
                'base_price_rials' => $definition->basePriceRials,
                'per_gb_rials' => $definition->perGbRials,
                'per_day_rials' => $definition->perDayRials,
                'reseller_discount_percent' => $definition->resellerDiscountPercent,
                'minimum_total_rials' => $definition->minimumTotalRials,
                'configured_plan_revision' => $expectedPlanRevision,
                'status' => 'draft',
                'revision' => $revision,
                'updated_at' => now(),
            ];

            if ($current === null) {
                $priceId = (int) DB::table('sales_plan_prices')->insertGetId([
                    ...$values, 'plan_id' => $planId, 'created_at' => now(),
                ]);
            } else {
                $priceId = (int) $current->id;
                DB::table('sales_plan_prices')->where('id', $priceId)->update($values);
            }

            $this->audit($rootId, $planId, $priceId, 'configured', $current?->revision, $revision);

            return $revision;
        });
    }

    /**
     * @return string One-time raw token, only for the root's short-lived UX.
     */
    public function requestChange(
        int $rootId, int $planId, int $expectedPlanRevision,
        int $expectedPriceRevision, string $operation,
    ): string {
        $this->access->handle($rootId);

        if (! in_array($operation, ['activate', 'deactivate'], true)) {
            throw ValidationException::withMessages(['pricing' => 'Invalid tariff operation.']);
        }

        $plan = DB::table('sales_plans')->where('id', $planId)->first();
        $price = DB::table('sales_plan_prices')->where('plan_id', $planId)->first();
        $this->assertState($plan, $price, $expectedPlanRevision, $expectedPriceRevision, $operation);
        $token = bin2hex(random_bytes(16));

        DB::table('sales_plan_price_confirmations')->insert([
            'actor_user_id' => $rootId,
            'plan_price_id' => $price->id,
            'token_hash' => hash('sha256', $token),
            'operation' => $operation,
            'expected_revision' => $expectedPriceRevision,
            'expected_plan_revision' => $expectedPlanRevision,
            'expires_at' => now()->addMinutes(3),
            'created_at' => now(),
        ]);

        return $token;
    }

    public function confirmChange(int $rootId, string $token): int
    {
        $this->access->handle($rootId);

        if (preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1) {
            throw ValidationException::withMessages(['confirmation' => 'Invalid tariff confirmation.']);
        }

        return DB::transaction(function () use ($rootId, $token): int {
            $confirmation = DB::table('sales_plan_price_confirmations')
                ->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();

            if ($confirmation === null || (int) $confirmation->actor_user_id !== $rootId
                || now()->greaterThanOrEqualTo($confirmation->expires_at)) {
                throw ValidationException::withMessages(['confirmation' => 'Expired or unauthorized confirmation.']);
            }

            $priceLookup = DB::table('sales_plan_prices')->where('id', $confirmation->plan_price_id)->first();

            if ($priceLookup === null) {
                throw ValidationException::withMessages(['pricing' => 'Tariff unavailable.']);
            }

            // Consistent lock order with configure: plan before price.
            $plan = DB::table('sales_plans')->where('id', $priceLookup->plan_id)->lockForUpdate()->first();
            $price = DB::table('sales_plan_prices')->where('id', $confirmation->plan_price_id)->lockForUpdate()->first();

            $this->assertState(
                $plan, $price, (int) $confirmation->expected_plan_revision,
                (int) $confirmation->expected_revision, (string) $confirmation->operation,
            );

            DB::table('sales_plan_price_confirmations')->where('id', $confirmation->id)->delete();
            $revision = (int) $price->revision + 1;
            DB::table('sales_plan_prices')->where('id', $price->id)->update([
                'status' => $confirmation->operation === 'activate' ? 'active' : 'inactive',
                'revision' => $revision,
                'updated_at' => now(),
            ]);

            $this->audit(
                $rootId, (int) $price->plan_id, (int) $price->id,
                (string) $confirmation->operation, $price->revision, $revision,
            );

            return $revision;
        });
    }

    private function assertState(
        ?object $plan, ?object $price, int $expectedPlanRevision,
        int $expectedPriceRevision, string $operation,
    ): void {
        if ($plan === null || $plan->status === 'archived' || $price === null
            || $expectedPlanRevision < 1 || $expectedPriceRevision < 1
            || (int) $plan->revision !== $expectedPlanRevision
            || (int) $price->revision !== $expectedPriceRevision
            || (int) $price->configured_plan_revision !== $expectedPlanRevision) {
            throw ValidationException::withMessages(['pricing' => 'Plan or tariff changed.']);
        }

        if (($operation === 'activate' && ! in_array($price->status, ['draft', 'inactive'], true))
            || ($operation === 'deactivate' && $price->status !== 'active')) {
            throw ValidationException::withMessages(['pricing' => 'Invalid tariff transition.']);
        }
    }

    private function summary(object $row): PlanSummary
    {
        $productSlug = DB::table('sales_products')->where('id', $row->product_id)->value('slug');

        if (! is_string($productSlug)) {
            throw ValidationException::withMessages(['product' => 'Product not found.']);
        }

        return PlanSummary::fromRecord((object) [...(array) $row, 'product_slug' => $productSlug]);
    }

    private function audit(
        int $rootId, int $planId, int $priceId, string $operation,
        ?int $previousRevision, int $revision,
    ): void {
        DB::table('sales_plan_price_audits')->insert([
            'actor_user_id' => $rootId,
            'plan_id' => $planId,
            'plan_price_id' => $priceId,
            'operation' => $operation,
            'old_revision' => $previousRevision,
            'new_revision' => $revision,
            'created_at' => now(),
        ]);
    }
}

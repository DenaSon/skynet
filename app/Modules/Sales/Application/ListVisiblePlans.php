<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\ResolveSalesBuyer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Buyer-scoped read-only plan discovery. Not a pricing or checkout entry point:
 * F11-B1.2 must authorize and reprice any selected plan before order creation.
 */
final readonly class ListVisiblePlans
{
    public function __construct(private ResolveSalesBuyer $buyers) {}

    /** @return list<PlanSummary> */
    public function handle(int $buyerId): array
    {
        $reseller = $this->buyers->isReseller($buyerId);

        return DB::table('sales_plans as plans')
            ->join('sales_products as products', 'products.id', '=', 'plans.product_id')
            ->where('products.is_active', true)
            ->where('plans.status', 'active')
            ->whereIn('plans.audience', $reseller ? ['public', 'reseller', 'both'] : ['public', 'both'])
            ->select('plans.*', 'products.slug as product_slug')
            ->orderByDesc('plans.is_featured')
            ->orderBy('plans.sort_order')
            ->orderBy('plans.id')
            ->get()
            ->map(static fn (object $row): PlanSummary => PlanSummary::fromRecord($row))
            ->all();
    }

    public function find(int $buyerId, int $planId): PlanSummary
    {
        $plan = collect($this->handle($buyerId))->first(
            static fn (PlanSummary $entry): bool => $entry->id === $planId,
        );

        if ($plan === null) {
            throw ValidationException::withMessages(['plan' => 'Plan not available to this buyer.']);
        }

        return $plan;
    }
}

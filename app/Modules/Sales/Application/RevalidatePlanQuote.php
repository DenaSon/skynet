<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Illuminate\Validation\ValidationException;

/**
 * Future checkout will invoke this inside its order-creation transaction,
 * before snapshotting the verified price and plan revisions.
 */
final readonly class RevalidatePlanQuote
{
    public function __construct(private CalculatePlanQuote $calculate) {}

    public function handle(int $buyerId, PlanPriceQuote $expected): PlanPriceQuote
    {
        if ($expected->buyerUserId !== $buyerId) {
            throw ValidationException::withMessages(['quote' => 'Quote does not belong to this buyer.']);
        }

        $fresh = $this->calculate->handle(
            $buyerId, $expected->planId, $expected->durationDays, $expected->trafficGb,
        );

        if ($fresh != $expected) {
            throw ValidationException::withMessages(['quote' => 'Plan, role or price changed; review the latest quote.']);
        }

        return $fresh;
    }
}

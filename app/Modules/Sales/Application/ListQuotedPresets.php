<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Illuminate\Validation\ValidationException;

/**
 * Buyer-authorized read-only preset discovery using canonical Sales pricing.
 *
 * In particular, active catalog status alone does not imply a published and
 * currently usable tariff. Builders are reserved for the separate B1.6 flow.
 */
final readonly class ListQuotedPresets
{
    public function __construct(
        private ListVisiblePlans $plans,
        private CalculatePlanQuote $quotes,
    ) {}

    /** @return list<QuotedPreset> */
    public function handle(int $buyerId): array
    {
        $results = [];

        foreach ($this->plans->handle($buyerId) as $plan) {
            if ($plan->mode !== 'preset' || $plan->durationDays === null) {
                continue;
            }

            try {
                $quote = $this->quotes->handle(
                    $buyerId, $plan->id, $plan->durationDays, $plan->trafficGb,
                );
            } catch (ValidationException) {
                // Draft, disabled, stale or below-floor tariffs are not offers.
                continue;
            }

            $results[] = new QuotedPreset($plan, $quote);
        }

        // Sort actual buyer prices before either presenter paginates. Equal
        // prices keep the existing catalog priority through PHP's stable sort.
        usort($results, static fn (QuotedPreset $a, QuotedPreset $b): int => $a->quote->totalPriceRials <=> $b->quote->totalPriceRials);

        return $results;
    }

    public function find(int $buyerId, int $planId): QuotedPreset
    {
        foreach ($this->handle($buyerId) as $entry) {
            if ($entry->plan->id === $planId) {
                return $entry;
            }
        }

        throw ValidationException::withMessages(['plan' => 'This plan is unavailable for preview.']);
    }
}

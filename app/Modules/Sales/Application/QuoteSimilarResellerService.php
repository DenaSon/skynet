<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\AuthorizeSubscriptionViewer;
use App\Modules\Identity\Application\ResolveSalesBuyer;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Provisioning\Application\GetMySubscription;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/** Copy only purchased quantities; current catalog and pricing remain authoritative. */
final readonly class QuoteSimilarResellerService
{
    public function handle(SubscriptionViewer $viewer, int $sourceOrderId): PlanPriceQuote
    {
        $buyerId = app(AuthorizeSubscriptionViewer::class)->userId($viewer);
        if (! app(ResolveSalesBuyer::class)->isReseller($buyerId)) {
            throw new AuthorizationException;
        }
        app(GetMySubscription::class)->handle($viewer, $sourceOrderId);
        $source = app(OrderReader::class)->findNewPlan($buyerId, $sourceOrderId);
        if ($source->status !== 'fulfilled' || $source->planId === null) {
            throw ValidationException::withMessages(['source' => 'A completed managed service is required.']);
        }

        return app(CalculatePlanQuote::class)->handle(
            $buyerId, $source->planId, $source->durationDays, $source->trafficGb,
        );
    }
}

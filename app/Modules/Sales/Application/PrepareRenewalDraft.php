<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\AuthorizeSubscriptionViewer;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Provisioning\Application\CheckPlanSquadReadiness;
use App\Modules\Provisioning\Application\ReadRenewableSubscription;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REN-01: one non-payable draft per existing managed service.
 * No sales_orders, bank/wallet settlements, outbox, provider writes or credential storage.
 */
final readonly class PrepareRenewalDraft
{
    public function __construct(
        private AuthorizeSubscriptionViewer $authorize,
        private OrderReader $orders,
        private ReadRenewableSubscription $read,
        private CalculatePlanQuote $quotes,
        private RevalidatePlanQuote $revalidate,
        private CheckPlanSquadReadiness $squads,
    ) {}

    /** Cheap, local-only button eligibility; the Action still verifies the live provider before draft creation. */
    public function available(SubscriptionViewer $viewer, int $sourceOrderId): bool
    {
        $buyerId = $this->authorize->userId($viewer);
        $order = $this->orders->find($buyerId, $sourceOrderId);

        return $order->status === 'fulfilled' && $order->planId !== null
            && $order->internalSquadUuid !== null
            && DB::table('provisioning_order_links as links')
                ->join('provisioning_subscriptions as sub', 'sub.id', '=', 'links.provisioning_subscription_id')
                ->where('links.sales_order_id', $sourceOrderId)
                ->where('sub.state', 'active')->whereNotNull('sub.remote_user_id')->exists();
    }

    public function handle(SubscriptionViewer $viewer, int $sourceOrderId): RenewalDraftPreview
    {
        if (! $this->available($viewer, $sourceOrderId)) {
            throw ValidationException::withMessages(['renewal' => 'Service cannot be renewed.']);
        }

        $buyerId = $this->authorize->userId($viewer);
        $source = $this->orders->findPlan($buyerId, $sourceOrderId);

        // The provider read verifies its immutable remote identity and repeats
        // owner/revocation checks *after* the GET, without exposing credentials.
        $originalSquad = strtolower((string) $source->internalSquadUuid);
        if (! $this->read->eligible($viewer, $sourceOrderId, $originalSquad)) {
            throw ValidationException::withMessages(['renewal' => 'Service configuration is not eligible for renewal.']);
        }

        // Only the originally purchased plan/volume/duration can be previewed.
        // Reuse current catalog price, buyer role and active pricing rules.
        $quote = $this->quotes->handle(
            $buyerId, (int) $source->planId, $source->durationDays, $source->trafficGb,
        );

        return DB::transaction(function () use ($viewer, $buyerId, $sourceOrderId, $source, $quote, $originalSquad): RenewalDraftPreview {
            if ($this->authorize->userId($viewer) !== $buyerId) {
                throw new AuthorizationException;
            }

            // Lock source and canonical pricing, then reject any stale quote.
            $locked = DB::table('sales_orders')->where('id', $sourceOrderId)
                ->where('buyer_user_id', $buyerId)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== 'fulfilled'
                || (int) $locked->plan_id !== $source->planId
                || (string) $locked->internal_squad_uuid !== (string) $source->internalSquadUuid
                || ! DB::table('provisioning_order_links as links')
                    ->join('provisioning_subscriptions as sub', 'sub.id', '=', 'links.provisioning_subscription_id')
                    ->where('links.sales_order_id', $sourceOrderId)
                    ->where('sub.state', 'active')->whereNotNull('sub.remote_user_id')->exists()) {
                throw ValidationException::withMessages(['renewal' => 'Service state changed.']);
            }

            $plan = DB::table('sales_plans')->where('id', $quote->planId)->lockForUpdate()->first();
            $pricing = DB::table('sales_plan_prices')->where('plan_id', $quote->planId)->lockForUpdate()->first();
            if ($plan === null || $pricing === null) {
                throw ValidationException::withMessages(['renewal' => 'Plan is no longer available.']);
            }

            $this->revalidate->handle($buyerId, $quote);
            $verified = $this->squads->verifiedSquadForCheckout($quote->planId, $quote->planRevision);
            if ($verified === null || strtolower($verified) !== $originalSquad) {
                throw ValidationException::withMessages(['renewal' => 'Plan connection has changed.']);
            }

            // Unique source_order_id + locked source row make repeat taps safe.
            // Draft can refresh its price only because it is not payable.
            DB::table('sales_renewal_drafts')->upsert([[
                'source_order_id' => $sourceOrderId,
                'plan_id' => $quote->planId,
                'plan_revision' => $quote->planRevision,
                'pricing_revision' => $quote->pricingRevision,
                'duration_days' => $quote->durationDays,
                'traffic_gb' => $quote->trafficGb,
                'internal_squad_uuid' => $verified,
                'total_price_rials' => $quote->totalPriceRials,
                'state' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['source_order_id'], [
                'plan_id', 'plan_revision', 'pricing_revision', 'duration_days',
                'traffic_gb', 'internal_squad_uuid', 'total_price_rials', 'updated_at',
            ]);

            $id = (int) DB::table('sales_renewal_drafts')
                ->where('source_order_id', $sourceOrderId)->value('id');

            return new RenewalDraftPreview(
                id: $id,
                sourceOrderId: $sourceOrderId,
                planName: $quote->planName,
                durationDays: $quote->durationDays,
                trafficGb: $quote->trafficGb,
                priceRials: $quote->totalPriceRials,
            );
        }, 3);
    }
}

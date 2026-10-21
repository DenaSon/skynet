<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\AuthorizeSubscriptionViewer;
use App\Modules\Identity\Application\SubscriptionViewer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Explicit REN-02 conversion: genuine Sales order but NEVER a provider CREATE.
 * The immutable renewal mapping commits in the same transaction as the order.
 */
final readonly class CreatePendingRenewalOrder
{
    public function __construct(
        private AuthorizeSubscriptionViewer $authorize,
        private PrepareRenewalDraft $drafts,
        private CalculatePlanQuote $quotes,
        private CreatePendingPlanOrder $create,
        private OrderReader $orders,
    ) {}

    public function handle(SubscriptionViewer $viewer, int $sourceOrderId): OrderDetails
    {
        if (config('sales.renewal_enabled', false) !== true) {
            throw ValidationException::withMessages(['renewal' => 'Renewal payments are not enabled.']);
        }

        // Provider GET, ownership and real-time price are checked before any
        // lock/transaction or payable order is generated.
        $draft = $this->drafts->handle($viewer, $sourceOrderId);
        $buyer = $this->authorize->userId($viewer);

        return DB::transaction(function () use ($viewer, $buyer, $sourceOrderId, $draft): OrderDetails {
            if ($this->authorize->userId($viewer) !== $buyer) {
                throw ValidationException::withMessages(['renewal' => 'Buyer changed.']);
            }

            $source = DB::table('sales_orders')->where('id', $sourceOrderId)
                ->where('buyer_user_id', $buyer)->lockForUpdate()->first();
            $linked = DB::table('provisioning_order_links as links')
                ->join('provisioning_subscriptions as sub', 'sub.id', '=', 'links.provisioning_subscription_id')
                ->where('links.sales_order_id', $sourceOrderId)
                ->where('sub.state', 'active')->whereNotNull('sub.remote_user_id')
                ->first(['sub.id', 'sub.reference']);
            $snapshot = DB::table('sales_renewal_drafts')->where('id', $draft->id)
                ->where('source_order_id', $sourceOrderId)->lockForUpdate()->first();

            if ($source === null || $source->status !== 'fulfilled'
                || $linked === null || $snapshot === null
                || $snapshot->state !== 'draft'
                || (int) $source->plan_id !== (int) $snapshot->plan_id
                || (string) $source->internal_squad_uuid !== (string) $snapshot->internal_squad_uuid
                || (int) $snapshot->total_price_rials !== $draft->priceRials) {
                throw ValidationException::withMessages(['renewal' => 'Renewal preview is stale.']);
            }

            $other = DB::table('sales_renewal_orders as renewal')
                ->join('sales_orders as order', 'order.id', '=', 'renewal.sales_order_id')
                ->where('renewal.source_order_id', $sourceOrderId)
                ->whereIn('order.status', ['pending_payment', 'paid'])
                ->orderByDesc('order.id')->first(['order.id', 'order.buyer_user_id']);
            if ($other !== null) {
                // Repeated tap is read-only. Cannot create an additional charge.
                return $this->orders->find($buyer, (int) $other->id);
            }

            // The exact original volume/duration and current published price,
            // discounts and verified Squad are revalidated by the existing
            // CreatePendingPlanOrder Action under its canonical row locks.
            $quote = $this->quotes->handle(
                $buyer, (int) $source->plan_id, (int) $source->duration_days,
                $source->traffic_gb === null ? null : (int) $source->traffic_gb,
            );
            if ($quote->totalPriceRials !== (int) $snapshot->total_price_rials
                || $quote->planRevision !== (int) $snapshot->plan_revision
                || $quote->pricingRevision !== (int) $snapshot->pricing_revision) {
                throw ValidationException::withMessages(['renewal' => 'Price changed; preview again.']);
            }

            $order = $this->create->handle($buyer, $quote, bin2hex(random_bytes(16)));
            if ($order->internalSquadUuid !== $snapshot->internal_squad_uuid) {
                throw ValidationException::withMessages(['renewal' => 'Service binding changed.']);
            }
            DB::table('sales_renewal_orders')->insert([
                'sales_order_id' => $order->id,
                'source_order_id' => $sourceOrderId,
                'provisioning_subscription_id' => $linked->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $order;
        }, 3);
    }
}

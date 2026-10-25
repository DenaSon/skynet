<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Jobs;

use App\Modules\Billing\Application\VerifyWalletOrderDebit;
use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Application\FulfillPaidPlanOrder;
use App\Modules\Provisioning\Application\FulfillPaidRenewalOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** One-order worker for authorized bank review, wallet settlement or recovery. */
final class FulfillPaidPlanOrderJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Internal bank-review intent; requires complete immutable settlement proof. */
    public const VERIFIED_BANK_REVIEW = -1;

    public int $tries = 1;

    public int $timeout = 55;

    public int $uniqueFor = 180;

    public function __construct(public int $orderId, public bool $reconcile = false, public int $actorId = 0)
    {
        $this->onConnection(ProviderQueueConnection::name());
    }

    public function uniqueId(): string
    {
        return 'parsa:fulfillment:'.$this->orderId;
    }

    public function handle(FulfillPaidPlanOrder $fulfill, AssertCatalogManagementAccess $access, ?VerifyWalletOrderDebit $wallet = null, ?FulfillPaidRenewalOrder $renewals = null): void
    {
        try {
            if ($this->actorId > 0) {
                $access->handle($this->actorId);
            } elseif ($this->actorId === self::VERIFIED_BANK_REVIEW && ! $this->reconcile) {
                // A delegated bank approval is NOT a root identity. Prove the
                // entire committed bank claim, ledger and immutable settlement
                // before permitting the existing guarded provider worker.
                if (! DB::table('sales_bank_settlements')
                    ->where('sales_order_id', $this->orderId)->exists()) {
                    return;
                }
                $order = DB::table('sales_orders')->where('id', $this->orderId)
                    ->where('status', 'paid')->first();
                if ($order === null) {
                    return;
                }
                $fulfill->assertSettlement($order);
            } elseif ($this->actorId === 0 && ! $this->reconcile) {
                // Preserve original customer-wallet-only automatic authorization.
                // Old rootless bank jobs are NEVER revived by this feature.
                $row = DB::table('sales_orders as o')
                    ->join('sales_order_settlements as s', 's.sales_order_id', '=', 'o.id')
                    ->where('o.id', $this->orderId)->where('o.status', 'paid')
                    ->whereNotNull('o.plan_id')->where('s.method', 'customer_wallet')
                    ->first(['o.buyer_user_id', 'o.total_price_rials', 's.billing_journal_id', 's.amount_rials']);
                if ($row === null || (int) $row->amount_rials !== (int) $row->total_price_rials
                    || ! ($wallet ?? app(VerifyWalletOrderDebit::class))->handle((int) $row->buyer_user_id, $this->orderId,
                        (int) $row->billing_journal_id, (int) $row->total_price_rials)) {
                    return;
                }
            } else {
                return;
            }
            $renewal = DB::table('sales_renewal_orders')->where('sales_order_id', $this->orderId)->exists();
            if ($renewal) {
                $handler = $renewals ?? app(FulfillPaidRenewalOrder::class);
                if ($this->reconcile) {
                    $handler->reconcile($this->orderId);
                } else {
                    $handler->handle($this->orderId);
                }
            } elseif ($this->reconcile) {
                $fulfill->reconcile($this->orderId);
            } else {
                // Already queued jobs must honor the current activation switch.
                // Keep untouched intents pending for the recovery dispatcher.
                if (config('sales.auto_fulfillment_enabled', false) !== true) {
                    return;
                }
                $fulfill->handle($this->orderId);
            }
        } catch (Throwable) {
            // Do not fail/retry and never log remote response bodies, bearer
            // credentials, connection URLs, banking records or exception chains.
            Log::warning('Fulfillment outcome needs operator inspection.', [
                'order_id' => $this->orderId,
            ]);
        }
    }
}

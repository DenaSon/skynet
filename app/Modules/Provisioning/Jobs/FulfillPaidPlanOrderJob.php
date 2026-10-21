<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Jobs;

use App\Modules\Billing\Application\VerifyWalletOrderDebit;
use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Application\FulfillPaidPlanOrder;
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

    public function handle(FulfillPaidPlanOrder $fulfill, AssertCatalogManagementAccess $access, ?VerifyWalletOrderDebit $wallet = null): void
    {
        try {
            if ($this->actorId > 0) {
                $access->handle($this->actorId);
            } elseif ($this->actorId === 0 && ! $this->reconcile) {
                // Internal auto-dispatch is permitted ONLY when an authentic
                // buyer-owned customer_wallet settlement and exact balanced
                // Billing postings already exist. No root impersonation.
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
            if ($this->reconcile) {
                // The lifecycle resolves an uncertain CREATE with GET only.
                $fulfill->reconcile($this->orderId);
            } else {
                // Paid settlement, Squad, idempotency and default-OFF flags
                // are checked again on the worker before any provider POST.
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

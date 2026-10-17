<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Jobs;

use App\Modules\Provisioning\Application\FulfillPaidPlanOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/** OP-03: only explicitly confirmed Admin actions enqueue this one-order job. */
final class FulfillPaidPlanOrderJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 55;

    public int $uniqueFor = 180;

    public function __construct(public int $orderId, public bool $reconcile = false)
    {
        // Never let a sync queue run the provider operation in a Telegram webhook.
        $this->onConnection('database');
    }

    public function uniqueId(): string
    {
        return 'parsa:fulfillment:'.$this->orderId;
    }

    public function handle(FulfillPaidPlanOrder $fulfill): void
    {
        try {
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

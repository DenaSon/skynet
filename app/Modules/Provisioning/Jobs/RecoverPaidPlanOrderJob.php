<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Jobs;

use App\Modules\Provisioning\Application\RecoverPaidPlanOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Single explicitly authorized recovery, isolated from regular sales workers. */
final class RecoverPaidPlanOrderJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 55;

    public int $uniqueFor = 180;

    public function __construct(public int $orderId, public int $actorId)
    {
        $this->onConnection(ProviderQueueConnection::name());
    }

    public function uniqueId(): string
    {
        // Share the order lock with regular provisioning/reconciliation workers.
        return 'parsa:fulfillment:'.$this->orderId;
    }

    public function handle(RecoverPaidPlanOrder $recovery): void
    {
        try {
            $recovery->handle($this->actorId, $this->orderId);
        } catch (Throwable) {
            // Never retry an uncertain CREATE or expose credentials, payment
            // records or remote errors in generic queue logs.
            Log::warning('Manual paid-order recovery requires operator inspection.', [
                'order_id' => $this->orderId,
            ]);
        }
    }
}

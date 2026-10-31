<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Jobs;

use App\Modules\Provisioning\Application\ManageResellerSubscriptionStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/** One provider-write attempt; never silently replay an uncertain PATCH. */
final class ChangeResellerSubscriptionStatusJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 55;

    public int $uniqueFor = 180;

    public function __construct(
        public int $buyerId,
        public int $orderId,
        public string $mode,
        public string $operationKey,
    ) {
        $this->onConnection(ProviderQueueConnection::name());
    }

    public function uniqueId(): string
    {
        return 'denagate-live:reseller-status:'.$this->orderId;
    }

    public function handle(ManageResellerSubscriptionStatus $statuses): void
    {
        try {
            $statuses->change($this->buyerId, $this->orderId, $this->mode, $this->operationKey);
        } catch (Throwable) {
            // Never log upstream provider bodies, URLs or subscription secrets.
            Log::warning('denagate_live.reseller_status_needs_review', [
                'order_id' => $this->orderId,
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Jobs;

use App\Modules\Provisioning\Application\ManageFulfilledSubscriptionStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/** One explicit request; no retry on ambiguous remote status updates. */
final class ChangeFulfilledSubscriptionStatusJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 55;

    public int $uniqueFor = 180;

    public function __construct(
        public int $orderId,
        public int $actorId,
        public string $mode,
        public string $operationKey = '',
    ) {
        $this->onConnection('database');
    }

    public function uniqueId(): string
    {
        return 'parsa:subscription-status:'.$this->orderId;
    }

    public function handle(ManageFulfilledSubscriptionStatus $statuses): void
    {
        try {
            if ($this->mode === 'reconcile') {
                $statuses->reconcile($this->actorId, $this->orderId);
            } else {
                $statuses->change($this->actorId, $this->orderId, $this->mode, $this->operationKey);
            }
        } catch (Throwable) {
            // Never log provider responses, subscription credentials or exceptions.
            Log::warning('Subscription status outcome needs operator review.', [
                'order_id' => $this->orderId,
            ]);
        }
    }
}

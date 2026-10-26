<?php

declare(strict_types=1);

namespace App\Modules\Trial\Jobs;

use App\Modules\Provisioning\Jobs\ProviderQueueConnection;
use App\Modules\Trial\Application\ProvisionFreeTrial;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProvisionFreeTrialJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 55;

    public int $uniqueFor = 180;

    public function __construct(public readonly int $trialId)
    {
        $this->onConnection(ProviderQueueConnection::name());
    }

    public function uniqueId(): string
    {
        return 'free-trial:'.$this->trialId;
    }

    public function handle(ProvisionFreeTrial $provision): void
    {
        try {
            $provision->handle($this->trialId);
        } catch (Throwable) {
            Log::warning('Trial activation unavailable.', ['trial_id' => $this->trialId]);
        }
    }
}

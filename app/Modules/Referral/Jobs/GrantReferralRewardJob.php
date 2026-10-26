<?php

declare(strict_types=1);

namespace App\Modules\Referral\Jobs;

use App\Modules\Provisioning\Jobs\ProviderQueueConnection;
use App\Modules\Sales\Application\ReferralRewardOutbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** SQL intent/ledger state is authoritative; queue uniqueness only reduces duplicate work. */
final class GrantReferralRewardJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 120;

    public function __construct(public readonly int $orderId)
    {
        $this->onConnection(ProviderQueueConnection::name());
    }

    public function uniqueId(): string
    {
        return 'referral:purchase:'.$this->orderId;
    }

    public function handle(ReferralRewardOutbox $outbox): void
    {
        $outbox->process($this->orderId);
    }
}

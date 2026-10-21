<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

/** Non-payable, credential-free renewal preview; never a settled order. */
final readonly class RenewalDraftPreview
{
    public function __construct(
        public int $id,
        public int $sourceOrderId,
        public string $planName,
        public int $durationDays,
        public ?int $trafficGb,
        public int $priceRials,
    ) {}
}

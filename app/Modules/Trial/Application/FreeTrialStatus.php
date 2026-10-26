<?php

declare(strict_types=1);

namespace App\Modules\Trial\Application;

final readonly class FreeTrialStatus
{
    public function __construct(
        public int $id,
        public string $providerStatus,
        public int $remainingBytes,
        public int $remainingSeconds,
    ) {}

    public function ended(): bool
    {
        return $this->remainingBytes === 0 || $this->remainingSeconds === 0;
    }
}

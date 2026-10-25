<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Infrastructure\Remnawave;

/** Safe, read-only subset of a remote node. No address, IP or proxy credentials. */
final readonly class RemnawaveNodeSummary
{
    public function __construct(
        public string $uuid,
        public string $name,
        public bool $connected,
        public bool $disabled,
        public ?bool $connecting = null,
        public ?int $usersOnline = null,
        public ?int $rxBytesPerSec = null,
        public ?int $txBytesPerSec = null,
        public ?string $lastStatusChange = null,
        public ?int $trafficLimitBytes = null,
        public ?int $trafficUsedBytes = null,
        public ?bool $trafficTrackingActive = null,
    ) {}
}

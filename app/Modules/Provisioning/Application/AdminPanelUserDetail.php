<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

/** Safe Admin-only provider projection; never stores subscription URLs or connection keys. */
final readonly class AdminPanelUserDetail
{
    public function __construct(
        public string $state,
        public ?int $id = null,
        public ?string $username = null,
        public ?string $status = null,
        public ?int $trafficLimitBytes = null,
        public ?int $usedTrafficBytes = null,
        public ?string $expiresAt = null,
        public ?bool $subscriptionMatched = null,
    ) {}
}

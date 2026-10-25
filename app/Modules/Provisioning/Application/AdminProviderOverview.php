<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

/**
 * Deliberately small, secret-free projection. Unverified/offline data is never
 * presented as a live provider result.
 */
final readonly class AdminProviderOverview
{
    /** @param list<array<string, int|string|bool|null>>|null $nodeSummaries */
    public function __construct(
        public string $state,
        public ?int $instances = null,
        public ?int $nodes = null,
        public ?int $connectedNodes = null,
        public ?int $hosts = null,
        public ?int $internalSquads = null,
        public ?int $configProfiles = null,
        public ?int $needsReview = null,
        public ?AdminSystemStats $systemStats = null,
        public ?array $nodeSummaries = null,
    ) {}
}

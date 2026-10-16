<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

/** Safe usage projection. No provider identifier, username or connection URL. */
final readonly class SubscriptionUsage
{
    public function __construct(
        public string $expireAt,
        public int $usedBytes,
        public int $limitBytes,
    ) {}
}

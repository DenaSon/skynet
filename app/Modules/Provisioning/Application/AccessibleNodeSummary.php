<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

/** Public-safe display only: never surface provider UUID/IP/host/inbounds. */
final readonly class AccessibleNodeSummary
{
    public function __construct(
        public string $name,
        public string $countryCode,
    ) {}
}

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
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Infrastructure\Remnawave;

/**
 * Secret-free, read-only Remnawave stats. Memory figures are reported in bytes.
 * These are panel-reported values, not independently verified VPS telemetry.
 */
final readonly class RemnawaveSystemStats
{
    public function __construct(
        public int $cpuCores,
        public int $memoryTotal,
        public int $memoryUsed,
        public int $memoryFree,
        public float $uptimeSeconds,
        public int $totalUsers,
        public int $onlineUsers,
    ) {}
}

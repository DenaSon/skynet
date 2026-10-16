<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

/** Secret-free projection of panel-reported, not independently verified VPS, system statistics. */
final readonly class AdminSystemStats
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

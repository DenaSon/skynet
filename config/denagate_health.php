<?php

declare(strict_types=1);

return [
    // Rollout is explicitly opt-in after the health history migration and worker checks.
    // This controls scheduled probes only; manual CLI commands remain available.
    'enabled' => env('DENAGATE_HEALTH_ENABLED', false),

    // Explicitly vetted UUID@public-IPv4:service-port targets; empty = no TCP probes.
    // Independent of Spatie health checks and never accepts Telegram-supplied addresses.
    'node_tcp_targets' => env('DENAGATE_NODE_TCP_TARGETS', ''),
];

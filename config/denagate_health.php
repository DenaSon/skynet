<?php

declare(strict_types=1);

return [
    // Rollout is explicitly opt-in after the health history migration and worker checks.
    // This controls scheduled probes only; manual CLI commands remain available.
    'enabled' => env('DENAGATE_HEALTH_ENABLED', false),
];

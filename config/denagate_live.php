<?php

declare(strict_types=1);

return [
    // Deployment opt-in. Never expose a live entry before a real Telegram smoke test.
    'enabled' => env('DENAGATE_LIVE_ENABLED', false),
    // Independent opt-in for real reseller Provider status writes.
    'reseller_status_enabled' => env('DENAGATE_LIVE_RESELLER_STATUS_ENABLED', false),
    'host' => 'live.denagate.ir',
    'url' => 'https://live.denagate.ir',
    'init_data_max_age' => 300,
];

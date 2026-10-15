<?php

return [
    // Independent from Sales public activation. OFF by default for safe rollout.
    'enabled' => env('TELEGRAM_NOTIFICATIONS_ENABLED', false),
    'max_attempts' => 5,
    'max_photo_bytes' => 5 * 1024 * 1024,
];

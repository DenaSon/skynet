<?php

return [
    // Independent from Sales public activation. OFF by default for safe rollout.
    'enabled' => env('TELEGRAM_NOTIFICATIONS_ENABLED', false),
    'max_attempts' => 5,
    'max_photo_bytes' => 5 * 1024 * 1024,

    // Optional single-chat Admin notification topic pilot. Never affects Sales.
    'admin_topic' => [
        'enabled' => env('TELEGRAM_ADMIN_NOTIFICATIONS_TOPIC_ENABLED', false),
        'chat_id' => env('TELEGRAM_ADMIN_NOTIFICATIONS_TOPIC_CHAT_ID'),
        'thread_id' => env('TELEGRAM_ADMIN_NOTIFICATIONS_TOPIC_THREAD_ID'),
    ],
];

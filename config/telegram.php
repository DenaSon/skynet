<?php

return [
    // Telegram sends this value in X-Telegram-Bot-Api-Secret-Token.
    // Never accept webhooks unless it is configured.
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    'bot_username' => env('TELEGRAM_BOT_USERNAME'),
    'sales' => [
        'enabled' => env('TELEGRAM_SALES_ENABLED', false),
        'token' => env('TELEGRAM_SALES_TOKEN'),
        'webhook_secret' => env('TELEGRAM_SALES_WEBHOOK_SECRET'),
        'bot_username' => env('TELEGRAM_SALES_BOT_USERNAME'),
        'support_username' => env('TELEGRAM_SALES_SUPPORT_USERNAME'),
    ],
    'denix' => [
        'enabled' => (bool) env('TELEGRAM_DENIX_ENABLED', false),
        'token' => env('TELEGRAM_DENIX_TOKEN'),
        'webhook_secret' => env('TELEGRAM_DENIX_WEBHOOK_SECRET'),
        'bot_username' => env('TELEGRAM_DENIX_BOT_USERNAME', 'denixobot'),
        'group_chat_id' => env('DENAGATE_AI_GROUP_CHAT_ID'),
        // Expected numeric Telegram user ID of the connected support account; fail closed if absent.
        'support_business_user_id' => env('DENAGATE_AI_SUPPORT_BUSINESS_USER_ID'),
    ],
    'public_signup_enabled' => env('TELEGRAM_PUBLIC_SIGNUP_ENABLED', false),
    'hybrid_menu_enabled' => env('TELEGRAM_HYBRID_MENU_ENABLED', false),
];

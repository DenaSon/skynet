<?php

return [
    // Telegram sends this value in X-Telegram-Bot-Api-Secret-Token.
    // Never accept webhooks unless it is configured.
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    'bot_username' => env('TELEGRAM_BOT_USERNAME'),
    'public_signup_enabled' => env('TELEGRAM_PUBLIC_SIGNUP_ENABLED', false),
];

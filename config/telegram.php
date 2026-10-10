<?php

return [
    // Telegram sends this value in X-Telegram-Bot-Api-Secret-Token.
    // Never accept webhooks unless it is configured.
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
];

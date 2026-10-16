<?php

return [
    'token' => env('TELEGRAM_TOKEN'),
    // Webhook authentication is enforced before Nutgram::run() by VerifyTelegramWebhook.
    // Do not enable the vendor's APP_KEY-derived safe_mode in addition to our explicit secret.
    'safe_mode' => false,
    'config' => [],
    'routes' => true,
    'mixins' => false,
    'namespace' => app_path('Interfaces/Telegram'),
    // Vendor errors may contain the BotFather token and full Telegram update.
    // The webhook controller already emits structured, credential-free warnings.
    'log_channel' => 'null',
];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'remnawave' => [
        'url' => env('REMNAWAVE_PANEL_URL'),
        'token' => env('REMNAWAVE_API_TOKEN'),
        'timeout' => (int) env('REMNAWAVE_TIMEOUT', 15),
        'writes_enabled' => (bool) env('REMNAWAVE_WRITES_ENABLED', false),
        // Separate gate, only after a real squad UUID/membership review.
        'squad_bindings_live_verified' => (bool) env('REMNAWAVE_SQUAD_BINDINGS_LIVE_VERIFIED', false),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];

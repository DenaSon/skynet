<?php

declare(strict_types=1);

return [
    // The DenaGate AI module always selects this provider explicitly.
    'default' => 'gapgpt',

    'providers' => [
        'gapgpt' => [
            'driver' => 'openai-compatible',
            'url' => env('GAPGPT_BASE_URL', 'https://api.gapgpt.app/v1'),
            'key' => env('GAPGPT_API_KEY'),
            'models' => [
                'text' => [
                    'default' => 'gpt-6-luna',
                ],
            ],
        ],
    ],
];

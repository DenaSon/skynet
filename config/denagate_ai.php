<?php

declare(strict_types=1);

return [
    // No network request can be made until an operator explicitly opts in.
    'enabled' => (bool) env('DENAGATE_AI_ENABLED', false),
    'default_model' => 'gpt-6-luna',
    'max_input_chars' => 2000,
    'max_output_chars' => 6000,
    'timeout_seconds' => 20,
    'context_max_messages' => 30,
    'context_max_chars' => 6000,
    'context_ttl_minutes' => 30,
    'user_requests_per_10_minutes' => 5,
    'group_requests_per_day' => 50,
    // Enable candidates only AFTER real GapGPT smoke and provider-price verification.
    'verified_economy_models' => array_filter(array_map('trim', explode(',', (string) env('DENAGATE_AI_VERIFIED_ECONOMY_MODELS', '')))),
];

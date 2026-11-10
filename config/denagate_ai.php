<?php

declare(strict_types=1);

return [
    // No network request can be made until an operator explicitly opts in.
    'enabled' => (bool) env('DENAGATE_AI_ENABLED', false),
    // Additional deployment safety gate: an old ON database toggle never activates
    // customer replies solely due to a source deployment. The root DB switch remains independent.
    'business_replies_enabled' => (bool) env('DENAGATE_AI_BUSINESS_REPLIES_ENABLED', false),
    'default_model' => 'gpt-6-luna',
    'max_input_chars' => 2000,
    'max_output_chars' => 6000,
    'timeout_seconds' => 20,
    'context_max_messages' => 30,
    'context_max_chars' => 6000,
    'context_ttl_minutes' => 30,
    'user_requests_per_10_minutes' => 25,
    'group_requests_per_day' => 250,
    // Operator-approved 4x Business support capacity; independent of management-group quotas.
    'business_customer_requests_per_10_minutes' => max(1, (int) env('DENAGATE_AI_BUSINESS_CUSTOMER_REQUESTS_PER_10_MINUTES', 20)),
    'business_customer_requests_per_day' => max(1, (int) env('DENAGATE_AI_BUSINESS_CUSTOMER_REQUESTS_PER_DAY', 80)),
    'business_global_requests_per_day' => max(1, (int) env('DENAGATE_AI_BUSINESS_GLOBAL_REQUESTS_PER_DAY', 200)),
    // Enable candidates only AFTER real GapGPT smoke and provider-price verification.
    'verified_economy_models' => array_filter(array_map('trim', explode(',', (string) env('DENAGATE_AI_VERIFIED_ECONOMY_MODELS', '')))),
];

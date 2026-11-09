<?php

declare(strict_types=1);

return [
    // No network request can be made until an operator explicitly opts in.
    'enabled' => (bool) env('DENAGATE_AI_ENABLED', false),
    'default_model' => 'gpt-6-luna',
    'max_input_chars' => 2000,
    'max_output_chars' => 6000,
    'timeout_seconds' => 20,
    'context_max_messages' => 6,
    'context_max_chars' => 6000,
    'context_ttl_minutes' => 30,
    'user_requests_per_10_minutes' => 5,
    'group_requests_per_day' => 50,
];

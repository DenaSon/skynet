<?php

return [
    // Configuration is a second factor for privileges, not automatic role assignment.
    // Use two or more Telegram numeric IDs, then enroll linked accounts via CLI.
    'telegram_ids' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SUPER_ADMIN_TELEGRAM_IDS', '')),
    ), static fn (string $value): bool => preg_match('/^[1-9][0-9]*$/D', $value) === 1)),
];

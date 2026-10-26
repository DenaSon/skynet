<?php

declare(strict_types=1);

return [
    // Never offer or reserve free trials before an explicit operator rollout.
    'enabled' => (bool) env('TRIAL_ENABLED', false),
    // Verify a disposable trial on this exact Squad before configuring its second value.
    'internal_squad_uuid' => strtolower((string) env('TRIAL_INTERNAL_SQUAD_UUID', '')),
    'verified_squad_uuid' => strtolower((string) env('TRIAL_VERIFIED_SQUAD_UUID', '')),
    'daily_limit' => (int) env('TRIAL_DAILY_LIMIT', 20),
    // Independent, stable, random 32+ character secret. Never store raw phones.
    'phone_hmac_key' => env('TRIAL_PHONE_HMAC_KEY'),
];

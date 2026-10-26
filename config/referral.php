<?php

return [
    'rewards_enabled' => env('REFERRAL_REWARDS_ENABLED', false),
    // Keep raw config values so malformed input cannot silently become money.
    'reward_tomans' => env('REFERRAL_REWARD_TOMANS', 30000),
    'max_rewards_per_referrer' => env('REFERRAL_MAX_REWARDS_PER_REFERRER', 7),
    // Required explicit UTC rollout boundary; never backfill historical purchases.
    'rewards_start_at' => env('REFERRAL_REWARDS_START_AT'),
];

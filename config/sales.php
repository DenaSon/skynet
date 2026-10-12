<?php

return [
    // F09-A: one public rate catalog; reseller pays 95% of the same quoted price.
    // Only 0 or 5 is supported for the initial MVP (5% is the agreed default).
    'reseller_discount_percent' => 5,
    // F11-A1: internal settlement only; no consumer route and no dispatcher.
    'wallet_settlement_enabled' => env('SALES_WALLET_SETTLEMENT_ENABLED', false),
    'manual_bank_review_enabled' => env('SALES_MANUAL_BANK_REVIEW_ENABLED', false),
];

<?php

return [
    // Confirmed wallet credit needed to apply for a Sales Panel (tomans).
    'panel_minimum_wallet_toman' => env('SALES_PANEL_MINIMUM_WALLET_TOMAN', 350_000),
    // F09-A: one public rate catalog; reseller pays 95% of the same quoted price.
    // Only 0 or 5 is supported for the initial MVP (5% is the agreed default).
    'reseller_discount_percent' => 5,
    // F11-A1: internal settlement only; no consumer route and no dispatcher.
    'wallet_settlement_enabled' => env('SALES_WALLET_SETTLEMENT_ENABLED', false),
    'manual_bank_review_enabled' => env('SALES_MANUAL_BANK_REVIEW_ENABLED', false),
    'one_click_bank_review_enabled' => env('SALES_ONE_CLICK_BANK_REVIEW_ENABLED', true),
    'auto_fulfillment_enabled' => env('SALES_AUTO_FULFILLMENT_ENABLED', true),
    // REN-02: deployment must separately verify real RW semantics before enabling paid renewals.
    'renewal_enabled' => env('SALES_RENEWAL_ENABLED', false),
    // Official RW Subpage domain, not the API/panel host. Empty disables UI.
    'subscription_page_host' => env('SALES_SUBSCRIPTION_PAGE_HOST', ''),
];

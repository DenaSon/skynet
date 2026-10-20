<?php

return [
    'topups_enabled' => env('BILLING_TOPUPS_ENABLED', false),
    'payment_instructions' => env('BILLING_PAYMENT_INSTRUCTIONS', ''),
    'payment_card_number' => env('BILLING_PAYMENT_CARD_NUMBER', ''),
];

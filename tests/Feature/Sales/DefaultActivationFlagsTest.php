<?php

declare(strict_types=1);

/**
 * MVP activation is opt-out: missing environment entries cannot silently
 * disable the approved one-decision checkout and queued RW provisioning.
 * Explicit false values remain available as an emergency rollback.
 */
it('enables one-click payment and automatic Remnawave fulfillment by default', function (): void {
    expect(config('sales.one_click_bank_review_enabled'))->toBeTrue()
        ->and(config('sales.auto_fulfillment_enabled'))->toBeTrue()
        ->and(config('services.remnawave.writes_enabled'))->toBeTrue()
        ->and(config('services.remnawave.squad_bindings_live_verified'))->toBeTrue();
});

it('keeps explicit runtime overrides available for disabling sensitive operations', function (): void {
    config()->set('sales.one_click_bank_review_enabled', false);
    config()->set('sales.auto_fulfillment_enabled', false);
    config()->set('services.remnawave.writes_enabled', false);
    config()->set('services.remnawave.squad_bindings_live_verified', false);

    expect(config('sales.one_click_bank_review_enabled'))->toBeFalse()
        ->and(config('sales.auto_fulfillment_enabled'))->toBeFalse()
        ->and(config('services.remnawave.writes_enabled'))->toBeFalse()
        ->and(config('services.remnawave.squad_bindings_live_verified'))->toBeFalse();
});

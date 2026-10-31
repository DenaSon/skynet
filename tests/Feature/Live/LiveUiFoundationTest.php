<?php

declare(strict_types=1);

it('defines exactly ten reusable UI primitives without leaking selectors outside DenaGate Live', function (): void {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toBeString()
        ->toContain('@theme {')
        ->toContain('@layer components {')
        ->toContain('--color-dg-primary: #14b8a6')
        ->toContain('--color-dg-action: #087f73');

    preg_match_all('/^\\s*\\.denagate-live \\.(dg-[a-z-]+) \\{/m', $css, $matches);

    expect($matches[1])->toBe([
        'dg-card',
        'dg-card-accent',
        'dg-stat',
        'dg-btn-primary',
        'dg-btn-secondary',
        'dg-field',
        'dg-status',
        'dg-heading',
        'dg-empty',
        'dg-nav-item',
    ]);

    $layer = explode('@layer components {', $css, 2)[1];
    $layer = explode("\n}\n\n@media", $layer, 2)[0];

    preg_match_all('/^\\s*(\\.[^\\n{]+)\\s*\\{/m', $layer, $selectors);

    foreach ($selectors[1] as $selector) {
        expect(trim($selector))->toStartWith('.denagate-live ');
    }
});

it('provides accessible form and navigation states without changing the existing Live runtime or standalone entry', function (): void {
    $css = file_get_contents(resource_path('css/app.css'));
    $layout = file_get_contents(resource_path('views/live/layout.blade.php'));
    $entry = file_get_contents(resource_path('views/live/entry.blade.php'));

    expect($css)->toContain('min-height: 44px')
        ->toContain(':focus-visible')
        ->toContain(':disabled')
        ->toContain('[aria-disabled="true"]')
        ->toContain('[aria-invalid="true"]')
        ->toContain('[aria-current="page"]')
        ->toContain('[data-tone="success"]')
        ->toContain('[data-tone="warning"]')
        ->toContain('[data-tone="danger"]')
        ->toContain('@media (prefers-reduced-motion: reduce)')
        ->and($layout)->toContain('denagate-live')
        ->toContain("@vite(['resources/css/app.css', 'resources/js/live.js'])")
        ->toContain('@livewireScriptConfig')
        ->and($entry)->toContain('id="telegram-login"')
        ->not->toContain('@vite(');
});

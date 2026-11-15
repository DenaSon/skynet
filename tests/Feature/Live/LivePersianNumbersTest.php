<?php

declare(strict_types=1);

use App\Support\PersianCalendar;

it('renders formatted Persian numbers without changing raw machine-facing values', function (): void {
    expect(PersianCalendar::number('723,256'))->toBe('۷۲۳٬۲۵۶')
        ->and(PersianCalendar::number('100,000.3'))->toBe('۱۰۰٬۰۰۰.۳')
        ->and(PersianCalendar::number('0'))->toBe('۰');

    // Callers localize only display strings. Numeric request and DB values stay untouched.
    $stored = 723256;
    expect($stored)->toBe(723256);
});

it('localizes text in Live and Demo but retains ASCII protocol and form values', function (): void {
    $dashboard = file_get_contents(resource_path('views/live/dashboard.blade.php'));
    $demo = file_get_contents(resource_path('views/live/demo-preview.blade.php'));
    $metric = file_get_contents(resource_path('views/components/live/report-metric.blade.php'));

    expect($dashboard)->toContain('PersianCalendar::number')
        ->toContain('number_format(intdiv($report[\'revenueRials\'], 10))')
        ->toContain('wire:click="selectReportDays({{ $days }})"')
        ->toContain('x-model.number="value"')
        ->toContain('x-on:click="$wire.chooseBuilderVolume(value)"')
        ->toContain('x-on:click="$wire.chooseBuilderDays(value)"')
        ->toContain('x-text="new Intl.NumberFormat(\'fa-IR\'')
        ->toContain('wire:model="customerUsername"')
        ->toContain('value="{{ $serviceSubscriptionUrl }}"')
        ->and($demo)->toContain('PersianCalendar::number(number_format(intdiv(')
        ->toContain('x-model.number="demoGb"')
        ->toContain('x-model.number="demoDays"')
        ->toContain('demoGb = {{ $gb }}')
        ->toContain('demoDays = {{ $days }}')
        ->toContain('new Intl.NumberFormat(\'fa-IR\'')
        ->and($metric)->toContain('PersianCalendar::number')
        ->toContain('number_format($value)');
});

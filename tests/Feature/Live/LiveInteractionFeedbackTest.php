<?php

declare(strict_types=1);

it('disables each Livewire action on its own in-flight request without changing its callback', function (): void {
    $blade = file_get_contents(resource_path('views/live/dashboard.blade.php'));
    expect($blade)->toBeString()
        ->not->toContain('wire:loading.delay role="status" class="mb-4"');

    preg_match_all('/<button\\b[\\s\\S]*?<\\/button>/u', $blade, $matches);
    $actions = 0;

    foreach ($matches[0] as $button) {
        if (! preg_match('/wire:click="([^"]+)"/u', $button)) {
            continue;
        }

        $actions++;
        expect($button)
            ->toContain('wire:loading.attr="disabled"')
            ->toContain('wire:target="');
    }

    expect($actions)->toBeGreaterThanOrEqual(35);
});

it('provides specific progress feedback for expensive and sensitive actions', function (): void {
    $blade = file_get_contents(resource_path('views/live/dashboard.blade.php'));

    foreach ([
        'selectPlan' => 'در حال انتخاب…',
        'previewQuote' => 'در حال محاسبه…',
        'confirmOrder' => 'در حال ثبت سفارش…',
        'prepareWalletPayment' => 'در حال بررسی اعتبار…',
        'confirmWalletPayment' => 'در حال ثبت پرداخت…',
        'revealServiceConnection' => 'در حال دریافت…',
        'refreshServiceUsage' => 'در حال بررسی…',
        'saveServiceLabel' => 'در حال ذخیره…',
        'prepareServiceStatus' => 'در حال بررسی…',
        'confirmServiceStatus' => 'در حال ثبت درخواست…',
        'previewServiceRenewal' => 'در حال بررسی قیمت…',
        'createServiceRenewal' => 'در حال ثبت سفارش…',
        'prepareRenewalPayment' => 'در حال بررسی اعتبار…',
        'confirmRenewalPayment' => 'در حال ثبت پرداخت…',
        'nextServices' => 'در حال دریافت…',
    ] as $action => $message) {
        expect($blade)->toContain('wire:loading.delay wire:target="'.$action.'" role="status"')
            ->toContain($message);
    }

    expect($blade)->toContain('wire:target="cancelWalletPayment,confirmWalletPayment"')
        ->toContain('wire:target="cancelServiceStatus,confirmServiceStatus"')
        ->toContain('wire:target="cancelRenewal,confirmRenewalPayment,createServiceRenewal"');
});

it('shows exact order and service context before risky confirmations and preserves field help', function (): void {
    $blade = file_get_contents(resource_path('views/live/dashboard.blade.php'));

    expect($blade)
        ->toContain('برای سفارش #{{ $createdOrder->id }}')
        ->toContain('سفارش #{{ $item->orderId }}')
        ->toContain('سفارش #{{ $renewalOrder->id }}')
        ->toContain('wire:click="confirmWalletPayment"')
        ->toContain('wire:click="confirmRenewalPayment"')
        ->toContain('wire:click="confirmServiceStatus"')
        ->toContain('aria-describedby="customer-username-hint"')
        ->toContain('aria-describedby="live-customer-label-hint"')
        ->toContain('aria-describedby="quote-days-hint"')
        ->toContain('aria-describedby="quote-gb-hint"');
});

it('reports failed connection copying without disclosing credentials outside the existing reveal gate', function (): void {
    $blade = file_get_contents(resource_path('views/live/dashboard.blade.php'));

    expect($blade)
        ->toContain('x-data="{ copied: false, copyError: false, copying: false }"')
        ->toContain('x-bind:disabled="copying"')
        ->toContain('x-cloak x-show="copied" role="status"')
        ->toContain('x-cloak x-show="copyError" role="alert"')
        ->toContain('کپی خودکار ممکن نشد. لینک را انتخاب و دستی کپی کنید.')
        ->toContain('@if ($serviceSubscriptionUrl !== null && $serviceQrDataUri !== null)')
        ->toContain('wire:click="revealServiceConnection"')
        ->toContain('wire:click="hideServiceConnection"');
});

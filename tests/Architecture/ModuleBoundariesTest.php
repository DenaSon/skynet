<?php

use Symfony\Component\Finder\Finder;

arch('identity has no administration dependency')
    ->expect('App\\Modules\\Identity')
    ->not->toUse('App\\Modules\\Administration');

arch('administration has no identity implementation dependency')
    ->expect('App\\Modules\\Administration')
    ->not->toUse('App\\Modules\\Identity');

arch('modules do not depend on legacy HTTP controllers')
    ->expect('App\\Modules')
    ->not->toUse('App\\Http\\Controllers');

arch('sales depends on Billing application contracts, never Billing infrastructure')
    ->expect('App\Modules\Sales')
    ->not->toUse('App\Modules\Billing\Infrastructure');

arch('sales does not depend on provider infrastructure')
    ->expect('App\Modules\Sales')
    ->not->toUse('App\Modules\Provisioning\Infrastructure');

arch('telegram does not depend on Billing persistence')
    ->expect('App\Interfaces\Telegram')
    ->not->toUse('App\Modules\Billing\Infrastructure');

arch('telegram does not depend on Sales persistence')
    ->expect('App\Interfaces\Telegram')
    ->not->toUse('App\Modules\Sales\Infrastructure');

arch('telegram does not depend on Remnawave infrastructure')
    ->expect('App\Interfaces\Telegram')
    ->not->toUse('App\Modules\Provisioning\Infrastructure');

it('disallows bypassing Billing through raw Sales SQL', function (): void {
    // Pest namespace architecture checks cannot detect Query Builder strings.
    // This boundary also protects against a future raw SQL shortcut in Sales.
    $forbidden = [
        'DB::table(\'billing_', 'DB::table("billing_',
        'DB::from(\'billing_', 'DB::from("billing_',
        '->join(\'billing_', '->join("billing_',
        '->leftJoin(\'billing_', '->leftJoin("billing_',
        'sales_bank_postings',
    ];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/app/Modules/Sales')->name('*.php') as $file) {
        foreach ($forbidden as $fragment) {
            expect($file->getContents())->not->toContain($fragment);
        }
    }
});

arch('referral uses application boundaries rather than other module persistence')
    ->expect('App\\Modules\\Referral')
    ->not->toUse([
        'App\\Modules\\Billing\\Infrastructure',
        'App\\Modules\\Sales\\Infrastructure',
        'App\\Modules\\Identity\\Infrastructure',
        'App\\Modules\\Provisioning\\Infrastructure',
    ]);

it('keeps reward financial SQL inside Billing', function (): void {
    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/app/Modules/Referral')->name('*.php') as $file) {
        expect($file->getContents())->not->toContain('billing_postings', 'billing_wallets', 'billing_journals');
    }
});

arch('identity does not depend on provider infrastructure')
    ->expect('App\\Modules\\Identity')
    ->not->toUse('App\\Modules\\Provisioning\\Infrastructure');

arch('billing does not depend on provider infrastructure')
    ->expect('App\\Modules\\Billing')
    ->not->toUse('App\\Modules\\Provisioning\\Infrastructure');

it('does not introduce new module-to-Telegram imports beyond explicit legacy seams', function (): void {
    // Snapshot of pre-existing imports, not a blanket exemption for a module.
    // Removing a legacy import is allowed; adding another one fails this guard.
    $legacy = [
        'Filtering/Application/FilteringIncidentAlerts.php' => [
            'App\\Interfaces\\Telegram\\Notifications\\DispatchTelegramNotification',
        ],
        'Provisioning/Application/FulfillPaidPlanOrder.php' => [
            'App\\Interfaces\\Telegram\\Notifications\\PaymentNotificationOutbox',
        ],
        'Provisioning/Application/FulfillPaidRenewalOrder.php' => [
            'App\\Interfaces\\Telegram\\Notifications\\PaymentNotificationOutbox',
        ],
        'Sales/Application/ManualBankPayments.php' => [
            'App\\Interfaces\\Telegram\\Notifications\\PaymentNotificationOutbox',
        ],
        'Sales/Application/ReferralRewardOutbox.php' => [
            'App\\Interfaces\\Telegram\\Notifications\\ReferralRewardNotificationOutbox',
        ],
        'Sales/Application/SettleWalletOrder.php' => [
            'App\\Interfaces\\Telegram\\Notifications\\PaymentNotificationOutbox',
        ],
        'Trial/Application/EndFreeTrial.php' => [
            'App\\Interfaces\\Telegram\\Notifications\\TrialNotificationOutbox',
        ],
        'Trial/Application/ProvisionFreeTrial.php' => [
            'App\\Interfaces\\Telegram\\Notifications\\TrialNotificationOutbox',
        ],
    ];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/app/Modules')->name('*.php') as $file) {
        $path = str_replace('\\', '/', $file->getRelativePathname());

        foreach (explode("\n", $file->getContents()) as $line) {
            $line = trim($line);
            if (! str_starts_with($line, 'use App\\Interfaces\\Telegram\\')) {
                continue;
            }

            $import = substr(rtrim($line, ';'), 4);
            expect(in_array($import, $legacy[$path] ?? [], true))
                ->toBeTrue("Unexpected module-to-Telegram dependency: {$path}: {$import}");
        }
    }
});

it('does not expand existing Filtering-to-Provisioning infrastructure imports', function (): void {
    // These read-only Remnawave imports predate this architecture guard.
    $legacy = [
        'Application/FilteringIncidentNotice.php' => [
            'App\\Modules\\Provisioning\\Infrastructure\\Remnawave\\RemnawaveApiClientFactory',
            'App\\Modules\\Provisioning\\Infrastructure\\Remnawave\\RemnawaveReadApi',
        ],
        'Application/MonitorNodeFiltering.php' => [
            'App\\Modules\\Provisioning\\Infrastructure\\Remnawave\\RemnawaveApiClientFactory',
            'App\\Modules\\Provisioning\\Infrastructure\\Remnawave\\RemnawaveNodeSummary',
            'App\\Modules\\Provisioning\\Infrastructure\\Remnawave\\RemnawaveReadApi',
        ],
    ];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/app/Modules/Filtering')->name('*.php') as $file) {
        $path = str_replace('\\', '/', $file->getRelativePathname());

        foreach (explode("\n", $file->getContents()) as $line) {
            $line = trim($line);
            if (! str_starts_with($line, 'use App\\Modules\\Provisioning\\Infrastructure\\')) {
                continue;
            }

            $import = substr(rtrim($line, ';'), 4);
            expect(in_array($import, $legacy[$path] ?? [], true))
                ->toBeTrue("Unexpected Filtering provider dependency: {$path}: {$import}");
        }
    }
});

<?php

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
        "DB::table('billing_", 'DB::table("billing_',
        "DB::from('billing_", 'DB::from("billing_',
        "->join('billing_", '->join("billing_',
        "->leftJoin('billing_", '->leftJoin("billing_',
        "sales_bank_postings",
    ];

    foreach ((new \Symfony\Component\Finder\Finder)->files()->in(dirname(__DIR__, 2).'/app/Modules/Sales')->name('*.php') as $file) {
        foreach ($forbidden as $fragment) {
            expect($file->getContents())->not->toContain($fragment);
        }
    }
});

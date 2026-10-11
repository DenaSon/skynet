<?php

use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('telegram:prune-updates')->daily();

/*
|--------------------------------------------------------------------------
| F09-A operator-only catalog setup
|--------------------------------------------------------------------------
| Prices are explicit operator data, not seeded guesses. No Telegram/HTTP
| mutation endpoint exists for products or rates in the MVP.
*/
Artisan::command('sales:product:set {slug} {name} {--min-gb=1} {--max-gb=100} {--inactive}', function () {
    $min = (string) $this->option('min-gb');
    $max = (string) $this->option('max-gb');

    if (! ctype_digit($min) || ! ctype_digit($max) || strlen($min) > 3 || strlen($max) > 3) {
        $this->error('Volume limits must be positive integer GB values.');

        return 1;
    }

    try {
        app(ConfigureProduct::class)->handle(
            (string) $this->argument('slug'),
            (string) $this->argument('name'),
            (int) $min,
            (int) $max,
            $this->option('inactive') === false,
        );
    } catch (ValidationException $exception) {
        $this->error(implode('; ', $exception->validator->errors()->all()));

        return 1;
    }

    $this->info('Product catalog entry saved.');

    return 0;
})->purpose('Create/update a Sales product using an operator terminal');

Artisan::command('sales:rate:set {slug} {days} {base_toman} {per_gb_toman} {--inactive}', function () {
    $parts = [
        (string) $this->argument('days'),
        (string) $this->argument('base_toman'),
        (string) $this->argument('per_gb_toman'),
    ];

    foreach ($parts as $part) {
        if (! ctype_digit($part) || strlen($part) > 11) {
            $this->error('Duration and prices must be non-negative integer values.');

            return 1;
        }
    }

    try {
        app(ConfigureRate::class)->handle(
            (string) $this->argument('slug'),
            (int) $parts[0],
            (int) $parts[1] * 10,
            (int) $parts[2] * 10,
            $this->option('inactive') === false,
        );
    } catch (ValidationException $exception) {
        $this->error(implode('; ', $exception->validator->errors()->all()));

        return 1;
    }

    $this->info('Duration tariff saved.');

    return 0;
})->purpose('Create/update a Sales duration rate from integer-toman inputs');

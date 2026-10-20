<?php

use App\Modules\Provisioning\Jobs\FulfillPaidPlanOrderJob;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('telegram:prune-updates')->daily();
Schedule::command('telegram:notifications:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('sales:fulfillment:dispatch')->everyMinute()->withoutOverlapping();

// Recovery for a committed bank approval whose post-commit enqueue was lost.
// Never dispatch while provider/price-binding gates are disabled. The existing
// unique queue job checks reviewer access and settlement before any write.
Artisan::command('sales:fulfillment:dispatch', function (): int {
    if (config('sales.auto_fulfillment_enabled', false) !== true
        || config('services.remnawave.writes_enabled') !== true
        || config('services.remnawave.squad_bindings_live_verified') !== true) {
        return 0;
    }

    $rows = DB::table('sales_fulfillment_outbox as outbox')
        ->join('sales_orders as orders', 'orders.id', '=', 'outbox.sales_order_id')
        ->join('sales_bank_settlements as bank', 'bank.sales_order_id', '=', 'orders.id')
        ->join('sales_bank_payment_claims as claim', 'claim.id', '=', 'bank.payment_claim_id')
        ->where('orders.status', 'paid')->where('outbox.state', 'pending')
        ->where('claim.status', 'approved')->whereNotNull('claim.reviewed_by')
        ->orderBy('orders.id')->limit(20)
        ->get(['orders.id', 'claim.reviewed_by']);

    foreach ($rows as $row) {
        FulfillPaidPlanOrderJob::dispatch((int) $row->id, false, (int) $row->reviewed_by);
    }

    return 0;
})->purpose('Recover approved paid-plan activation intents through the existing authorized queue');

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

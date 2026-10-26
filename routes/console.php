<?php

use App\Interfaces\Telegram\Notifications\SubscriptionAlertMonitor;
use App\Interfaces\Telegram\Notifications\TrialEndMonitor;
use App\Interfaces\Telegram\Processing\SalesTelegramRuntime;
use App\Interfaces\Telegram\Support\SalesNativeMenu;
use App\Modules\Billing\Application\VerifyWalletOrderDebit;
use App\Modules\Provisioning\Jobs\FulfillPaidPlanOrderJob;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Trial\Application\TrialAvailability;
use App\Modules\Trial\Jobs\ProvisionFreeTrialJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// One-time, explicitly invoked operator action. Never register commands in a webhook
// or in the Scheduler, and never target the Admin Bot token.
Artisan::command('sales:telegram:menu:register', function (): int {
    $salesToken = config('telegram.sales.token');
    $adminToken = config('nutgram.token');

    if (config('telegram.sales.enabled') !== true
        || ! is_string($salesToken) || trim($salesToken) === ''
        || ! is_string($adminToken) || trim($adminToken) === ''
        || hash_equals($salesToken, $adminToken)) {
        $this->error('Sales Bot or separate Admin Bot credentials are not configured correctly.');

        return 1;
    }

    try {
        SalesNativeMenu::register(app(SalesTelegramRuntime::class)->make());
    } catch (Throwable) {
        $this->error('Could not register the Sales native command menu. Check Sales Bot connectivity.');

        return 1;
    }

    $this->info('Sales native command menu registered. Existing users can send /start to remove the old keyboard.');

    return 0;
})->purpose('Register the native Telegram command menu on the Sales Bot only');

// Reuse the existing proven pruning logic without a second deletion path.
Schedule::command('system:cleanup')->weekly()->withoutOverlapping();
Schedule::command('telegram:notifications:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('telegram:receipts:remind')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('sales:fulfillment:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('sales:subscriptions:alerts')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('system:cleanup', function (): int {
    // Only processed, 7+ day-old Telegram webhook receipts; never Sales or ledger rows.
    $result = Artisan::call('telegram:prune-updates');
    $this->output->write(Artisan::output());

    return $result;
})->purpose('Safely clean processed old Telegram webhook receipts');

// Scan only linked, authorized Sales subscriptions. Uses provider GET only.
Artisan::command('sales:subscriptions:alerts', function (): int {
    $checked = app(SubscriptionAlertMonitor::class)->scan();
    $this->info("Checked {$checked} owned subscription(s).");

    return 0;
})->purpose('Queue expiry and remaining-traffic warnings for owned Sales subscriptions');

// Recover a committed payment whose post-commit enqueue was lost. Only untouched
// pending intents qualify; an uncertain CREATE must never be replayed by cron.
Artisan::command('sales:fulfillment:dispatch', function (): int {
    if (config('sales.auto_fulfillment_enabled', false) !== true
        || config('services.remnawave.writes_enabled') !== true
        || config('services.remnawave.squad_bindings_live_verified') !== true) {
        return 0;
    }

    $pending = DB::table('sales_fulfillment_outbox as outbox')
        ->join('sales_orders as orders', 'orders.id', '=', 'outbox.sales_order_id')
        ->where('orders.status', 'paid')->whereNotNull('orders.plan_id')
        ->where('outbox.state', 'pending')->where('outbox.attempts', 0)
        ->whereNotExists(function ($query): void {
            $query->selectRaw('1')->from('provisioning_operations as operation')
                ->whereColumn('operation.idempotency_key', 'outbox.event_key');
        })
        ->whereNotExists(function ($query): void {
            $query->selectRaw('1')->from('provisioning_order_links as link')
                ->whereColumn('link.sales_order_id', 'orders.id');
        });

    // Stream candidates in bounded pages, then cap eligible jobs per payment
    // method. Rejected old rows must not hide later recoverable paid intents.
    $bank = (clone $pending)
        ->join('sales_bank_settlements as bank', 'bank.sales_order_id', '=', 'orders.id')
        ->join('sales_bank_payment_claims as claim', 'claim.id', '=', 'bank.payment_claim_id')
        ->whereColumn('claim.sales_order_id', 'orders.id')
        ->whereColumn('bank.amount_rials', 'orders.total_price_rials')
        ->where('claim.status', 'approved')->whereNotNull('claim.reviewed_by')
        ->where('claim.reviewed_by', '>', 0)
        ->whereNotExists(function ($query): void {
            $query->selectRaw('1')->from('sales_order_settlements as wallet')
                ->whereColumn('wallet.sales_order_id', 'orders.id');
        })
        ->select(['orders.id', 'outbox.event_key', 'claim.reviewed_by'])
        ->lazyById(20, 'orders.id', 'id');

    $wallet = (clone $pending)
        ->join('sales_order_settlements as wallet', 'wallet.sales_order_id', '=', 'orders.id')
        ->where('wallet.method', 'customer_wallet')
        ->whereColumn('wallet.amount_rials', 'orders.total_price_rials')
        ->whereNotExists(function ($query): void {
            $query->selectRaw('1')->from('sales_bank_settlements as bank')
                ->whereColumn('bank.sales_order_id', 'orders.id');
        })
        ->select(['orders.id', 'outbox.event_key', 'orders.buyer_user_id', 'orders.total_price_rials', 'wallet.billing_journal_id'])
        ->lazyById(20, 'orders.id', 'id');

    foreach ([$bank, $wallet] as $rows) {
        $dispatched = 0;
        foreach ($rows as $row) {
            $orderId = (int) $row->id;
            $renewal = DB::table('sales_renewal_orders')->where('sales_order_id', $orderId)->exists();
            $event = 'sales-order:'.$orderId.($renewal ? ':renew' : ':create');
            if ($row->event_key !== $event
                || ($renewal && config('sales.renewal_enabled', false) !== true)
                || (! $renewal && DB::table('provisioning_subscriptions')
                    ->where('reference', 'sales-order:'.$orderId)->exists())) {
                continue;
            }
            $actorId = (int) ($row->reviewed_by ?? 0);
            if ($actorId === 0 && ! app(VerifyWalletOrderDebit::class)->handle(
                (int) $row->buyer_user_id, $orderId, (int) $row->billing_journal_id, (int) $row->total_price_rials,
            )) {
                continue;
            }
            FulfillPaidPlanOrderJob::dispatch($orderId, false, $actorId);
            if (++$dispatched === 20) {
                break;
            }
        }
    }

    return 0;
})->purpose('Recover untouched paid bank/wallet plan intents through the existing authorized queue');

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

// Recover only untouched trial reservations. Claimed/uncertain writes are never replayed.
Schedule::command('sales:trials:dispatch')->everyMinute()->withoutOverlapping();
Artisan::command('sales:trials:dispatch', function (): int {
    if (! TrialAvailability::ready()) {
        return 0;
    }
    DB::table('trial_claims')->where('state', 'provisioning')->where('updated_at', '<', now()->subMinutes(3))
        ->update(['state' => 'needs_review', 'updated_at' => now()]);
    foreach (DB::table('trial_claims')->where('state', 'reserved')->orderBy('id')->limit(20)->pluck('id') as $id) {
        ProvisionFreeTrialJob::dispatch((int) $id);
    }

    return 0;
})->purpose('Recover untouched free-trial reservations through the provider queue');

Schedule::command('sales:trials:monitor')->everyMinute()->withoutOverlapping();
Artisan::command('sales:trials:monitor', function (): int {
    $ended = app(TrialEndMonitor::class)->scan();
    if ($ended > 0) {
        $this->info("Finalized {$ended} free trial(s).");
    }

    return 0;
})->purpose('Verify trial time/quota completion and queue one terminal notice');

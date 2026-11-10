<?php

use App\Interfaces\Telegram\Notifications\SubscriptionAlertMonitor;
use App\Interfaces\Telegram\Notifications\TrialEndMonitor;
use App\Interfaces\Telegram\Processing\DenixCommandCatalog;
use App\Interfaces\Telegram\Processing\DenizBusinessConnectionGuard;
use App\Interfaces\Telegram\Processing\SalesTelegramRuntime;
use App\Interfaces\Telegram\Support\SalesNativeMenu;
use App\Modules\Billing\Application\VerifyWalletOrderDebit;
use App\Modules\Filtering\Application\MonitorNodeFiltering;
use App\Modules\Filtering\Application\PruneFilteringHistory;
use App\Modules\Filtering\Application\ScanRegionalFiltering;
use App\Modules\Provisioning\Jobs\FulfillPaidPlanOrderJob;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Sales\Application\ReferralRewardOutbox;
use App\Modules\Trial\Application\ReadFreeTrial;
use App\Modules\Trial\Application\TrialAvailability;
use App\Modules\Trial\Jobs\ProvisionFreeTrialJob;
use App\Support\Health\NodeTcpProbe;
use App\Support\Health\OperationalProbe;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;
use Spatie\Health\Commands\DispatchQueueCheckJobsCommand;
use Spatie\Health\Commands\ScheduleCheckHeartbeatCommand;
use Spatie\Health\Models\HealthCheckResultHistoryItem;

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

// Register only the configured Deniz management group's native slash menu.
// This is an explicit operator action, never a deploy hook or scheduled job.
Artisan::command('deniz:telegram:commands:register {--dry-run : Preview without a Telegram request}', function (): int {
    $chat = config('telegram.denix.group_chat_id');
    if ((! is_int($chat) && (! is_string($chat) || preg_match('/\A-[1-9][0-9]*\z/D', $chat) !== 1))
        || (int) $chat >= 0) {
        $this->error('Configure a valid negative Deniz management group chat ID.');

        return 1;
    }

    $commands = DenixCommandCatalog::telegramCommands();
    if ($this->option('dry-run')) {
        foreach ($commands as $command) {
            $this->line('/'.$command['command'].' — '.$command['description']);
        }

        return 0;
    }

    $token = config('telegram.denix.token');
    if (config('telegram.denix.enabled') !== true
        || config('denagate_ai.enabled') !== true
        || ! is_string($token) || preg_match('/\A[1-9][0-9]*:[A-Za-z0-9_-]+\z/D', $token) !== 1
        || $token === config('nutgram.token') || $token === config('telegram.sales.token')) {
        $this->error('Deniz is disabled or its independent Bot token is not configured.');

        return 1;
    }

    try {
        $response = Http::timeout(10)->asJson()->post('https://api.telegram.org/bot'.$token.'/setMyCommands', [
            'commands' => $commands,
            'scope' => ['type' => 'chat', 'chat_id' => (int) $chat],
        ]);
    } catch (Throwable) {
        $this->error('Could not register the Deniz group command menu.');

        return 1;
    }

    if (! $response->successful() || $response->json('ok') !== true || $response->json('result') !== true) {
        $this->error('Telegram did not confirm Deniz group command registration.');

        return 1;
    }

    $this->info('Deniz management group commands registered.');

    return 0;
})->purpose('Register Deniz slash commands for its configured management group only');

// Read-only, privacy-safe P0 diagnostics. Never prints customer identities,
 // connection IDs, user questions, tokens or provider response bodies.
Artisan::command('deniz:business:status', function (DenizBusinessConnectionGuard $access): int {
    $global = 'denix:business:quota:global';
    $attempts = RateLimiter::attempts($global);
    $limit = max(1, (int) config('denagate_ai.business_global_requests_per_day', 50));
    $queue = config('queue.default');

    $this->line('Business gate: '.($access->enabled() ? 'enabled' : 'disabled'));
    $this->line('Shared Business usage: '.$attempts.'/'.$limit);
    $this->line('Shared quota resets in: '.RateLimiter::availableIn($global).' seconds');
    $this->line('Cache driver: '.config('cache.default'));
    $this->line('Queue driver: '.$queue);

    if ($queue === 'database' && Schema::hasTable('jobs')) {
        $this->line('Pending ai jobs: '.DB::table('jobs')->where('queue', 'ai')->count());
    }

    if (Schema::hasTable('failed_jobs')) {
        $this->line('Failed ai jobs: '.DB::table('failed_jobs')->where('queue', 'ai')->count());
    }

    return 0;
})->purpose('Inspect Deniz Business shared quota, gate and AI queue without secrets or mutations');

// Explicit one-time emergency recovery, never scheduled or automatic.
// A reset permits additional paid requests and must be operator-approved.
Artisan::command('deniz:business:quota:reopen {--confirm : I approve reopening the paid shared Business budget}', function (): int {
    if ($this->option('confirm') !== true) {
        $this->error('No changes made. Reopening paid Business quota requires --confirm.');

        return 1;
    }

    $global = 'denix:business:quota:global';
    $attempts = RateLimiter::attempts($global);
    RateLimiter::clear($global);
    Cache::forget('denix:business:ops:global-capacity-warning');

    Log::warning('Deniz Business shared quota manually reopened.', [
        'prior_attempts' => $attempts,
        'limit' => max(1, (int) config('denagate_ai.business_global_requests_per_day', 50)),
    ]);
    $this->info('Shared Business quota reopened; personal customer quotas remain unchanged.');

    return 0;
})->purpose('Explicitly reopen only the shared Deniz Business quota during an approved incident');

// Node TCP targets are derived from RW Nodes + direct Hosts every run.
// No manual UUID/IP mapping and no network probes in Telegram.
Artisan::command('network:tcp-probe', function (): int {
    $checked = app(NodeTcpProbe::class)->run();
    $this->info("Checked {$checked} approved Node TCP target(s).");

    return 0;
})->purpose('Record bounded TCP connect latency to live RW direct Node endpoints');

Schedule::command('network:tcp-probe')->everyMinute()->withoutOverlapping(5);

// DenaGate Health foundation: checks run only after explicit production opt-in.
// Queue probes and Scheduler heartbeat precede the check in the same minute.
// Spatie notifications are OFF; future Telegram UI will read stored results.
Schedule::command(DispatchQueueCheckJobsCommand::class)
    ->everyMinute()->when(static fn (): bool => config('denagate_health.enabled') === true)
    ->withoutOverlapping(5);
Schedule::command(ScheduleCheckHeartbeatCommand::class)
    ->everyMinute()->when(static fn (): bool => config('denagate_health.enabled') === true)
    ->withoutOverlapping(5);
Schedule::command('health:check --no-notification')
    ->everyMinute()->when(static fn (): bool => config('denagate_health.enabled') === true)
    ->withoutOverlapping(5);
Schedule::command('model:prune', ['--model' => [HealthCheckResultHistoryItem::class]])
    ->dailyAt('03:45')->when(static fn (): bool => config('denagate_health.enabled') === true)
    ->withoutOverlapping();

// FLT-02: separately gated, bounded Remnawave GET-only Node observations.
Schedule::command('filtering:nodes:scan')->everyTwoMinutes()->withoutOverlapping(3);
Artisan::command('filtering:nodes:scan', function (): int {
    $count = app(MonitorNodeFiltering::class)->scan();
    $this->info("Recorded {$count} eligible Node observation(s).");

    return 0;
})->purpose('Collect read-only per-Node filtering signals and queue guarded Admin notices');

// FLT-03: gated Iran regional TCP observations; offset from common :00/:30
// scheduled work while retaining one read-only scan every ten minutes.
Schedule::command('filtering:regional:scan')->cron('3-59/10 * * * *')->withoutOverlapping(5)->runInBackground();
Artisan::command('filtering:regional:scan', function (): int {
    $count = app(ScanRegionalFiltering::class)->scan();
    $this->info("Recorded {$count} regional Node observation(s).");

    return 0;
})->purpose('Run bounded Check-Host Iran TCP reachability observations');

// FLT-01: bounded retention only; no polling, alerts or provider interactions.
Schedule::command('filtering:prune')->everyFiveMinutes()->withoutOverlapping(5);
Artisan::command('filtering:prune', function (): int {
    $pruned = app(PruneFilteringHistory::class)->run();
    $this->info('Filtering history pruned: '.$pruned['checks'].' checks, '.$pruned['incidents'].' resolved incidents.');

    return 0;
})->purpose('Prune expired filtering checks and resolved incidents in bounded batches');

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

    // Only after a successful scan; failure to persist the observational
    // heartbeat must never affect subscription alerts or deliveries.
    try {
        Cache::put(OperationalProbe::SUBSCRIPTION_HEARTBEAT, now()->timestamp, now()->addDay());
    } catch (Throwable) {
        // Health will report an unknown heartbeat instead of masking the job.
    }

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
    $this->info("Finalized {$ended} free trial(s); warnings checked for active reachable trials.");

    return 0;
})->purpose('Verify trial time/quota completion and queue one terminal notice');

// Read-only deployment diagnosis: never reveal phone, HMAC, token or connection URL.
Artisan::command('sales:trials:inspect {id? : Trial claim ID; defaults to the latest claim}', function (): int {
    $query = DB::table('trial_claims');
    $id = $this->argument('id');
    if ($id !== null && (! ctype_digit((string) $id) || (int) $id < 1)) {
        $this->error('Invalid trial ID.');

        return 1;
    }
    $trial = $id === null ? $query->orderByDesc('id')->first() : $query->find((int) $id);
    if ($trial === null) {
        $this->info('No trial claim found.');

        return 0;
    }
    $contact = DB::table('telegram_bot_contacts')->where('user_id', $trial->user_id)
        ->where('bot_key', 'sales')->where('delivery_status', 'reachable')
        ->where('chat_id', $trial->telegram_user_id)->exists();
    $this->line(json_encode([
        'trial_id' => (int) $trial->id, 'state' => $trial->state, 'end_reason' => $trial->end_reason,
        'trial_enabled' => config('trial.enabled'), 'sales_enabled' => config('telegram.sales.enabled'),
        'notifications_enabled' => config('telegram_notifications.enabled'), 'queue' => config('queue.default'),
        'sales_contact_reachable' => $contact, 'end_notice_started' => $trial->end_notice_started_at !== null,
        'end_notice_sent' => $trial->end_notice_sent_at !== null,
    ], JSON_THROW_ON_ERROR));
    try {
        $live = app(ReadFreeTrial::class)
            ->status((int) $trial->user_id, (int) $trial->telegram_user_id);
        $this->line(json_encode([
            'provider_read' => 'ok', 'provider_status' => $live->providerStatus,
            'remaining_bytes' => $live->remainingBytes, 'remaining_seconds' => $live->remainingSeconds,
            'ended' => $live->ended(),
        ], JSON_THROW_ON_ERROR));
    } catch (Throwable) {
        $this->warn('Provider/owner verification unavailable; no completion inferred.');
    }
    $this->line(DB::table('telegram_notification_deliveries')->where('trial_claim_id', $trial->id)
        ->orderBy('id')->get(['id', 'event_type', 'state', 'attempts', 'last_failure', 'available_at', 'sent_at'])->toJson());

    return 0;
})->purpose('Inspect safe trial state, provider counters and notification delivery evidence (GET only)');

// Recover only explicitly captured successful-purchase intents; never scan old sales for rewards.
Schedule::command('sales:referrals:dispatch')->everyMinute()->withoutOverlapping();
Artisan::command('sales:referrals:dispatch', function (): int {
    $count = app(ReferralRewardOutbox::class)->dispatchPending();
    $this->info("Queued {$count} referral reward intent(s).");

    return 0;
})->purpose('Recover pending referral rewards through the existing asynchronous queue');

Artisan::command('sales:referrals:status', function (): int {
    $this->line(json_encode(app(ReferralRewardOutbox::class)->status(), JSON_THROW_ON_ERROR));

    return 0;
})->purpose('Read referral worker readiness and safe delivery-state counts');

<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use App\Interfaces\Telegram\Notifications\TelegramNotificationSender;
use App\Modules\Trial\Application\ProvisionFreeTrial;
use App\Modules\Trial\Application\ReadFreeTrial;
use App\Modules\Trial\Application\ReserveFreeTrial;
use App\Modules\Trial\Jobs\ProvisionFreeTrialJob;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\Support\FreeTrialFixtures;

uses(RefreshDatabase::class, FreeTrialFixtures::class);

beforeEach(function (): void {
    $this->configureFreeTrial();
    $this->freezeTime();
});

it('creates exactly 250 MiB and two worker-time hours independently of commerce', function (): void {
    $member = $this->trialMember();
    $id = app(ReserveFreeTrial::class)->handle($member->id, 9210001, 9210001, '+989123456789');
    $this->travel(40)->minutes();
    $start = now()->startOfSecond();
    app(ProvisionFreeTrial::class)->handle($id);
    $row = DB::table('trial_claims')->find($id);
    expect($row->state)->toBe('active')
        ->and($this->trialRemote['trafficLimitBytes'])->toBe(262144000)
        ->and($this->trialRemote['trafficLimitStrategy'])->toBe('NO_RESET')
        ->and($this->trialRemote['expireAt'])->toBe($start->copy()->addHours(2)->format('Y-m-d\TH:i:s\Z'))
        ->and(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(0)
        ->and(DB::table('provisioning_order_links')->count())->toBe(0);
    app(ProvisionFreeTrial::class)->handle($id);
    (new ProvisionFreeTrialJob($id))->handle(app(ProvisionFreeTrial::class));
    Http::assertSentCount(1);
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'TrialActivated')->count())->toBe(1);
});

it('does not write when disabled or the exact Squad verification is missing', function (string $key, mixed $value): void {
    $member = $this->trialMember();
    $id = app(ReserveFreeTrial::class)->handle($member->id, 9210001, 9210001, '+989123456789');
    config()->set($key, $value);
    expect(fn () => app(ProvisionFreeTrial::class)->handle($id))->toThrow(ValidationException::class);
    expect(DB::table('trial_claims')->find($id)->state)->toBe('reserved');
    Http::assertNothingSent();
})->with([
    ['trial.enabled', false], ['services.remnawave.writes_enabled', false],
    ['trial.verified_squad_uuid', ''], ['trial.internal_squad_uuid', 'bad'],
]);

it('rechecks account and paid history at the worker without releasing the claim', function (): void {
    $member = $this->trialMember();
    $id = app(ReserveFreeTrial::class)->handle($member->id, 9210001, 9210001, '+989123456789');
    $member->is_active = false;
    $member->save();
    app(ProvisionFreeTrial::class)->handle($id);
    expect(DB::table('trial_claims')->find($id)->state)->toBe('failed');
    Http::assertNothingSent();
});

it('retains rejected or uncertain CREATE outcomes and never automatically replays them', function (int $status): void {
    $member = $this->trialMember();
    $id = app(ReserveFreeTrial::class)->handle($member->id, 9210001, 9210001, '+989123456789');
    $this->trialProviderStatus = $status;
    app(ProvisionFreeTrial::class)->handle($id);
    app(ProvisionFreeTrial::class)->handle($id);
    expect(DB::table('trial_claims')->find($id)->state)->toBe('needs_review')
        ->and(DB::table('provisioning_operations')->value('state'))->toBe('needs_review');
    Http::assertSentCount(1);
    $this->artisan('sales:trials:dispatch')->assertSuccessful();
    Queue::assertNotPushed(ProvisionFreeTrialJob::class);
})->with([400, 503]);

it('recovers an untouched reservation and quarantines a crashed claimed job', function (): void {
    $member = $this->trialMember();
    $id = app(ReserveFreeTrial::class)->handle($member->id, 9210001, 9210001, '+989123456789');
    $this->artisan('sales:trials:dispatch')->assertSuccessful();
    Queue::assertPushed(ProvisionFreeTrialJob::class, fn ($job): bool => $job->trialId === $id && $job->connection === 'database');
    DB::table('trial_claims')->where('id', $id)->update(['state' => 'provisioning', 'updated_at' => now()->subMinutes(4)]);
    Queue::fake();
    $this->artisan('sales:trials:dispatch')->assertSuccessful();
    Queue::assertNotPushed(ProvisionFreeTrialJob::class);
    expect(DB::table('trial_claims')->find($id)->state)->toBe('needs_review');
    Http::assertNothingSent();
});

it('reads fresh owner-bound status and denies expired exhausted changed or cross-user credentials', function (): void {
    $member = $this->trialMember();
    $this->activateFreeTrial($member);
    $reads = app(ReadFreeTrial::class);
    expect($reads->status($member->id, 9210001)->remainingSeconds)->toBe(7200)
        ->and($reads->connection($member->id, 9210001))->toBe('https://sub.example.test/private-trial');
    expect(fn () => $reads->connection($member->id, 9299999))->toThrow(AuthorizationException::class);
    $this->trialRemote['userTraffic']['usedTrafficBytes'] = ProvisionFreeTrial::BYTES;
    expect($reads->status($member->id, 9210001)->ended())->toBeTrue();
    expect(fn () => $reads->connection($member->id, 9210001))->toThrow(RuntimeException::class);
    $this->trialRemote['trafficLimitBytes']++;
    expect(fn () => $reads->status($member->id, 9210001))->toThrow(RuntimeException::class);
});

it('reconciles a committed provider CREATE using GET and never a second POST', function (): void {
    $member = $this->trialMember();
    $id = $this->activateFreeTrial($member);
    DB::table('trial_claims')->where('id', $id)->update(['state' => 'needs_review', 'provisioning_subscription_id' => null]);
    DB::table('provisioning_operations')->update(['state' => 'needs_review']);
    DB::table('provisioning_subscriptions')->update(['state' => 'needs_review']);
    config()->set('super_admin.telegram_ids', ['9210091', '9210092']);
    $operator = $this->trialMember(9210091);
    Role::findOrCreate('super-admin', 'web');
    $operator->syncRoles(['super-admin']);
    app(ProvisionFreeTrial::class)->reconcile($id, $operator->id);
    expect(DB::table('trial_claims')->find($id)->state)->toBe('active');
    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request->method() === 'GET');
    expect(DB::table('provisioning_operations')->count())->toBe(1);
});

it('queues one activation intent and sends only after a fresh owned provider check', function (): void {
    $member = $this->trialMember();
    $id = $this->activateFreeTrial($member);
    DB::table('telegram_bot_contacts')->insert([
        'user_id' => $member->id, 'bot_key' => 'sales', 'chat_id' => 9210001,
        'delivery_status' => 'reachable', 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $delivery = DB::table('telegram_notification_deliveries')->where('trial_claim_id', $id)->first();
    $worker = new DispatchTelegramNotification((int) $delivery->id);
    $worker->handle(app(TelegramNotificationSender::class));
    $worker->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->find($delivery->id)->state)->toBe('sent');
    Http::assertSentCount(3); // CREATE, ownership GET, one Telegram send.
});

it('rechecks paid purchase history between reservation and activation', function (): void {
    $member = $this->trialMember();
    $id = app(ReserveFreeTrial::class)->handle($member->id, 9210001, 9210001, '+989123456789');
    DB::table('sales_orders')->insert([
        'buyer_user_id' => $member->id, 'idempotency_key' => str_repeat('a', 32),
        'product_slug' => 'existing', 'product_name' => 'Paid Plan', 'duration_days' => 30, 'traffic_gb' => 10,
        'public_price_rials' => 1000, 'discount_percent' => 0, 'discount_rials' => 0,
        'total_price_rials' => 1000, 'status' => 'paid', 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(ProvisionFreeTrial::class)->handle($id);
    expect(DB::table('trial_claims')->find($id)->state)->toBe('failed');
    Http::assertNothingSent();
});

it('caps worker-day activations across a backlog admitted on earlier days', function (): void {
    $one = $this->trialMember();
    $two = $this->trialMember(9210002);
    $first = app(ReserveFreeTrial::class)->handle($one->id, 9210001, 9210001, '+989123456789');
    $second = app(ReserveFreeTrial::class)->handle($two->id, 9210002, 9210002, '+989125556677');
    $this->travel(1)->days();
    config()->set('trial.daily_limit', 1);
    app(ProvisionFreeTrial::class)->handle($first);
    app(ProvisionFreeTrial::class)->handle($second);
    expect(DB::table('trial_claims')->find($second)->state)->toBe('reserved')
        ->and((int) DB::table('trial_admission_days')->sum('activation_count'))->toBe(1);
    Http::assertSentCount(1);
    $this->travel(1)->days();
    app(ProvisionFreeTrial::class)->handle($second);
    expect(DB::table('trial_claims')->find($second)->state)->toBe('active');
    Http::assertSentCount(2);
});

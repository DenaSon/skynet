<?php

declare(strict_types=1);

use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Referral\Jobs\GrantReferralRewardJob;
use App\Modules\Sales\Application\ReferralRewardOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ReferralRewardFixture as Fixture;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('referral', [
        'rewards_enabled' => true, 'reward_tomans' => 30000,
        'max_rewards_per_referrer' => 7, 'rewards_start_at' => '2026-10-26T00:00:00Z',
    ]);
    $this->travelTo(now()->setDate(2026, 10, 26)->setTime(12, 0));
    Http::preventStrayRequests();
    Queue::fake();
});

function ref02Capture(int $order): void
{
    $fields = app(ReferralRewardOutbox::class)->captureFields();
    if ($fields !== []) {
        DB::table('sales_fulfillment_outbox')->where('sales_order_id', $order)->update($fields);
    }
}

function ref02Row(int $order): object
{
    return DB::table('sales_fulfillment_outbox')->where('sales_order_id', $order)->first();
}

it('recovers lost enqueue and commits the frozen reward and delivery state exactly once', function (): void {
    $referrer = Fixture::member();
    $order = Fixture::order(Fixture::member(), $referrer);
    ref02Capture($order);
    $original = ref02Row($order)->updated_at;
    expect(DB::table('referral_rewards')->count())->toBe(0);
    $this->travel(5)->minutes();
    config()->set('referral.reward_tomans', 45000);
    $outbox = app(ReferralRewardOutbox::class);
    expect($outbox->dispatchPending())->toBe(1);
    Queue::assertPushed(GrantReferralRewardJob::class, fn ($job) => $job->orderId === $order && $job->connection === 'database');
    $job = new GrantReferralRewardJob($order);
    $job->handle($outbox);
    $job->handle($outbox);
    expect(ref02Row($order)->referral_state)->toBe('credited')
        ->and((int) ref02Row($order)->referral_attempts)->toBe(1)
        ->and(ref02Row($order)->updated_at)->toBe($original)
        ->and(DB::table('referral_rewards')->count())->toBe(1)
        ->and(app(ViewWallet::class)->handle($referrer->id)->balanceRials)->toBe(300000)
        ->and($outbox->dispatchPending())->toBe(0)
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(0);
    Http::assertNothingSent();
});

it('does not backfill historic or disabled activations when enabled later', function (): void {
    $old = Fixture::order(Fixture::member(), Fixture::member());
    $disabled = Fixture::order(Fixture::member(), Fixture::member());
    config()->set('referral.rewards_enabled', false);
    ref02Capture($disabled);
    config()->set('referral.rewards_enabled', true);
    $outbox = app(ReferralRewardOutbox::class);
    expect($outbox->dispatchPending())->toBe(0);
    $outbox->process($old);
    $outbox->process($disabled);
    expect(ref02Row($disabled)->referral_state)->toBeNull()
        ->and(DB::table('referral_rewards')->count())->toBe(0);
});

it('pauses captured work while disabled or misconfigured then resumes without repricing', function (): void {
    $order = Fixture::order(Fixture::member(), Fixture::member());
    ref02Capture($order);
    $outbox = app(ReferralRewardOutbox::class);
    config()->set('referral.rewards_enabled', false);
    $outbox->process($order);
    expect($outbox->dispatchPending())->toBe(0);
    config()->set('referral.rewards_enabled', true);
    config()->set('referral.reward_tomans', 'invalid');
    $outbox->process($order);
    expect(ref02Row($order)->referral_state)->toBe('pending')
        ->and((int) ref02Row($order)->referral_attempts)->toBe(0);
    config()->set('referral.reward_tomans', 50000);
    $outbox->process($order);
    expect(ref02Row($order)->referral_state)->toBe('credited')
        ->and((int) DB::table('referral_rewards')->value('amount_rials'))->toBe(300000);
});

it('permanently suppresses a capped intent even if the cap is raised later', function (): void {
    config()->set('referral.max_rewards_per_referrer', 1);
    $referrer = Fixture::member();
    $first = Fixture::order(Fixture::member(), $referrer);
    $second = Fixture::order(Fixture::member(), $referrer);
    ref02Capture($first);
    ref02Capture($second);
    $outbox = app(ReferralRewardOutbox::class);
    $outbox->process($first);
    $outbox->process($second);
    expect(ref02Row($second)->referral_state)->toBe('suppressed');
    config()->set('referral.max_rewards_per_referrer', 7);
    $outbox->process($second);
    expect(DB::table('referral_rewards')->count())->toBe(1)
        ->and($outbox->dispatchPending())->toBe(0);
});

it('honors a lower current cap without changing captured amounts', function (): void {
    $referrer = Fixture::member();
    $orders = [Fixture::order(Fixture::member(), $referrer), Fixture::order(Fixture::member(), $referrer)];
    foreach ($orders as $order) {
        ref02Capture($order);
    }
    config()->set('referral.max_rewards_per_referrer', 1);
    foreach ($orders as $order) {
        app(ReferralRewardOutbox::class)->process($order);
    }
    expect(DB::table('referral_rewards')->count())->toBe(1);
});

it('keeps first successful purchase ordering even when later jobs run first', function (): void {
    $buyer = Fixture::member();
    $referrer = Fixture::member();
    $first = Fixture::order($buyer, $referrer);
    ref02Capture($first);
    $this->travel(1)->seconds();
    $second = Fixture::order($buyer, $referrer);
    ref02Capture($second);
    $outbox = app(ReferralRewardOutbox::class);
    $outbox->process($second);
    $this->travel(2)->minutes();
    $outbox->process($first);
    expect(ref02Row($second)->referral_state)->toBe('suppressed')
        ->and(ref02Row($first)->referral_state)->toBe('credited')
        ->and((int) DB::table('referral_rewards')->value('sales_order_id'))->toBe($first);
});

it('suppresses ineligible membership instead of endlessly retrying', function (): void {
    $referrer = Fixture::member();
    $order = Fixture::order(Fixture::member(), $referrer);
    ref02Capture($order);
    $referrer->forceFill(['is_active' => false])->save();
    app(ReferralRewardOutbox::class)->process($order);
    $referrer->forceFill(['is_active' => true])->save();
    app(ReferralRewardOutbox::class)->process($order);
    expect(ref02Row($order)->referral_state)->toBe('suppressed')
        ->and(ref02Row($order)->referral_failure)->toBe('membership')
        ->and(DB::table('referral_rewards')->count())->toBe(0);
});

it('holds broken settlement evidence for review without affecting the fulfilled order', function (): void {
    $order = Fixture::order(Fixture::member(), Fixture::member());
    ref02Capture($order);
    DB::table('billing_postings')->where('side', 'debit')->update(['amount_rials' => 1]);
    app(ReferralRewardOutbox::class)->process($order);
    expect(ref02Row($order)->referral_state)->toBe('needs_review')
        ->and(ref02Row($order)->referral_failure)->toBe('integrity')
        ->and(ref02Row($order)->state)->toBe('completed')
        ->and(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('fulfilled')
        ->and(DB::table('referral_rewards')->count())->toBe(0);
    Http::assertNothingSent();
});

it('rolls back money when terminal state fails and retries only the local reward', function (): void {
    $order = Fixture::order(Fixture::member(), Fixture::member());
    ref02Capture($order);
    DB::unprepared("CREATE TRIGGER fail_ref02 BEFORE UPDATE ON sales_fulfillment_outbox WHEN NEW.referral_state = 'credited' BEGIN SELECT RAISE(ABORT, 'fixture'); END");
    $outbox = app(ReferralRewardOutbox::class);
    $outbox->process($order);
    expect(ref02Row($order)->referral_state)->toBe('pending')
        ->and((int) ref02Row($order)->referral_attempts)->toBe(1)
        ->and(DB::table('referral_rewards')->count())->toBe(0)
        ->and(DB::table('billing_journals')->where('kind', 'referral_reward')->count())->toBe(0);
    DB::unprepared('DROP TRIGGER fail_ref02');
    $outbox->process($order); // not due yet
    expect(ref02Row($order)->referral_state)->toBe('pending');
    $this->travel(61)->seconds();
    $outbox->process($order);
    expect(ref02Row($order)->referral_state)->toBe('credited')
        ->and((int) ref02Row($order)->referral_attempts)->toBe(2)
        ->and(DB::table('referral_rewards')->count())->toBe(1);
    Http::assertNothingSent();
});

it('bounds persistent database failures and does not lose their durable intent', function (): void {
    $order = Fixture::order(Fixture::member(), Fixture::member());
    ref02Capture($order);
    DB::unprepared("CREATE TRIGGER fail_ref02 BEFORE INSERT ON referral_rewards BEGIN SELECT RAISE(ABORT, 'fixture'); END");
    for ($i = 0; $i < 6; $i++) {
        app(ReferralRewardOutbox::class)->process($order);
        $this->travel(16)->minutes();
    }
    DB::unprepared('DROP TRIGGER fail_ref02');
    expect(ref02Row($order)->referral_state)->toBe('needs_review')
        ->and((int) ref02Row($order)->referral_attempts)->toBe(5)
        ->and(DB::table('referral_rewards')->count())->toBe(0);
});

it('rejects a malformed captured policy without using current values as a fallback', function (string $snapshot): void {
    $order = Fixture::order(Fixture::member(), Fixture::member());
    ref02Capture($order);
    DB::table('sales_fulfillment_outbox')->where('sales_order_id', $order)->update(['referral_policy' => $snapshot]);
    app(ReferralRewardOutbox::class)->process($order);
    expect(ref02Row($order)->referral_state)->toBe('needs_review')
        ->and(DB::table('referral_rewards')->count())->toBe(0);
})->with(['null', '{}', '{"amount":-10,"limit":7,"start":"2026-10-26 00:00:00"}']);

it('records invalid capture configuration as terminal without blocking activation', function (): void {
    $order = Fixture::order(Fixture::member(), Fixture::member());
    config()->set('referral.reward_tomans', 'bad');
    ref02Capture($order);
    config()->set('referral.reward_tomans', 30000);
    app(ReferralRewardOutbox::class)->process($order);
    expect(ref02Row($order)->referral_state)->toBe('suppressed')
        ->and(ref02Row($order)->referral_failure)->toBe('configuration')
        ->and(DB::table('referral_rewards')->count())->toBe(0);
});

it('uses an asynchronous queue even with unsafe defaults', function (string $driver, string $expected): void {
    config()->set('queue.default', $driver);
    config()->set('queue.connections.'.$driver.'.driver', $driver);
    expect((new GrantReferralRewardJob(123))->connection)->toBe($expected);
})->with([['sync', 'database'], ['null', 'database'], ['database', 'database'], ['redis', 'redis']]);

it('reports safe counts and protects pending intents from rollback', function (): void {
    $order = Fixture::order(Fixture::member(), Fixture::member());
    ref02Capture($order);
    $this->artisan('sales:referrals:status')->expectsOutputToContain('"pending":1')->assertSuccessful();
    $this->artisan('sales:referrals:dispatch')->assertSuccessful();
    Queue::assertPushed(GrantReferralRewardJob::class);
    $migration = require database_path('migrations/2026_10_26_000006_add_referral_reward_outbox.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
});

it('skips capture safely while the additive migration is not yet present', function (): void {
    $migration = require database_path('migrations/2026_10_26_000006_add_referral_reward_outbox.php');
    $migration->down();
    expect(app(ReferralRewardOutbox::class)->captureFields())->toBe([])
        ->and(app(ReferralRewardOutbox::class)->dispatchPending())->toBe(0)
        ->and(app(ReferralRewardOutbox::class)->status()['ready'])->toBeFalse();
    $migration->up();
});

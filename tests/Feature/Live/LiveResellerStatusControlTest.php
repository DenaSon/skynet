<?php

declare(strict_types=1);

use App\Interfaces\Web\Live\Dashboard;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ManageResellerSubscriptionStatus;
use App\Modules\Provisioning\Jobs\ChangeResellerSubscriptionStatusJob;
use App\Modules\Sales\Application\CalculatePlanQuote;
use App\Settings\ResellerServiceStatusSettings;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\CreatePendingPlanOrder;
use App\Modules\Sales\Application\ManagePlanPricing;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\PlanDefinition;
use App\Modules\Sales\Application\PlanPricingDefinition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(now()->startOfSecond());
    config()->set('denagate_live.enabled', true);
    $settings = app(ResellerServiceStatusSettings::class);
    $settings->enabled = true;
    $settings->save();
    config()->set('services.remnawave.writes_enabled', true);
    config()->set('services.remnawave.url', 'https://status-only.test');
    config()->set('services.remnawave.token', 'fake-status-token');
    config()->set('services.remnawave.squad_bindings_live_verified', true);
    config()->set('super_admin.telegram_ids', ['997601', '997603']);
    Role::findOrCreate('super-admin', 'web');
    Role::findOrCreate('member', 'web');
    Role::findOrCreate('reseller', 'web');
    Http::preventStrayRequests();
});

function status6bFixture(): array
{
    $root = User::factory()->create();
    $root->assignRole('super-admin');
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 997601]);
    $buyer = User::factory()->create();
    $buyer->assignRole('reseller');
    TelegramAccount::query()->create(['user_id' => $buyer->id, 'telegram_user_id' => 997602]);

    app(ConfigureProduct::class)->handle('status-control-test', 'سرویس آزمایشی');
    $plans = app(ManagePlans::class);
    $plan = $plans->create($root->id, new PlanDefinition(
        'status-control-test', 'status-control-30d', 'پلن وضعیت', 'hybrid', 'preset', 'reseller',
        20, 30, 1, 100, 15, 90,
    ));
    $plans->confirmChange($root->id, $plans->requestChange($root->id, $plan->id, 1, 'activate'));
    $prices = app(ManagePlanPricing::class);
    $rev = $prices->configure($root->id, $plan->id, 2, 0,
        new PlanPricingDefinition('fixed', 1_000_000, 0, 0, 0, 35, 1));
    $prices->confirmChange($root->id, $prices->requestChange($root->id, $plan->id, 2, $rev, 'activate'));
    DB::table('sales_plan_internal_squad_bindings')->insert([
        'plan_id' => $plan->id, 'plan_revision' => 2,
        'internal_squad_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $quote = app(CalculatePlanQuote::class)->handle($buyer->id, $plan->id, 30, 20);
    $orderId = app(CreatePendingPlanOrder::class)->handle(
        $buyer->id, $quote, bin2hex(random_bytes(16)),
    )->id;
    DB::table('sales_orders')->where('id', $orderId)->update(['status' => 'fulfilled']);
    DB::table('sales_fulfillment_outbox')->insert([
        'sales_order_id' => $orderId, 'event_key' => 'status-control:'.$orderId,
        'state' => 'completed', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $reference = 'sales-order:'.$orderId;
    $subId = DB::table('provisioning_subscriptions')->insertGetId([
        'reference' => $reference,
        'remote_username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
        'remote_user_id' => 52, 'state' => 'active',
        'last_operation_key' => 'status-control:original',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('provisioning_order_links')->insert([
        'sales_order_id' => $orderId, 'provisioning_subscription_id' => $subId,
        'created_at' => now(),
    ]);

    return ['root' => $root, 'buyer' => $buyer, 'order' => $orderId, 'sub' => $subId];
}

/** @return array<string,mixed> */
function status6bRemote(int $orderId, string $status): array
{
    $reference = 'sales-order:'.$orderId;

    return [
        'id' => 52,
        'shortUuid' => 'status-private',
        'username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
        'description' => 'SkyNetwork ref:'.hash('sha256', $reference),
        'status' => $status,
        'expireAt' => now()->addDays(10)->utc()->format('Y-m-d\TH:i:s\Z'),
        'trafficLimitBytes' => 20 * 1073741824,
        'trafficLimitStrategy' => 'NO_RESET',
        'subscriptionUrl' => 'https://secret.test/credential-status',
        'userTraffic' => ['usedTrafficBytes' => 1073741824],
        'activeInternalSquads' => [],
    ];
}

function status6bProvider(int $orderId, string &$status): void
{
    Http::fake(function (Request $request) use ($orderId, &$status) {
        if ($request->method() === 'PATCH' && $request->url() === 'https://status-only.test/api/users') {
            $status = (string) $request['status'];
        }

        return Http::response(['response' => status6bRemote($orderId, $status)]);
    });
}

it('is disabled by default and never changes Admin provider controls', function (): void {
    $d = status6bFixture();
    $settings = app(ResellerServiceStatusSettings::class);
    $settings->enabled = false;
    $settings->save();
    Http::fake();

    $this->actingAs($d['buyer'])->withSession(['denagate_live.user_id' => $d['buyer']->id]);
    Livewire::test(Dashboard::class)->call('showSection', 'services')
        ->call('openService', $d['order'])->assertDontSee('غیرفعال‌سازی سرویس');

    expect(fn () => app(ManageResellerSubscriptionStatus::class)->authorizeChange(
        $d['buyer']->id, $d['order'], 'disable',
    ))->toThrow(ValidationException::class);
    expect(DB::table('provisioning_operations')->count())->toBe(0);
    Http::assertNothingSent();
});

it('requires live status and one-use confirmation before queuing the only provider write', function (): void {
    $d = status6bFixture();
    $status = 'ACTIVE';
    status6bProvider($d['order'], $status);
    Queue::fake();
    $this->actingAs($d['buyer'])->withSession(['denagate_live.user_id' => $d['buyer']->id]);

    $page = Livewire::test(Dashboard::class)->call('showSection', 'services')
        ->call('openService', $d['order'])
        ->assertDontSee('غیرفعال‌سازی سرویس')
        ->call('refreshServiceUsage')
        ->assertSee('غیرفعال‌سازی سرویس')
        ->call('prepareServiceStatus', 'disable')
        ->assertSee('تأیید غیرفعال‌سازی')
        ->call('confirmServiceStatus')
        ->assertSee('درخواست ثبت شد');

    Queue::assertPushed(ChangeResellerSubscriptionStatusJob::class, 1);
    $page->call('confirmServiceStatus');
    Queue::assertPushed(ChangeResellerSubscriptionStatusJob::class, 1);
    Http::assertSent(static fn (Request $req): bool => $req->method() === 'GET');
    Http::assertNotSent(static fn (Request $req): bool => $req->method() === 'PATCH');
    expect(DB::table('provisioning_operations')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
});

it('executes one verified disable and only permits resume after this buyers completed pause', function (): void {
    $d = status6bFixture();
    $status = 'ACTIVE';
    status6bProvider($d['order'], $status);
    $action = app(ManageResellerSubscriptionStatus::class);
    $key = 'live:status:'.$d['order'].':'.$d['buyer']->id.':'.str_repeat('a', 32);

    $action->change($d['buyer']->id, $d['order'], 'disable', $key);
    expect($status)->toBe('DISABLED')
        ->and($action->canResume($d['buyer']->id, $d['order']))->toBeTrue();
    $opCount = DB::table('provisioning_operations')->count();
    expect(fn () => $action->change($d['buyer']->id, $d['order'], 'disable', $key))
        ->toThrow(ValidationException::class);

    expect(DB::table('provisioning_operations')->count())->toBe($opCount);
    Http::assertSentCount(3);
});

it('resumes a locally recorded pause without changing expiration, quota or wallet', function (): void {
    $d = status6bFixture();
    $status = 'ACTIVE';
    status6bProvider($d['order'], $status);
    $action = app(ManageResellerSubscriptionStatus::class);
    $pauseKey = 'live:status:'.$d['order'].':'.$d['buyer']->id.':'.str_repeat('a', 32);
    $enableKey = 'live:status:'.$d['order'].':'.$d['buyer']->id.':'.str_repeat('b', 32);

    $action->change($d['buyer']->id, $d['order'], 'disable', $pauseKey);
    expect($status)->toBe('DISABLED')
        ->and($action->canResume($d['buyer']->id, $d['order']))->toBeTrue();

    $action->change($d['buyer']->id, $d['order'], 'enable', $enableKey);
    expect($status)->toBe('ACTIVE')
        ->and($action->canResume($d['buyer']->id, $d['order']))->toBeFalse()
        ->and(DB::table('provisioning_operations')->where('state', 'completed')->count())->toBe(2)
        ->and(DB::table('billing_journals')->count())->toBe(0);

    Http::assertSentCount(4);
    Http::assertSent(static fn (Request $req): bool => $req->method() === 'PATCH'
        && $req->url() === 'https://status-only.test/api/users'
        && in_array($req['status'], ['ACTIVE', 'DISABLED'], true)
        && count($req->data()) === 2);
});

it('rejects foreign owner, revoked role and admin-disabled subscriptions before PATCH', function (): void {
    $d = status6bFixture();
    $other = User::factory()->create();
    $other->assignRole('reseller');
    $status = 'DISABLED';
    status6bProvider($d['order'], $status);
    $action = app(ManageResellerSubscriptionStatus::class);

    expect(fn () => $action->authorizeChange($other->id, $d['order'], 'enable'))
        ->toThrow(ValidationException::class);

    DB::table('provisioning_operations')->insert([
        'subscription_id' => $d['sub'], 'idempotency_key' => 'mvp04:'.$d['order'].':'.str_repeat('e', 32),
        'kind' => 'disable', 'payload_hash' => hash('sha256', 'admin'),
        'expected_payload' => json_encode(['status' => 'DISABLED']),
        'state' => 'completed', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('provisioning_subscriptions')->where('id', $d['sub'])->update([
        'last_operation_key' => 'mvp04:'.$d['order'].':'.str_repeat('e', 32),
    ]);
    expect($action->canResume($d['buyer']->id, $d['order']))->toBeFalse()
        ->and(fn () => $action->authorizeChange($d['buyer']->id, $d['order'], 'enable'))
        ->toThrow(ValidationException::class);

    $d['buyer']->syncRoles(['member']);
    expect(fn () => $action->authorizeChange($d['buyer']->id, $d['order'], 'disable'))
        ->toThrow(AuthorizationException::class);

    Http::assertNotSent(static fn (Request $req): bool => $req->method() === 'PATCH');
});

it('leaves a failed provider PATCH in needs_review without automatic retry or exposed credentials', function (): void {
    $d = status6bFixture();
    $status = 'ACTIVE';
    Http::fake(function (Request $request) use ($d, &$status) {
        if ($request->method() === 'PATCH') {
            return Http::response(['message' => 'secret-provider-error'], 503);
        }

        return Http::response(['response' => status6bRemote($d['order'], $status)]);
    });
    Log::spy();
    $job = new ChangeResellerSubscriptionStatusJob(
        $d['buyer']->id, $d['order'], 'disable',
        'live:status:'.$d['order'].':'.$d['buyer']->id.':'.str_repeat('c', 32),
    );
    $job->handle(app(ManageResellerSubscriptionStatus::class));
    expect(DB::table('provisioning_subscriptions')->where('id', $d['sub'])->value('state'))->toBe('needs_review')
        ->and(DB::table('provisioning_operations')->where('state', 'needs_review')->count())->toBe(1)
        ->and(DB::table('billing_journals')->count())->toBe(0);
    Http::assertSentCount(2);
    Log::shouldHaveReceived('warning')->with(
        'denagate_live.reseller_status_needs_review', ['order_id' => $d['order']],
    )->once();
});

it('reads the database toggle dynamically and ignores the retired environment flag', function (): void {
    $d = status6bFixture();
    $action = app(ManageResellerSubscriptionStatus::class);
    config()->set('denagate_live.reseller_status_enabled', true);

    expect($action->enabled())->toBeTrue();

    $settings = app(ResellerServiceStatusSettings::class);
    $settings->enabled = false;
    $settings->save();

    expect($action->enabled())->toBeFalse()
        ->and(fn () => $action->authorizeChange($d['buyer']->id, $d['order'], 'disable'))
        ->toThrow(ValidationException::class);

    $settings->enabled = true;
    $settings->save();
    expect($action->enabled())->toBeTrue();

    config()->set('services.remnawave.writes_enabled', false);
    expect($action->enabled())->toBeFalse();
    config()->set('services.remnawave.writes_enabled', true);
    config()->set('denagate_live.enabled', false);
    expect($action->enabled())->toBeFalse();
});

it('fails closed without a settings row, before contacting the provider', function (): void {
    $d = status6bFixture();
    DB::table('settings')->where('group', 'reseller_service_status')->delete();
    Http::fake();

    $action = app(ManageResellerSubscriptionStatus::class);
    expect($action->enabled())->toBeFalse()
        ->and(fn () => $action->authorizeChange($d['buyer']->id, $d['order'], 'disable'))
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('rejects a queued provider job when the super admin has since disabled the setting', function (): void {
    $d = status6bFixture();
    Http::fake();
    $settings = app(ResellerServiceStatusSettings::class);
    $settings->enabled = false;
    $settings->save();
    Log::spy();

    $job = new ChangeResellerSubscriptionStatusJob(
        $d['buyer']->id, $d['order'], 'disable',
        'live:status:'.$d['order'].':'.$d['buyer']->id.':'.str_repeat('d', 32),
    );
    $job->handle(app(ManageResellerSubscriptionStatus::class));
    Http::assertNothingSent();
    expect(DB::table('provisioning_operations')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
    Log::shouldHaveReceived('warning')->with(
        'denagate_live.reseller_status_needs_review', ['order_id' => $d['order']],
    )->once();
});

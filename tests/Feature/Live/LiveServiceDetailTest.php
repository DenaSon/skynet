<?php

declare(strict_types=1);

use App\Interfaces\Web\Live\Dashboard;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\CalculateQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Sales\Application\CreatePendingOrder;
use App\Modules\Sales\Application\ManageResellerServiceLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('denagate_live.enabled', true);
    config()->set('services.remnawave.url', 'https://remna.test');
    config()->set('services.remnawave.token', 'read-only-test-token');
    config()->set('services.remnawave.writes_enabled', false);
    Role::findOrCreate('reseller', 'web');
    Role::findOrCreate('member', 'web');
    app(ConfigureProduct::class)->handle('live4a-plan', 'پلن آزمایشی');
    app(ConfigureRate::class)->handle('live4a-plan', 30, 100_000, 20_000);
    Http::preventStrayRequests();
});

function live4aReseller(int $telegramId): User
{
    $user = User::factory()->create();
    $user->assignRole('reseller');
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);

    return $user;
}

function live4aService(User $buyer, string $state = 'active'): int
{
    $quote = app(CalculateQuote::class)->handle($buyer->id, 'live4a-plan', 30, 10);
    $order = app(CreatePendingOrder::class)->handle($buyer->id, $quote, bin2hex(random_bytes(16)));
    DB::table('sales_orders')->where('id', $order->id)->update(['status' => 'fulfilled']);

    $reference = 'live4a:'.$order->id;
    $subId = DB::table('provisioning_subscriptions')->insertGetId([
        'reference' => $reference,
        'remote_username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
        'remote_user_id' => 200000 + $order->id,
        'state' => $state,
        'last_operation_key' => 'live4a:fixture:'.$order->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('provisioning_order_links')->insert([
        'sales_order_id' => $order->id,
        'provisioning_subscription_id' => $subId,
        'created_at' => now(),
    ]);

    return $order->id;
}

/** @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
function live4aRemote(int $orderId, array $overrides = []): array
{
    $reference = 'live4a:'.$orderId;
    $raw = [
        'id' => 200000 + $orderId,
        'username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
        'shortUuid' => 'secret-4a-private',
        'status' => 'ACTIVE',
        'expireAt' => '2027-10-12T00:00:00.000Z',
        'trafficLimitBytes' => 10 * 1073741824,
        'trafficLimitStrategy' => 'NO_RESET',
        'subscriptionUrl' => 'https://secret.example.test/token-4a',
        'description' => 'SkyNetwork ref:'.hash('sha256', $reference),
        'activeInternalSquads' => [],
        'userTraffic' => ['usedTrafficBytes' => 3 * 1073741824],
    ];

    return array_replace_recursive($raw, $overrides);
}

/** @param array<string,mixed> $overrides */
function live4aMockProvider(int $orderId, array $overrides = []): void
{
    Http::fake([
        'https://remna.test/api/users/'.(200000 + $orderId) => Http::response([
            'response' => live4aRemote($orderId, $overrides),
        ]),
    ]);
}

it('shows only the owned service detail and local plan data without provider network access', function (): void {
    $owner = live4aReseller(964101);
    $foreign = live4aReseller(964102);
    $this->actingAs($owner)->withSession(['denagate_live.user_id' => $owner->id]);
    $mine = live4aService($owner);
    app(ManageResellerServiceLabels::class)->save($owner->id, $mine, 'مشتری <مهم>');
    $foreignId = live4aService($foreign);
    app(ManageResellerServiceLabels::class)->save($foreign->id, $foreignId, 'مشتری نماینده دیگر');

    $page = Livewire::test(Dashboard::class)
        ->call('showSection', 'services')
        ->assertSee('مشاهده جزئیات')
        ->call('openService', $mine)
        ->assertSet('selectedServiceOrderId', $mine)
        ->assertSee('جزئیات سرویس')
        ->assertSee('مشتری &lt;مهم&gt;', false)
        ->assertSee('مدت خریداری‌شده')
        ->assertSee('حجم خریداری‌شده')
        ->assertSee('بررسی وضعیت')
        ->assertDontSee('مشتری نماینده دیگر')
        ->assertDontSee('secret-4a-private')
        ->assertDontSee('subscriptionUrl')
        ->call('backToServices')
        ->assertSet('selectedServiceOrderId', null)
        ->assertSee('مشاهده جزئیات');

    expect(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('provisioning_operations')->count())->toBe(0);
    Http::assertNothingSent();
});

it('reads one exact owner-verified provider usage on explicit tap and displays safe expiry and remaining data', function (): void {
    $owner = live4aReseller(964103);
    $this->actingAs($owner)->withSession(['denagate_live.user_id' => $owner->id]);
    $order = live4aService($owner);
    live4aMockProvider($order);

    $page = Livewire::test(Dashboard::class)
        ->call('showSection', 'services')
        ->call('openService', $order);
    Http::assertNothingSent();

    $page->call('refreshServiceUsage')
        ->assertSee('وضعیت بررسی‌شده')
        ->assertSee('حجم مصرف‌شده')
        ->assertSee('3.00')
        ->assertSee('7.00')
        ->assertSee('تاریخ انقضا')
        ->assertSee('آخرین بررسی')
        ->assertDontSee('secret-4a-private')
        ->assertDontSee('token-4a')
        ->assertDontSee('20000'.(string) $order);

    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $req): bool => $req->method() === 'GET'
        && $req->url() === 'https://remna.test/api/users/'.(200000 + $order));
    expect(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('provisioning_operations')->count())->toBe(0);
});

it('identifies expired and unlimited provider plans without pretending local state is live status', function (): void {
    $owner = live4aReseller(964104);
    $this->actingAs($owner)->withSession(['denagate_live.user_id' => $owner->id]);
    $order = live4aService($owner);
    live4aMockProvider($order, [
        'expireAt' => '2020-10-12T00:00:00.000Z',
        'trafficLimitBytes' => 0,
    ]);
    Livewire::test(Dashboard::class)->call('showSection', 'services')
        ->call('openService', $order)->call('refreshServiceUsage')
        ->assertSee('منقضی‌شده')
        ->assertSee('بدون سقف');
    Http::assertSentCount(1);
});

it('shows unknown provider data on outage and clears previously verified usage on retry failure', function (): void {
    $owner = live4aReseller(964105);
    $this->actingAs($owner)->withSession(['denagate_live.user_id' => $owner->id]);
    $order = live4aService($owner);
    Http::fake([
        'https://remna.test/api/users/'.(200000 + $order) => Http::sequence()
            ->push(['response' => live4aRemote($order)], 200)
            ->push(['message' => 'provider token secret'], 503),
    ]);

    $page = Livewire::test(Dashboard::class)->call('showSection', 'services')
        ->call('openService', $order)->call('refreshServiceUsage')
        ->assertSee('7.00');
    $page->call('refreshServiceUsage')->assertSee('اطلاعات به‌روز سرویس در دسترس نیست')
        ->assertDontSee('7.00')
        ->assertDontSee('provider token secret');
    Http::assertSentCount(2);
});

it('denies forged foreign service selections, absent links and account revocation before provider GET', function (): void {
    $owner = live4aReseller(964106);
    $foreign = live4aReseller(964107);
    $this->actingAs($owner)->withSession(['denagate_live.user_id' => $owner->id]);
    $mine = live4aService($owner);
    $other = live4aService($foreign);

    $quote = app(CalculateQuote::class)->handle($owner->id, 'live4a-plan', 30, 10);
    $pending = app(CreatePendingOrder::class)->handle($owner->id, $quote, bin2hex(random_bytes(16)))->id;

    $page = Livewire::test(Dashboard::class)->call('showSection', 'services')
        ->call('openService', $other)
        ->assertSee('این سرویس در دسترس نیست.')
        ->assertSet('selectedServiceOrderId', null)
        ->call('openService', $pending)
        ->assertSet('selectedServiceOrderId', null)
        ->call('openService', $mine)
        ->assertSet('selectedServiceOrderId', $mine);

    $owner->syncRoles(['member']);
    $page->call('refreshServiceUsage')->assertForbidden();
    Http::assertNothingSent();
});

it('does not expose a provider check for a locally unverified or failed service', function (): void {
    $owner = live4aReseller(964108);
    $this->actingAs($owner)->withSession(['denagate_live.user_id' => $owner->id]);
    $order = live4aService($owner, 'needs_review');

    Livewire::test(Dashboard::class)->call('showSection', 'services')
        ->call('openService', $order)->assertSee('نیازمند بررسی')
        ->assertDontSee('wire:click="refreshServiceUsage"', false)
        ->call('refreshServiceUsage')
        ->assertSee('دریافت وضعیت فعلی این سرویس امکان‌پذیر نیست.');

    Http::assertNothingSent();
});

it('retains the current service cursor and clears stale live usage when navigating', function (): void {
    $owner = live4aReseller(964109);
    $this->actingAs($owner)->withSession(['denagate_live.user_id' => $owner->id]);

    for ($i = 0; $i < 8; $i++) {
        live4aService($owner);
    }
    $latest = (int) DB::table('sales_orders')->where('buyer_user_id', $owner->id)->max('id');
    $page = Livewire::test(Dashboard::class)->call('showSection', 'services')
        ->call('nextServices', $latest - 5)
        ->assertSee('بازگشت به اولین صفحه')
        ->call('openService', $latest - 6)
        ->assertSet('selectedServiceOrderId', $latest - 6)
        ->call('backToServices')
        ->assertSet('serviceCursor', $latest - 5)
        ->assertSet('selectedServiceOrderId', null)
        ->call('firstServices')
        ->assertSet('serviceCursor', null);
    Http::assertNothingSent();
});

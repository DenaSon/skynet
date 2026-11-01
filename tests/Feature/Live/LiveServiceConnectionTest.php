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
    app(ConfigureProduct::class)->handle('live4b-plan', 'پلن آزمایشی');
    app(ConfigureRate::class)->handle('live4b-plan', 30, 100_000, 20_000);
    Http::preventStrayRequests();
});

function live4bUser(int $telegramId): User
{
    $user = User::factory()->create();
    $user->assignRole('reseller');
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);

    return $user;
}

function live4bService(User $buyer, string $state = 'active'): int
{
    $quote = app(CalculateQuote::class)->handle($buyer->id, 'live4b-plan', 30, 10);
    $order = app(CreatePendingOrder::class)->handle($buyer->id, $quote, bin2hex(random_bytes(16)));
    DB::table('sales_orders')->where('id', $order->id)->update(['status' => 'fulfilled']);

    $reference = 'live4b:'.$order->id;
    $subId = DB::table('provisioning_subscriptions')->insertGetId([
        'reference' => $reference,
        'remote_username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
        'remote_user_id' => 500000 + $order->id,
        'state' => $state,
        'last_operation_key' => 'live4b:fixture:'.$order->id,
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

/** @param array<string,mixed> $override
 * @return array<string,mixed>
 */
function live4bRemote(int $orderId, array $override = []): array
{
    $reference = 'live4b:'.$orderId;

    return array_replace_recursive([
        'id' => 500000 + $orderId,
        'username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
        'shortUuid' => 'secret-4b-short',
        'status' => 'ACTIVE',
        'expireAt' => '2027-10-12T00:00:00.000Z',
        'trafficLimitBytes' => 10 * 1073741824,
        'trafficLimitStrategy' => 'NO_RESET',
        'subscriptionUrl' => 'https://sub.example.test/protected-token-4b?x=1&y=2',
        'description' => 'SkyNetwork ref:'.hash('sha256', $reference),
        'activeInternalSquads' => [],
        'userTraffic' => ['usedTrafficBytes' => 0],
    ], $override);
}

function live4bMockProvider(int $orderId, array $override = []): void
{
    Http::fake([
        'https://remna.test/api/users/'.(500000 + $orderId) => Http::response([
            'response' => live4bRemote($orderId, $override),
        ]),
    ]);
}

it('does not disclose or fetch a connection until the owner explicitly requests it', function (): void {
    $buyer = live4bUser(976101);
    $this->actingAs($buyer)->withSession(['denagate_live.user_id' => $buyer->id]);
    $order = live4bService($buyer);
    live4bMockProvider($order);

    $page = Livewire::test(Dashboard::class)
        ->call('showSection', 'services')
        ->call('openService', $order)
        ->assertSee('اطلاعات اتصال')
        ->assertSee('مشاهده اطلاعات اتصال')
        ->assertSee('dg-btn-secondary')
        ->assertDontSee('protected-token-4b')
        ->assertSet('serviceSubscriptionUrl', null)
        ->assertSet('serviceQrDataUri', null);
    Http::assertNothingSent();

    $page->call('revealServiceConnection')
        ->assertSee('کپی لینک')
        ->assertSee('dg-field')
        ->assertSee('dg-btn-primary')
        ->assertSee('پنهان کردن')
        ->assertSee('کد QR اتصال سرویس')
        ->assertSee('protected-token-4b')
        ->assertDontSee('secret-4b-short')
        ->assertDontSee('read-only-test-token');

    $url = 'https://sub.example.test/protected-token-4b?x=1&y=2';
    expect($page->get('serviceSubscriptionUrl'))->toBe($url)
        ->and($page->get('serviceQrDataUri'))->toStartWith('data:image/png;base64,');
    $png = base64_decode(substr($page->get('serviceQrDataUri'), strlen('data:image/png;base64,')), true);
    expect(is_string($png))->toBeTrue()
        ->and(str_starts_with($png, "\x89PNG\r\n\x1a\n"))->toBeTrue();

    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $req): bool => $req->method() === 'GET'
        && $req->url() === 'https://remna.test/api/users/'.(500000 + $order));

    $page->call('hideServiceConnection')->assertSet('serviceSubscriptionUrl', null)
        ->assertSet('serviceQrDataUri', null)->assertDontSee('protected-token-4b')
        ->call('backToServices')->assertSee('مشاهده جزئیات');
    Http::assertSentCount(1);
});

it('clears a previously revealed credential when provider access is no longer usable', function (): void {
    $buyer = live4bUser(976102);
    $this->actingAs($buyer)->withSession(['denagate_live.user_id' => $buyer->id]);
    $order = live4bService($buyer);
    Http::fake([
        'https://remna.test/api/users/'.(500000 + $order) => Http::sequence()
            ->push(['response' => live4bRemote($order)], 200)
            ->push(['response' => live4bRemote($order, ['status' => 'DISABLED'])], 200),
    ]);

    $page = Livewire::test(Dashboard::class)->call('showSection', 'services')
        ->call('openService', $order)->call('revealServiceConnection')
        ->assertSee('protected-token-4b');

    $page->call('revealServiceConnection')
        ->assertSet('serviceSubscriptionUrl', null)
        ->assertSet('serviceQrDataUri', null)
        ->assertSee('دریافت اطلاعات اتصال امکان‌پذیر نیست.')
        ->assertDontSee('protected-token-4b');
    Http::assertSentCount(2);
});

it('never reveals credentials for a forged foreign order or a locally blocked service', function (): void {
    $buyer = live4bUser(976103);
    $other = live4bUser(976104);
    $this->actingAs($buyer)->withSession(['denagate_live.user_id' => $buyer->id]);
    $mine = live4bService($buyer, 'needs_review');
    $foreign = live4bService($other);

    Livewire::test(Dashboard::class)->call('showSection', 'services')
        ->call('openService', $foreign)
        ->assertSet('selectedServiceOrderId', null)
        ->call('revealServiceConnection')
        ->assertSee('ابتدا سرویس خود را انتخاب کنید.')
        ->call('openService', $mine)
        ->assertDontSee('مشاهده اطلاعات اتصال')
        ->call('revealServiceConnection')
        ->assertSee('اطلاعات اتصال این سرویس فعلاً در دسترس نیست.')
        ->assertSet('serviceSubscriptionUrl', null);
    Http::assertNothingSent();
});

it('edits only the owner label, escapes HTML and never changes phone or provider state', function (): void {
    $buyer = live4bUser(976105);
    $this->actingAs($buyer)->withSession(['denagate_live.user_id' => $buyer->id]);
    $order = live4bService($buyer);
    $labels = app(ManageResellerServiceLabels::class);
    $labels->save($buyer->id, $order, 'مشتری قدیمی');
    $initial = (array) DB::table('sales_service_customer_labels')->where('sales_order_id', $order)->first();

    $page = Livewire::test(Dashboard::class)->call('showSection', 'services')->call('openService', $order)
        ->assertSee('نام مشتری')
        ->call('editServiceLabel')
        ->assertSet('serviceLabelInput', 'مشتری قدیمی')
        ->set('serviceLabelInput', 'مشتری <جدید>')
        ->call('saveServiceLabel')
        ->assertSee('نام مشتری ذخیره شد.')
        ->assertSee('مشتری &lt;جدید&gt;', false)
        ->assertDontSee('wire:click="saveServiceLabel"', false);

    expect($labels->get($buyer->id, $order))->toBe('مشتری <جدید>')
        ->and(DB::table('sales_service_customer_labels')->where('sales_order_id', $order)->value('phone'))
        ->toBe($initial['phone'])
        ->and(DB::table('provisioning_operations')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();

    $page->call('editServiceLabel')->set('serviceLabelInput', '')->call('saveServiceLabel');
    expect($labels->get($buyer->id, $order))->toBeNull();
});

it('rejects long invalid labels without overwriting the current customer name', function (): void {
    $buyer = live4bUser(976106);
    $this->actingAs($buyer)->withSession(['denagate_live.user_id' => $buyer->id]);
    $order = live4bService($buyer);
    app(ManageResellerServiceLabels::class)->save($buyer->id, $order, 'مشتری اصلی');

    Livewire::test(Dashboard::class)->call('showSection', 'services')->call('openService', $order)
        ->call('editServiceLabel')->set('serviceLabelInput', str_repeat('a', 61))
        ->call('saveServiceLabel')
        ->assertSee('نام مشتری باید حداکثر ۶۰ نویسه')
        ->assertSee('مشتری اصلی');
    expect(app(ManageResellerServiceLabels::class)->get($buyer->id, $order))->toBe('مشتری اصلی');
    Http::assertNothingSent();
});

it('rejects every credential or label action after reseller permission is revoked', function (): void {
    $buyer = live4bUser(976107);
    $this->actingAs($buyer)->withSession(['denagate_live.user_id' => $buyer->id]);
    $order = live4bService($buyer);
    live4bMockProvider($order);
    $page = Livewire::test(Dashboard::class)->call('showSection', 'services')
        ->call('openService', $order)->call('editServiceLabel');

    $buyer->syncRoles(['member']);

    $page->call('saveServiceLabel')->assertForbidden();
    Http::assertNothingSent();
});

it('clears secrets on navigation and never reissues GET from the label editor', function (): void {
    $buyer = live4bUser(976108);
    $this->actingAs($buyer)->withSession(['denagate_live.user_id' => $buyer->id]);
    $order = live4bService($buyer);
    live4bMockProvider($order);

    $page = Livewire::test(Dashboard::class)->call('showSection', 'services')
        ->call('openService', $order)
        ->call('revealServiceConnection')
        ->call('editServiceLabel')
        ->set('serviceLabelInput', 'نمونه')
        ->call('saveServiceLabel')
        ->assertSee('نمونه')
        ->call('showSection', 'overview')
        ->assertSet('serviceSubscriptionUrl', null)
        ->assertSet('serviceQrDataUri', null)
        ->assertDontSee('protected-token-4b');

    Http::assertSentCount(1);
});

it('checks only the visible owned page on request and renders a compact 72-hour summary without credentials', function (): void {
    $buyer = live4bUser(976140);
    $other = live4bUser(976141);
    $this->actingAs($buyer)->withSession(['denagate_live.user_id' => $buyer->id]);
    $soon = live4bService($buyer);
    $outside = live4bService($buyer);
    $expired = live4bService($buyer);
    $unknown = live4bService($buyer);
    $foreign = live4bService($other);

    Http::fake([
        'https://remna.test/api/users/'.(500000 + $soon) => Http::response([
            'response' => live4bRemote($soon, ['expireAt' => now()->addHours(49)->toIso8601String()]),
        ]),
        'https://remna.test/api/users/'.(500000 + $outside) => Http::response([
            'response' => live4bRemote($outside, ['expireAt' => now()->addHours(74)->toIso8601String()]),
        ]),
        'https://remna.test/api/users/'.(500000 + $expired) => Http::response([
            'response' => live4bRemote($expired, ['status' => 'EXPIRED', 'expireAt' => now()->subHour()->toIso8601String()]),
        ]),
        'https://remna.test/api/users/'.(500000 + $unknown) => Http::response([], 503),
    ]);
    $page = Livewire::test(Dashboard::class)->call('showSection', 'services')
        ->assertSee('⏳ بررسی انقضا')
        ->assertDontSee('⏳ انقضا تا ۳ روز:');
    Http::assertNothingSent();

    $page->call('checkServiceExpiries')
        ->assertSee('⏳ انقضا تا ۳ روز:')
        ->assertSee('⏳ 2 روز و 1 ساعت تا انقضا')
        ->assertSee('⚪ نامشخص:')
        ->assertDontSee('protected-token-4b')
        ->assertDontSee('secret-4b-short')
        ->assertDontSee((string) (500000 + $foreign));
    $snapshot = $page->get('serviceExpiry');
    expect($snapshot['count'])->toBe(1)
        ->and($snapshot['unknown'])->toBe(1)
        ->and(array_keys($snapshot['remaining']))->toBe([$soon]);
    Http::assertSentCount(4);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET');
    expect(DB::table('provisioning_operations')->count())->toBe(0);

    // Unrelated Livewire renders must not cause another provider sweep.
    $page->call('backToServices')->assertSee('⏳ انقضا تا ۳ روز:');
    Http::assertSentCount(4);
    $page->call('showSection', 'reports')->assertSet('serviceExpiry', null);
});

it('resets the checked expiry data on pagination and does not scan off-page services', function (): void {
    $buyer = live4bUser(976142);
    $this->actingAs($buyer)->withSession(['denagate_live.user_id' => $buyer->id]);
    $orders = [];
    for ($i = 0; $i < 8; $i++) {
        $orders[] = live4bService($buyer);
    }
    $responses = [];
    foreach ($orders as $i => $order) {
        $responses['https://remna.test/api/users/'.(500000 + $order)] = Http::response([
            'response' => live4bRemote($order, [
                'expireAt' => ($i === 0 ? now()->addHours(4) : now()->addDays(15))->toIso8601String(),
            ]),
        ]);
    }
    Http::fake($responses);

    $page = Livewire::test(Dashboard::class)->call('showSection', 'services')
        ->call('checkServiceExpiries')
        ->assertSee('⏳ انقضا تا ۳ روز:');
    expect($page->get('serviceExpiry')['count'])->toBe(0);
    Http::assertSentCount(6);

    $page->call('nextServices', $orders[2])
        ->assertSet('serviceExpiry', null)
        ->assertDontSee('⏳ انقضا تا ۳ روز:')
        ->call('checkServiceExpiries')
        ->assertSee('⏳ انقضا تا ۳ روز:');
    expect($page->get('serviceExpiry')['count'])->toBe(1);
    Http::assertSentCount(8);

    $page->call('firstServices')->assertSet('serviceExpiry', null)
        ->assertDontSee('⏳ انقضا تا ۳ روز:');
    Http::assertSentCount(8);
});

it('checks the searched owner-scoped service page and clears old results on a new search', function (): void {
    $buyer = live4bUser(976143);
    $foreign = live4bUser(976144);
    $this->actingAs($buyer)->withSession(['denagate_live.user_id' => $buyer->id]);
    $alice = live4bService($buyer);
    $bob = live4bService($buyer);
    $other = live4bService($foreign);
    app(ManageResellerServiceLabels::class)->save($buyer->id, $alice, 'مشتری علی');
    app(ManageResellerServiceLabels::class)->save($buyer->id, $bob, 'مشتری محمد');
    app(ManageResellerServiceLabels::class)->save($foreign->id, $other, 'مشتری علی دیگر');
    Http::fake([
        'https://remna.test/api/users/'.(500000 + $alice) => Http::response([
            'response' => live4bRemote($alice, ['expireAt' => now()->addHours(10)->toIso8601String()]),
        ]),
        'https://remna.test/api/users/'.(500000 + $bob) => Http::response([
            'response' => live4bRemote($bob, ['expireAt' => now()->addHours(20)->toIso8601String()]),
        ]),
    ]);

    $page = Livewire::test(Dashboard::class)->call('showSection', 'services')
        ->set('serviceSearchInput', 'علی')->call('searchServices')
        ->assertSee('مشتری علی')
        ->assertDontSee('مشتری محمد')
        ->call('checkServiceExpiries');
    expect($page->get('serviceExpiry')['count'])->toBe(1);
    Http::assertSentCount(1);

    $page->set('serviceSearchInput', 'محمد')->call('searchServices')
        ->assertSet('serviceExpiry', null)
        ->assertDontSee('⏳ انقضا تا ۳ روز:')
        ->call('checkServiceExpiries');
    expect($page->get('serviceExpiry')['count'])->toBe(1);
    Http::assertSentCount(2);
    expect(DB::table('provisioning_operations')->count())->toBe(0);
});

it('fails closed when reseller access is revoked before checking expiry', function (): void {
    $buyer = live4bUser(976145);
    $this->actingAs($buyer)->withSession(['denagate_live.user_id' => $buyer->id]);
    live4bService($buyer);
    Http::fake();

    $page = Livewire::test(Dashboard::class)->call('showSection', 'services');
    $buyer->syncRoles(['member']);
    $page->call('checkServiceExpiries')->assertForbidden();
    Http::assertNothingSent();
});

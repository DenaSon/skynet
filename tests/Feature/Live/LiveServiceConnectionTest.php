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
        ->assertSee('نمایش لینک و QR')
        ->assertDontSee('protected-token-4b')
        ->assertSet('serviceSubscriptionUrl', null)
        ->assertSet('serviceQrDataUri', null);
    Http::assertNothingSent();

    $page->call('revealServiceConnection')
        ->assertSee('کپی لینک')
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
        ->assertDontSee('نمایش لینک و QR')
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

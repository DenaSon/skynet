<?php

declare(strict_types=1);

use App\Interfaces\Web\Live\Dashboard;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\CalculateQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Sales\Application\CreatePendingOrder;
use App\Modules\Sales\Application\ManageResellerServiceLabels;
use App\Modules\Sales\Application\SettleWalletOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\PanelWalletFunding;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('denagate_live.enabled', true);
    config()->set('sales.wallet_settlement_enabled', true);
    Role::findOrCreate('reseller', 'web');
    Role::findOrCreate('member', 'web');
    Http::preventStrayRequests();
    app(ConfigureProduct::class)->handle('live-read-plan', 'پلن اختصاصی');
    app(ConfigureRate::class)->handle('live-read-plan', 30, 100_000, 20_000);
});

function liveReadUser(int $telegramId): User
{
    $user = User::factory()->create();
    $user->assignRole('reseller');
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);

    return $user;
}

function liveReadOrder(User $owner, bool $settle = true): int
{
    $quote = app(CalculateQuote::class)->handle($owner->id, 'live-read-plan', 30, 10);
    $orderId = app(CreatePendingOrder::class)->handle($owner->id, $quote, bin2hex(random_bytes(16)))->id;

    if ($settle) {
        app(SettleWalletOrder::class)->handle(SubscriptionViewer::web(), $orderId);
        DB::table('sales_orders')->where('id', $orderId)->update(['status' => 'fulfilled']);
        $sub = DB::table('provisioning_subscriptions')->insertGetId([
            'reference' => 'live-web:'.$orderId,
            'remote_username' => 'private_'.substr(hash('sha256', (string) $orderId), 0, 20),
            'remote_user_id' => $orderId + 77000,
            'state' => 'active',
            'last_operation_key' => 'live-web-test:'.$orderId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('provisioning_order_links')->insert([
            'sales_order_id' => $orderId,
            'provisioning_subscription_id' => $sub,
            'created_at' => now(),
        ]);
    }

    return $orderId;
}

it('shows the real owned wallet, settled revenue, counts and customer labels without remote calls', function (): void {
    $owner = liveReadUser(879001);
    $foreign = liveReadUser(879002);
    PanelWalletFunding::credit($owner->id, 2_000_000);
    PanelWalletFunding::credit($foreign->id, 5_000_000);

    $this->actingAs($owner)->withSession(['denagate_live.user_id' => $owner->id]);
    $ownedOrder = liveReadOrder($owner);
    app(ManageResellerServiceLabels::class)->save($owner->id, $ownedOrder, 'کاربر <خصوصی>');
    $this->actingAs($foreign)->withSession(['denagate_live.user_id' => $foreign->id]);
    $foreignOrder = liveReadOrder($foreign);
    app(ManageResellerServiceLabels::class)->save($foreign->id, $foreignOrder, 'نام نماینده دیگر');
    $this->actingAs($owner)->withSession(['denagate_live.user_id' => $owner->id]);
    $cost = (int) DB::table('sales_orders')->where('id', $ownedOrder)->value('total_price_rials');
    $expectedBalance = number_format(intdiv(2_000_000 - $cost, 10));
    $expectedSale = number_format(intdiv($cost, 10));
    $journalCount = DB::table('billing_journals')->count();

    Livewire::test(Dashboard::class)
        ->assertSee($expectedBalance)
        ->assertSee($expectedSale)
        ->assertSee('سرویس‌های ثبت‌شده')
        ->assertDontSee('اطلاعات واقعی سرویس‌ها')
        ->call('showSection', 'services')
        ->assertSee('کاربر &lt;خصوصی&gt;', false)
        ->assertSee('فعال در سوابق')
        ->assertDontSee('private_')
        ->assertDontSee('نام نماینده دیگر')
        ->assertDontSee((string) $foreignOrder.' · تاریخ ثبت')
        ->call('showSection', 'credit')
        ->assertSee($expectedBalance)
        ->call('showSection', 'reports')
        ->assertSee($expectedSale)
        ->assertSee('تراکنش‌های تأییدشده')
        ->call('selectReportDays', 7)
        ->assertSet('reportDays', 7)
        ->call('selectReportDays', 365)
        ->assertSet('reportDays', 7)
        ->assertSee('خریدهای جدید');

    expect(DB::table('billing_journals')->count())->toBe($journalCount)
        ->and(DB::table('sales_order_settlements')->count())->toBe(2);

    Http::assertNothingSent();
});

it('lists only a bounded reseller-owned service page and supports cursor reset', function (): void {
    $owner = liveReadUser(879003);
    $this->actingAs($owner)->withSession(['denagate_live.user_id' => $owner->id]);
    PanelWalletFunding::credit($owner->id, 4_000_000);
    for ($i = 0; $i < 8; $i++) {
        liveReadOrder($owner);
    }

    $latest = (int) DB::table('sales_orders')->where('buyer_user_id', $owner->id)->max('id');

    Livewire::test(Dashboard::class)
        ->call('showSection', 'services')
        ->assertSee('نمایش سرویس‌های بیشتر')
        ->assertSee('شماره سفارش:')
        ->call('nextServices', $latest - 5)
        ->assertSet('serviceCursor', $latest - 5)
        ->assertSee('بازگشت به اولین صفحه')
        ->call('firstServices')
        ->assertSet('serviceCursor', null)
        ->call('nextServices', -1)
        ->assertSet('serviceCursor', null);

    Http::assertNothingSent();
});

it('revokes dashboard access when a reseller loses the role', function (): void {
    $owner = liveReadUser(879004);
    PanelWalletFunding::credit($owner->id, 600_000);

    $this->actingAs($owner)->withSession(['denagate_live.user_id' => $owner->id])
        ->get('https://live.denagate.ir/dashboard')->assertOk();

    $owner->syncRoles(['member']);

    $this->get('https://live.denagate.ir/dashboard')->assertForbidden();
    $this->assertGuest('web');
    Http::assertNothingSent();
});

it('denies an inactive reseller using a fresh authenticated session', function (): void {
    $owner = liveReadUser(879005);
    PanelWalletFunding::credit($owner->id, 600_000);

    $this->actingAs($owner)->withSession(['denagate_live.user_id' => $owner->id])
        ->get('https://live.denagate.ir/dashboard')->assertOk();

    $owner->forceFill(['is_active' => false])->save();

    $this->get('https://live.denagate.ir/dashboard')->assertForbidden();
    $this->assertGuest('web');
    Http::assertNothingSent();
});

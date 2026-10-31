<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Interfaces\Web\Live\Dashboard;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use Spatie\Permission\Models\Role;
use Tests\Support\PanelWalletFunding;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('denagate_live.enabled', true);
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram.sales.bot_username', '@denagatebot');
    config()->set('billing.topups_enabled', true);
    config()->set('billing.payment_card_number', '6037991234567890');
    Role::findOrCreate('reseller', 'web');
    Role::findOrCreate('member', 'web');
    Http::preventStrayRequests();
    Http::fake();
});

function live5aReseller(int $tgId): User
{
    $buyer = User::factory()->create();
    $buyer->assignRole('reseller');
    TelegramAccount::query()->create(['user_id' => $buyer->id, 'telegram_user_id' => $tgId]);

    return $buyer;
}

function live5aSalesBot(int $tgId): Nutgram
{
    $route = new Route('POST', 'api/telegram/sales/webhook', static fn (): null => null);
    $route->defaults('bot_key', TelegramBotContext::SALES);
    $route->bind(request());
    request()->setRouteResolver(static fn (): Route => $route);

    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/sales.php');
    $bot->setCommonUser(TelegramUser::make($tgId, false, 'Live Reseller'));
    $bot->setCommonChat(Chat::make($tgId, ChatType::PRIVATE));

    return $bot;
}

it('shows the configured Sales-only deep link in the owned credit page without a topup write', function (): void {
    $buyer = live5aReseller(996101);
    $this->actingAs($buyer)->withSession(['denagate_live.user_id' => $buyer->id]);

    Livewire::test(Dashboard::class)->call('showSection', 'credit')
        ->assertSee('افزایش اعتبار در بات')
        ->assertSee('https://t.me/denagatebot?start=live_topup', false)
        ->assertSee('تازه‌سازی اعتبار')
        ->assertDontSee('در مرحله بعد');

    expect(DB::table('billing_topups')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('sales_orders')->count())->toBe(0);

    Http::assertNothingSent();
});

it('fails closed for absent, malformed or disabled Sales Bot configuration', function (): void {
    $buyer = live5aReseller(996102);
    $this->actingAs($buyer)->withSession(['denagate_live.user_id' => $buyer->id]);

    foreach (['', '@not valid', 'bot?start=other', 'https://evil.example/bot', str_repeat('a', 50)] as $invalid) {
        config()->set('telegram.sales.bot_username', $invalid);
        Livewire::test(Dashboard::class)->call('showSection', 'credit')
            ->assertDontSee('href="https://t.me/', false)
            ->assertSee('امکان انتقال مستقیم به بات فعلاً فراهم نیست');
    }
    config()->set('telegram.sales.bot_username', 'denagatebot');

    foreach (['telegram.sales.enabled', 'billing.topups_enabled'] as $flag) {
        config()->set($flag, false);
        Livewire::test(Dashboard::class)->call('showSection', 'credit')
            ->assertDontSee('href="https://t.me/', false);
        config()->set($flag, true);
    }

    expect(DB::table('billing_topups')->count())->toBe(0);
    Http::assertNothingSent();
});

it('routes a private reseller deep link to the existing receipt flow without creating credit or topups', function (): void {
    $buyer = live5aReseller(996103);
    $bot = live5aSalesBot(996103);

    $bot->hearText('/start live_topup')->reply()
        ->assertCalled('sendMessage', 1)
        ->assertReplyText('مبلغ شارژ را انتخاب کنید.', contains: true);

    expect(DB::table('billing_topups')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('billing_postings')->count())->toBe(0)
        ->and(DB::table('sales_orders')->count())->toBe(0)
        ->and($buyer->hasRole('reseller'))->toBeTrue();

    $bot->hearCallbackQueryData('sales:wallet:topup:custom')->reply()->assertCalled('editMessageText');
    Http::assertNothingSent();
});

it('keeps the normal home for ordinary members or revoked resellers using a forged deep link', function (): void {
    $buyer = live5aReseller(996104);
    $buyer->syncRoles(['member']);
    $bot = live5aSalesBot(996104);

    $bot->hearText('/start live_topup')->reply()
        ->assertCalled('sendRichMessage', 1);

    expect(DB::table('billing_topups')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);

    config()->set('denagate_live.enabled', false);
    $buyer->assignRole('reseller');
    $bot->hearText('/start live_topup')->reply()->assertCalled('sendRichMessage', 1);
    Http::assertNothingSent();
});

it('refreshes the actual ledger-backed balance after an approved payment without writing from Live', function (): void {
    $buyer = live5aReseller(996105);
    $this->actingAs($buyer)->withSession(['denagate_live.user_id' => $buyer->id]);
    $page = Livewire::test(Dashboard::class)->call('showSection', 'credit')
        ->assertSee('0')
        ->assertSee('تازه‌سازی اعتبار');

    PanelWalletFunding::credit($buyer->id, 750_000);
    $journalCount = DB::table('billing_journals')->count();

    $page->call('$refresh')->assertSee('75,000')
        ->assertSee('اعتبار قابل استفاده');

    expect(DB::table('billing_journals')->count())->toBe($journalCount)
        ->and(DB::table('billing_topups')->count())->toBe(0)
        ->and(DB::table('sales_orders')->count())->toBe(0);
    Http::assertNothingSent();
});

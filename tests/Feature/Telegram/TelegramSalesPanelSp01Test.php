<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Spatie\Permission\Models\Role;
use Tests\Support\PanelWalletFunding;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('cache.default', 'database');
    Http::preventStrayRequests();
    Http::fake();
    $route = new Route('POST', 'api/telegram/sales/webhook', static fn (): null => null);
    $route->defaults('bot_key', TelegramBotContext::SALES);
    $route->bind(request());
    request()->setRouteResolver(static fn (): Route => $route);
});

function panel01Bot(int $telegramId, User $user): Nutgram
{
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/sales.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Buyer'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/** @return array<string, mixed> */
function panel01Payload(Request $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $data;
}

it('shows panel application on home and saves a pending request only after terms acceptance and confirmation', function (): void {
    $member = User::factory()->create();
    PanelWalletFunding::credit($member->id, 7_500_000);
    $bot = panel01Bot(986101, $member);

    $bot->hearCallbackQueryData('sales:home')->reply()->assertRaw(static function (Request $request): bool {
        $data = panel01Payload($request);
        $buttons = json_encode($data['reply_markup'], JSON_THROW_ON_ERROR);

        return str_contains($buttons, 'sales:panel:request')
            && str_contains($buttons, 'sales:help:apps');
    }, index: 1);
    $bot->hearCallbackQueryData('sales:account')->reply()->assertRaw(static function (Request $request): bool {
        $data = panel01Payload($request);

        return ! str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'sales:panel:request');
    }, index: 1);
    $bot->hearCallbackQueryData('sales:panel:request')->reply()->assertRaw(static function (Request $request): bool {
        $data = panel01Payload($request);

        return str_contains($data['text'], '۷۵۰٬۰۰۰ تومان')
            && str_contains($data['text'], 'تعرفه و تخفیف')
            && str_contains($data['text'], 'اختلال اینترنت')
            && str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'sales:panel:accept');
    }, index: 1);
    $bot->hearText('علی')->reply()->assertCalled('sendMessage');
    expect(DB::table('sales_panel_requests')->count())->toBe(0);
    $bot->hearCallbackQueryData('sales:panel:confirm')->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_panel_requests')->count())->toBe(0);
    $bot->hearCallbackQueryData('sales:panel:accept')->reply()->assertCalled('editMessageText');
    $bot->hearText('علی')->reply()->assertCalled('sendMessage');
    $bot->hearText('محمدی')->reply()->assertCalled('sendMessage');
    $bot->hearText('+۹۸۹۱۲۳۴۵۶۷۸۹')->reply()->assertRaw(static function (Request $request): bool {
        $data = panel01Payload($request);

        return str_contains($data['text'], 'بررسی درخواست پنل فروش')
            && str_contains($data['text'], '09123456789')
            && str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'sales:panel:confirm');
    });
    expect(DB::table('sales_panel_requests')->count())->toBe(0);

    $bot->hearCallbackQueryData('sales:panel:confirm')->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_panel_requests')->count())->toBe(1)
        ->and(DB::table('sales_panel_requests')->value('status'))->toBe('pending')
        ->and(DB::table('sales_panel_requests')->value('mobile'))->toBe('09123456789')
        ->and($member->fresh()->hasRole('member'))->toBeTrue()
        ->and($member->fresh()->hasRole('reseller'))->toBeFalse();

    $bot->hearCallbackQueryData('sales:panel:confirm')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:panel:request')->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_panel_requests')->count())->toBe(1)
        ->and(DB::table('billing_journals')->count())->toBe(1);
    Http::assertNothingSent();
});

it('shows a wallet guidance screen with all three routes when balance is insufficient', function (): void {
    $member = User::factory()->create();
    $bot = panel01Bot(986106, $member);
    $key = TelegramBotContext::current()->cacheKey('telegram:panel:request:'.$member->id);

    $bot->hearCallbackQueryData('sales:panel:request')->reply()->assertRaw(static function (Request $request): bool {
        $data = panel01Payload($request);
        $buttons = $data['reply_markup']['inline_keyboard'] ?? [];

        return str_contains($data['text'] ?? '', '۷۵۰٬۰۰۰ تومان')
            && str_contains($data['text'] ?? '', 'پس از تأیید پنل فروش')
            && ($buttons[0][0]['callback_data'] ?? null) === 'sales:wallet'
            && ($buttons[1][0]['callback_data'] ?? null) === 'sales:support'
            && ($buttons[2][0]['callback_data'] ?? null) === 'sales:home';
    }, index: 1);
    expect(Cache::get($key))->toBeNull();
    $bot->hearCallbackQueryData('sales:panel:accept')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:wallet')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:support')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:home')->reply()->assertCalled('editMessageText');

    expect(DB::table('sales_panel_requests')->count())->toBe(0)
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(0);
    PanelWalletFunding::credit($member->id, 7_500_000);
    $bot->hearCallbackQueryData('sales:panel:request')->reply()->assertRaw(
        static fn (Request $request): bool => str_contains(
            json_encode(panel01Payload($request)['reply_markup'], JSON_THROW_ON_ERROR),
            'sales:panel:accept',
        ),
        index: 1,
    );
    Http::assertNothingSent();
});

it('rechecks wallet credit at final confirmation after it has been spent', function (): void {
    $member = User::factory()->create();
    PanelWalletFunding::credit($member->id, 7_500_000);
    $bot = panel01Bot(986107, $member);

    $bot->hearCallbackQueryData('sales:panel:request')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:panel:accept')->reply()->assertCalled('editMessageText');
    $bot->hearText('علی')->reply()->assertCalled('sendMessage');
    $bot->hearText('محمدی')->reply()->assertCalled('sendMessage');
    $bot->hearText('09123456789')->reply()->assertCalled('sendMessage');

    PanelWalletFunding::spend($member->id, 1);
    $bot->hearCallbackQueryData('sales:panel:confirm')->reply()->assertRaw(static function (Request $request): bool {
        $data = panel01Payload($request);

        return str_contains($data['text'] ?? '', 'موجودی کیف پول کافی نیست')
            && str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'sales:support');
    }, index: 1);

    expect(DB::table('sales_panel_requests')->count())->toBe(0)
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(0)
        ->and($member->fresh()->hasRole('reseller'))->toBeFalse();
    Http::assertNothingSent();
});

it('cancels or expires the private form without creating an application', function (): void {
    $member = User::factory()->create();
    PanelWalletFunding::credit($member->id, 7_500_000);
    $bot = panel01Bot(986102, $member);
    $key = TelegramBotContext::current()->cacheKey('telegram:panel:request:'.$member->id);

    $bot->hearCallbackQueryData('sales:panel:request')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:panel:cancel')->reply()->assertCalled('editMessageText');
    expect(Cache::get($key))->toBeNull();
    $bot->hearCallbackQueryData('sales:panel:confirm')->reply()->assertCalled('editMessageText');

    $bot->hearCallbackQueryData('sales:panel:request')->reply()->assertCalled('editMessageText');
    $this->travel(11)->minutes();
    $bot->hearText('علی')->reply()->assertCalled('sendMessage');
    expect(DB::table('sales_panel_requests')->count())->toBe(0);
    Http::assertNothingSent();
});

it('denies group callbacks, disabled users, forged cross-user confirmation and active reseller requests', function (): void {
    $member = User::factory()->create();
    Role::firstOrCreate(['name' => 'reseller', 'guard_name' => 'web']);
    $reseller = User::factory()->create();
    $reseller->syncRoles(['reseller']);
    PanelWalletFunding::credit($member->id, 7_500_000);
    $bot = panel01Bot(986103, $member);
    $partner = panel01Bot(986104, $reseller);

    $partner->hearCallbackQueryData('sales:panel:request')->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_panel_requests')->count())->toBe(0);

    $bot->hearCallbackQueryData('sales:panel:request')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:panel:accept')->reply()->assertCalled('editMessageText');
    $bot->hearText('حسین')->reply()->assertCalled('sendMessage');
    $partner->hearCallbackQueryData('sales:panel:confirm')->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_panel_requests')->count())->toBe(0);

    $bot->setCommonChat(Chat::make(-986103, ChatType::GROUP));
    $bot->hearCallbackQueryData('sales:panel:request')->reply()->assertNoReply();
    $bot->hearCallbackQueryData('sales:panel:confirm')->reply()->assertNoReply();

    $bot->setCommonChat(Chat::make(986103, ChatType::PRIVATE));
    $member->forceFill(['is_active' => false])->save();
    $bot->hearCallbackQueryData('sales:panel:confirm')->reply()->assertNoReply();
    expect(DB::table('sales_panel_requests')->count())->toBe(0);
});

it('leaving to sales home cancels panel draft without disturbing existing wallet routes', function (): void {
    $member = User::factory()->create();
    PanelWalletFunding::credit($member->id, 7_500_000);
    $bot = panel01Bot(986105, $member);
    $bot->hearCallbackQueryData('sales:panel:request')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:panel:accept')->reply()->assertCalled('editMessageText');
    $bot->hearText('علی')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('sales:home')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:wallet')->reply()->assertCalled('editMessageText');

    expect(Cache::get(TelegramBotContext::current()->cacheKey('telegram:panel:request:'.$member->id)))->toBeNull()
        ->and(DB::table('sales_panel_requests')->count())->toBe(0);
});

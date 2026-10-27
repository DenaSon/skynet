<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Interfaces\Telegram\Support\ReplyMenuLabels;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\SalesPanelRequests;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
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
    Http::preventStrayRequests();
    Http::fake();
    $route = new Route('POST', 'api/telegram/sales/webhook', static fn (): null => null);
    $route->defaults('bot_key', TelegramBotContext::SALES);
    $route->bind(request());
    request()->setRouteResolver(static fn (): Route => $route);
});

/** Sales-native Nutgram bot backed by an actual private linked account. */
function polish152Bot(int $telegramId, User $user): Nutgram
{
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/sales.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Buyer'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/** @return array<string, mixed> */
function polish152Payload(Request $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $data;
}

function polish152RichHtml(Request $request): string
{
    $data = polish152Payload($request);
    $rich = is_string($data['rich_message'] ?? null)
        ? json_decode($data['rich_message'], true, 512, JSON_THROW_ON_ERROR)
        : ($data['rich_message'] ?? []);

    return (string) ($rich['html'] ?? '');
}

it('displays an ordinary member role and real wallet balance after returning home', function (): void {
    $member = User::factory()->create();
    $bot = polish152Bot(987001, $member);

    $bot->hearCallbackQueryData('sales:home')->reply()->assertRaw(static function (Request $request): bool {
        $body = polish152Payload($request);
        $markup = json_encode($body['reply_markup'], JSON_THROW_ON_ERROR);

        return str_contains($body['text'], 'نوع حساب: <b>👤 کاربر عادی</b>')
            && str_contains($body['text'], 'اعتبار من: <b>0 تومان</b>')
            && str_contains($markup, 'sales:wallet')
            && str_contains($markup, 'sales:account')
            && ! str_contains($markup, 'menu:builder')
            && ($body['parse_mode'] ?? null) === 'HTML';
    }, index: 1);

    $bot->hearCallbackQueryData('sales:wallet')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:home')->reply()->assertRaw(static function (Request $request): bool {
        $body = polish152Payload($request);

        return str_contains($body['text'], 'نوع حساب: <b>👤 کاربر عادی</b>');
    }, index: 1);
    Http::assertNothingSent();
});

it('shows panel request status for an ordinary member but never promotes via navigation', function (): void {
    $member = User::factory()->create();
    $bot = polish152Bot(987002, $member);

    $bot->hearCallbackQueryData('sales:home')->reply()->assertRaw(static function (Request $request): bool {
        $body = polish152Payload($request);

        return str_contains(json_encode($body['reply_markup'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), '🧩 درخواست پنل فروش');
    }, index: 1);

    PanelWalletFunding::credit($member->id, 7_500_000);
    app(SalesPanelRequests::class)->submit($member->id, 'سارا', 'محمدی', '09123456789');
    $bot->hearCallbackQueryData('sales:home')->reply()->assertRaw(static function (Request $request): bool {
        $body = polish152Payload($request);

        return str_contains(json_encode($body['reply_markup'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), '📨 وضعیت درخواست پنل فروش');
    }, index: 1);

    expect($member->fresh()->hasRole('reseller'))->toBeFalse()
        ->and(DB::table('sales_panel_requests')->count())->toBe(1);
    Http::assertNothingSent();
});

it('gives active Sales Panel holders their unique home builder action without another application form', function (): void {
    Role::firstOrCreate(['name' => 'reseller', 'guard_name' => 'web']);
    $member = User::factory()->create();
    $member->syncRoles(['reseller']);
    $bot = polish152Bot(987003, $member);

    $bot->hearCallbackQueryData('sales:home')->reply()->assertRaw(static function (Request $request): bool {
        $body = polish152Payload($request);
        $markup = json_encode($body['reply_markup'], JSON_THROW_ON_ERROR);

        return str_contains($body['text'], 'نوع حساب: <b>🧩 پنل فروش</b>')
            && str_contains($body['text'], 'اعتبار من: <b>0 تومان</b>')
            && str_contains($markup, 'menu:builder')
            && str_contains($markup, 'sales:wallet');
    }, index: 1);
    $bot->hearCallbackQueryData('sales:account')->reply()->assertRaw(static function (Request $request): bool {
        $body = polish152Payload($request);
        $markup = json_encode($body['reply_markup'], JSON_THROW_ON_ERROR);

        return str_contains(polish152RichHtml($request), '🧩 پنل فروش')
            && str_contains($markup, 'menu:builder')
            && ! str_contains($markup, 'sales:panel:request');
    }, index: 1);

    expect(ReplyMenuLabels::all())->toContain(ReplyMenuLabels::LEGACY_BUILDER)
        ->toContain(ReplyMenuLabels::SALES_WALLET)
        ->toContain(ReplyMenuLabels::LEGACY_WALLET)
        ->toContain(ReplyMenuLabels::BUILDER)
        ->toContain(ReplyMenuLabels::WALLET);
    Http::assertNothingSent();
});

it('keeps referral unavailable-state navigation open to home and account', function (): void {
    config()->set('telegram.sales.bot_username', '');
    $member = User::factory()->create();
    $bot = polish152Bot(987004, $member);

    $bot->hearCallbackQueryData('sales:invite')->reply()->assertRaw(static function (Request $request): bool {
        $body = polish152Payload($request);
        $markup = json_encode($body['reply_markup'], JSON_THROW_ON_ERROR);

        return str_contains($body['text'], 'لینک دعوت فعلاً در دسترس نیست')
            && str_contains($markup, 'sales:account')
            && str_contains($markup, 'sales:home');
    }, index: 1);
    Http::assertNothingSent();
});

it('shows reseller-only builder guidance under Sales support without exposing it to members', function (): void {
    Role::findOrCreate('reseller', 'web');
    $reseller = User::factory()->create();
    $reseller->syncRoles(['reseller']);
    $bot = polish152Bot(987050, $reseller);
    $bot->hearCallbackQueryData('sales:support')->reply()
        ->assertRaw(static function (Request $request): bool {
            $body = polish152Payload($request);

            return str_contains(json_encode($body['reply_markup'], JSON_THROW_ON_ERROR), 'sales:help:builder');
        }, index: 1);
    $bot->hearCallbackQueryData('sales:help:builder')->reply()
        ->assertRaw(static function (Request $request): bool {
            $body = polish152Payload($request);

            return str_contains(polish152RichHtml($request), 'راهنمای پلن‌ساز')
                && str_contains(json_encode($body['reply_markup'], JSON_THROW_ON_ERROR), 'menu:builder');
        }, index: 1);
    $reseller->syncRoles(['member']);
    $bot->hearCallbackQueryData('sales:support')->reply()
        ->assertRaw(static function (Request $request): bool {
            $body = polish152Payload($request);

            return ! str_contains(json_encode($body['reply_markup'], JSON_THROW_ON_ERROR), 'sales:help:builder');
        }, index: 1);
    Http::assertNothingSent();
});

it('offers a readable panel setup guide with existing credit and application destinations', function (): void {
    $member = User::factory()->create();
    $bot = polish152Bot(987060, $member);
    $bot->hearCallbackQueryData('sales:help:apps')->reply()
        ->assertRaw(static function (Request $request): bool {
            $rows = polish152Payload($request)['reply_markup']['inline_keyboard'];

            return $rows[2][0]['callback_data'] === 'sales:help:purchase'
                && $rows[3][0]['text'] === '🧩 راه‌اندازی پنل فروش'
                && $rows[3][0]['callback_data'] === 'sales:help:panel-setup';
        }, index: 1);
    $bot->hearCallbackQueryData('sales:help:panel-setup')->reply()
        ->assertCalled('editMessageText')
        ->assertRaw(static function (Request $request): bool {
            $body = polish152Payload($request);
            $rows = $body['reply_markup']['inline_keyboard'];
            $callbacks = array_column(array_merge(...$rows), 'callback_data');

            $html = polish152RichHtml($request);

            return str_contains($html, '۷۵۰٬۰۰۰ تومان')
                && str_contains($html, '۱ ساعت')
                && str_contains($html, 'در صورت تأیید')
                && str_contains($html, '۳۵٪ تخفیف نمایندگی')
                && str_contains($html, '۷۵۰ هزار تومان، اعتبار اولیه شماست؛ هزینه راه‌اندازی نیست')
                && str_contains($html, 'نام، نام خانوادگی و شماره موبایل')
                && substr_count($html, '<details>') === 4
                && $rows[0][0]['callback_data'] === 'sales:wallet:topup'
                && $rows[0][0]['style'] === 'success'
                && $rows[1][0]['callback_data'] === 'sales:panel:request'
                && $rows[1][0]['style'] === 'primary'
                && in_array('sales:help:apps', $callbacks, true)
                && in_array('sales:home', $callbacks, true);
        }, index: 1);
    expect(DB::table('sales_panel_requests')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('sales_orders')->count())->toBe(0);

    config()->set('billing.topups_enabled', true);
    config()->set('billing.payment_card_number', '6037991234567890');
    $bot->hearCallbackQueryData('sales:wallet:topup')->reply()->assertRaw(static function (Request $request): bool {
        return str_contains(polish152Payload($request)['text'], 'مبلغ شارژ را انتخاب کنید');
    }, index: 1);
    $bot->hearCallbackQueryData('sales:panel:request')->reply()->assertRaw(static function (Request $request): bool {
        return str_contains(polish152Payload($request)['text'], 'اعتبار کافی نیست');
    }, index: 1);
    expect(DB::table('sales_panel_requests')->count())->toBe(0);
    PanelWalletFunding::credit($member->id, 7_500_000);
    $bot->hearCallbackQueryData('sales:panel:request')->reply()->assertRaw(static function (Request $request): bool {
        return str_contains(polish152Payload($request)['text'], 'قوانین پنل فروش');
    }, index: 1);
    expect($member->fresh()->hasRole('reseller'))->toBeFalse()
        ->and(DB::table('sales_panel_requests')->count())->toBe(0);
    Http::assertNothingSent();
});

it('hides panel setup and rejects a previously delivered guide callback after a role change', function (array $roles): void {
    Role::findOrCreate('reseller', 'web');
    $member = User::factory()->create();
    $bot = polish152Bot(987061, $member);
    $bot->hearCallbackQueryData('sales:help:panel-setup')->reply()->assertCalled('editMessageText');
    $member->syncRoles($roles);
    $bot->hearCallbackQueryData('sales:help:apps')->reply()->assertRaw(static function (Request $request): bool {
        return ! str_contains(json_encode(polish152Payload($request)['reply_markup'], JSON_THROW_ON_ERROR), 'sales:help:panel-setup');
    }, index: 1);
    $bot->hearCallbackQueryData('sales:help:panel-setup')->reply()->assertRaw(static function (Request $request): bool {
        $body = polish152Payload($request);

        return ! str_contains($body['text'], 'راه‌اندازی پنل فروش')
            && ! str_contains(json_encode($body['reply_markup'], JSON_THROW_ON_ERROR), 'sales:panel:request');
    }, index: 1);
    expect(DB::table('sales_panel_requests')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
})->with([['roles' => ['reseller']], ['roles' => ['member', 'reseller']], ['roles' => ['admin']]]);

it('keeps panel setup private and unavailable after account deactivation', function (): void {
    $member = User::factory()->create();
    $bot = polish152Bot(987062, $member);
    $bot->setCommonChat(Chat::make(-987062, ChatType::GROUP));
    $bot->hearCallbackQueryData('sales:help:panel-setup')->reply()->assertNoReply();
    $bot->setCommonChat(Chat::make(987062, ChatType::PRIVATE));
    $member->forceFill(['is_active' => false])->save();
    $bot->hearCallbackQueryData('sales:help:panel-setup')->reply()->assertRaw(static function (Request $request): bool {
        return ! str_contains(polish152Payload($request)['text'], 'راه‌اندازی پنل فروش');
    }, index: 1);
    expect(DB::table('sales_panel_requests')->count())->toBe(0);
    Http::assertNothingSent();
});

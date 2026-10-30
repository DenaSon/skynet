<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\SalesPanelRequests;
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
    PanelWalletFunding::credit($member->id, 3_500_000);
    $bot = panel01Bot(986101, $member);

    $bot->hearCallbackQueryData('sales:home')->reply()->assertRaw(static function (Request $request): bool {
        $data = panel01Payload($request);
        $buttons = json_encode($data['reply_markup'], JSON_THROW_ON_ERROR);

        return str_contains($buttons, 'sales:panel:request')
            && ! str_contains($buttons, 'sales:help:apps');
    }, index: 1);
    $bot->hearCallbackQueryData('sales:account')->reply()->assertRaw(static function (Request $request): bool {
        $data = panel01Payload($request);

        return str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'sales:panel:request');
    }, index: 1);
    $bot->hearCallbackQueryData('sales:panel:request')->reply()->assertRaw(static function (Request $request): bool {
        $data = panel01Payload($request);

        return str_contains($data['text'], '۳۵۰٬۰۰۰ تومان')
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

        return str_contains($data['text'] ?? '', '۳۵۰٬۰۰۰ تومان')
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
    PanelWalletFunding::credit($member->id, 3_500_000);
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
    PanelWalletFunding::credit($member->id, 3_500_000);
    $bot = panel01Bot(986107, $member);

    $bot->hearCallbackQueryData('sales:panel:request')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:panel:accept')->reply()->assertCalled('editMessageText');
    $bot->hearText('علی')->reply()->assertCalled('sendMessage');
    $bot->hearText('محمدی')->reply()->assertCalled('sendMessage');
    $bot->hearText('09123456789')->reply()->assertCalled('sendMessage');

    PanelWalletFunding::spend($member->id, 1);
    $bot->hearCallbackQueryData('sales:panel:confirm')->reply()->assertRaw(static function (Request $request): bool {
        $data = panel01Payload($request);

        return str_contains($data['text'] ?? '', 'اعتبار کافی نیست')
            && str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'sales:support');
    }, index: 1);

    expect(DB::table('sales_panel_requests')->count())->toBe(0)
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(0)
        ->and($member->fresh()->hasRole('reseller'))->toBeFalse();
    Http::assertNothingSent();
});

it('cancels or expires the private form without creating an application', function (): void {
    $member = User::factory()->create();
    PanelWalletFunding::credit($member->id, 3_500_000);
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
    PanelWalletFunding::credit($member->id, 3_500_000);
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
    PanelWalletFunding::credit($member->id, 3_500_000);
    $bot = panel01Bot(986105, $member);
    $bot->hearCallbackQueryData('sales:panel:request')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:panel:accept')->reply()->assertCalled('editMessageText');
    $bot->hearText('علی')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('sales:home')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:wallet')->reply()->assertCalled('editMessageText');

    expect(Cache::get(TelegramBotContext::current()->cacheKey('telegram:panel:request:'.$member->id)))->toBeNull()
        ->and(DB::table('sales_panel_requests')->count())->toBe(0);
});

it('makes Sales Panel application discoverable via persistent menu and Account for an ordinary member', function (): void {
    $member = User::factory()->create();
    $bot = panel01Bot(986111, $member);

    $bot->hearText('/start')->reply()->assertRaw(static function (Request $request): bool {
        $data = panel01Payload($request);
        $keyboard = $data['reply_markup']['keyboard'] ?? [];

        return ($data['reply_markup']['is_persistent'] ?? null) === true
            && ($keyboard[3][0]['text'] ?? null) === '🧩 درخواست پنل فروش';
    }, index: 0);
    $bot->hearCallbackQueryData('sales:account')->reply()->assertRaw(static function (Request $request): bool {
        $data = panel01Payload($request);

        return str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'sales:panel:request');
    }, index: 1);
    $bot->hearText('🧩 درخواست پنل فروش')->reply()->assertRaw(static function (Request $request): bool {
        $data = panel01Payload($request);

        return str_contains((string) ($data['text'] ?? ''), '۳۵۰٬۰۰۰ تومان')
            && str_contains((string) ($data['text'] ?? ''), 'اعتبار کافی نیست');
    });
    expect(DB::table('sales_panel_requests')->count())->toBe(0);
    Http::assertNothingSent();
});

it('shows existing application status instead of duplicate request in persistent menu and Account', function (): void {
    $member = User::factory()->create();
    $bot = panel01Bot(986112, $member);
    PanelWalletFunding::credit($member->id, 3_500_000);
    $existing = app(SalesPanelRequests::class)
        ->submit($member->id, 'علی', 'محمدی', '09123456789');

    $bot->hearText('/start')->reply()->assertRaw(static function (Request $request): bool {
        $keyboard = panel01Payload($request)['reply_markup']['keyboard'] ?? [];

        return ($keyboard[3][0]['text'] ?? null) === '📨 وضعیت درخواست پنل فروش';
    }, index: 0);
    $bot->hearCallbackQueryData('sales:account')->reply()->assertRaw(static function (Request $request): bool {
        $data = panel01Payload($request);
        $keyboard = json_encode($data['reply_markup'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return str_contains($keyboard, '📨 وضعیت درخواست پنل فروش')
            && str_contains($keyboard, 'sales:panel:request');
    }, index: 1);
    $bot->hearText('📨 وضعیت درخواست پنل فروش')->reply()->assertRaw(static function (Request $request): bool {
        return str_contains((string) (panel01Payload($request)['text'] ?? ''), 'در انتظار بررسی');
    });
    expect(DB::table('sales_panel_requests')->count())->toBe(1)
        ->and(DB::table('sales_panel_requests')->value('id'))->toBe($existing['id'])
        ->and(DB::table('billing_journals')->count())->toBe(1);
    Http::assertNothingSent();
});

it('keeps the member tutorial visible with a harmless extra role but hides it from resellers', function (): void {
    Role::findOrCreate('member', 'web');
    Role::findOrCreate('panel-observer', 'web');
    Role::findOrCreate('reseller', 'web');
    $member = User::factory()->create();
    $member->syncRoles(['member', 'panel-observer']);
    $bot = panel01Bot(986113, $member);

    $bot->hearCallbackQueryData('sales:help:apps')->reply()->assertRaw(static function (Request $request): bool {
        return str_contains(json_encode(panel01Payload($request)['reply_markup'], JSON_THROW_ON_ERROR), 'sales:help:panel-setup');
    }, index: 1);
    $bot->hearText('/start')->reply()->assertRaw(static function (Request $request): bool {
        return str_contains(json_encode(panel01Payload($request)['reply_markup'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), '🧩 درخواست پنل فروش');
    }, index: 0);

    $member->syncRoles(['reseller']);
    $bot->hearText('/start')->reply()->assertRaw(static function (Request $request): bool {
        return ! str_contains(json_encode(panel01Payload($request)['reply_markup'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'درخواست پنل فروش');
    }, index: 0);
    Http::assertNothingSent();
});

it('shows the green panel request CTA on Wallet at the configured exact threshold, but not below or after application', function (): void {
    config()->set('sales.panel_minimum_wallet_toman', '550000');
    $member = User::factory()->create();
    $bot = panel01Bot(986171, $member);
    PanelWalletFunding::credit($member->id, 5_499_999);

    $bot->hearCallbackQueryData('sales:wallet')->reply()->assertRaw(static function (Request $request): bool {
        $data = panel01Payload($request);

        return ! str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'sales:panel:request')
            && ! str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'sales:account');
    }, index: 1);

    PanelWalletFunding::credit($member->id, 1);
    foreach (['sales:wallet', 'sales:wallet:simple'] as $callback) {
        $bot->hearCallbackQueryData($callback)->reply()->assertRaw(static function (Request $request): bool {
            $buttons = panel01Payload($request)['reply_markup']['inline_keyboard'] ?? [];

            return ($buttons[0][0]['callback_data'] ?? null) === 'sales:panel:request'
                && ($buttons[0][0]['style'] ?? null) === 'success';
        }, index: 1);
    }

    app(SalesPanelRequests::class)->submit($member->id, 'علی', 'محمدی', '09123456789');
    $bot->hearCallbackQueryData('sales:wallet:simple')->reply()->assertRaw(static function (Request $request): bool {
        return ! str_contains(json_encode(panel01Payload($request)['reply_markup'], JSON_THROW_ON_ERROR), 'sales:panel:request');
    }, index: 1);

    Role::findOrCreate('reseller', 'web');
    $member->syncRoles(['reseller']);
    $bot->hearCallbackQueryData('sales:wallet:simple')->reply()->assertRaw(static function (Request $request): bool {
        return ! str_contains(json_encode(panel01Payload($request)['reply_markup'], JSON_THROW_ON_ERROR), 'sales:panel:request');
    }, index: 1);
    Http::assertNothingSent();
});

it('describes a previously approved panel request without a current reseller role accurately', function (): void {
    $member = User::factory()->create();
    PanelWalletFunding::credit($member->id, 3_500_000);
    $bot = panel01Bot(986172, $member);
    $application = app(SalesPanelRequests::class)->submit($member->id, 'علی', 'محمدی', '09123456789');
    DB::table('sales_panel_requests')->where('id', $application['id'])->update(['status' => 'approved']);

    $bot->hearCallbackQueryData('sales:panel:request')->reply()->assertRaw(static function (Request $request): bool {
        $text = (string) (panel01Payload($request)['text'] ?? '');

        return str_contains($text, 'قبلاً تأیید شده')
            && str_contains($text, 'در حال حاضر فعال نیست')
            && ! str_contains($text, '✅ پنل فروش برای حساب شما فعال است');
    }, index: 1);

    Role::findOrCreate('reseller', 'web');
    $member->syncRoles(['reseller']);
    $bot->hearCallbackQueryData('sales:panel:request')->reply()->assertRaw(static function (Request $request): bool {
        return str_contains((string) (panel01Payload($request)['text'] ?? ''), '✅ پنل فروش برای حساب شما فعال است');
    }, index: 1);
    Http::assertNothingSent();
});

it('reads a changed panel credit minimum from config consistently across Telegram rules and tutorials', function (): void {
    config()->set('sales.panel_minimum_wallet_toman', '420000');
    $member = User::factory()->create();
    $bot = panel01Bot(986151, $member);
    PanelWalletFunding::credit($member->id, 4_199_999);

    $bot->hearCallbackQueryData('sales:panel:request')->reply()
        ->assertRaw(static function (Request $request): bool {
            $text = (string) (panel01Payload($request)['text'] ?? '');

            return str_contains($text, '۴۲۰٬۰۰۰ تومان')
                && ! str_contains($text, '۳۵۰٬۰۰۰ تومان')
                && str_contains($text, 'اعتبار کافی نیست');
        }, index: 1);

    $bot->hearCallbackQueryData('sales:help:panel-setup')->reply()
        ->assertRaw(static function (Request $request): bool {
            $data = panel01Payload($request);
            $rich = is_string($data['rich_message'] ?? null)
                ? json_decode($data['rich_message'], true, 512, JSON_THROW_ON_ERROR)
                : ($data['rich_message'] ?? []);
            $html = (string) ($rich['html'] ?? '');

            return str_contains($html, '۴۲۰٬۰۰۰ تومان')
                && str_contains($html, '۳۵٪')
                && ! str_contains($html, '۳۵۰٬۰۰۰ تومان');
        }, index: 1);
    $bot->hearCallbackQueryData('sales:help:simple:panel-setup')->reply()
        ->assertRaw(static function (Request $request): bool {
            $data = panel01Payload($request);

            return ! isset($data['rich_message'])
                && str_contains((string) ($data['text'] ?? ''), '۴۲۰٬۰۰۰ تومان')
                && ! str_contains((string) ($data['text'] ?? ''), '۳۵۰٬۰۰۰ تومان');
        }, index: 1);
    $bot->hearCallbackQueryData('sales:help:apps')->reply()
        ->assertRaw(static function (Request $request): bool {
            $data = panel01Payload($request);
            $rich = is_string($data['rich_message'] ?? null)
                ? json_decode($data['rich_message'], true, 512, JSON_THROW_ON_ERROR)
                : ($data['rich_message'] ?? []);

            return str_contains((string) ($rich['html'] ?? ''), '۴۲۰٬۰۰۰ تومان');
        }, index: 1);

    PanelWalletFunding::credit($member->id, 1);
    $bot->hearCallbackQueryData('sales:panel:request')->reply()
        ->assertRaw(static function (Request $request): bool {
            $text = (string) (panel01Payload($request)['text'] ?? '');

            return str_contains($text, '۴۲۰٬۰۰۰ تومان')
                && str_contains($text, 'قوانین پنل فروش')
                && ! str_contains($text, '۳۵۰٬۰۰۰ تومان');
        }, index: 1);
    expect(DB::table('sales_panel_requests')->count())->toBe(0);
    Http::assertNothingSent();
});

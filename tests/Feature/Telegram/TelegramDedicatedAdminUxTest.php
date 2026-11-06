<?php

use App\Interfaces\Telegram\Support\MenuRenderer;
use App\Interfaces\Telegram\Support\ReplyMenuLabels;
use App\Interfaces\Telegram\Support\ReplyMenuRenderer;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

function mb04Bot(int $telegramId, User $user): Nutgram
{
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);

    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Admin'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/** @return list<string> */
function mb04Labels(ReplyKeyboardMarkup $keyboard): array
{
    return array_map(
        static fn ($button): string => $button->text,
        array_merge(...$keyboard->keyboard),
    );
}

it('opens the actual Admin home from start, menu and legacy home callback without customer shortcuts', function (): void {
    config()->set('telegram.hybrid_menu_enabled', false);
    $admin = User::factory()->admin()->create();
    $bot = mb04Bot(984001, $admin);

    $bot->hearText('/start')->reply()
        ->assertReplyText("🛡️ مدیریت DenaGate\n\nبخش موردنظر را انتخاب کنید.");
    $bot->hearText('/menu')->reply()
        ->assertReplyText("🛡️ مدیریت DenaGate\n\nبخش موردنظر را انتخاب کنید.");
    $bot->hearCallbackQueryData('menu:home')->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText');
    $bot->hearText('/admin')->reply()
        ->assertReplyText("🛡️ مدیریت DenaGate\n\nبخش موردنظر را انتخاب کنید.");

    $actor = app(ResolveTelegramActor::class)->handle(984001);
    expect($actor)->not->toBeNull();

    $labels = mb04Labels(app(ReplyMenuRenderer::class)->homeKeyboard($actor));
    expect($labels)->toContain(ReplyMenuLabels::USERS)
        ->not->toContain(ReplyMenuLabels::BUY, ReplyMenuLabels::CATALOG, ReplyMenuLabels::SUBSCRIPTIONS,
            ReplyMenuLabels::INVITE, ReplyMenuLabels::HOME, ReplyMenuLabels::ROLES);
});

it('refreshes the persistent keyboard for an authorized Admin on start and preserves old button actions', function (): void {
    config()->set('telegram.hybrid_menu_enabled', true);
    $admin = User::factory()->admin()->create();
    $bot = mb04Bot(984002, $admin);

    $bot->hearText('/start')->reply()
        ->assertReplyText("🛡️ مدیریت DenaGate\n\nبخش موردنظر را از منوی پایین انتخاب کنید.");
    $bot->hearText(ReplyMenuLabels::USERS)->reply()->assertCalled('sendMessage');
    $bot->hearText(ReplyMenuLabels::ACCOUNT)->reply()->assertCalled('sendMessage');
    $bot->hearText(ReplyMenuLabels::HOME)->reply()
        ->assertReplyText("🛡️ مدیریت DenaGate\n\nبخش موردنظر را از منوی پایین انتخاب کنید.");

    // A label from an old keyboard, and original commands, still work after UI retirement.
    $bot->hearText(ReplyMenuLabels::BUY)->reply()->assertCalled('sendMessage');
    $bot->hearText('/buy')->reply()->assertCalled('sendMessage');
    $bot->hearText('/orders')->reply()->assertCalled('sendMessage');
    expect(DB::table('sales_orders')->count())->toBe(0);
});

it('shows only authorized real Admin operations for trusted roots and gates financial navigation', function (): void {
    config()->set('telegram.hybrid_menu_enabled', true);
    config()->set('super_admin.telegram_ids', ['984003', '984099']);
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $bot = mb04Bot(984003, $root);

    $actor = app(ResolveTelegramActor::class)->handle(984003);
    expect($actor)->not->toBeNull();

    $keyboard = app(ReplyMenuRenderer::class)->homeKeyboard($actor);
    $labels = mb04Labels($keyboard);
    expect($labels)->toContain(ReplyMenuLabels::USERS, ReplyMenuLabels::DASHBOARD, ReplyMenuLabels::REPORTS)
        ->toContain(ReplyMenuLabels::FINANCE_SECTION, ReplyMenuLabels::DENABAN_RADAR, ReplyMenuLabels::CONTROL_PANEL)
        ->not->toContain(ReplyMenuLabels::TOPUPS, ReplyMenuLabels::RECEIPTS,
            ReplyMenuLabels::BUY, ReplyMenuLabels::SUBSCRIPTIONS, ReplyMenuLabels::HOME,
            ReplyMenuLabels::ROLES, ReplyMenuLabels::SALES_SECTION, ReplyMenuLabels::SERVERS, ReplyMenuLabels::SETTINGS)
        ->and(count($keyboard->keyboard[0]))->toBe(2);

    $bot->hearText('/start')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('menu:admin:sales')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('menu:admin:products')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('menu:admin:plans')->reply()->assertCalled('editMessageText');
    $bot->hearText(ReplyMenuLabels::FINANCE_SECTION)->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('menu:admin:finance')->reply()->assertCalled('editMessageText');

    config()->set('sales.manual_bank_review_enabled', true);
    expect(mb04Labels(app(ReplyMenuRenderer::class)->homeKeyboard($actor)))
        ->toContain(ReplyMenuLabels::FINANCE_SECTION)
        ->not->toContain(ReplyMenuLabels::RECEIPTS);

    $bot->hearText(ReplyMenuLabels::FINANCE_SECTION)->reply()->assertCalled('sendMessage');
});

it('keeps non-admin navigation unchanged and removes the Admin landing after role revocation', function (): void {
    config()->set('telegram.hybrid_menu_enabled', false);
    $member = User::factory()->create(['name' => 'Regular User']);
    $memberBot = mb04Bot(984004, $member);
    $memberBot->hearText('/start')->reply()
        ->assertReplyText("🏠 DenaGate\n\nخوش آمدید، Regular User.");
    $memberBot->hearText('/admin')->reply()->assertReplyText('دسترسی مجاز نیست.');

    $admin = User::factory()->admin()->create();
    $bot = mb04Bot(984005, $admin);
    $bot->hearText('/start')->reply()
        ->assertReplyText("🛡️ مدیریت DenaGate\n\nبخش موردنظر را انتخاب کنید.");

    $admin->syncRoles(['member']);
    $bot->hearText('/start')->reply()
        ->assertReplyText("🏠 DenaGate\n\nخوش آمدید، {$admin->name}.");
    $bot->hearCallbackQueryData('menu:admin:users')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
});

it('keeps private chat guards and refuses stale Admin keyboards for inactive users', function (): void {
    config()->set('telegram.hybrid_menu_enabled', true);
    $admin = User::factory()->admin()->create();
    $bot = mb04Bot(984006, $admin);

    $bot->setCommonChat(Chat::make(-984006, ChatType::GROUP));
    $bot->hearText('/start')->reply()->assertNoReply();
    $bot->hearText(ReplyMenuLabels::USERS)->reply()->assertNoReply();

    $bot->setCommonChat(Chat::make(984006, ChatType::PRIVATE));
    $admin->forceFill(['is_active' => false])->save();
    $bot->hearText(ReplyMenuLabels::USERS)->reply()
        ->assertReplyText('🔒 حساب شما به DenaGate متصل نیست. برای اتصال، کد یک‌بارمصرف صادرشده توسط مدیر را در گفت‌وگوی خصوصی با دستور /link وارد کنید.');
});

it('renders Admin as fresh text from a photo callback without trying to edit the media', function (): void {
    // Routing/RBAC are covered by earlier tests. This isolates the media
    // transport path without Nutgram Fake's synthetic-photo routing limits.
    $admin = User::factory()->admin()->create();
    TelegramAccount::query()->create(['user_id' => $admin->id, 'telegram_user_id' => 984101]);
    $actor = app(ResolveTelegramActor::class)->handle(984101);
    expect($actor)->not->toBeNull();

    $photo = new Message;
    $photo->photo = [['file_id' => 'test-receipt', 'width' => 600, 'height' => 600]];

    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('isCallbackQuery')->twice()->andReturn(true);
    $bot->shouldReceive('answerCallbackQuery')->once()->andReturn(true);
    $bot->shouldReceive('message')->once()->andReturn($photo);
    $bot->shouldReceive('sendMessage')->once()->andReturn(new Message);
    $bot->shouldNotReceive('editMessageText');
    app(MenuRenderer::class)->showAdmin($bot, $actor);
});

it('keeps in-place Admin text menu edits for ordinary callbacks', function (): void {
    $admin = User::factory()->admin()->create();
    TelegramAccount::query()->create(['user_id' => $admin->id, 'telegram_user_id' => 984102]);
    $actor = app(ResolveTelegramActor::class)->handle(984102);
    expect($actor)->not->toBeNull();

    $textMessage = new Message;
    $textMessage->text = 'Previous Admin page';

    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('isCallbackQuery')->times(3)->andReturn(true);
    $bot->shouldReceive('answerCallbackQuery')->once()->andReturn(true);
    $bot->shouldReceive('message')->once()->andReturn($textMessage);
    $bot->shouldReceive('callbackQuery')->once()->andReturn(null);
    $bot->shouldReceive('editMessageText')->once()->andReturn(new Message);
    $bot->shouldNotReceive('sendMessage');
    app(MenuRenderer::class)->showAdmin($bot, $actor);
});

it('keeps the stats entry in the persistent root Admin keyboard and denies stale or ordinary-admin taps', function (): void {
    config()->set('telegram.hybrid_menu_enabled', true);
    config()->set('super_admin.telegram_ids', ['984201', '984299']);
    Http::fake(['*' => Http::response([], 503)]);

    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $rootBot = mb04Bot(984201, $root);
    expect(mb04Labels(app(ReplyMenuRenderer::class)->adminKeyboard(
        app(ResolveTelegramActor::class)->handle(984201),
    )))->toContain(ReplyMenuLabels::REPORTS);

    $rootBot->hearText(ReplyMenuLabels::REPORTS)->reply()->assertCalled('sendRichMessage');
    // Previously delivered text buttons remain valid.
    $rootBot->hearText(ReplyMenuLabels::STATS)->reply()->assertCalled('sendRichMessage');

    $ordinary = User::factory()->admin()->create();
    $ordinaryBot = mb04Bot(984202, $ordinary);
    expect(mb04Labels(app(ReplyMenuRenderer::class)->adminKeyboard(
        app(ResolveTelegramActor::class)->handle(984202),
    )))->not->toContain(ReplyMenuLabels::STATS);
    $ordinaryBot->hearText(ReplyMenuLabels::STATS)->reply()
        ->assertReplyText('دسترسی مجاز نیست. منوی شما به‌روز شد.');

    $root->syncRoles(['member']);
    $rootBot->hearText(ReplyMenuLabels::STATS)->reply()
        ->assertReplyText('دسترسی مجاز نیست. منوی شما به‌روز شد.');

    $ordinaryBot->setCommonChat(Chat::make(-984202, ChatType::GROUP));
    $ordinaryBot->hearText(ReplyMenuLabels::STATS)->reply()->assertNoReply();
});

it('opens the KPI Rich report from the persistent root menu and rejects stale or nonprivate taps', function (): void {
    config()->set('telegram.hybrid_menu_enabled', true);
    config()->set('super_admin.telegram_ids', ['984211', '984299']);
    Http::preventStrayRequests();

    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $rootBot = mb04Bot(984211, $root);
    $actor = app(ResolveTelegramActor::class)->handle(984211);
    expect($actor)->not->toBeNull();

    $keyboard = app(ReplyMenuRenderer::class)->adminKeyboard($actor);
    $labels = mb04Labels($keyboard);
    expect($keyboard->keyboard[0][0]->text)->toBe(ReplyMenuLabels::DASHBOARD)
        ->and($keyboard->keyboard[0][1]->text)->toBe(ReplyMenuLabels::REPORTS)
        ->and($keyboard->keyboard[2][0]->text)->toBe(ReplyMenuLabels::DENABAN_RADAR)
        ->and($keyboard->keyboard[2][1]->text)->toBe(ReplyMenuLabels::CONTROL_PANEL)
        ->and($labels)->toContain(ReplyMenuLabels::REPORTS, ReplyMenuLabels::USERS, ReplyMenuLabels::FINANCE_SECTION)
        ->and(ReplyMenuLabels::all())->toContain(ReplyMenuLabels::MANAGEMENT_KPIS);

    $rootBot->hearText('/start')->reply()
        ->assertReplyText("🛡️ مدیریت DenaGate\n\nبخش موردنظر را از منوی پایین انتخاب کنید.");
    $rootBot->hearText(ReplyMenuLabels::DASHBOARD)->reply()->assertCalled('sendRichMessage');
    $rootBot->hearText(ReplyMenuLabels::MANAGEMENT_KPIS)->reply()
        ->assertCalled('sendRichMessage');

    // Ordinary and revoked Admins must not obtain root-only KPI data from
    // copied text or previously delivered persistent keyboards.
    $ordinary = User::factory()->admin()->create();
    $ordinaryBot = mb04Bot(984212, $ordinary);
    expect(mb04Labels(app(ReplyMenuRenderer::class)->adminKeyboard(
        app(ResolveTelegramActor::class)->handle(984212),
    )))->not->toContain(ReplyMenuLabels::MANAGEMENT_KPIS);
    $ordinaryBot->hearText(ReplyMenuLabels::MANAGEMENT_KPIS)->reply()
        ->assertReplyText('دسترسی مجاز نیست. منوی شما به‌روز شد.');

    $root->syncRoles(['member']);
    $rootBot->hearText(ReplyMenuLabels::MANAGEMENT_KPIS)->reply()
        ->assertReplyText('دسترسی مجاز نیست. منوی شما به‌روز شد.');

    $ordinaryBot->setCommonChat(Chat::make(-984212, ChatType::GROUP));
    $ordinaryBot->hearText(ReplyMenuLabels::MANAGEMENT_KPIS)->reply()->assertNoReply();

    Http::assertNothingSent();
});

it('shows root-only control panel routes in Inline and persistent menus without weakening RBAC', function (): void {
    config()->set('telegram.hybrid_menu_enabled', true);
    config()->set('super_admin.telegram_ids', ['984311', '984399']);

    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $bot = mb04Bot(984311, $root);
    $actor = app(ResolveTelegramActor::class)->handle(984311);
    expect($actor)->not->toBeNull();
    expect(mb04Labels(app(ReplyMenuRenderer::class)->adminKeyboard($actor)))->toBe([
        ReplyMenuLabels::DASHBOARD, ReplyMenuLabels::REPORTS,
        ReplyMenuLabels::USERS, ReplyMenuLabels::FINANCE_SECTION,
        ReplyMenuLabels::DENABAN_RADAR, ReplyMenuLabels::CONTROL_PANEL,
    ]);

    $bot->hearCallbackQueryData('menu:admin:control')->reply()
        ->assertRaw(static function (Request $request): bool {
            $data = FakeNutgram::getActualData($request);
            $markup = is_string($data['reply_markup'] ?? null)
                ? json_decode($data['reply_markup'], true, 512, JSON_THROW_ON_ERROR)
                : ($data['reply_markup'] ?? []);
            $buttons = $markup['inline_keyboard'] ?? [];

            return ($buttons[0][0]['callback_data'] ?? null) === 'menu:admin:sales'
                && ($buttons[0][1]['callback_data'] ?? null) === 'menu:admin:servers'
                && ($buttons[1][0]['callback_data'] ?? null) === 'admin:rwbackup:list:1'
                && ($buttons[1][1]['callback_data'] ?? null) === 'menu:admin:roles'
                && ($buttons[2][0]['callback_data'] ?? null) === 'admin:welcome:show'
                && ($buttons[3][0]['callback_data'] ?? null) === 'menu:admin';
        }, index: 1);
    $bot->hearText(ReplyMenuLabels::CONTROL_PANEL)->reply()
        ->assertReplyText("⚙️ کنترل پنل\n\nبخش موردنظر را انتخاب کنید.");

    $ordinary = User::factory()->admin()->create();
    $ordinaryBot = mb04Bot(984312, $ordinary);
    $ordinaryBot->hearText(ReplyMenuLabels::CONTROL_PANEL)->reply()
        ->assertReplyText('دسترسی مجاز نیست. منوی شما به‌روز شد.');
    $ordinaryBot->hearCallbackQueryData('menu:admin:control')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);

    $root->syncRoles(['member']);
    $bot->hearCallbackQueryData('menu:admin:control')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
    $ordinaryBot->setCommonChat(Chat::make(-984312, ChatType::GROUP));
    $ordinaryBot->hearCallbackQueryData('menu:admin:control')->reply()->assertNoReply();
});

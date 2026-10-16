<?php

use App\Interfaces\Telegram\Support\ReplyMenuLabels;
use App\Interfaces\Telegram\Support\ReplyMenuRenderer;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;

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
        ->assertReplyText("🛡️ مدیریت ParsaGate\n\nبخش موردنظر را انتخاب کنید.");
    $bot->hearText('/menu')->reply()
        ->assertReplyText("🛡️ مدیریت ParsaGate\n\nبخش موردنظر را انتخاب کنید.");
    $bot->hearCallbackQueryData('menu:home')->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText');
    $bot->hearText('/admin')->reply()
        ->assertReplyText("🛡️ مدیریت ParsaGate\n\nبخش موردنظر را انتخاب کنید.");

    $actor = app(ResolveTelegramActor::class)->handle(984001);
    expect($actor)->not->toBeNull();

    $labels = mb04Labels(app(ReplyMenuRenderer::class)->homeKeyboard($actor));
    expect($labels)->toContain(ReplyMenuLabels::USERS, ReplyMenuLabels::ACCOUNT)
        ->not->toContain(ReplyMenuLabels::BUY, ReplyMenuLabels::CATALOG, ReplyMenuLabels::SUBSCRIPTIONS,
            ReplyMenuLabels::INVITE, ReplyMenuLabels::HOME, ReplyMenuLabels::ROLES);
});

it('refreshes the persistent keyboard for an authorized Admin on start and preserves old button actions', function (): void {
    config()->set('telegram.hybrid_menu_enabled', true);
    $admin = User::factory()->admin()->create();
    $bot = mb04Bot(984002, $admin);

    $bot->hearText('/start')->reply()
        ->assertReplyText("🛡️ مدیریت ParsaGate\n\nبخش موردنظر را از منوی پایین انتخاب کنید.");
    $bot->hearText(ReplyMenuLabels::USERS)->reply()->assertCalled('sendMessage');
    $bot->hearText(ReplyMenuLabels::ACCOUNT)->reply()->assertCalled('sendMessage');
    $bot->hearText(ReplyMenuLabels::HOME)->reply()
        ->assertReplyText("🛡️ مدیریت ParsaGate\n\nبخش موردنظر را از منوی پایین انتخاب کنید.");

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
    expect($labels)->toContain(ReplyMenuLabels::USERS, ReplyMenuLabels::ROLES, ReplyMenuLabels::SALES_SECTION, ReplyMenuLabels::ACCOUNT)
        ->not->toContain(ReplyMenuLabels::FINANCE_SECTION, ReplyMenuLabels::TOPUPS, ReplyMenuLabels::RECEIPTS,
            ReplyMenuLabels::BUY, ReplyMenuLabels::SUBSCRIPTIONS, ReplyMenuLabels::HOME)
        ->and(count($keyboard->keyboard[0]))->toBe(2);

    $bot->hearText('/start')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('menu:admin:sales')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('menu:admin:products')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('menu:admin:plans')->reply()->assertCalled('editMessageText');
    $bot->hearText(ReplyMenuLabels::FINANCE_SECTION)->reply()
        ->assertReplyText('دسترسی مجاز نیست. منوی شما به‌روز شد.');
    $bot->hearCallbackQueryData('menu:admin:finance')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);

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
        ->assertReplyText("🏠 ParsaGate\n\nخوش آمدید، Regular User.");
    $memberBot->hearText('/admin')->reply()->assertReplyText('دسترسی مجاز نیست.');

    $admin = User::factory()->admin()->create();
    $bot = mb04Bot(984005, $admin);
    $bot->hearText('/start')->reply()
        ->assertReplyText("🛡️ مدیریت ParsaGate\n\nبخش موردنظر را انتخاب کنید.");

    $admin->syncRoles(['member']);
    $bot->hearText('/start')->reply()
        ->assertReplyText("🏠 ParsaGate\n\nخوش آمدید، {$admin->name}.");
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
        ->assertReplyText('🔒 حساب شما به ParsaGate متصل نیست. برای اتصال، کد یک‌بارمصرف صادرشده توسط مدیر را در گفت‌وگوی خصوصی با دستور /link وارد کنید.');
});

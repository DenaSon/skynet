<?php

use App\Modules\Identity\Application\IssueTelegramLinkCode;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;

uses(RefreshDatabase::class);

it('responds to start for an unlinked private Telegram user', function (): void {
    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make(11111, false, 'Visitor'));
    $bot->setCommonChat(Chat::make(11111, ChatType::PRIVATE));

    $bot->hearText('/start')->reply()
        ->assertReplyText('🔒 حساب شما به DenaGate متصل نیست. برای اتصال، کد یک‌بارمصرف صادرشده توسط مدیر را در گفت‌وگوی خصوصی با دستور /link وارد کنید.');
});

it('renders an admin menu only after verified account linking', function (): void {
    $user = User::factory()->admin()->create();
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 22222]);

    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make(22222, false, 'Owner'));
    $bot->setCommonChat(Chat::make(22222, ChatType::PRIVATE));

    $bot->hearText('/admin')->reply()
        ->assertReplyText("🛡️ مدیریت DenaGate\n\nبخش موردنظر را انتخاب کنید.");
});

it('does not disclose the admin menu to linked members', function (): void {
    $user = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 33333]);

    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make(33333, false, 'Member'));
    $bot->setCommonChat(Chat::make(33333, ChatType::PRIVATE));

    $bot->hearText('/admin')->reply()->assertReplyText('دسترسی مجاز نیست.');
});

it('ignores Telegram commands originating in group chats', function (): void {
    $user = User::factory()->admin()->create();
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 44444]);

    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make(44444, false, 'Owner'));
    $bot->setCommonChat(Chat::make(-44555, ChatType::GROUP));

    $bot->hearText('/admin')->reply()->assertNoReply();
});

it('redeems a single-use pairing code through the bot command in a private chat', function (): void {
    $user = User::factory()->create();
    $code = app(IssueTelegramLinkCode::class)->handle($user->id);

    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make(55555, false, 'Member'));
    $bot->setCommonChat(Chat::make(55555, ChatType::PRIVATE));

    $bot->hearText('/link '.$code)->reply()->assertCalled('sendMessage', 2);

    expect(TelegramAccount::query()->where('telegram_user_id', 55555)->value('user_id'))->toBe($user->id);
});

it('shows actual account roles and status, and exposes a settings screen', function (): void {
    $user = User::factory()->create(['name' => "Test\nMember"]);
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 66666]);

    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make(66666, false, 'Member'));
    $bot->setCommonChat(Chat::make(66666, ChatType::PRIVATE));

    $bot->hearText('/account')->reply()
        ->assertReplyText("👤 حساب من\n\nنام: Test Member\nشناسه DenaGate: {$user->id}\nنقش‌ها: کاربر\nوضعیت: 🟢 فعال");

    $bot->hearText('/settings')->reply()
        ->assertReplyText("⚙️ تنظیمات\n\nدر حال حاضر گزینه‌ای برای تغییر تنظیمات وجود ندارد.");
});

it('edits existing messages when navigating by inline callbacks', function (): void {
    $user = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 77777]);

    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make(77777, false, 'Member'));
    $bot->setCommonChat(Chat::make(77777, ChatType::PRIVATE));

    $bot->hearCallbackQueryData('menu:account')->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText');

    $bot->hearCallbackQueryData('menu:settings')->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText');

    $bot->hearCallbackQueryData('menu:home')->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText');
});

it('rejects forged admin callbacks from members and ordinary admins', function (): void {
    $member = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $member->id, 'telegram_user_id' => 88888]);

    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make(88888, false, 'Member'));
    $bot->setCommonChat(Chat::make(88888, ChatType::PRIVATE));

    $bot->hearCallbackQueryData('menu:admin:users')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('editMessageText', 0)
        ->assertCalled('sendMessage', 0);

    $admin = User::factory()->admin()->create();
    TelegramAccount::query()->create(['user_id' => $admin->id, 'telegram_user_id' => 99999]);
    $bot->setCommonUser(TelegramUser::make(99999, false, 'Admin'));
    $bot->setCommonChat(Chat::make(99999, ChatType::PRIVATE));

    $bot->hearCallbackQueryData('menu:admin:roles')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('editMessageText', 0);
});

it('allows a trusted Super Admin to navigate the role-management preview', function (): void {
    config()->set('super_admin.telegram_ids', ['121212', '343434']);

    $user = User::factory()->create();
    $user->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 121212]);

    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make(121212, false, 'Root'));
    $bot->setCommonChat(Chat::make(121212, ChatType::PRIVATE));

    $bot->hearCallbackQueryData('menu:admin:roles')->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText');

    config()->set('super_admin.telegram_ids', ['343434', '565656']);
    $bot->hearCallbackQueryData('menu:admin:roles')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('editMessageText', 0);
});

it('rechecks privileges after a role is revoked and refuses stale callbacks', function (): void {
    $user = User::factory()->admin()->create();
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 13579]);

    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make(13579, false, 'Former Admin'));
    $bot->setCommonChat(Chat::make(13579, ChatType::PRIVATE));

    $bot->hearCallbackQueryData('menu:admin:users')->reply()
        ->assertCalled('editMessageText');

    $user->syncRoles(['member']);

    $bot->hearCallbackQueryData('menu:admin:users')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('editMessageText', 0);
});

it('does not open protected settings for a deactivated account', function (): void {
    $user = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 24680]);
    $user->forceFill(['is_active' => false])->save();

    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make(24680, false, 'Disabled'));
    $bot->setCommonChat(Chat::make(24680, ChatType::PRIVATE));

    $bot->hearText('/settings')->reply()
        ->assertReplyText('🔒 حساب شما به DenaGate متصل نیست. برای اتصال، کد یک‌بارمصرف صادرشده توسط مدیر را در گفت‌وگوی خصوصی با دستور /link وارد کنید.');
});

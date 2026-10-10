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
        ->assertReplyText('🔒 حساب شما به SkyNet متصل نیست. برای اتصال، کد یک‌بارمصرف صادرشده توسط مدیر را در گفت‌وگوی خصوصی با دستور /link وارد کنید.');
});

it('renders an admin menu only after verified account linking', function (): void {
    $user = User::factory()->admin()->create();
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 22222]);

    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make(22222, false, 'Owner'));
    $bot->setCommonChat(Chat::make(22222, ChatType::PRIVATE));

    $bot->hearText('/admin')->reply()
        ->assertReplyText("🛡️ مدیریت SkyNet\n\nرابط تلگرام متصل است. عملیات مدیریتی در فازهای بعدی فعال خواهند شد.");
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

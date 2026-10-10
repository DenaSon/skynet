<?php

use App\Modules\Identity\Domain\UserRole;
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
    $user = User::factory()->create(['role' => UserRole::Admin]);
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 22222]);

    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make(22222, false, 'Owner'));
    $bot->setCommonChat(Chat::make(22222, ChatType::PRIVATE));

    $bot->hearText('/admin')->reply()
        ->assertReplyText("🛡️ مدیریت SkyNet\n\nرابط تلگرام متصل است. عملیات مدیریتی در فازهای بعدی فعال خواهند شد.");
});

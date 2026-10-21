<?php

use App\Interfaces\Telegram\Support\PendingReferral;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Referral\Infrastructure\Models\Referral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;

uses(RefreshDatabase::class);

function referralBot(int $telegramId): Nutgram
{
    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Referral'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

it('keeps signup disabled by default and never creates an unsolicited account on start', function (): void {
    $bot = referralBot(601001);

    $bot->hearText('/start')->reply()->assertReplyText(
        '🔒 حساب شما به DenaGate متصل نیست. برای اتصال، کد یک‌بارمصرف صادرشده توسط مدیر را در گفت‌وگوی خصوصی با دستور /link وارد کنید.'
    );
    $bot->hearCallbackQueryData('onboarding:join')->reply()->assertCalled('editMessageText');

    expect(User::query()->count())->toBe(0);
});

it('requires the user to click join after visiting a deep link', function (): void {
    config()->set('telegram.public_signup_enabled', true);
    config()->set('telegram.bot_username', 'DenaGate_Test_Bot');

    $referrer = User::factory()->create(['name' => 'Inviter']);
    $bot = referralBot(601002);
    TelegramAccount::query()->create(['user_id' => $referrer->id, 'telegram_user_id' => 601002]);

    $bot->hearText('/invite')->reply()
        ->assertCalled('sendMessage');

    $inviteCode = DB::table('referral_codes')->where('user_id', $referrer->id)->value('code');
    expect($inviteCode)->toBeString()->toHaveLength(24);

    $bot = referralBot(601003);
    $bot->hearText('/start ref_'.$inviteCode)->reply()
        ->assertCalled('sendMessage');

    expect(User::query()->count())->toBe(1);

    $bot->hearCallbackQueryData('onboarding:join')->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText');

    expect(User::query()->count())->toBe(2)
        ->and(Referral::query()->count())->toBe(1)
        ->and(Referral::query()->firstOrFail()->referrer_user_id)->toBe($referrer->id);
});

it('does not let a group chat register or claim a referral', function (): void {
    config()->set('telegram.public_signup_enabled', true);
    $bot = referralBot(601004);
    $bot->setCommonChat(Chat::make(-70000, ChatType::GROUP));

    $bot->hearText('/start ref_'.str_repeat('a', 24))->reply()->assertNoReply();
    $bot->hearCallbackQueryData('onboarding:join')->reply()->assertNoReply();

    expect(User::query()->count())->toBe(0)
        ->and(Referral::query()->count())->toBe(0);
});

it('lets existing linked admins keep their role and ignores later deep links', function (): void {
    config()->set('telegram.public_signup_enabled', true);
    $admin = User::factory()->admin()->create();
    TelegramAccount::query()->create(['user_id' => $admin->id, 'telegram_user_id' => 601005]);
    $bot = referralBot(601005);

    $bot->hearText('/start ref_'.str_repeat('f', 24))->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('onboarding:join')->reply()->assertCalled('editMessageText');

    expect(User::query()->count())->toBe(1)
        ->and($admin->fresh()->hasRole('admin'))->toBeTrue()
        ->and(Referral::query()->count())->toBe(0);
});

it('does not reuse an expired or invalid pending referral', function (): void {
    config()->set('telegram.public_signup_enabled', true);
    $bot = referralBot(601006);
    app(PendingReferral::class)->remember(601006, 'ref_'.str_repeat('a', 24));
    $this->travel(16)->minutes();

    $bot->hearCallbackQueryData('onboarding:join')->reply()->assertCalled('editMessageText');

    expect(Referral::query()->count())->toBe(0);
    expect(User::query()->count())->toBe(1);
});

it('does not expose referral links before bot username has been configured', function (): void {
    $user = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 601007]);
    $bot = referralBot(601007);

    $bot->hearText('/invite')->reply()->assertReplyText(
        'لینک دعوت هنوز آماده نیست. لطفاً به مدیر اطلاع دهید.'
    );

    expect(DB::table('referral_codes')->count())->toBe(0);
});

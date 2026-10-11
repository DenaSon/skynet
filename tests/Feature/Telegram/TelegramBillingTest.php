<?php

use App\Interfaces\Telegram\Support\BillingReviewTokens;
use App\Modules\Billing\Application\RequestTopup;
use App\Modules\Billing\Application\SubmitTopupReference;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function billingTelegramBot(int $id, User $user): Nutgram
{
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $id]);
    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($id, false, 'Operator'));
    $bot->setCommonChat(Chat::make($id, ChatType::PRIVATE));

    return $bot;
}

it('shows a reseller wallet and blocks topups when the deployment switch is disabled', function (): void {
    Role::findOrCreate('reseller', 'web');
    $reseller = User::factory()->create();
    $reseller->syncRoles(['member', 'reseller']);
    $bot = billingTelegramBot(777001, $reseller);

    $bot->hearText('/wallet')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('menu:wallet')->reply()->assertCalled('editMessageText');
    $bot->hearText('/topup 500000')->reply()->assertCalled('sendMessage');
    expect(DB::table('billing_topups')->count())->toBe(0);
});

it('accepts explicit reseller topup and payment reference submissions without auto-credit', function (): void {
    config()->set('billing.topups_enabled', true);
    Role::findOrCreate('reseller', 'web');
    $reseller = User::factory()->create();
    $reseller->syncRoles(['reseller']);
    $bot = billingTelegramBot(777002, $reseller);

    $bot->hearText('/topup 500000')->reply()->assertCalled('sendMessage');
    $id = (int) DB::table('billing_topups')->value('id');
    expect($id)->toBeGreaterThan(0)
        ->and(DB::table('billing_topups')->value('amount_rials'))->toBe(5000000);

    $bot->hearText("/paid {$id} RECEIPT12345")->reply()->assertCalled('sendMessage');
    expect(DB::table('billing_topups')->where('id', $id)->value('status'))->toBe('submitted')
        ->and(app(ViewWallet::class)->handle($reseller->id)->balanceRials)->toBe(0);
});

it('permits a trusted root to confirm an independently checked payment once', function (): void {
    config()->set('super_admin.telegram_ids', ['888001', '888002']);
    Role::findOrCreate('reseller', 'web');

    $reseller = User::factory()->create();
    $reseller->syncRoles(['reseller']);
    $id = app(RequestTopup::class)->handle($reseller->id, 4000000, 'tg:888001:12345');
    app(SubmitTopupReference::class)->handle($reseller->id, $id, 'TRANSFER1234');

    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $bot = billingTelegramBot(888001, $root);

    $bot->hearText('/topups')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData("billing:review:{$id}:a")->reply()->assertCalled('editMessageText');

    $token = app(BillingReviewTokens::class)->issue($root->id, $id, true, 'TRANSFER1234');
    $bot->hearCallbackQueryData('billing:apply:'.$token)->reply()->assertCalled('editMessageText');

    expect(app(ViewWallet::class)->handle($reseller->id)->balanceRials)->toBe(4000000);

    $bot->hearCallbackQueryData('billing:apply:'.$token)->reply()->assertCalled('editMessageText');
    expect(DB::table('billing_journals')->count())->toBe(1);
});

it('rejects unauthorized reviewers, forged callbacks and group chat commands', function (): void {
    $member = User::factory()->create();
    $bot = billingTelegramBot(777003, $member);

    $bot->hearText('/wallet')->reply()->assertReplyText('دسترسی مجاز نیست.');
    $bot->hearText('/topups')->reply()->assertReplyText('دسترسی مجاز نیست.');
    $bot->hearCallbackQueryData('menu:billing-reviews')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('editMessageText', 0);

    $bot->setCommonChat(Chat::make(-333333, ChatType::GROUP));
    $bot->hearText('/wallet')->reply()->assertNoReply();
    $bot->hearText('/topups')->reply()->assertNoReply();
    $bot->hearCallbackQueryData('billing:help')->reply()->assertNoReply();
});

it('cannot use a different root confirmation token or one older than three minutes', function (): void {
    config()->set('super_admin.telegram_ids', ['888101', '888102']);
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $bot = billingTelegramBot(888101, $root);

    $second = User::factory()->create();
    $second->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $second->id, 'telegram_user_id' => 888102]);

    $token = app(BillingReviewTokens::class)->issue($root->id, 1, true, 'TRANSFER0001');

    $bot->setCommonUser(TelegramUser::make(888102, false, 'Root two'));
    $bot->setCommonChat(Chat::make(888102, ChatType::PRIVATE));
    $bot->hearCallbackQueryData('billing:apply:'.$token)->reply()->assertCalled('editMessageText');

    $bot->setCommonUser(TelegramUser::make(888101, false, 'Root one'));
    $bot->setCommonChat(Chat::make(888101, ChatType::PRIVATE));
    $this->travel(4)->minutes();
    $bot->hearCallbackQueryData('billing:apply:'.$token)->reply()->assertCalled('editMessageText');

    expect(DB::table('billing_journals')->count())->toBe(0);
});

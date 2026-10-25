<?php

use App\Interfaces\Telegram\Support\UserStatusConfirmations;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function configureUserManagementBot(int $telegramId, User $user): Nutgram
{
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);

    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Operator'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

it('renders a real user list and navigates to details and search', function (): void {
    $admin = User::factory()->admin()->create();
    $bot = configureUserManagementBot(41234, $admin);
    $member = User::factory()->create(['name' => 'Directory Target']);

    $bot->hearCallbackQueryData('menu:admin:users')->reply()
        ->assertCalled('editMessageText')
        ->assertCalled('answerCallbackQuery');

    $bot->hearCallbackQueryData("users:view:{$member->id}:1:a")->reply()
        ->assertCalled('editMessageText')
        ->assertCalled('answerCallbackQuery');

    $bot->hearCallbackQueryData('users:search')->reply()
        ->assertCalled('editMessageText');

    $bot->hearText('/finduser Directory')->reply()
        ->assertCalled('sendMessage')
        ->assertCalled('sendMessage');
});

it('supports paging and persisted search context without leaking accounts', function (): void {
    $admin = User::factory()->admin()->create();
    User::factory()->count(15)->create(['name' => 'FilterMe']);
    $bot = configureUserManagementBot(42345, $admin);

    $bot->hearCallbackQueryData('users:page:2')->reply()
        ->assertCalled('editMessageText');

    $bot->hearText('/finduser FilterMe')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('users:findpage:2')->reply()
        ->assertCalled('editMessageText');

    Cache::forget('tg:users:search:'.$admin->id);
    $bot->hearCallbackQueryData('users:findpage:2')->reply()
        ->assertCalled('editMessageText')
        ->assertCalled('answerCallbackQuery');
});

it('requires an unexpired one-time user-bound confirmation for status changes', function (): void {
    $admin = User::factory()->admin()->create();
    $subject = User::factory()->create();
    $bot = configureUserManagementBot(43456, $admin);

    $bot->hearCallbackQueryData("users:confirm:{$subject->id}:1:a")->reply()
        ->assertCalled('editMessageText');

    $confirmation = app(UserStatusConfirmations::class);
    $token = $confirmation->issue($admin->id, $subject->id, true, 1);

    $bot->hearCallbackQueryData('users:apply:'.$token)->reply()
        ->assertCalled('editMessageText');

    expect($subject->fresh()->is_active)->toBeFalse()
        ->and(DB::table('identity_audit_events')->where('event', 'identity.user.status_changed')->count())->toBe(1);

    $bot->hearCallbackQueryData('users:apply:'.$token)->reply()
        ->assertCalled('editMessageText')
        ->assertCalled('answerCallbackQuery');

    expect(DB::table('identity_audit_events')->where('event', 'identity.user.status_changed')->count())->toBe(1);
});

it('cannot replay another admin confirmation or use an expired one', function (): void {
    $one = User::factory()->admin()->create();
    $two = User::factory()->admin()->create();
    $subject = User::factory()->create();

    $bot = configureUserManagementBot(44567, $two);
    $token = app(UserStatusConfirmations::class)->issue($one->id, $subject->id, true, 1);

    $bot->hearCallbackQueryData('users:apply:'.$token)->reply()
        ->assertCalled('editMessageText');

    expect($subject->fresh()->is_active)->toBeTrue();

    $bot->setCommonUser(TelegramUser::make(45678, false, 'First'));
    $bot->setCommonChat(Chat::make(45678, ChatType::PRIVATE));
    TelegramAccount::query()->create(['user_id' => $one->id, 'telegram_user_id' => 45678]);

    $this->travel(4)->minutes();
    $bot->hearCallbackQueryData('users:apply:'.$token)->reply()
        ->assertCalled('editMessageText');

    expect($subject->fresh()->is_active)->toBeTrue();
});

it('denies forged callbacks to members and revoked admins without side effects', function (): void {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    $bot = configureUserManagementBot(46789, $admin);
    $target = User::factory()->create();

    $token = app(UserStatusConfirmations::class)->issue($admin->id, $target->id, true, 1);
    $admin->syncRoles(['member']);

    $bot->hearCallbackQueryData("users:view:{$target->id}:1:a")->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('editMessageText', 0);

    $bot->hearCallbackQueryData('users:apply:'.$token)->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('editMessageText', 0);

    $bot->setCommonUser(TelegramUser::make(47890, false, 'Member'));
    $bot->setCommonChat(Chat::make(47890, ChatType::PRIVATE));
    TelegramAccount::query()->create(['user_id' => $member->id, 'telegram_user_id' => 47890]);

    $bot->hearText('/finduser Target')->reply()
        ->assertReplyText('دسترسی مجاز نیست.');

    expect($target->fresh()->is_active)->toBeTrue();
});

it('ignores administrative commands from group chats', function (): void {
    $admin = User::factory()->admin()->create();
    $bot = configureUserManagementBot(48901, $admin);
    $bot->setCommonChat(Chat::make(-80000, ChatType::GROUP));

    $bot->hearText('/users')->reply()->assertNoReply();
    $bot->hearCallbackQueryData('users:page:1')->reply()->assertNoReply();
});

it('rejects a status confirmation issued before a customer became a custom operator', function (): void {
    $admin = User::factory()->admin()->create();
    $subject = User::factory()->create();
    $bot = configureUserManagementBot(49701, $admin);
    $token = app(UserStatusConfirmations::class)->issue($admin->id, $subject->id, true, 1);
    $role = Role::findOrCreate('promoted-finance', 'web');
    $role->givePermissionTo('finance.view');
    $subject->syncRoles([$role]);

    $bot->hearCallbackQueryData("users:confirm:{$subject->id}:1:a")->reply()
        ->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('users:apply:'.$token)->reply()
        ->assertCalled('editMessageText');

    expect($subject->fresh()->is_active)->toBeTrue()
        ->and($subject->fresh()->hasRole($role))->toBeTrue()
        ->and(DB::table('identity_audit_events')->count())->toBe(0);
});

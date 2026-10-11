<?php

use App\Interfaces\Telegram\Support\RbacConfirmations;
use App\Modules\Identity\Application\CreateRole;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;

uses(RefreshDatabase::class);

function prepareRbacBot(): array
{
    config()->set('super_admin.telegram_ids', ['757575', '858585']);
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 757575]);

    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make(757575, false, 'Root'));
    $bot->setCommonChat(Chat::make(757575, ChatType::PRIVATE));

    return [$root, $bot];
}

it('lists roles and opens the create-role help screen from a trusted root', function (): void {
    [$root, $bot] = prepareRbacBot();

    $bot->hearCallbackQueryData('menu:admin:roles')->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText');

    $bot->hearCallbackQueryData('rbac:new')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('rbac:page:1')->reply()->assertCalled('editMessageText');

    expect($root->hasRole('super-admin'))->toBeTrue();
});

it('creates a role through one-time confirmation but cannot replay the creation', function (): void {
    [$root, $bot] = prepareRbacBot();

    $bot->hearText('/newrole reseller')->reply()->assertCalled('sendMessage');

    $token = app(RbacConfirmations::class)->issue($root->id, 'create', name: 'reseller');
    $bot->hearCallbackQueryData('rbac:apply:'.$token)->reply()->assertCalled('editMessageText');

    expect(Role::query()->where('name', 'reseller')->exists())->toBeTrue();

    $bot->hearCallbackQueryData('rbac:apply:'.$token)->reply()->assertCalled('editMessageText');

    expect(Role::query()->where('name', 'reseller')->count())->toBe(1);
});

it('can toggle custom permissions and user roles using confirmed callbacks', function (): void {
    [$root, $bot] = prepareRbacBot();
    $role = app(CreateRole::class)->handle($root, 'reseller');
    $permission = Permission::findByName('users.manage', 'web');
    $target = User::factory()->create();

    $bot->hearCallbackQueryData("rbac:perm:{$role->id}:{$permission->id}")->reply()->assertCalled('editMessageText');

    $token = app(RbacConfirmations::class)->issue($root->id, 'permission', $role->id, $permission->id, false);
    $bot->hearCallbackQueryData('rbac:apply:'.$token)->reply()->assertCalled('editMessageText');

    expect($role->fresh()->hasPermissionTo('users.manage'))->toBeTrue();

    $bot->hearCallbackQueryData("rbac:user:{$target->id}:1")->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData("rbac:member:{$target->id}:{$role->id}:1")->reply()->assertCalled('editMessageText');

    $token = app(RbacConfirmations::class)->issue($root->id, 'member', $target->id, $role->id, false);
    $bot->hearCallbackQueryData('rbac:apply:'.$token)->reply()->assertCalled('editMessageText');

    expect($target->fresh()->hasRole('reseller'))->toBeTrue();
    expect(DB::table('identity_audit_events')->where('event', 'identity.roles.assigned')->exists())->toBeTrue();
});

it('rejects denied administrative callbacks even when forged manually', function (): void {
    [$root, $bot] = prepareRbacBot();
    $member = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $member->id, 'telegram_user_id' => 969696]);
    $bot->setCommonUser(TelegramUser::make(969696, false, 'Member'));
    $bot->setCommonChat(Chat::make(969696, ChatType::PRIVATE));

    $bot->hearCallbackQueryData('rbac:page:1')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('editMessageText', 0);

    $bot->hearText('/newrole privileged')->reply()
        ->assertReplyText('دسترسی مجاز نیست.');

    expect(Role::query()->where('name', 'privileged')->exists())->toBeFalse();
});

it('rejects a stolen confirmation and a root whose ENV access was revoked', function (): void {
    [$root, $bot] = prepareRbacBot();
    $other = User::factory()->admin()->create();
    TelegramAccount::query()->create(['user_id' => $other->id, 'telegram_user_id' => 989898]);
    $token = app(RbacConfirmations::class)->issue($root->id, 'create', name: 'reseller');

    $bot->setCommonUser(TelegramUser::make(989898, false, 'Admin'));
    $bot->setCommonChat(Chat::make(989898, ChatType::PRIVATE));

    $bot->hearCallbackQueryData('rbac:apply:'.$token)->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('editMessageText', 0);

    $bot->setCommonUser(TelegramUser::make(757575, false, 'Root'));
    $bot->setCommonChat(Chat::make(757575, ChatType::PRIVATE));

    config()->set('super_admin.telegram_ids', ['858585', '989898']);
    $bot->hearCallbackQueryData('rbac:apply:'.$token)->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('editMessageText', 0);

    expect(Role::query()->where('name', 'reseller')->exists())->toBeFalse();
});

it('blocks protected root roles and ignores group callbacks', function (): void {
    [$root, $bot] = prepareRbacBot();
    $superRole = Role::findByName('super-admin', 'web');
    $target = User::factory()->create();

    $bot->hearCallbackQueryData("rbac:perm:{$superRole->id}:1")->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData("rbac:member:{$target->id}:{$superRole->id}:1")->reply()->assertCalled('editMessageText');

    expect($target->fresh()->hasRole('super-admin'))->toBeFalse();

    $bot->setCommonChat(Chat::make(-1234567, ChatType::GROUP));
    $bot->hearCallbackQueryData('rbac:page:1')->reply()->assertNoReply();
});

<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['994801', '994899']);
    Http::preventStrayRequests();
});

/** @return array<string,mixed> */
function adminNav2Payload(NutgramRequest $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $data;
}

function adminNav2Has(NutgramRequest $request, string $callback): bool
{
    $data = adminNav2Payload($request);

    return str_contains(json_encode($data['reply_markup'] ?? [], JSON_THROW_ON_ERROR), '"callback_data":"'.$callback.'"');
}

function adminNav2Bot(User $user, int $telegramId): Nutgram
{
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Operator'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

it('returns from root catalog, roles and servers to the control panel without removing existing actions', function (): void {
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $bot = adminNav2Bot($root, 994801);

    $bot->hearCallbackQueryData('menu:admin:sales')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool =>
            adminNav2Has($r, 'menu:admin:control')
            && adminNav2Has($r, 'menu:admin:products')
            && adminNav2Has($r, 'menu:admin:plans')
            && str_contains((string) (adminNav2Payload($r)['text'] ?? ''), 'کاتالوگ'), index: 1);

    $bot->hearCallbackQueryData('menu:admin:roles')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool =>
            adminNav2Has($r, 'menu:admin:control')
            && adminNav2Has($r, 'rbac:new'), index: 1);

    config()->set('services.remnawave.url', null);
    config()->set('services.remnawave.token', null);
    Http::fake();
    $bot->hearCallbackQueryData('menu:admin:servers')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool =>
            adminNav2Has($r, 'menu:admin:control')
            && adminNav2Has($r, 'menu:admin:panelusers')
            && adminNav2Has($r, 'servers:refresh'), index: 1);

    // Invalid pages never reach the remote provider and still have a visible way back.
    $bot->hearCallbackQueryData('rw:users:page:9999')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool =>
            adminNav2Has($r, 'menu:admin:servers'), index: 1);

    Http::assertNothingSent();
});

it('moves the root notification shortcut under system settings while retaining delegated access', function (): void {
    config()->set('telegram_notifications.admin_topic.enabled', true);

    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $rootBot = adminNav2Bot($root, 994801);
    $rootBot->hearCallbackQueryData('menu:admin')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool =>
            adminNav2Has($r, 'menu:admin:control')
            && ! adminNav2Has($r, 'admin:notice-topic:show'), index: 1);

    $rootBot->hearCallbackQueryData('admin:welcome:show')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool =>
            adminNav2Has($r, 'admin:notice-topic:show')
            && adminNav2Has($r, 'admin:ops:show')
            && adminNav2Has($r, 'menu:admin:control'), index: 1);
    $rootBot->hearCallbackQueryData('admin:notice-topic:show')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool =>
            adminNav2Has($r, 'admin:welcome:show'), index: 1);

    $role = Role::findOrCreate('finance-nav-operator', 'web');
    foreach (['purchases.view', 'purchases.review'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $operator = User::factory()->create();
    $operator->assignRole($role);
    $operatorBot = adminNav2Bot($operator, 994802);

    $operatorBot->hearCallbackQueryData('menu:admin')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool =>
            adminNav2Has($r, 'admin:notice-topic:show')
            && ! adminNav2Has($r, 'menu:admin:control'), index: 1);
    $operatorBot->hearCallbackQueryData('admin:notice-topic:show')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool =>
            adminNav2Has($r, 'menu:admin')
            && ! adminNav2Has($r, 'admin:welcome:show'), index: 1);

    $operatorBot->hearCallbackQueryData('menu:admin:control')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
    Http::assertNothingSent();
});

it('keeps delegated catalog navigation returning to the accessible Admin home', function (): void {
    $role = Role::findOrCreate('catalog-nav-operator', 'web');
    $role->givePermissionTo(Permission::findOrCreate('plans.manage', 'web'));
    $operator = User::factory()->create();
    $operator->assignRole($role);
    $bot = adminNav2Bot($operator, 994803);

    $bot->hearCallbackQueryData('menu:admin:sales')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool =>
            adminNav2Has($r, 'menu:admin')
            && ! adminNav2Has($r, 'menu:admin:control')
            && adminNav2Has($r, 'menu:admin:plans'), index: 1);
    Http::assertNothingSent();
});

<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContacts;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function adminTopicBot(User $user, int $telegramId): Nutgram
{
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);
    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Admin'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

beforeEach(function (): void {
    config()->set('telegram_notifications.admin_topic.enabled', true);
    config()->set('super_admin.telegram_ids', ['981001', '981002']);
    config()->set('nutgram.token', '12345:admin_test');
    Http::preventStrayRequests();
    Http::fake(['https://api.telegram.org/*' => Http::response([
        'ok' => true, 'result' => ['message_thread_id' => 74],
    ])]);
});

it('creates exactly one private Admin topic for a trusted root and persists its thread', function (): void {
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $bot = adminTopicBot($root, 981001);

    $bot->hearCallbackQueryData('admin:notice-topic:enable')->reply()->assertCalled('editMessageText');
    expect((int) DB::table('telegram_bot_contacts')->where('user_id', $root->id)
        ->where('bot_key', 'admin')->value('admin_notification_thread_id'))->toBe(74);

    $bot->hearCallbackQueryData('admin:notice-topic:enable')->reply()->assertCalled('editMessageText');
    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $request): bool => str_ends_with($request->url(), '/createForumTopic')
        && (string) $request['chat_id'] === '981001'
        && $request['name'] === '🔔 اعلان‌های مدیریت');
});

it('allows an actual finance reviewer with current view and review permissions', function (): void {
    $role = Role::firstOrCreate(['name' => 'FinanceManager', 'guard_name' => 'web']);
    foreach (['purchases.view', 'purchases.review'] as $permission) {
        $role->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
    }
    $manager = User::factory()->create();
    $manager->assignRole($role);
    $bot = adminTopicBot($manager, 981021);

    $bot->hearCallbackQueryData('admin:notice-topic:enable')->reply()->assertCalled('editMessageText');

    expect((int) DB::table('telegram_bot_contacts')->where('user_id', $manager->id)
        ->where('bot_key', 'admin')->value('admin_notification_thread_id'))->toBe(74);
    Http::assertSentCount(1);
});

it('refuses unprivileged, revoked, and disabled requests before contacting Telegram', function (): void {
    $member = User::factory()->create();
    $bot = adminTopicBot($member, 981031);
    $bot->hearCallbackQueryData('admin:notice-topic:enable')->reply()->assertCalled('editMessageText');

    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $rootBot = adminTopicBot($root, 981001);
    $root->forceFill(['is_active' => false])->save();
    $rootBot->hearCallbackQueryData('admin:notice-topic:enable')->reply()->assertCalled('editMessageText');

    $root->forceFill(['is_active' => true])->save();
    config()->set('telegram_notifications.admin_topic.enabled', false);
    $rootBot->hearCallbackQueryData('admin:notice-topic:enable')->reply()->assertCalled('editMessageText');

    Http::assertSentCount(0);
    expect(DB::table('telegram_bot_contacts')->count())->toBe(0);
});

it('does not blindly repeat a possibly successful Telegram create after transport failure', function (): void {
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => false], 500)]);
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $bot = adminTopicBot($root, 981001);

    $bot->hearCallbackQueryData('admin:notice-topic:enable')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('admin:notice-topic:enable')->reply()->assertCalled('editMessageText');

    Http::assertSentCount(1);
    expect((int) DB::table('telegram_bot_contacts')->where('user_id', $root->id)
        ->where('bot_key', 'admin')->value('admin_notification_thread_id'))->toBe(0);
});

it('disables topic binding on Telegram chat relinking without erasing the contact', function (): void {
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    adminTopicBot($root, 981001);

    DB::table('telegram_bot_contacts')->insert([
        'user_id' => $root->id, 'bot_key' => 'admin', 'chat_id' => 981001,
        'delivery_status' => 'reachable', 'admin_notification_thread_id' => 74,
        'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    // A different linked Telegram account should not inherit the old private topic.
    TelegramAccount::query()->where('user_id', $root->id)->update(['telegram_user_id' => 981002]);
    $request = Illuminate\Http\Request::create('/api/telegram/admin/webhook', 'POST', [
        'message' => [
            'from' => ['id' => 981002],
            'chat' => ['id' => 981002, 'type' => 'private'],
        ],
    ]);
    app(TelegramBotContacts::class)->record($request, 'admin');

    $row = DB::table('telegram_bot_contacts')->where('user_id', $root->id)->where('bot_key', 'admin')->first();
    expect((int) $row->chat_id)->toBe(981002)
        ->and($row->admin_notification_thread_id)->toBeNull();
});

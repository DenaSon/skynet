<?php

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function mb06aLegacyActor(int $telegramId, User $user): Nutgram
{
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);

    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Acceptance'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

it('preserves linked Member and Reseller read-only legacy paths while denying privileged actions', function (): void {
    config()->set('telegram.hybrid_menu_enabled', true);
    config()->set('telegram.sales.enabled', false);
    config()->set('telegram_notifications.enabled', false);
    config()->set('sales.manual_bank_review_enabled', true);
    Http::preventStrayRequests();
    Http::fake();

    $member = User::factory()->create(['name' => 'Acceptance Member']);
    $memberBot = mb06aLegacyActor(990601, $member);

    $memberBot->hearText('/start')->reply()
        ->assertReplyText("🏠 ParsaGate\n\nخوش آمدید، Acceptance Member.");
    foreach (['/buy', '/catalog', '/orders', '/subscriptions'] as $command) {
        $memberBot->hearText($command)->reply()->assertCalled('sendMessage');
    }
    foreach (['/admin', '/roles', '/payments', '/builder', '/servers'] as $command) {
        $memberBot->hearText($command)->reply()->assertReplyText('دسترسی مجاز نیست.');
    }
    $memberBot->hearCallbackQueryData('menu:admin:roles')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);

    Role::findOrCreate('reseller', 'web');
    $reseller = User::factory()->create(['name' => 'Acceptance Reseller']);
    $reseller->assignRole('reseller');
    $resellerBot = mb06aLegacyActor(990602, $reseller);

    $resellerBot->hearText('/start')->reply()
        ->assertReplyText("🏠 ParsaGate\n\nخوش آمدید، Acceptance Reseller.");
    $resellerBot->hearText('/builder')->reply()->assertCalled('sendMessage');
    $resellerBot->hearText('/catalog')->reply()->assertCalled('sendMessage');
    $resellerBot->hearText('/admin')->reply()->assertReplyText('دسترسی مجاز نیست.');

    $reseller->syncRoles(['member']);
    $resellerBot->hearText('/builder')->reply()->assertReplyText('دسترسی مجاز نیست.');
    $resellerBot->hearCallbackQueryData('builder:list:1')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);

    expect(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('sales_bank_settlements')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps ordinary Admin read navigation without granting trusted root privileges after stale callbacks', function (): void {
    config()->set('telegram.hybrid_menu_enabled', false);
    config()->set('sales.manual_bank_review_enabled', true);
    Http::preventStrayRequests();
    Http::fake();

    $admin = User::factory()->admin()->create();
    $bot = mb06aLegacyActor(990603, $admin);
    $bot->hearText('/start')->reply()
        ->assertReplyText("🛡️ مدیریت ParsaGate\n\nبخش موردنظر را انتخاب کنید.");
    $bot->hearText('/users')->reply()->assertCalled('sendMessage');
    $bot->hearText('/roles')->reply()->assertReplyText('دسترسی مجاز نیست.');
    $bot->hearText('/payments')->reply()->assertReplyText('دسترسی مجاز نیست.');
    $bot->hearCallbackQueryData('menu:admin:finance')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);

    $admin->syncRoles(['member']);
    $bot->hearCallbackQueryData('menu:admin:users')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
    $bot->hearText('/admin')->reply()->assertReplyText('دسترسی مجاز نیست.');

    expect(DB::table('sales_bank_settlements')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});

it('runs one existing queued notification job through the real database worker without live delivery', function (): void {
    config()->set('telegram_notifications.enabled', false);
    config()->set('telegram.sales.enabled', false);
    Http::preventStrayRequests();
    Http::fake();

    DispatchTelegramNotification::dispatch(990699)->onConnection('database');

    expect(DB::table('jobs')->count())->toBe(1);

    expect(Artisan::call('queue:work', [
        'connection' => 'database',
        '--queue' => 'default',
        '--once' => true,
        '--tries' => 1,
        '--timeout' => 15,
        '--sleep' => 0,
    ]))->toBe(0);

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(0);
    Http::assertNothingSent();
});

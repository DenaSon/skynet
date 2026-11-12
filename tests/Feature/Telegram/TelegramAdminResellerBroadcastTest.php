<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['995501']);
    config()->set('telegram_notifications.enabled', true);
    config()->set('telegram.sales.enabled', true);
    config()->set('nutgram.token', '123:admin_test');
    config()->set('telegram.sales.token', '456:sales_test');
    Role::findOrCreate('reseller', 'web');
    Role::findOrCreate('super-admin', 'web');
    Http::preventStrayRequests();
    Http::fake();
});

function annBroadcastBot(User $user, int $tg): Nutgram
{
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $tg]);
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    require base_path('app/Interfaces/Telegram/routes/legacy_text.php');
    $bot->setCommonUser(TelegramUser::make($tg, false, 'Operator'));
    $bot->setCommonChat(Chat::make($tg, ChatType::PRIVATE));

    return $bot;
}

it('offers root-only reseller announcement compose, one-use confirmation and status without public publishing', function (): void {
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $bot = annBroadcastBot($root, 995501);

    $reseller = User::factory()->create();
    $reseller->syncRoles(['reseller']);
    TelegramAccount::query()->create(['user_id' => $reseller->id, 'telegram_user_id' => 995611]);
    DB::table('telegram_bot_contacts')->insert([
        'user_id' => $reseller->id,
        'bot_key' => 'sales',
        'chat_id' => 995611,
        'delivery_status' => 'reachable',
        'last_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $bot->hearCallbackQueryData('admin:ann:list:1')->reply()->assertRaw(
        static function (NutgramRequest $request): bool {
            $data = FakeNutgram::getActualData($request);
            return str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'admin:ann:reseller:new');
        }, index: 1,
    );
    $bot->hearCallbackQueryData('admin:ann:reseller:new')->reply()->assertCalled('editMessageText');
    expect(Cache::get('admin:ann:draft:'.$root->id)['mode'])->toBe('reseller');

    $bot->hearText('تغییرات ویژه <نمایندگان>')->reply();
    $bot->hearText("متن مخصوص نمایندگان\nخط دوم")->reply();
    $draft = Cache::get('admin:ann:draft:'.$root->id);
    expect($draft['step'])->toBe('preview')
        ->and(DB::table('announcements')->count())->toBe(0)
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(0);

    $bot->hearCallbackQueryData('admin:ann:reseller:prepare:'.$draft['token'])->reply()
        ->assertCalled('editMessageText');
    expect(Cache::get('admin:ann:draft:'.$root->id)['step'])->toBe('confirm-reseller');

    $bot->hearCallbackQueryData('admin:ann:reseller:commit:'.$draft['token'])->reply()
        ->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('admin:ann:reseller:commit:'.$draft['token'])->reply()
        ->assertCalled('editMessageText');

    expect(DB::table('announcements')->count())->toBe(1)
        ->and(DB::table('announcements')->value('status'))->toBe('reseller_sent')
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(1);

    $bot->hearCallbackQueryData('admin:ann:view:1')->reply()->assertRaw(
        static function (NutgramRequest $request): bool {
            $data = FakeNutgram::getActualData($request);
            $markup = json_encode($data['reply_markup'], JSON_THROW_ON_ERROR);
            return ! str_contains($markup, 'admin:ann:edit:1')
                && ! str_contains($markup, 'admin:ann:prepare:1:publish');
        }, index: 1,
    );

    Http::assertNothingSent();
});

it('denies broadcast callbacks after losing trusted root access', function (): void {
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $bot = annBroadcastBot($root, 995501);

    $bot->hearCallbackQueryData('admin:ann:reseller:new')->reply();
    $bot->hearText('خبر نمایندگان')->reply();
    $bot->hearText('متن خبر')->reply();
    $token = Cache::get('admin:ann:draft:'.$root->id)['token'];

    $root->syncRoles(['reseller']);
    $bot->hearCallbackQueryData('admin:ann:reseller:prepare:'.$token)->reply();
    $bot->hearCallbackQueryData('admin:ann:reseller:commit:'.$token)->reply();
    expect(DB::table('announcements')->count())->toBe(0)
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(0);
    Http::assertNothingSent();
});

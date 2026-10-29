<?php

use App\Interfaces\Telegram\Support\UserStatusConfirmations;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
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
    User::factory()->count(25)->create(['name' => 'FilterMe']);
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

it('renders twenty newest-first users as two-column buttons and filters real resellers', function (): void {
    $admin = User::factory()->admin()->create();
    $bot = configureUserManagementBot(50101, $admin);
    Role::findOrCreate('reseller', 'web');
    $older = User::factory()->create(['name' => 'Older reseller']);
    $older->syncRoles(['reseller']);
    User::factory()->count(22)->create();
    $newer = User::factory()->create(['name' => 'Newer reseller']);
    $newer->syncRoles(['reseller']);

    $bot->hearCallbackQueryData('menu:admin:users')->reply()->assertRaw(
        static function (Request $request) use ($newer): bool {
            $data = FakeNutgram::getActualData($request);
            $markup = is_string($data['reply_markup'] ?? null)
                ? json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR)
                : ($data['reply_markup'] ?? []);
            $rows = $markup['inline_keyboard'] ?? [];
            $users = array_values(array_filter($rows, static fn (array $row): bool => str_starts_with((string) ($row[0]['callback_data'] ?? ''), 'users:view:')));
            $text = (string) ($data['text'] ?? '');

            return count($users) === 10
                && count(array_filter($users, static fn (array $row): bool => count($row) === 2)) === 10
                && str_contains((string) $users[0][0]['callback_data'], 'users:view:'.$newer->id.':1:a')
                && str_contains($text, 'صفحه 1 از 2')
                && ! str_contains($text, 'Newer reseller')
                && str_contains(json_encode($markup, JSON_THROW_ON_ERROR), 'users:resellers:1');
        },
        index: 1,
    );

    $bot->hearCallbackQueryData('users:resellers:1')->reply()->assertRaw(
        static function (Request $request) use ($newer, $older): bool {
            $data = FakeNutgram::getActualData($request);
            $markup = is_string($data['reply_markup'] ?? null)
                ? json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR)
                : ($data['reply_markup'] ?? []);
            $rows = $markup['inline_keyboard'] ?? [];
            $callbacks = collect($rows)->flatMap(static fn (array $row) => array_map(static fn (array $button) => $button['callback_data'] ?? '', $row))->all();

            return str_contains((string) ($data['text'] ?? ''), 'تعداد: 2')
                && in_array('users:view:'.$newer->id.':1:r', $callbacks, true)
                && in_array('users:view:'.$older->id.':1:r', $callbacks, true)
                && ! in_array('users:resellers:2', $callbacks, true);
        },
        index: 1,
    );

    $bot->hearCallbackQueryData('users:view:'.$newer->id.':1:r')->reply()->assertRaw(
        static function (Request $request) use ($newer): bool {
            $data = FakeNutgram::getActualData($request);

            return str_contains((string) ($data['reply_markup'] ?? ''), 'users:profile:'.$newer->id.':1:r')
                && str_contains((string) ($data['reply_markup'] ?? ''), 'users:resellers:1');
        },
        index: 1,
    );
});

it('opens native ForceReply search, consumes plain text once and preserves /finduser', function (): void {
    $admin = User::factory()->admin()->create();
    $bot = configureUserManagementBot(50201, $admin);
    $subject = User::factory()->create(['name' => 'UniqueInlineSearchName']);

    $bot->hearCallbackQueryData('users:search')->reply()->assertCalled('sendMessage');
    expect(Cache::has('tg:users:search-prompt:'.$admin->id))->toBeTrue();

    $bot->hearText('UniqueInlineSearchName')->reply()->assertCalled('sendMessage');
    expect(Cache::has('tg:users:search-prompt:'.$admin->id))->toBeFalse()
        ->and(Cache::get('tg:users:search:'.$admin->id))->toBe('UniqueInlineSearchName');

    $bot->hearCallbackQueryData('users:findpage:1')->reply()->assertRaw(
        static function (Request $request) use ($subject): bool {
            $data = FakeNutgram::getActualData($request);

            return str_contains((string) ($data['reply_markup'] ?? ''), 'users:view:'.$subject->id.':1:s');
        },
        index: 1,
    );

    $bot->hearCallbackQueryData('users:search')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('users:cancelsearch')->reply()->assertCalled('editMessageText');
    expect(Cache::has('tg:users:search-prompt:'.$admin->id))->toBeFalse();

    $bot->hearText('/finduser UniqueInlineSearchName')->reply()->assertCalled('sendMessage');
});

it('keeps reseller list context across protected status confirmation and refuses revoked callbacks', function (): void {
    $admin = User::factory()->admin()->create();
    $bot = configureUserManagementBot(50301, $admin);
    Role::findOrCreate('reseller', 'web');
    $reseller = User::factory()->create();
    $reseller->syncRoles(['reseller']);

    $bot->hearCallbackQueryData('users:confirm:'.$reseller->id.':1:r')->reply()
        ->assertCalled('editMessageText');
    $token = app(UserStatusConfirmations::class)->issue($admin->id, $reseller->id, true, 1, false, true);
    $bot->hearCallbackQueryData('users:apply:'.$token)->reply()->assertRaw(
        static function (Request $request) use ($reseller): bool {
            $data = FakeNutgram::getActualData($request);

            return str_contains((string) ($data['reply_markup'] ?? ''), 'users:resellers:1')
                && str_contains((string) ($data['reply_markup'] ?? ''), 'users:profile:'.$reseller->id.':1:r');
        },
        index: 1,
    );

    $admin->syncRoles(['member']);
    $bot->hearCallbackQueryData('users:resellers:1')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
});

<?php

use App\Interfaces\Telegram\Support\MenuRenderer;
use App\Interfaces\Telegram\Support\UserStatusConfirmations;
use App\Modules\Identity\Application\BrowseUsers;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
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

/** @param array<string,mixed> $payload */
function userManagementRichHtml(array $payload): string
{
    $rich = is_string($payload['rich_message'] ?? null)
        ? json_decode($payload['rich_message'], true, 512, JSON_THROW_ON_ERROR)
        : ($payload['rich_message'] ?? []);

    return ($rich['is_rtl'] ?? null) === true ? (string) ($rich['html'] ?? '') : '';
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

it('shows a safe RTL Rich identity table and hides only Telegram-generated placeholder emails', function (): void {
    $admin = User::factory()->admin()->create();
    $bot = configureUserManagementBot(50601, $admin);
    $placeholder = 'telegram-'.str_repeat('ab', 16).'@accounts.skynet.invalid';
    $subject = User::factory()->create(['name' => 'Name <script>alert(1)</script>', 'email' => $placeholder]);
    TelegramAccount::query()->create(['user_id' => $subject->id, 'telegram_user_id' => 987650601]);

    $bot->hearCallbackQueryData("users:view:{$subject->id}:2:s")->reply()->assertRaw(
        static function (Request $request) use ($subject, $placeholder): bool {
            $payload = FakeNutgram::getActualData($request);
            $html = userManagementRichHtml($payload);
            $buttons = is_string($payload['reply_markup'] ?? null)
                ? $payload['reply_markup']
                : json_encode($payload['reply_markup'] ?? [], JSON_THROW_ON_ERROR);

            return str_contains($html, '<h2>👤 جزئیات کاربر</h2>')
                && str_contains($html, '<table bordered striped compact>')
                && str_contains($html, '<tr><td>ایمیل</td><td>ایمیل ثبت نشده</td></tr>')
                && str_contains($html, '<tr><td>تلگرام</td><td>متصل</td></tr>')
                && str_contains($html, '&lt;script&gt;')
                && ! str_contains($html, '<script>')
                && ! str_contains($html, $placeholder)
                && str_contains($html, '<footer>💡 ')
                && str_contains($buttons, "users:profile:{$subject->id}:2:s")
                && str_contains($buttons, "users:confirm:{$subject->id}:2:s")
                && str_contains($buttons, 'users:findpage:2')
                && str_contains($buttons, 'menu:admin');
        },
        index: 1,
    );
    expect($subject->fresh()->email)->toBe($placeholder);

    $real = User::factory()->create(['email' => 'customer@example.com']);
    $bot->hearCallbackQueryData("users:view:{$real->id}:1:a")->reply()->assertRaw(
        static function (Request $request): bool {
            $html = userManagementRichHtml(FakeNutgram::getActualData($request));

            return str_contains($html, '<tr><td>ایمیل</td><td>customer@example.com</td></tr>')
                && ! str_contains($html, 'ایمیل ثبت نشده');
        },
        index: 1,
    );

    // A lookalike address is not one of the 32-hex Telegram placeholders.
    $lookalike = User::factory()->create(['email' => 'telegram-customer@accounts.skynet.invalid']);
    $bot->hearCallbackQueryData("users:view:{$lookalike->id}:1:a")->reply()->assertRaw(
        static function (Request $request): bool {
            $html = userManagementRichHtml(FakeNutgram::getActualData($request));

            return str_contains($html, 'telegram-customer@accounts.skynet.invalid');
        },
        index: 1,
    );
});

it('opens an HTTPS username link from a reachable Sales contact and hides it for unlinked users', function (): void {
    $admin = User::factory()->admin()->create();
    $bot = configureUserManagementBot(50401, $admin);
    $linked = User::factory()->create();
    $unlinked = User::factory()->create();
    $telegramUserId = 123456789012;

    TelegramAccount::query()->create([
        'user_id' => $linked->id,
        'telegram_user_id' => $telegramUserId,
    ]);
    DB::table('telegram_bot_contacts')->insert([
        'user_id' => $linked->id,
        'bot_key' => 'sales',
        'chat_id' => $telegramUserId,
        'delivery_status' => 'reachable',
        'last_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    config()->set('telegram.sales.token', 'test-sales-bot-token');
    Http::fake([
        'https://api.telegram.org/bottest-sales-bot-token/getChat*' => Http::response([
            'ok' => true,
            'result' => ['id' => $telegramUserId, 'username' => 'Helmabanoo52'],
        ], 200),
    ]);

    $bot->hearCallbackQueryData("users:view:{$linked->id}:1:a")->reply()->assertRaw(
        static function (Request $request): bool {
            $data = FakeNutgram::getActualData($request);
            $markup = is_string($data['reply_markup'] ?? null)
                ? json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR)
                : ($data['reply_markup'] ?? []);
            $buttons = collect($markup['inline_keyboard'] ?? [])
                ->flatMap(static fn (array $row): array => $row);
            $contact = $buttons->first(static fn (array $button): bool => ($button['text'] ?? '') === '💬 ارتباط با کاربر');

            return $contact !== null
                && ($contact['url'] ?? null) === 'https://t.me/Helmabanoo52'
                && ! isset($contact['callback_data'])
                && str_contains(userManagementRichHtml($data), '<h2>👤 جزئیات کاربر</h2>');
        },
        index: 1,
    );

    Http::assertSent(static fn (Illuminate\Http\Client\Request $request): bool => $request->method() === 'GET'
        && $request['chat_id'] === $telegramUserId);

    $bot->hearCallbackQueryData("users:view:{$unlinked->id}:1:a")->reply()->assertRaw(
        static function (Request $request): bool {
            $data = FakeNutgram::getActualData($request);
            $markup = is_string($data['reply_markup'] ?? null)
                ? json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR)
                : ($data['reply_markup'] ?? []);

            return ! str_contains(json_encode($markup, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'ارتباط با کاربر');
        },
        index: 1,
    );

    Http::assertSentCount(1);
});

it('keeps admin details usable when username lookup is unavailable or invalid', function (): void {
    $admin = User::factory()->admin()->create();
    $bot = configureUserManagementBot(50501, $admin);
    $subject = User::factory()->create();
    $telegramUserId = 9876543210;

    TelegramAccount::query()->create([
        'user_id' => $subject->id,
        'telegram_user_id' => $telegramUserId,
    ]);

    config()->set('telegram.sales.token', 'test-sales-bot-token');
    Http::fake();
    $bot->hearCallbackQueryData("users:view:{$subject->id}:1:a")->reply()->assertRaw(
        static function (Request $request) use ($subject): bool {
            $data = FakeNutgram::getActualData($request);

            return str_contains(userManagementRichHtml($data), '<h2>👤 جزئیات کاربر</h2>')
                && str_contains((string) ($data['reply_markup'] ?? ''), 'users:profile:'.$subject->id)
                && ! str_contains((string) ($data['reply_markup'] ?? ''), 'ارتباط با کاربر');
        },
        index: 1,
    );
    Http::assertNothingSent();

    DB::table('telegram_bot_contacts')->insert([
        'user_id' => $subject->id,
        'bot_key' => 'sales',
        'chat_id' => $telegramUserId,
        'delivery_status' => 'reachable',
        'last_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Http::fake([
        'https://api.telegram.org/bottest-sales-bot-token/getChat*' => Http::response([
            'ok' => true,
            'result' => ['id' => $telegramUserId, 'username' => 'bad/username'],
        ]),
    ]);

    $bot->hearCallbackQueryData("users:view:{$subject->id}:1:a")->reply()->assertRaw(
        static function (Request $request): bool {
            $data = FakeNutgram::getActualData($request);

            return str_contains(userManagementRichHtml($data), '<h2>👤 جزئیات کاربر</h2>')
                && ! str_contains((string) ($data['reply_markup'] ?? ''), 'ارتباط با کاربر');
        },
        index: 1,
    );
});

it('finds the local owner by sk_ username using the existing Admin search and profile routes', function (): void {
    $admin = User::factory()->admin()->create();
    $bot = configureUserManagementBot(50607, $admin);
    $owner = User::factory()->create(['name' => 'Subscription owner']);
    $trialId = DB::table('trial_claims')->insertGetId([
        'user_id' => $owner->id,
        'telegram_user_id' => 978654322,
        'phone_fingerprint' => hash('sha256', 'admin-lookup-trial'),
        'state' => 'needs_review',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $reference = 'free-trial:'.$trialId;
    $username = 'sk_'.substr(hash('sha256', $reference), 0, 24);
    DB::table('provisioning_subscriptions')->insert([
        'reference' => $reference,
        'remote_username' => $username,
        'remote_user_id' => null,
        'state' => 'needs_review',
        'last_operation_key' => $reference.':create',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $bot->hearCallbackQueryData('users:search')->reply()->assertCalled('sendMessage');
    $bot->hearText($username)->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('users:findpage:1')->reply()->assertRaw(
        static function (Request $request) use ($owner): bool {
            $data = FakeNutgram::getActualData($request);

            return str_contains((string) ($data['reply_markup'] ?? ''), 'users:view:'.$owner->id.':1:s');
        },
        index: 1,
    );
    $bot->hearCallbackQueryData('users:view:'.$owner->id.':1:s')->reply()
        ->assertCalled('editMessageText');

    // Preserve the legacy command entry point and the same owner result.
    $bot->hearText('/finduser '.$username)->reply()->assertCalled('sendMessage');
});

it('shows persisted representative registration only to authorized reviewers in RTL details', function (): void {
    config()->set('super_admin.telegram_ids', ['70980101', '70980102']);
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $bot = configureUserManagementBot(70980101, $root);

    Role::findOrCreate('reseller', 'web');
    $reseller = User::factory()->create(['name' => 'Telegram display name', 'is_active' => false]);
    $reseller->syncRoles(['reseller']);
    DB::table('sales_panel_requests')->insert([
        'user_id' => $reseller->id,
        'first_name' => 'علی <script>',
        'last_name' => 'محمدی',
        'mobile' => '09123456789',
        'status' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $bot->hearCallbackQueryData("users:view:{$reseller->id}:1:r")->reply()->assertRaw(
        static function (Request $request): bool {
            $payload = FakeNutgram::getActualData($request);
            $html = userManagementRichHtml($payload);

            return str_contains($html, '<h2>🧩 اطلاعات نمایندگی</h2>')
                && str_contains($html, 'علی &lt;script&gt; محمدی')
                && ! str_contains($html, '<script>')
                && str_contains($html, '<tr><td>شماره تماس</td><td>09123456789</td></tr>')
                && str_contains($html, '🔴 غیرفعال')
                && str_contains((string) ($payload['reply_markup'] ?? ''), 'users:resellers:1');
        },
        index: 1,
    );

    // Status updates return through the same detail projection, with no lost fields.
    $token = app(UserStatusConfirmations::class)->issue($root->id, $reseller->id, false, 1, false, true);
    $bot->hearCallbackQueryData('users:apply:'.$token)->reply()->assertRaw(
        static function (Request $request): bool {
            $html = userManagementRichHtml(FakeNutgram::getActualData($request));

            return str_contains($html, '<h2>🧩 اطلاعات نمایندگی</h2>')
                && str_contains($html, '09123456789')
                && str_contains($html, '🟢 فعال');
        },
        index: 1,
    );
    expect($reseller->fresh()->is_active)->toBeTrue();
});

it('keeps registered phone private from ordinary user viewers and omits it for members', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('users.view');
    $bot = configureUserManagementBot(70980103, $viewer);
    Role::findOrCreate('reseller', 'web');

    $reseller = User::factory()->create();
    $reseller->syncRoles(['reseller']);
    $member = User::factory()->create();
    foreach ([$reseller, $member] as $user) {
        DB::table('sales_panel_requests')->insert([
            'user_id' => $user->id,
            'first_name' => 'مینا',
            'last_name' => 'احمدی',
            'mobile' => '09999999999',
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $bot->hearCallbackQueryData("users:view:{$reseller->id}:1:a")->reply()->assertRaw(
        static function (Request $request): bool {
            $html = userManagementRichHtml(FakeNutgram::getActualData($request));

            return str_contains($html, 'مینا احمدی')
                && str_contains($html, '<h2>🧩 اطلاعات نمایندگی</h2>')
                && ! str_contains($html, '09999999999')
                && ! str_contains($html, 'شماره تماس');
        },
        index: 1,
    );

    $bot->hearCallbackQueryData("users:view:{$member->id}:1:a")->reply()->assertRaw(
        static function (Request $request): bool {
            $html = userManagementRichHtml(FakeNutgram::getActualData($request));

            return ! str_contains($html, 'مینا احمدی')
                && ! str_contains($html, '09999999999')
                && ! str_contains($html, 'اطلاعات نمایندگی');
        },
        index: 1,
    );

    $resellerWithoutRequest = User::factory()->create();
    $resellerWithoutRequest->syncRoles(['reseller']);
    $bot->hearCallbackQueryData("users:view:{$resellerWithoutRequest->id}:1:a")->reply()->assertRaw(
        static function (Request $request): bool {
            $html = userManagementRichHtml(FakeNutgram::getActualData($request));

            return str_contains($html, 'اطلاعات ثبت‌نام موجود نیست')
                && ! str_contains($html, 'شماره تماس');
        },
        index: 1,
    );
});

it('preserves reseller name, reviewer-only phone and navigation in actual native fallback after Rich error', function (): void {
    config()->set('super_admin.telegram_ids', ['70980201', '70980202']);
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 70980201]);

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('users.view');

    Role::findOrCreate('reseller', 'web');
    $reseller = User::factory()->create();
    $reseller->syncRoles(['reseller']);
    DB::table('sales_panel_requests')->insert([
        'user_id' => $reseller->id,
        'first_name' => 'مهسا',
        'last_name' => 'رضایی',
        'mobile' => '09123456789',
        'status' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([$root->id => true, $viewer->id => false] as $actorId => $maySeePhone) {
        $entry = app(BrowseUsers::class)->find($actorId, $reseller->id);
        expect($entry)->not->toBeNull();

        $keyboard = null;
        $bot = Mockery::mock(Nutgram::class);
        $bot->shouldReceive('isCallbackQuery')->andReturn(false);
        $bot->shouldReceive('sendRichMessage')->once()->andReturnUsing(function (...$args) use (&$keyboard): void {
            foreach ($args as $arg) {
                if ($arg instanceof InputRichMessage) {
                    expect($arg->html)->toContain('اطلاعات نمایندگی');
                }
                if ($arg instanceof InlineKeyboardMarkup) {
                    $keyboard = $arg;
                }
            }

            throw new TelegramException('Bad Request: unsupported RichMessage', 400);
        });
        $bot->shouldReceive('sendMessage')->once()->andReturnUsing(
            function (...$args) use (&$keyboard, $maySeePhone, $reseller): null {
                $text = $args[0];
                expect($text)->toContain('اطلاعات نمایندگی', 'مهسا رضایی')
                    ->not->toContain('<table', '<h2>');
                expect(str_contains($text, '09123456789'))->toBe($maySeePhone);
                expect(str_contains($text, 'شماره تماس'))->toBe($maySeePhone);
                expect(in_array($keyboard, $args, true))->toBeTrue()
                    ->and(json_encode($keyboard, JSON_THROW_ON_ERROR))
                    ->toContain('users:resellers:1', 'users:profile:'.$reseller->id.':1:r', 'menu:admin');

                return null;
            },
        );

        app(MenuRenderer::class)->showUserDetails($bot, $entry, resellersOnly: true);
    }
});

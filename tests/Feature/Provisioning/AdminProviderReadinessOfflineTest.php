<?php

use App\Interfaces\Telegram\Support\ReplyMenuLabels;
use App\Interfaces\Telegram\Support\ReplyMenuRenderer;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\CheckPlanSquadReadiness;
use App\Modules\Provisioning\Application\ReadAdminProviderOverview;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\PlanDefinition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;

uses(RefreshDatabase::class);

/** @return array{0:User,1:Nutgram} */
function mb05bRoot(int $telegramId = 997001, bool $super = true): array
{
    config()->set('super_admin.telegram_ids', ['997001', '997002']);
    $user = User::factory()->create();
    if ($super) {
        $user->syncRoles(['super-admin']);
    }
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);
    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Tester'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return [$user, $bot];
}

function mb05bFakeRead(): void
{
    Http::preventStrayRequests();
    Http::fake([
        'https://rw.example.test/api/system/stats' => Http::response([
            'response' => [
                'cpu' => ['cores' => 2],
                'memory' => ['total' => 2060730368, 'used' => 1229987840, 'free' => 830742528],
                'uptime' => 23341.83,
                'users' => ['totalUsers' => 2],
                'onlineStats' => ['onlineNow' => 0],
            ],
        ]),
        'https://rw.example.test/api/system/health' => Http::response([
            'response' => ['runtimeMetrics' => [['instanceId' => 'api-1']]],
        ]),
        'https://rw.example.test/api/nodes' => Http::response(['response' => [
            ['uuid' => 'node-a', 'name' => 'a', 'isConnected' => true, 'isDisabled' => false],
            ['uuid' => 'node-b', 'name' => 'b', 'isConnected' => false, 'isDisabled' => false],
        ]]),
        'https://rw.example.test/api/hosts' => Http::response(['response' => [['uuid' => 'h-1']]]),
        'https://rw.example.test/api/internal-squads' => Http::response([
            'response' => ['total' => 2, 'internalSquads' => []],
        ]),
        'https://rw.example.test/api/config-profiles' => Http::response([
            'response' => ['total' => 1, 'configProfiles' => []],
        ]),
    ]);
    config()->set('services.remnawave.url', 'https://rw.example.test');
    config()->set('services.remnawave.token', 'offline-test-only');
}

it('fails safely with no provider credentials while keeping Super Admin navigation available', function (): void {
    [$root, $bot] = mb05bRoot();
    config()->set('telegram.hybrid_menu_enabled', true);
    config()->set('services.remnawave.url', null);
    config()->set('services.remnawave.token', null);
    Http::preventStrayRequests();
    Http::fake();

    $status = app(ReadAdminProviderOverview::class)->handle($root->id);
    expect($status->state)->toBe('error')->and($status->nodes)->toBeNull();

    $actor = app(ResolveTelegramActor::class)->handle(997001);
    $keyboard = app(ReplyMenuRenderer::class)->homeKeyboard($actor);
    $labels = array_map(static fn ($button): string => $button->text, array_merge(...$keyboard->keyboard));
    expect($labels)->toContain(ReplyMenuLabels::SERVERS);

    $bot->hearText('/servers')->reply()
        ->assertReplyText("🖥️ وضعیت سرورها\n\nدریافت اطلاعات ممکن نیست. کمی بعد دوباره تلاش کنید.");
    Http::assertNothingSent();
});

it('rejects members, ordinary administrators, revoked users and group chats before remote requests', function (): void {
    [$member, $bot] = mb05bRoot(super: false);
    mb05bFakeRead();

    expect(fn () => app(ReadAdminProviderOverview::class)->handle($member->id))
        ->toThrow(AuthorizationException::class);
    $bot->hearText('/servers')->reply()->assertReplyText('دسترسی مجاز نیست.');

    $admin = User::factory()->admin()->create();
    expect(fn () => app(CheckPlanSquadReadiness::class)->handle($admin->id, 1))
        ->toThrow(AuthorizationException::class);

    $bot->setCommonChat(Chat::make(-997001, ChatType::GROUP));
    $bot->hearText('/servers')->reply()->assertNoReply();

    $member->forceFill(['is_active' => false])->save();
    $bot->setCommonChat(Chat::make(997001, ChatType::PRIVATE));
    $bot->hearText('/servers')->reply()->assertReplyText('دسترسی مجاز نیست.');
    Http::assertNothingSent();
});

it('returns secret-free typed inventory only through authorized read-only GETs', function (): void {
    [$root, $bot] = mb05bRoot();
    mb05bFakeRead();

    DB::table('provisioning_subscriptions')->insert([
        'reference' => 'offline-read-test', 'remote_username' => 'tester',
        'state' => 'needs_review', 'last_operation_key' => 'op-1',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $subscriptionId = (int) DB::table('provisioning_subscriptions')->value('id');
    DB::table('provisioning_operations')->insert([
        'subscription_id' => $subscriptionId, 'idempotency_key' => 'unique-operation',
        'kind' => 'create', 'payload_hash' => str_repeat('a', 64),
        'expected_payload' => '{}', 'state' => 'needs_review',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $read = app(ReadAdminProviderOverview::class)->handle($root->id);
    expect($read->state)->toBe('available')->and($read->instances)->toBe(1)
        ->and($read->nodes)->toBe(2)->and($read->connectedNodes)->toBe(1)
        ->and($read->hosts)->toBe(1)->and($read->internalSquads)->toBe(2)
        ->and($read->configProfiles)->toBe(1)->and($read->needsReview)->toBe(1)
        ->and($read->systemStats?->cpuCores)->toBe(2);

    $bot->hearText('/servers')->reply()
        ->assertReplyText("🖥️ وضعیت سرورها\n\nسرورهای متصل: 1 از 2\nمیزبان‌ها: 1\nگروه‌ها: 2\nپروفایل‌ها: 1\nنمونه‌های فعال پنل: 1\nدرخواست‌های داخلی نیازمند بررسی: 1\n\n📊 آمار گزارش‌شده پنل\nهسته‌های CPU: 2\nRAM مصرف‌شده: 1.15 از 1.92 GiB (60٪)\nRAM آزاد: 0.77 GiB\nزمان فعالیت: 6 ساعت و 29 دقیقه\nکاربران کل: 2\nکاربران آنلاین: 0");

    Http::assertSentCount(12);
    Http::assertSent(fn ($request): bool => $request->method() === 'GET');
});

it('never renders a successful provider state on failed, malformed or missing-config responses', function (): void {
    [$root, $bot] = mb05bRoot();
    config()->set('services.remnawave.token', null);
    Http::preventStrayRequests();
    Http::fake();

    expect(app(ReadAdminProviderOverview::class)->handle($root->id)->state)->toBe('error');
    $bot->hearText('/servers')->reply()
        ->assertReplyText("🖥️ وضعیت سرورها\n\nدریافت اطلاعات ممکن نیست. کمی بعد دوباره تلاش کنید.");
    Http::assertNothingSent();

    config()->set('services.remnawave.url', 'https://rw.example.test');
    config()->set('services.remnawave.token', 'offline-test-only');
    Http::fake(['https://rw.example.test/*' => Http::response(['response' => ['runtimeMetrics' => 'healthy']])]);
    expect(app(ReadAdminProviderOverview::class)->handle($root->id)->state)->toBe('error');
});

it('prepares version-bound Plan to Squad readiness without enabling provider writes or sales', function (): void {
    [$root] = mb05bRoot();
    app(ConfigureProduct::class)->handle('vpn-access', 'VPN');
    $plan = app(ManagePlans::class)->create($root->id, new PlanDefinition(
        'vpn-access', 'offline-plan', 'Offline Plan', 'hybrid', 'preset', 'both',
        30, 30, 1, 100, 15, 90,
    ));

    $check = app(CheckPlanSquadReadiness::class);
    config()->set('services.remnawave.squad_bindings_live_verified', true);
    expect($check->handle($root->id, $plan->id))->toBeFalse();

    $uuid = '11111111-1111-4111-8111-111111111111';
    DB::table('sales_plan_internal_squad_bindings')->insert([
        'plan_id' => $plan->id, 'internal_squad_uuid' => $uuid,
        'plan_revision' => $plan->revision, 'verified_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // The plan is still draft and the parent product is inactive.
    expect($check->handle($root->id, $plan->id))->toBeFalse();
    DB::table('sales_products')->where('slug', 'vpn-access')->update(['is_active' => true]);
    DB::table('sales_plans')->where('id', $plan->id)->update(['status' => 'active']);
    expect($check->handle($root->id, $plan->id))->toBeTrue();

    DB::table('sales_plans')->where('id', $plan->id)->increment('revision');
    expect($check->handle($root->id, $plan->id))->toBeFalse();
    DB::table('sales_plan_internal_squad_bindings')->where('plan_id', $plan->id)->update(['plan_revision' => 2]);
    expect($check->handle($root->id, $plan->id))->toBeTrue();

    config()->set('services.remnawave.squad_bindings_live_verified', false);
    expect($check->handle($root->id, $plan->id))->toBeFalse();
    config()->set('services.remnawave.squad_bindings_live_verified', true);
    DB::table('sales_plan_internal_squad_bindings')->where('plan_id', $plan->id)->update(['verified_at' => null]);
    expect($check->handle($root->id, $plan->id))->toBeFalse();
    DB::table('sales_plan_internal_squad_bindings')->where('plan_id', $plan->id)
        ->update(['verified_at' => now(), 'internal_squad_uuid' => 'not-a-uuid']);
    expect($check->handle($root->id, $plan->id))->toBeFalse();
    expect(config('services.remnawave.writes_enabled'))->toBeFalse();
});

it('protects recorded mappings from destructive rollback', function (): void {
    [$root] = mb05bRoot();
    app(ConfigureProduct::class)->handle('vpn-access', 'VPN');
    $plan = app(ManagePlans::class)->create($root->id, new PlanDefinition(
        'vpn-access', 'mapping-test', 'Offline', 'hybrid', 'preset', 'both',
        30, 30, 1, 100, 15, 90,
    ));
    DB::table('sales_plan_internal_squad_bindings')->insert([
        'plan_id' => $plan->id, 'internal_squad_uuid' => '11111111-1111-4111-8111-111111111111',
        'plan_revision' => 1, 'verified_at' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $migration = require database_path('migrations/2026_10_16_000001_create_sales_plan_internal_squad_bindings.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect(DB::table('sales_plan_internal_squad_bindings')->count())->toBe(1);
});

it('always exposes safe server navigation to Super Admin even without panel configuration', function (): void {
    [$root, $bot] = mb05bRoot();
    config()->set('telegram.hybrid_menu_enabled', true);
    config()->set('services.remnawave.url', null);
    config()->set('services.remnawave.token', null);
    Http::preventStrayRequests();
    Http::fake();

    $actor = app(ResolveTelegramActor::class)->handle(997001);
    $keyboard = app(ReplyMenuRenderer::class)->homeKeyboard($actor);
    $labels = array_map(static fn ($button): string => $button->text, array_merge(...$keyboard->keyboard));
    expect($labels)->toContain(ReplyMenuLabels::SERVERS);

    $safe = "🖥️ وضعیت سرورها\n\nدریافت اطلاعات ممکن نیست. کمی بعد دوباره تلاش کنید.";
    $bot->hearText('/servers')->reply()->assertReplyText($safe);
    $bot->hearCallbackQueryData('menu:admin:servers')->reply()
        ->assertCalled('answerCallbackQuery')->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('servers:refresh')->reply()
        ->assertCalled('answerCallbackQuery')->assertCalled('editMessageText');
    $bot->hearText(ReplyMenuLabels::SERVERS)->reply()->assertReplyText($safe);

    Http::assertNothingSent();
});

it('shows zero Nodes and refreshes current data using GET only, with the root menu enabled', function (): void {
    [$root, $bot] = mb05bRoot();
    config()->set('telegram.hybrid_menu_enabled', true);
    config()->set('services.remnawave.writes_enabled', false);
    config()->set('services.remnawave.url', 'https://rw.example.test');
    config()->set('services.remnawave.token', 'offline-test-only');

    Http::preventStrayRequests();
    Http::fake([
        'https://rw.example.test/api/system/stats' => Http::response([
            'response' => [
                'cpu' => ['cores' => 2],
                'memory' => ['total' => 2060730368, 'used' => 1229987840, 'free' => 830742528],
                'uptime' => 23341.83,
                'users' => ['totalUsers' => 2],
                'onlineStats' => ['onlineNow' => 0],
            ],
        ]),
        'https://rw.example.test/api/system/health' => Http::response([
            'response' => ['runtimeMetrics' => [['instanceId' => 'api-1']]],
        ]),
        'https://rw.example.test/api/nodes' => Http::response(['response' => []]),
        'https://rw.example.test/api/hosts' => Http::response(['response' => []]),
        'https://rw.example.test/api/internal-squads' => Http::response([
            'response' => ['total' => 1, 'internalSquads' => []],
        ]),
        'https://rw.example.test/api/config-profiles' => Http::response([
            'response' => ['total' => 1, 'configProfiles' => []],
        ]),
    ]);

    $actor = app(ResolveTelegramActor::class)->handle(997001);
    $keyboard = app(ReplyMenuRenderer::class)->homeKeyboard($actor);
    $labels = array_map(static fn ($button): string => $button->text, array_merge(...$keyboard->keyboard));
    expect($labels)->toContain(ReplyMenuLabels::SERVERS);

    $expected = "🖥️ وضعیت سرورها\n\nسرورهای متصل: 0 از 0\nمیزبان‌ها: 0\nگروه‌ها: 1\nپروفایل‌ها: 1\nنمونه‌های فعال پنل: 1\nدرخواست‌های داخلی نیازمند بررسی: 0\n\nهنوز سروری تعریف نشده است.\n\n📊 آمار گزارش‌شده پنل\nهسته‌های CPU: 2\nRAM مصرف‌شده: 1.15 از 1.92 GiB (60٪)\nRAM آزاد: 0.77 GiB\nزمان فعالیت: 6 ساعت و 29 دقیقه\nکاربران کل: 2\nکاربران آنلاین: 0";

    $bot->hearText('/servers')->reply()->assertReplyText($expected);
    $bot->hearCallbackQueryData('menu:admin:servers')->reply()
        ->assertCalled('answerCallbackQuery')->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('servers:refresh')->reply()
        ->assertCalled('answerCallbackQuery')->assertCalled('editMessageText');
    $bot->hearText(ReplyMenuLabels::SERVERS)->reply()
        ->assertReplyText($expected);

    Http::assertSentCount(24);
    Http::assertSent(fn ($request): bool => $request->method() === 'GET');
    expect(config('services.remnawave.writes_enabled'))->toBeFalse();
});

it('rejects old server buttons immediately after role revocation without start', function (): void {
    [$root, $bot] = mb05bRoot();
    config()->set('telegram.hybrid_menu_enabled', true);
    mb05bFakeRead();
    $root->syncRoles(['member']);

    $bot->hearCallbackQueryData('menu:admin:servers')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('editMessageText', 0);
    $bot->hearCallbackQueryData('servers:refresh')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('editMessageText', 0);
    $bot->hearText(ReplyMenuLabels::SERVERS)->reply()
        ->assertReplyText('دسترسی مجاز نیست. منوی شما به‌روز شد.');

    // An ordinary Admin must never gain the P0 overview after a stale button tap.
    $root->syncRoles(['admin']);
    $bot->hearCallbackQueryData('servers:refresh')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('editMessageText', 0);

    $bot->setCommonChat(Chat::make(-997001, ChatType::GROUP));
    $bot->hearCallbackQueryData('servers:refresh')->reply()->assertNoReply();
    Http::assertNothingSent();
});

it('never presents an HTTP 503 response as live panel health on refresh', function (): void {
    [$root, $bot] = mb05bRoot();
    config()->set('services.remnawave.url', 'https://rw.example.test');
    config()->set('services.remnawave.token', 'offline-test-only');
    Http::preventStrayRequests();
    Http::fake(['https://rw.example.test/*' => Http::response(['error' => 'private'], 503)]);

    $bot->hearCallbackQueryData('servers:refresh')->reply()
        ->assertCalled('answerCallbackQuery')->assertCalled('editMessageText');

    expect(app(ReadAdminProviderOverview::class)->handle($root->id)->state)->toBe('error');
    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request->method() === 'GET');
});

it('preserves old server inventory when optional P1 statistics fail', function (): void {
    [$root, $bot] = mb05bRoot();
    mb05bFakeRead();
    Http::fake(['https://rw.example.test/api/system/stats' => Http::response(['error' => 'unavailable'], 503)]);

    $overview = app(ReadAdminProviderOverview::class)->handle($root->id);
    expect($overview->state)->toBe('available')
        ->and($overview->nodes)->toBe(2)
        ->and($overview->systemStats)->toBeNull();

    $bot->hearText('/servers')->reply()->assertReplyText(
        "🖥️ وضعیت سرورها\n\nسرورهای متصل: 1 از 2\nمیزبان‌ها: 1\nگروه‌ها: 2\nپروفایل‌ها: 1\nنمونه‌های فعال پنل: 1\nدرخواست‌های داخلی نیازمند بررسی: 0",
    );

    Http::assertSentCount(12);
    Http::assertSent(static fn ($request): bool => $request->method() === 'GET');
});

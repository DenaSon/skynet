<?php

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

it('fails closed before live verification, even with configured credentials', function (): void {
    [$root, $bot] = mb05bRoot();
    config()->set('services.remnawave.url', 'https://rw.example.test');
    config()->set('services.remnawave.token', 'offline-test-only');
    config()->set('services.remnawave.admin_reads_verified', false);
    Http::preventStrayRequests();
    Http::fake();

    $status = app(ReadAdminProviderOverview::class)->handle($root->id);
    expect($status->state)->toBe('unavailable')
        ->and($status->nodes)->toBeNull();

    $bot->hearText('/servers')->reply()
        ->assertReplyText("🖥️ وضعیت سرورها\n\nاطلاعات سرورها هنوز آماده نمایش نیست.");
    Http::assertNothingSent();
});

it('rejects members, ordinary administrators, revoked users and group chats before remote requests', function (): void {
    [$member, $bot] = mb05bRoot(super: false);
    config()->set('services.remnawave.admin_reads_verified', true);
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

it('returns secret-free typed inventory only through authorized read-only GETs with an explicit live gate', function (): void {
    [$root, $bot] = mb05bRoot();
    mb05bFakeRead();
    config()->set('services.remnawave.admin_reads_verified', true);

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
        ->and($read->configProfiles)->toBe(1)->and($read->needsReview)->toBe(1);

    $bot->hearText('/servers')->reply()
        ->assertReplyText("🖥️ وضعیت سرورها\n\nسرورهای متصل: 1 از 2\nمیزبان‌ها: 1\nگروه‌ها: 2\nپروفایل‌ها: 1\nنمونه‌های فعال: 1\nدرخواست‌های نیازمند بررسی: 1");

    Http::assertSentCount(10);
    Http::assertSent(fn ($request): bool => $request->method() === 'GET');
});

it('never renders a successful provider state on failed, malformed or missing-config responses', function (): void {
    [$root, $bot] = mb05bRoot();
    config()->set('services.remnawave.admin_reads_verified', true);
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

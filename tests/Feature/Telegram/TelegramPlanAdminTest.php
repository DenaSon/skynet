<?php

use App\Interfaces\Telegram\Support\PlanAdminDrafts;
use App\Interfaces\Telegram\Support\PlanAdminForms;
use App\Interfaces\Telegram\Support\ReplyMenuLabels;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ManagePlanPricing;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\PlanDefinition;
use App\Modules\Sales\Application\PlanPricingDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['944001', '944002']);
    config()->set('telegram.hybrid_menu_enabled', true);
    app(ConfigureProduct::class)->handle('vpn-access', 'VPN access');
});

function b14Bot(int $telegramId, ?User $user = null): Nutgram
{
    if ($user !== null) {
        TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);
    }

    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Operator'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/** @return array{0:User,1:Nutgram} */
function b14Root(): array
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);

    return [$root, b14Bot(944001, $root)];
}

function b14Plan(User $root, string $slug = 'existing-30'): int
{
    $plan = app(ManagePlans::class)->create($root->id, new PlanDefinition(
        'vpn-access', $slug, 'Existing Plan', 'hybrid', 'preset', 'both',
        30, 30, 1, 100, 15, 90,
    ));

    return $plan->id;
}

it('restricts plan management to active linked allowlisted Super Admins in private chats', function (): void {
    $member = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $memberBot = b14Bot(944010, $member);

    $memberBot->hearText('/plans')->reply()->assertReplyText('دسترسی مجاز نیست.');
    $memberBot->hearCallbackQueryData('menu:admin:plans')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('editMessageText', 0);

    $adminBot = b14Bot(944011, $admin);
    $adminBot->hearText('/plans')->reply()->assertReplyText('دسترسی مجاز نیست.');
    $adminBot->hearText(ReplyMenuLabels::PLANS)->reply()
        ->assertReplyText('دسترسی مجاز نیست. منوی شما به‌روز شد.');

    [$root, $bot] = b14Root();
    $bot->hearText('/plans')->reply()->assertCalled('sendMessage');
    $bot->setCommonChat(Chat::make(-944001, ChatType::GROUP));
    $bot->hearText('/plans')->reply()->assertNoReply();
    $bot->hearCallbackQueryData('plan:begin:preset:0')->reply()->assertNoReply();

    expect(DB::table('sales_plans')->count())->toBe(0);
});

it('renders the configured application brand in the Admin plans title', function (): void {
    config()->set('app.name', 'DenaGate Test');
    [, $bot] = b14Root();

    $bot->hearText('/plans')->reply()->assertReplyText("📦 مدیریت پلن‌های DenaGate Test\nتعداد: 0 | صفحه 1 از 1\n\nبرای بررسی امکان ثبت سفارش، جزئیات هر پلن را باز کنید.\nهنوز پلنی تعریف نشده است.");
});

it('browses products and paged plans using existing authorized Sales Actions', function (): void {
    [$root, $bot] = b14Root();

    $bot->hearCallbackQueryData('plan:products')->reply()->assertCalled('editMessageText');
    for ($i = 0; $i < 7; $i++) {
        b14Plan($root, 'existing-'.($i + 10));
    }

    $bot->hearText('/plans')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('plan:list:2')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('plan:view:7')->reply()->assertCalled('editMessageText');

    expect(DB::table('sales_plans')->count())->toBe(7);
});

it('creates a draft preset with a one-shot preview and leaves checkout/provider unchanged', function (): void {
    [$root, $bot] = b14Root();
    Http::preventStrayRequests();
    Http::fake();

    $bot->hearCallbackQueryData('plan:begin:preset:0')->reply()->assertCalled('editMessageText');
    $bot->hearText('vpn-access | basic-30 | پلن پایه | hybrid | both | 30 | 30')->reply()
        ->assertCalled('sendMessage');

    $state = app(PlanAdminDrafts::class)->peek($root->id);
    expect($state['stage'])->toBe('preview');
    $token = $state['token'];

    expect(DB::table('sales_plans')->count())->toBe(0);

    $bot->hearCallbackQueryData('plan:save:'.$token)->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('plan:save:'.$token)->reply()->assertCalled('editMessageText');

    expect(DB::table('sales_plans')->count())->toBe(1)
        ->and(DB::table('sales_plans')->value('status'))->toBe('draft')
        ->and(DB::table('sales_plans')->value('traffic_gb'))->toBe(30)
        ->and(DB::table('sales_plans')->value('duration_days'))->toBe(30)
        ->and(DB::table('sales_plan_audits')->where('operation', 'created')->count())->toBe(1)
        ->and(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);

    Http::assertNothingSent();
});

it('supports reseller-only builder creation and typed unlimited presets', function (): void {
    [$root, $bot] = b14Root();

    $bot->hearCallbackQueryData('plan:begin:builder:0')->reply();
    $bot->hearText('vpn-access | custom-builder | سفارش سفارشی | hybrid | 1 | 100 | 15 | 90')->reply();
    $token = app(PlanAdminDrafts::class)->peek($root->id)['token'];
    $bot->hearCallbackQueryData('plan:save:'.$token)->reply();

    $bot->hearCallbackQueryData('plan:begin:preset:0')->reply();
    $bot->hearText('vpn-access | unlimited-30 | نامحدود | time | reseller | - | 30')->reply();
    $token = app(PlanAdminDrafts::class)->peek($root->id)['token'];
    $bot->hearCallbackQueryData('plan:save:'.$token)->reply();

    expect(DB::table('sales_plans')->where('slug', 'custom-builder')->value('mode'))->toBe('builder')
        ->and(DB::table('sales_plans')->where('slug', 'custom-builder')->value('audience'))->toBe('reseller')
        ->and(DB::table('sales_plans')->where('slug', 'unlimited-30')->value('traffic_gb'))->toBeNull()
        ->and(DB::table('sales_plans')->where('slug', 'unlimited-30')->value('kind'))->toBe('time');
});

it('edits existing plan with all fields while refusing stale revisions', function (): void {
    [$root, $bot] = b14Root();
    $id = b14Plan($root);
    $current = app(ManagePlans::class)->find($root->id, $id);
    $form = str_replace('Existing Plan', 'Updated Plan', app(PlanAdminForms::class)->editLine($current));

    $bot->hearCallbackQueryData("plan:begin:edit:{$id}")->reply();
    $bot->hearText($form)->reply();
    $token = app(PlanAdminDrafts::class)->peek($root->id)['token'];
    $bot->hearCallbackQueryData('plan:save:'.$token)->reply();

    expect(DB::table('sales_plans')->value('name'))->toBe('Updated Plan')
        ->and(DB::table('sales_plans')->value('revision'))->toBe(2);

    $bot->hearCallbackQueryData("plan:begin:edit:{$id}")->reply();
    $bot->hearText($form)->reply();
    $token = app(PlanAdminDrafts::class)->peek($root->id)['token'];
    app(ManagePlans::class)->update($root->id, $id, 2,
        new PlanDefinition('vpn-access', 'existing-30', 'Changed Elsewhere', 'hybrid', 'preset', 'both', 30, 30, 1, 100, 15, 90));
    $bot->hearCallbackQueryData('plan:save:'.$token)->reply()->assertCalled('editMessageText');

    expect(DB::table('sales_plans')->value('name'))->toBe('Changed Elsewhere')
        ->and(DB::table('sales_plans')->value('revision'))->toBe(3);
});

it('uses backend hashed one-time confirmations for publish, deactivate, archive and delete', function (): void {
    [$root, $bot] = b14Root();
    $id = b14Plan($root);

    $bot->hearCallbackQueryData("plan:status:{$id}:activate")->reply()->assertCalled('editMessageText');
    $token = app('db')->table('sales_plan_confirmations')->value('token_hash');
    expect($token)->toHaveLength(64)
        ->and(DB::table('sales_plans')->value('status'))->toBe('draft');

    // The UI confirmation token is available only from the trusted backend; a forged token fails.
    $bot->hearCallbackQueryData('plan:apply:p:'.str_repeat('a', 32))->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_plans')->value('status'))->toBe('draft');

    $confirm = app(ManagePlans::class)->requestChange($root->id, $id, 1, 'activate');
    $bot->hearCallbackQueryData('plan:apply:p:'.$confirm)->reply();
    $bot->hearCallbackQueryData('plan:apply:p:'.$confirm)->reply();

    expect(DB::table('sales_plans')->value('status'))->toBe('active')
        ->and(DB::table('sales_plan_audits')->where('operation', 'activate')->count())->toBe(1);

    $deactivate = app(ManagePlans::class)->requestChange($root->id, $id, 2, 'deactivate');
    $bot->hearCallbackQueryData('plan:apply:p:'.$deactivate)->reply();
    $archive = app(ManagePlans::class)->requestChange($root->id, $id, 3, 'archive');
    $bot->hearCallbackQueryData('plan:apply:p:'.$archive)->reply();
    $delete = app(ManagePlans::class)->requestChange($root->id, $id, 4, 'delete');
    $bot->hearCallbackQueryData('plan:apply:p:'.$delete)->reply();

    expect(DB::table('sales_plans')->count())->toBe(0);
});

it('configures a fixed tariff in tomans then publishes and disables it separately', function (): void {
    [$root, $bot] = b14Root();
    $id = b14Plan($root);

    $bot->hearCallbackQueryData("plan:begin:fixed:{$id}")->reply();
    $bot->hearText('100000 | 50000 | 5')->reply();
    $draft = app(PlanAdminDrafts::class)->peek($root->id);
    $bot->hearCallbackQueryData('plan:save:'.$draft['token'])->reply();

    expect(DB::table('sales_plan_prices')->value('fixed_price_rials'))->toBe(1_000_000)
        ->and(DB::table('sales_plan_prices')->value('minimum_total_rials'))->toBe(500_000)
        ->and(DB::table('sales_plan_prices')->value('status'))->toBe('draft');

    $bot->hearCallbackQueryData("plan:status:{$id}:pa")->reply()->assertCalled('editMessageText');
    $confirm = app(ManagePlanPricing::class)->requestChange($root->id, $id, 1, 1, 'activate');
    $bot->hearCallbackQueryData('plan:apply:t:'.$confirm)->reply()->assertCalled('editMessageText');

    expect(DB::table('sales_plan_prices')->value('status'))->toBe('active')
        ->and(DB::table('sales_plan_price_audits')->where('operation', 'activate')->count())->toBe(1);

    $confirm = app(ManagePlanPricing::class)->requestChange($root->id, $id, 1, 2, 'deactivate');
    $bot->hearCallbackQueryData('plan:apply:t:'.$confirm)->reply();

    expect(DB::table('sales_plan_prices')->value('status'))->toBe('inactive');
});

it('configures a bounded formula and rejects invalid or stale pricing submissions', function (): void {
    [$root, $bot] = b14Root();
    $id = b14Plan($root);

    $bot->hearCallbackQueryData("plan:begin:formula:{$id}")->reply();
    $bot->hearText('0 | 0 | 0 | 1 | 5')->reply();
    expect(app(PlanAdminDrafts::class)->peek($root->id)['stage'])->toBe('step:0');

    $bot->hearText('50000 | 10000 | 1000 | 10000 | 5')->reply();
    $token = app(PlanAdminDrafts::class)->peek($root->id)['token'];
    $bot->hearCallbackQueryData('plan:save:'.$token)->reply();

    expect(DB::table('sales_plan_prices')->value('method'))->toBe('formula')
        ->and(DB::table('sales_plan_prices')->value('per_gb_rials'))->toBe(100_000);

    $bot->hearCallbackQueryData("plan:begin:formula:{$id}")->reply();
    $bot->hearText('60000 | 10000 | 1000 | 10000 | 5')->reply();
    $token = app(PlanAdminDrafts::class)->peek($root->id)['token'];
    app(ManagePlanPricing::class)->configure($root->id, $id, 1, 1,
        new PlanPricingDefinition('formula', null, 400_000, 100_000, 10_000, 5, 100_000));
    $bot->hearCallbackQueryData('plan:save:'.$token)->reply();

    expect(DB::table('sales_plan_prices')->value('base_price_rials'))->toBe(400_000)
        ->and(DB::table('sales_plan_prices')->value('revision'))->toBe(2);
});

it('expires drafts, blocks cross-root replay, and denies operations after allowlist revocation', function (): void {
    [$root, $bot] = b14Root();
    $id = b14Plan($root);

    $bot->hearCallbackQueryData('plan:begin:preset:0')->reply();
    $bot->hearText('vpn-access | temp-30 | Temp | hybrid | both | 30 | 30')->reply();
    $token = app(PlanAdminDrafts::class)->peek($root->id)['token'];

    $other = User::factory()->create();
    $other->syncRoles(['super-admin']);
    $otherBot = b14Bot(944002, $other);
    $otherBot->hearCallbackQueryData('plan:save:'.$token)->reply()->assertCalled('editMessageText');

    // The test harness returns the same singleton Nutgram fake for both actors.
    $bot->setCommonUser(TelegramUser::make(944001, false, 'Root'));
    $bot->setCommonChat(Chat::make(944001, ChatType::PRIVATE));
    $this->travel(11)->minutes();
    $bot->hearCallbackQueryData('plan:save:'.$token)->reply();
    $this->travelBack();
    expect(DB::table('sales_plans')->count())->toBe(1);

    $bot->hearCallbackQueryData("plan:begin:edit:{$id}")->reply();
    $bot->hearText(app(PlanAdminForms::class)->editLine(app(ManagePlans::class)->find($root->id, $id)))->reply();
    $token = app(PlanAdminDrafts::class)->peek($root->id)['token'];

    config()->set('super_admin.telegram_ids', ['944002', '944003']);
    $bot->hearCallbackQueryData('plan:save:'.$token)->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);

    expect(DB::table('sales_plans')->value('revision'))->toBe(1);
});

it('keeps legacy purchasing isolated and never sends a provider request', function (): void {
    [$root, $bot] = b14Root();
    Http::preventStrayRequests();
    Http::fake();
    $id = b14Plan($root);

    $bot->hearText('/buy')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData("plan:view:{$id}")->reply();

    expect(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('billing_postings')->count())->toBe(0)
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(0);

    Http::assertNothingSent();
});

it('shows the root plan-readiness screen without creating any Squad mapping or provider user', function (): void {
    [$root, $bot] = b14Root();
    $planId = b14Plan($root);

    config()->set('services.remnawave.squad_bindings_live_verified', false);
    config()->set('services.remnawave.writes_enabled', false);
    Http::preventStrayRequests();
    Http::fake();

    $bot->hearCallbackQueryData('plan:view:'.$planId)->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText');

    expect(DB::table('sales_plan_internal_squad_bindings')->count())->toBe(0)
        ->and(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('provisioning_operations')->count())->toBe(0);
    Http::assertNothingSent();
});

it('reads attached service details with GET only and preserves private root plan callbacks', function (): void {
    [$root, $bot] = b14Root();
    $planId = b14Plan($root);

    config()->set('services.remnawave.url', 'https://rw.example.test');
    config()->set('services.remnawave.token', 'test-only');
    config()->set('services.remnawave.writes_enabled', false);
    config()->set('services.remnawave.squad_bindings_live_verified', false);
    DB::table('sales_plan_internal_squad_bindings')->insert([
        'plan_id' => $planId,
        'plan_revision' => 1,
        'internal_squad_uuid' => '550e8400-e29b-41d4-a716-446655440001',
        'verified_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://rw.example.test/api/internal-squads/*' => Http::response([
            'response' => [
                'uuid' => '550e8400-e29b-41d4-a716-446655440001',
                'name' => 'Staging service',
            ],
        ]),
    ]);

    $bot->hearCallbackQueryData('plan:view:'.$planId)->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText');

    expect(DB::table('sales_plan_internal_squad_bindings')->where('plan_id', $planId)->count())->toBe(1)
        ->and(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(0);
    Http::assertSentCount(1);
    Http::assertSent(static fn ($request): bool => $request->method() === 'GET');
});

it('allows a delegated plan manager through real Admin routes without Squad binding rights', function (): void {
    [$root] = b14Root();
    $id = b14Plan($root, 'a3-admin-catalog');
    $manager = User::factory()->create();
    Role::findOrCreate('plan-editor-a3', 'web')->givePermissionTo('plans.manage');
    $manager->assignRole('plan-editor-a3');
    $bot = b14Bot(944030, $manager);

    $bot->hearText('/plans')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('plan:view:'.$id)->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('plan:begin:edit:'.$id)->reply()->assertCalled('editMessageText');

    expect(app(ResolveTelegramActor::class)
        ->handle(944030)?->allows('plans.manage'))->toBeTrue();
});

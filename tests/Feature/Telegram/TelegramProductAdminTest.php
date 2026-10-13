<?php

use App\Interfaces\Telegram\Support\ProductAdminDrafts;
use App\Interfaces\Telegram\Support\ProductAdminForms;
use App\Interfaces\Telegram\Support\ReplyMenuLabels;
use App\Interfaces\Telegram\Support\ReplyMenuRenderer;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Sales\Application\ListProducts;
use App\Modules\Sales\Application\ManageBaseCatalog;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\PlanDefinition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['978001', '978002']);
    config()->set('telegram.hybrid_menu_enabled', true);
});

function baseProductBot(int $id, ?User $user = null): Nutgram
{
    if ($user !== null) {
        TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $id]);
    }

    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($id, false, 'Operator'));
    $bot->setCommonChat(Chat::make($id, ChatType::PRIVATE));

    return $bot;
}

/** @return array{0:User,1:Nutgram} */
function baseProductRoot(): array
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);

    return [$root, baseProductBot(978001, $root)];
}

it('restricts products navigation to linked allowlisted active root and private chats', function (): void {
    $member = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $mBot = baseProductBot(978010, $member);
    $mBot->hearText('/products')->reply()->assertReplyText('دسترسی مجاز نیست.');
    $mBot->hearCallbackQueryData('menu:admin:products')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
    $aBot = baseProductBot(978011, $admin);
    $aBot->hearText(ReplyMenuLabels::PRODUCTS)->reply()
        ->assertReplyText('دسترسی مجاز نیست. منوی شما به‌روز شد.');

    [$root, $bot] = baseProductRoot();
    $bot->hearText('/products')->reply()->assertCalled('sendMessage');
    $bot->setCommonChat(Chat::make(-978001, ChatType::GROUP));
    $bot->hearText('/products')->reply()->assertNoReply();
    $bot->hearCallbackQueryData('product:form:create:0:0')->reply()->assertNoReply();

    expect(fn () => app(ManageBaseCatalog::class)->all($member->id))->toThrow(AuthorizationException::class)
        ->and(DB::table('sales_products')->count())->toBe(0);
});

it('creates an inactive product with an owner-bound one-time preview and audit', function (): void {
    [$root, $bot] = baseProductRoot();
    Http::preventStrayRequests();
    Http::fake();

    $bot->hearCallbackQueryData('product:form:create:0:0')->reply()->assertCalled('editMessageText');
    $bot->hearText('vpn-new | محصول جدید | 1 | 100')->reply()->assertCalled('sendMessage');
    $s = app(ProductAdminDrafts::class)->peek($root->id);
    expect($s['stage'])->toBe('preview')
        ->and(DB::table('sales_products')->count())->toBe(0);

    $bot->hearCallbackQueryData('product:apply:'.$s['token'])->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('product:apply:'.$s['token'])->reply()->assertCalled('editMessageText');

    expect(DB::table('sales_products')->where('slug', 'vpn-new')->value('is_active'))->toBe(0)
        ->and(DB::table('sales_catalog_management_audits')->where('operation', 'product.created')->count())->toBe(1)
        ->and(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('billing_postings')->count())->toBe(0)
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(0);
    Http::assertNothingSent();
});

it('edits an existing product without changing its slug or managed plans and audits status toggles', function (): void {
    [$root, $bot] = baseProductRoot();
    app(ConfigureProduct::class)->handle('vpn-access', 'اشتراک VPN');
    $id = (int) DB::table('sales_products')->where('slug', 'vpn-access')->value('id');

    $plan = app(ManagePlans::class)->create($root->id, new PlanDefinition(
        'vpn-access', 'e2e-existing', 'Existing managed plan',
        'hybrid', 'preset', 'both', 10, 30, 10, 10, 30, 30,
    ));

    $bot->hearCallbackQueryData("product:form:edit:{$id}:0")->reply();
    $bot->hearText('محصول ویرایش شده | 5 | 50')->reply();
    $token = app(ProductAdminDrafts::class)->peek($root->id)['token'];
    $bot->hearCallbackQueryData('product:apply:'.$token)->reply();
    expect(DB::table('sales_products')->where('id', $id)->value('max_gb'))->toBe(50)
        ->and(DB::table('sales_products')->where('id', $id)->value('slug'))->toBe('vpn-access')
        ->and(DB::table('sales_plans')->where('id', $plan->id)->value('product_id'))->toBe($id);

    $bot->hearCallbackQueryData("product:status:disable:{$id}:0")->reply();
    expect(DB::table('sales_products')->where('id', $id)->value('is_active'))->toBe(1);
    $token = app(ProductAdminDrafts::class)->peek($root->id)['token'];
    $bot->hearCallbackQueryData('product:apply:'.$token)->reply();
    expect(DB::table('sales_products')->where('id', $id)->value('is_active'))->toBe(0);

    $bot->hearCallbackQueryData("product:status:enable:{$id}:0")->reply();
    $token = app(ProductAdminDrafts::class)->peek($root->id)['token'];
    $bot->hearCallbackQueryData('product:apply:'.$token)->reply();
    expect(DB::table('sales_products')->where('id', $id)->value('is_active'))->toBe(1)
        ->and(DB::table('sales_catalog_management_audits')->where('product_id', $id)->count())->toBe(3)
        ->and(DB::table('sales_orders')->count())->toBe(0);
});

it('creates draft-like inactive F09 rates in tomans and exposes only explicitly activated rates', function (): void {
    [$root, $bot] = baseProductRoot();
    app(ConfigureProduct::class)->handle('vpn-access', 'اشتراک VPN');
    $id = (int) DB::table('sales_products')->value('id');
    $member = User::factory()->create();

    $bot->hearCallbackQueryData("product:form:rate:{$id}:30")->reply();
    $bot->hearText('50000 | 2000')->reply();
    $token = app(ProductAdminDrafts::class)->peek($root->id)['token'];
    $bot->hearCallbackQueryData('product:apply:'.$token)->reply();

    expect(DB::table('sales_product_rates')->value('duration_days'))->toBe(30)
        ->and(DB::table('sales_product_rates')->value('base_price_rials'))->toBe(500_000)
        ->and(DB::table('sales_product_rates')->value('per_gb_rials'))->toBe(20_000)
        ->and(DB::table('sales_product_rates')->value('is_active'))->toBe(0)
        ->and(app(ListProducts::class)->handle($member->id))->toBe([]);

    $bot->hearCallbackQueryData("product:status:rate-enable:{$id}:30")->reply();
    $token = app(ProductAdminDrafts::class)->peek($root->id)['token'];
    $bot->hearCallbackQueryData('product:apply:'.$token)->reply();

    expect(app(ListProducts::class)->handle($member->id))->toHaveCount(1)
        ->and(DB::table('sales_catalog_management_audits')->where('operation', 'rate.activated')->count())->toBe(1);

    $bot->hearCallbackQueryData("product:form:rate:{$id}:30")->reply();
    $bot->hearText('60000 | 1000')->reply();
    $token = app(ProductAdminDrafts::class)->peek($root->id)['token'];
    $bot->hearCallbackQueryData('product:apply:'.$token)->reply();
    expect(DB::table('sales_product_rates')->value('is_active'))->toBe(0)
        ->and(app(ListProducts::class)->handle($member->id))->toBe([]);

    $bot->hearCallbackQueryData("product:status:rate-enable:{$id}:30")->reply();
    $token = app(ProductAdminDrafts::class)->peek($root->id)['token'];
    $bot->hearCallbackQueryData('product:apply:'.$token)->reply();
    $bot->hearCallbackQueryData("product:status:rate-disable:{$id}:30")->reply();
    $token = app(ProductAdminDrafts::class)->peek($root->id)['token'];
    $bot->hearCallbackQueryData('product:apply:'.$token)->reply();
    expect(app(ListProducts::class)->handle($member->id))->toBe([]);

    expect(DB::table('sales_catalog_management_audits')->where('product_id', $id)->count())->toBe(5);
});

it('rejects stale product and rate changes after external operator edits', function (): void {
    [$root, $bot] = baseProductRoot();
    app(ConfigureProduct::class)->handle('vpn-access', 'Original');
    $id = (int) DB::table('sales_products')->value('id');

    $bot->hearCallbackQueryData("product:form:edit:{$id}:0")->reply();
    $bot->hearText('Stale Name | 1 | 90')->reply();
    $token = app(ProductAdminDrafts::class)->peek($root->id)['token'];
    app(ConfigureProduct::class)->handle('vpn-access', 'Changed by terminal');
    $bot->hearCallbackQueryData('product:apply:'.$token)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_products')->value('name'))->toBe('Changed by terminal')
        ->and(DB::table('sales_catalog_management_audits')->count())->toBe(0);

    $bot->hearCallbackQueryData("product:form:rate:{$id}:30")->reply();
    $bot->hearText('10000 | 500')->reply();
    $token = app(ProductAdminDrafts::class)->peek($root->id)['token'];
    app(ConfigureRate::class)->handle('vpn-access', 30, 100_000, 10_000);
    $bot->hearCallbackQueryData('product:apply:'.$token)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_product_rates')->value('base_price_rials'))->toBe(100_000)
        ->and(DB::table('sales_catalog_management_audits')->count())->toBe(0);
});

it('rejects invalid volume, rate bounds, duration and duplicate slugs before writing', function (): void {
    [$root, $bot] = baseProductRoot();
    $bot->hearCallbackQueryData('product:form:create:0:0')->reply();
    $bot->hearText('bad slug | Invalid | 1 | 100')->reply();
    expect(app(ProductAdminDrafts::class)->peek($root->id)['stage'])->toBe('input');

    $bot->hearText('vpn-access | Valid | 0 | 101')->reply();
    expect(DB::table('sales_products')->count())->toBe(0);

    app(ConfigureProduct::class)->handle('vpn-access', 'Original');
    $bot->hearText('vpn-access | Duplicate | 1 | 100')->reply();
    expect(app(ProductAdminDrafts::class)->peek($root->id)['stage'])->toBe('input');

    expect(fn () => app(ManageBaseCatalog::class)->snapshot($root->id, 'rate', 1, 31))
        ->toThrow(ValidationException::class);
    expect(fn () => app((ProductAdminForms::class))->parse('rate', '0 | 0'))
        ->not->toThrow(ValidationException::class);
    expect(fn () => app(ManageBaseCatalog::class)->validate($root->id, 'rate', 1, 30, [
        'base_price_rials' => 0, 'per_gb_rials' => 0,
    ]))->toThrow(ValidationException::class);
});

it('uses expiring one-time buyer-bound confirmations and rejects role/allowlist revocation', function (): void {
    [$root, $bot] = baseProductRoot();
    app(ConfigureProduct::class)->handle('vpn-access', 'Existing');
    $id = (int) DB::table('sales_products')->value('id');

    $bot->hearCallbackQueryData("product:status:disable:{$id}:0")->reply();
    $token = app(ProductAdminDrafts::class)->peek($root->id)['token'];
    $other = User::factory()->create();
    $other->syncRoles(['super-admin']);
    $otherBot = baseProductBot(978002, $other);
    $otherBot->hearCallbackQueryData('product:apply:'.$token)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_products')->value('is_active'))->toBe(1);

    $bot->setCommonUser(TelegramUser::make(978001, false, 'Operator'));
    $bot->setCommonChat(Chat::make(978001, ChatType::PRIVATE));
    $this->travel(4)->minutes();
    $bot->hearCallbackQueryData('product:apply:'.$token)->reply()->assertCalled('editMessageText');
    $this->travelBack();
    expect(DB::table('sales_products')->value('is_active'))->toBe(1);

    $bot->hearCallbackQueryData("product:status:disable:{$id}:0")->reply();
    $token = app(ProductAdminDrafts::class)->peek($root->id)['token'];
    config()->set('super_admin.telegram_ids', ['978002', '978003']);
    $bot->hearCallbackQueryData('product:apply:'.$token)->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
    expect(DB::table('sales_products')->value('is_active'))->toBe(1);
});

it('navigates between product and plan menus without stealing other pending form text', function (): void {
    [$root, $bot] = baseProductRoot();
    app(ConfigureProduct::class)->handle('vpn-access', 'VPN');
    $actor = app(ResolveTelegramActor::class)->handle(978001);
    $buttons = array_map(static fn ($button): string => $button->text,
        array_merge(...app(ReplyMenuRenderer::class)->adminKeyboard($actor)->keyboard));
    expect($buttons)->toContain(ReplyMenuLabels::PRODUCTS);

    $bot->hearCallbackQueryData('plan:products')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('menu:admin:products')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('product:form:create:0:0')->reply();
    expect(app(ProductAdminDrafts::class)->peek($root->id))->not->toBeNull();
    $bot->hearText(ReplyMenuLabels::PLANS)->reply();
    expect(app(ProductAdminDrafts::class)->peek($root->id))->toBeNull();

    $bot->hearText('/products')->reply()->assertCalled('sendMessage');
    $bot->hearText('/plans')->reply()->assertCalled('sendMessage');
    $bot->hearText('/buy')->reply()->assertCalled('sendMessage');

    config()->set('telegram.hybrid_menu_enabled', false);
    $bot->hearText(ReplyMenuLabels::PRODUCTS)->reply()->assertNoReply();
    $bot->hearText('/products')->reply()->assertCalled('sendMessage');

    expect(DB::table('sales_orders')->count())->toBe(0);
});

<?php

use App\Interfaces\Telegram\Support\PlanAdminDrafts;
use App\Interfaces\Telegram\Support\ProductAdminDrafts;
use App\Interfaces\Telegram\Support\ReplyMenuLabels;
use App\Interfaces\Telegram\Support\TelegramUi;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\PlanDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['981001', '981002']);
    config()->set('telegram.hybrid_menu_enabled', true);
});

function uxPolishBot(int $telegramId, ?User $user = null): Nutgram
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
function uxPolishRoot(): array
{
    $user = User::factory()->create();
    $user->syncRoles(['super-admin']);

    return [$user, uxPolishBot(981001, $user)];
}

it('uses native Telegram clipboard buttons with exact payload and HTML escaping', function (): void {
    $sample = 'vpn-new | اشتراک <تست> & ویژه | 1 | 100';
    $button = TelegramUi::copy($sample);
    expect($button->copy_text->text)->toBe($sample)
        ->and($button->callback_data)->toBeNull()
        ->and(TelegramUi::code($sample))->toContain('&lt;تست&gt;', '&amp;')
        ->and(TelegramUi::status('active'))->toBe('🟢 فعال')
        ->and(TelegramUi::planKind('hybrid'))->toBe('حجم و زمان');

    expect(fn () => TelegramUi::copy(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TelegramUi::copy(str_repeat('a', 257)))->toThrow(InvalidArgumentException::class);
});

it('guides product creation and legacy rate pricing field by field without publishing or creating orders', function (): void {
    [$root, $bot] = uxPolishRoot();
    $bot->hearCallbackQueryData('product:form:create:0:0')->reply()->assertCalled('editMessageText');
    expect(app(ProductAdminDrafts::class)->peek($root->id)['stage'])->toBe('step:0');

    $bot->hearText('vpn-ux')->reply()->assertCalled('sendMessage');
    expect(app(ProductAdminDrafts::class)->peek($root->id)['stage'])->toBe('step:1');
    $bot->hearText('اشتراک جدید')->reply();
    $bot->hearText('1')->reply();
    expect(app(ProductAdminDrafts::class)->peek($root->id)['stage'])->toBe('step:3');
    $bot->hearText('100')->reply();
    $s = app(ProductAdminDrafts::class)->peek($root->id);

    expect($s['stage'])->toBe('preview')
        ->and(DB::table('sales_products')->count())->toBe(0);
    $bot->hearCallbackQueryData('product:apply:'.$s['token'])->reply();
    $id = (int) DB::table('sales_products')->value('id');

    expect(DB::table('sales_products')->value('slug'))->toBe('vpn-ux')
        ->and(DB::table('sales_products')->value('is_active'))->toBe(0);

    $bot->hearCallbackQueryData("product:form:rate:{$id}:30")->reply();
    $bot->hearText('50000')->reply();
    $bot->hearCallbackQueryData('product:back')->reply();
    expect(app(ProductAdminDrafts::class)->peek($root->id)['stage'])->toBe('step:0');

    $bot->hearText('50000')->reply();
    $bot->hearText('2000')->reply();
    $s = app(ProductAdminDrafts::class)->peek($root->id);
    expect($s['stage'])->toBe('preview');
    $bot->hearCallbackQueryData('product:apply:'.$s['token'])->reply();

    expect(DB::table('sales_product_rates')->value('is_active'))->toBe(0)
        ->and(DB::table('sales_product_rates')->value('base_price_rials'))->toBe(500_000)
        ->and(DB::table('sales_orders')->count())->toBe(0);
});

it('guides preset and fixed price entry with button choices and no checkout', function (): void {
    [$root, $bot] = uxPolishRoot();
    app(ConfigureProduct::class)->handle('vpn-access', 'VPN access');

    $bot->hearCallbackQueryData('plan:begin:preset:0')->reply()->assertCalled('editMessageText');
    expect(app(PlanAdminDrafts::class)->peek($root->id)['stage'])->toBe('step:0');

    $bot->hearCallbackQueryData('plan:choose:vpn-access')->reply();
    expect(app(PlanAdminDrafts::class)->peek($root->id)['stage'])->toBe('step:1');
    $bot->hearText('ux-standard-10')->reply();
    $bot->hearText('پلن ساده')->reply();
    $bot->hearCallbackQueryData('plan:choose:hybrid')->reply();
    $bot->hearCallbackQueryData('plan:choose:both')->reply();
    $bot->hearText('10')->reply();
    $bot->hearText('30')->reply();
    $s = app(PlanAdminDrafts::class)->peek($root->id);

    expect($s['stage'])->toBe('preview')
        ->and(DB::table('sales_plans')->count())->toBe(0);
    $bot->hearCallbackQueryData('plan:save:'.$s['token'])->reply();
    expect(DB::table('sales_plans')->value('status'))->toBe('draft');

    $id = (int) DB::table('sales_plans')->value('id');
    $bot->hearCallbackQueryData("plan:begin:fixed:{$id}")->reply();
    $bot->hearText('100000')->reply();
    $bot->hearText('50000')->reply();
    $bot->hearText('5')->reply();
    $s = app(PlanAdminDrafts::class)->peek($root->id);
    expect($s['stage'])->toBe('preview');
    $bot->hearCallbackQueryData('plan:save:'.$s['token'])->reply();

    expect(DB::table('sales_plan_prices')->value('status'))->toBe('draft')
        ->and(DB::table('sales_plan_prices')->value('fixed_price_rials'))->toBe(1_000_000)
        ->and(DB::table('sales_orders')->count())->toBe(0);
});

it('keeps grouped navigation role-gated and legacy menu targets routable', function (): void {
    $member = User::factory()->create();
    $memberBot = uxPolishBot(981011, $member);
    $memberBot->hearCallbackQueryData('menu:admin:sales')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('editMessageText', 0);
    $memberBot->hearText(ReplyMenuLabels::SALES_SECTION)->reply()
        ->assertReplyText('دسترسی مجاز نیست. منوی شما به‌روز شد.');

    [$root, $bot] = uxPolishRoot();
    $bot->hearCallbackQueryData('menu:admin:sales')->reply()->assertCalled('editMessageText');
    $bot->hearText(ReplyMenuLabels::SALES_SECTION)->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('menu:admin:products')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('menu:admin:plans')->reply()->assertCalled('editMessageText');
    $bot->hearText(ReplyMenuLabels::PRODUCTS)->reply()->assertCalled('sendMessage');
    $bot->hearText(ReplyMenuLabels::PLANS)->reply()->assertCalled('sendMessage');

    $bot->setCommonChat(Chat::make(-981001, ChatType::GROUP));
    $bot->hearCallbackQueryData('menu:admin:sales')->reply()->assertNoReply();
    expect(DB::table('sales_orders')->count())->toBe(0);
});

it('edits only the selected plan field without losing the old full-form editing path', function (): void {
    [$root, $bot] = uxPolishRoot();
    app(ConfigureProduct::class)->handle('vpn-access', 'VPN access');
    $plan = app(ManagePlans::class)->create($root->id, new PlanDefinition(
        'vpn-access', 'ux-edit-preset', 'نام قدیمی', 'hybrid', 'preset', 'both',
        10, 30, 10, 10, 30, 30,
    ));

    $bot->hearCallbackQueryData("plan:begin:edit:{$plan->id}")->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('plan:editfields')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('plan:editfield:2')->reply()->assertCalled('editMessageText');
    $bot->hearText('نام جدید')->reply();
    $state = app(PlanAdminDrafts::class)->peek($root->id);
    expect($state['stage'])->toBe('preview')
        ->and(DB::table('sales_plans')->value('name'))->toBe('نام قدیمی');

    $bot->hearCallbackQueryData('plan:save:'.$state['token'])->reply();
    $bot->hearCallbackQueryData('plan:save:'.$state['token'])->reply();

    expect(DB::table('sales_plans')->value('name'))->toBe('نام جدید')
        ->and(DB::table('sales_plans')->value('revision'))->toBe(2)
        ->and(DB::table('sales_orders')->count())->toBe(0);

    $bot->hearCallbackQueryData("plan:begin:edit:{$plan->id}")->reply();
    $bot->hearCallbackQueryData('plan:editfields')->reply();
    $bot->hearCallbackQueryData('plan:editfield:5')->reply();
    $bot->hearCallbackQueryData('plan:editchoice:unknown')->reply();
    expect(app(PlanAdminDrafts::class)->peek($root->id)['stage'])->toBe('editfield:5');
    $bot->hearCallbackQueryData('plan:editchoice:both')->reply();
    expect(app(PlanAdminDrafts::class)->peek($root->id)['stage'])->toBe('preview');

    $bot->hearCallbackQueryData('plan:cancel')->reply();
    expect(app(PlanAdminDrafts::class)->peek($root->id))->toBeNull();

    $bot->hearCallbackQueryData("plan:begin:edit:{$plan->id}")->reply();
    $bot->hearCallbackQueryData('plan:editquick')->reply()->assertCalled('editMessageText');
    expect(app(PlanAdminDrafts::class)->peek($root->id)['stage'])->toBe('input')
        ->and(DB::table('sales_plans')->value('name'))->toBe('نام جدید');

    $bot->hearCallbackQueryData('plan:editfields')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('plan:cancel')->reply();
    expect(app(PlanAdminDrafts::class)->peek($root->id))->toBeNull();
});

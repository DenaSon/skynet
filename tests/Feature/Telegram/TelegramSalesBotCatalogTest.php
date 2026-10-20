<?php

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Interfaces\Telegram\Support\PendingBuilderSelection;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\CalculatePlanQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ListQuotedPresets;
use App\Modules\Sales\Application\ManagePlanPricing;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\PlanDefinition;
use App\Modules\Sales\Application\PlanPricingDefinition;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('telegram.sales.enabled', false);
    config()->set('telegram.public_signup_enabled', false);
    config()->set('super_admin.telegram_ids', ['977001', '977002']);
    app(ConfigureProduct::class)->handle('vpn-access', 'VPN Access');
    Role::findOrCreate('reseller', 'web');
    Http::preventStrayRequests();
    Http::fake();
});

function sb02Bot(int $telegramId, ?User $user = null): Nutgram
{
    if ($user !== null) {
        TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);
    }

    // Nutgram fake transport with the real Sales-only registry (not Admin).
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/sales.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Sales'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

function sb02Root(): User
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 977001]);

    return $root;
}

function sb02Plan(User $root, string $slug, string $audience = 'both', string $mode = 'preset', string $kind = 'hybrid'): int
{
    $builder = $mode === 'builder';
    $plan = app(ManagePlans::class)->create($root->id, new PlanDefinition(
        'vpn-access', $slug, 'Plan '.$slug, $kind, $mode, $audience,
        $builder || $kind === 'time' ? null : 30,
        $builder ? null : 30,
        $kind === 'time' ? null : 1,
        $kind === 'time' ? null : 100,
        15, 90,
    ));

    $plans = app(ManagePlans::class);
    $token = $plans->requestChange($root->id, $plan->id, 1, 'activate');
    $plans->confirmChange($root->id, $token);

    $prices = app(ManagePlanPricing::class);
    $revision = $prices->configure(
        $root->id, $plan->id, 2, 0,
        $builder
            ? new PlanPricingDefinition('formula', null, 100_000, 10_000, 2_000, 10, 100_000)
            : new PlanPricingDefinition('fixed', 1_000_000, 0, 0, 0, 10, 100_000),
    );
    $token = $prices->requestChange($root->id, $plan->id, 2, $revision, 'activate');
    $prices->confirmChange($root->id, $token);

    return $plan->id;
}

it('presents only current published plans to the linked member with working home, list and detail navigation', function (): void {
    $root = sb02Root();
    $publicId = sb02Plan($root, 'public-30');
    $privateId = sb02Plan($root, 'reseller-only', 'reseller');
    $member = User::factory()->create(['name' => 'Buyer']);
    $bot = sb02Bot(977010, $member);

    $bot->hearText('/start')->reply()->assertCalled('sendMessage', 2);
    $bot->hearText('/catalog')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('menu:plan-catalog')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('catalog:list:1')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData("catalog:detail:{$publicId}:1")->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData("catalog:detail:{$privateId}:1")->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('menu:home')->reply()->assertCalled('editMessageText');

    expect(collect(app(ListQuotedPresets::class)->handle($member->id))->pluck('plan.id')->all())
        ->toBe([$publicId]);
    expect(app(CalculatePlanQuote::class)->handle($member->id, $publicId, 30, 30)->totalPriceRials)
        ->toBe(1_000_000);
    Http::assertNothingSent();
});

it('shows reseller discounts once and safely previews custom builder volume and days in Sales', function (): void {
    $root = sb02Root();
    $presetId = sb02Plan($root, 'both-plan');
    $builderId = sb02Plan($root, 'reseller-builder', 'reseller', 'builder');
    $reseller = User::factory()->create(['name' => 'Reseller']);
    $reseller->assignRole('reseller');
    $bot = sb02Bot(977011, $reseller);

    $bot->hearText('/start')->reply()->assertCalled('sendMessage', 2);
    $bot->hearText('/catalog')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData("catalog:detail:{$presetId}:1")->reply()->assertCalled('editMessageText');
    $bot->hearText('/builder')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('menu:builder')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData("builder:detail:{$builderId}:1")->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData("builder:days:{$builderId}:30:1")->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData("builder:quote:{$builderId}:30:45:1")->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData("builder:custom:{$builderId}:1")->reply()->assertCalled('editMessageText');
    expect(app(PendingBuilderSelection::class)->peek($reseller->id))->not->toBeNull();
    $bot->hearText('35 | 45')->reply()->assertCalled('sendMessage');
    expect(app(PendingBuilderSelection::class)->peek($reseller->id))->toBeNull();
    $bot->hearCallbackQueryData('builder:cancel')->reply()->assertCalled('editMessageText');

    expect(app(CalculatePlanQuote::class)->handle($reseller->id, $presetId, 30, 30)->totalPriceRials)
        ->toBe(900_000);
    expect(app(CalculatePlanQuote::class)->handle($reseller->id, $builderId, 45, 30)->totalPriceRials)
        ->toBe(441_000);
    Http::assertNothingSent();
});

it('denies unauthorized reseller callbacks and stale role or deactivated actor without restarting', function (): void {
    $root = sb02Root();
    $builderId = sb02Plan($root, 'restricted-builder', 'reseller', 'builder');
    $user = User::factory()->create();
    $bot = sb02Bot(977012, $user);

    $bot->hearText('/builder')->reply()->assertReplyText('دسترسی مجاز نیست.');
    $bot->hearCallbackQueryData("builder:quote:{$builderId}:30:45:1")->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);

    $user->assignRole('reseller');
    $bot->hearText('/builder')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData("builder:custom:{$builderId}:1")->reply()->assertCalled('editMessageText');
    $user->syncRoles(['member']);
    $bot->hearText('35 | 45')->reply()->assertReplyText('دسترسی مجاز نیست.');
    $bot->hearCallbackQueryData("builder:quote:{$builderId}:30:45:1")->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
    $user->forceFill(['is_active' => false])->save();
    $bot->hearText('/catalog')->reply()->assertReplyText('دسترسی مجاز نیست.');
    Http::assertNothingSent();
});

it('rejects withdrawn prices, invalid callbacks and non-private interactions', function (): void {
    $root = sb02Root();
    $id = sb02Plan($root, 'withdrawn');
    $user = User::factory()->create();
    $bot = sb02Bot(977013, $user);
    $bot->hearCallbackQueryData('catalog:detail:invalid:1')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('catalog:list:bad')->reply()->assertCalled('editMessageText');

    $prices = app(ManagePlanPricing::class);
    $token = $prices->requestChange($root->id, $id, 2, 2, 'deactivate');
    $prices->confirmChange($root->id, $token);
    expect(app(ListQuotedPresets::class)->handle($user->id))->toBe([]);
    $bot->hearCallbackQueryData("catalog:detail:{$id}:1")->reply()->assertCalled('editMessageText');

    $bot->setCommonChat(Chat::make(-977013, ChatType::GROUP));
    $bot->hearText('/catalog')->reply()->assertNoReply();
    $bot->hearText('/builder')->reply()->assertNoReply();
    $bot->hearCallbackQueryData('menu:plan-catalog')->reply()->assertNoReply();
    $bot->hearCallbackQueryData('menu:builder')->reply()->assertNoReply();
    Http::assertNothingSent();
});

it('keeps orders, settlement, provisioning and admin commands unavailable in the Sales token', function (): void {
    $user = User::factory()->create();
    $bot = sb02Bot(977014, $user);

    foreach (['/buy', '/paid 1 ref', '/payments', '/roles', '/admin'] as $text) {
        $bot->hearText($text)->reply()->assertReplyText('برای ادامه، یکی از دکمه‌های منو را انتخاب کنید.');
    }
    foreach (['shop:order:forged', 'bank:apply:forged', 'menu:admin:users'] as $callback) {
        $bot->hearCallbackQueryData($callback)->reply()->assertCalled('answerCallbackQuery')->assertCalled('editMessageText');
    }
    expect(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('sales_bank_payment_claims')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(0);
    Http::assertNothingSent();
});

/**
 * Reproduce the route-owned context of the real Sales webhook. Nutgram's fake
 * registers handlers but does not set Laravel's HTTP route by itself.
 */
function sb02SalesWebhookContext(): void
{
    $route = new Route('POST', 'api/telegram/sales/webhook', static fn (): null => null);
    $route->defaults('bot_key', 'sales');
    $route->bind(request());
    request()->setRouteResolver(static fn (): Route => $route);
}

it('clearly offers the Sales plan-selection path without claiming an unverified purchase is available', function (): void {
    sb02SalesWebhookContext();
    expect(TelegramBotContext::current()->key)->toBe('sales');
    $member = User::factory()->create();
    $bot = sb02Bot(977020, $member);

    $bot->hearText('/catalog')->reply()->assertReplyText(
        "📋 پلن‌ها و قیمت‌ها\nصفحه 1 از 1\n\nقیمت‌ها از تعرفه فعال محاسبه می‌شوند و ممکن است تغییر کنند.\n"
        ."برای بررسی امکان ثبت سفارش، یک پلن را انتخاب کنید.\n\nدر حال حاضر پلنی برای نمایش وجود ندارد."
    );
    $bot->hearText('/start')->reply()->assertCalled('sendMessage', 2);

    expect(DB::table('sales_orders')->count())->toBe(0);
    Http::assertNothingSent();
});

it('gives Sales resellers truthful custom-plan guidance without unlocking finance or checkout', function (): void {
    sb02SalesWebhookContext();
    expect(TelegramBotContext::current()->key)->toBe('sales');
    $reseller = User::factory()->create();
    $reseller->assignRole('reseller');
    $bot = sb02Bot(977021, $reseller);

    $bot->hearText('/builder')->reply()->assertReplyText(
        "💼 پلن‌ساز نمایندگان\nصفحه 1 از 1\n\n"
        ."حجم و مدت دلخواه را انتخاب کنید؛ امکان ثبت سفارش پس از بررسی آمادگی سرویس مشخص می‌شود.\n\n"
        .'در حال حاضر پلن سفارشی فعالی برای نمایندگان وجود ندارد.'
    );

    expect(DB::table('sales_orders')->count())->toBe(0);
    Http::assertNothingSent();
});

it('shows bounded ready plan prices on home using current buyer quotes and preserves checkout boundaries', function (): void {
    sb02SalesWebhookContext();
    config()->set('services.remnawave.squad_bindings_live_verified', true);
    $root = sb02Root();
    $ready = [];
    for ($i = 1; $i <= 6; $i++) {
        $ready[] = sb02Plan($root, 'home-'.$i);
    }
    $restricted = sb02Plan($root, 'private-home', 'reseller');
    $unready = sb02Plan($root, 'unready-home');
    foreach ([...$ready, $restricted] as $id) {
        DB::table('sales_plan_internal_squad_bindings')->insert([
            'plan_id' => $id, 'plan_revision' => 2,
            'internal_squad_uuid' => '123e4567-e89b-12d3-a456-426614174000',
            'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    DB::table('sales_plans')->where('id', $ready[0])->update(['name' => '<Home> & offer']);
    $buyer = User::factory()->create();
    $bot = sb02Bot(977030, $buyer);
    $bot->hearText('/start')->reply()->assertCalled('sendMessage', 2)
        ->assertRaw(static function (Request $request) use ($ready, $restricted, $unready): bool {
            $body = FakeNutgram::getActualData($request);
            $encoded = json_encode($body, JSON_THROW_ON_ERROR);

            return $body['parse_mode'] === 'HTML'
                && str_contains($body['text'], '&lt;Home&gt; &amp; offer')
                && str_contains($body['text'], '<b>100,000 تومان</b>')
                && substr_count($body['text'], 'مبلغ:') === 5
                && str_contains($encoded, 'catalog:detail:'.$ready[0].':1')
                && ! str_contains($encoded, 'catalog:detail:'.$ready[5].':1')
                && ! str_contains($encoded, 'catalog:detail:'.$restricted.':1')
                && ! str_contains($encoded, 'catalog:detail:'.$unready.':1');
        }, index: 1);
    $bot->hearCallbackQueryData('sales:home:2')->reply()->assertCalled('sendMessage', 0)
        ->assertCalled('answerCallbackQuery')->assertCalled('editMessageText')
        ->assertRaw(static function (Request $request): bool {
            $body = FakeNutgram::getActualData($request);

            return substr_count($body['text'], 'مبلغ:') === 1 && str_contains($body['text'], 'صفحه 2 از 2');
        }, index: 1);
    $buyer->assignRole('reseller');
    $bot->hearCallbackQueryData('sales:home:1')->reply()->assertRaw(static function (Request $request): bool {
        $body = FakeNutgram::getActualData($request);

        return str_contains($body['text'], '<b>90,000 تومان</b>');
    }, index: 1);
    config()->set('services.remnawave.squad_bindings_live_verified', false);
    $bot->hearCallbackQueryData('sales:home:invalid')->reply()->assertRaw(static function (Request $request): bool {
        $body = FakeNutgram::getActualData($request);

        return str_contains($body['text'], 'فعلاً پلن آماده خریدی وجود ندارد')
            && ! str_contains($body['text'], 'مبلغ:');
    }, index: 1);
    expect(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('provisioning_operations')->count())->toBe(0);
    Http::assertNothingSent();
});

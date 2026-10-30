<?php

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Interfaces\Telegram\Support\PendingBuilderSelection;
use App\Interfaces\Telegram\Support\PendingShopVolume;
use App\Interfaces\Telegram\Support\ReplyMenuLabels;
use App\Interfaces\Telegram\Support\ReplyMenuRenderer;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\CalculatePlanQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ListResellerBuilders;
use App\Modules\Sales\Application\ManagePlanPricing;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\PlanDefinition;
use App\Modules\Sales\Application\PlanPricingDefinition;
use GuzzleHttp\Psr7\Request;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['966001', '966002']);
    config()->set('telegram.hybrid_menu_enabled', true);
    app(ConfigureProduct::class)->handle('vpn-access', 'VPN Access');
    Role::findOrCreate('reseller', 'web');
});

function b16Bot(int $id, ?User $user = null): Nutgram
{
    if ($user !== null) {
        TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $id]);
    }

    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($id, false, 'Test'));
    $bot->setCommonChat(Chat::make($id, ChatType::PRIVATE));

    return $bot;
}

function b16Root(): User
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 966001]);

    return $root;
}

function b16Reseller(): User
{
    $user = User::factory()->create();
    $user->assignRole('reseller');

    return $user;
}

function b16Builder(User $root, string $slug = 'builder-1', string $kind = 'hybrid'): int
{
    $plan = app(ManagePlans::class)->create($root->id, new PlanDefinition(
        'vpn-access', $slug, $slug, $kind, 'builder', 'reseller',
        null, null, $kind === 'time' ? null : 1, $kind === 'time' ? null : 100,
        15, 90,
    ));

    $plans = app(ManagePlans::class);
    $token = $plans->requestChange($root->id, $plan->id, 1, 'activate');
    $plans->confirmChange($root->id, $token);

    $prices = app(ManagePlanPricing::class);
    $revision = $prices->configure($root->id, $plan->id, 2, 0, new PlanPricingDefinition(
        'formula', null, 100_000, $kind === 'time' ? 0 : 10_000, 2_000, 10, 100_000,
    ));
    $token = $prices->requestChange($root->id, $plan->id, 2, $revision, 'activate');
    $prices->confirmChange($root->id, $token);

    return $plan->id;
}

/** Exercise the real Sales registry rather than the legacy Admin-context builder tests. */
function b16SalesBot(int $id, User $buyer): Nutgram
{
    $route = new Route('POST', 'api/telegram/sales/webhook', static fn (): null => null);
    $route->defaults('bot_key', TelegramBotContext::SALES);
    $route->bind(request());
    request()->setRouteResolver(static fn (): Route => $route);

    TelegramAccount::query()->create(['user_id' => $buyer->id, 'telegram_user_id' => $id]);
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/sales.php');
    $bot->setCommonUser(TelegramUser::make($id, false, 'Reseller'));
    $bot->setCommonChat(Chat::make($id, ChatType::PRIVATE));

    return $bot;
}

function b16Rich(Request $request): string
{
    $data = FakeNutgram::getActualData($request);
    $rich = is_string($data['rich_message'] ?? null)
        ? json_decode($data['rich_message'], true, 512, JSON_THROW_ON_ERROR)
        : ($data['rich_message'] ?? []);

    return (string) ($rich['html'] ?? '');
}

it('renders real reseller prices and a guarded green checkout CTA in Rich without creating orders', function (): void {
    Http::preventStrayRequests();
    Http::fake();
    $root = b16Root();
    $id = b16Builder($root);
    $reseller = b16Reseller();
    $bot = b16SalesBot(966121, $reseller);
    $checkout = 'sales:checkout:preview:'.$id.':45:30';

    $bot->hearCallbackQueryData("builder:quote:{$id}:30:45:1")->reply()->assertRaw(
        static function (Request $request) use ($id, $checkout): bool {
            $data = FakeNutgram::getActualData($request);
            $rich = is_string($data['rich_message'] ?? null)
                ? json_decode($data['rich_message'], true, 512, JSON_THROW_ON_ERROR)
                : ($data['rich_message'] ?? []);
            $html = b16Rich($request);
            $buttons = (string) ($data['reply_markup'] ?? '');

            return ($rich['is_rtl'] ?? false) === true
                && str_contains($html, '49,000 تومان')
                && str_contains($html, '10٪')
                && str_contains($html, '44,100 تومان')
                && str_contains($html, '<tg-button type="callback_data" style="success" data="'.$checkout.'">🛒 ادامه خرید</tg-button>')
                && str_contains($buttons, $checkout)
                && str_contains($buttons, "builder:quote:simple:{$id}:30:45:1")
                && str_contains($buttons, 'builder:custom:'.$id.':1');
        }, index: 1,
    );

    $bot->hearCallbackQueryData("builder:quote:simple:{$id}:30:45:1")->reply()->assertRaw(
        static function (Request $request) use ($checkout): bool {
            $data = FakeNutgram::getActualData($request);

            return ! isset($data['rich_message'])
                && str_contains((string) ($data['text'] ?? ''), 'قیمت عمومی: 49,000 تومان')
                && str_contains((string) ($data['text'] ?? ''), 'قیمت مخصوص پنل فروش: 44,100 تومان')
                && str_contains((string) ($data['reply_markup'] ?? ''), $checkout);
        }, index: 1,
    );

    $bot->hearCallbackQueryData("builder:custom:{$id}:1")->reply()->assertCalled('editMessageText');
    $bot->hearText('۳۵ | ۴۵')->reply()->assertCalled('sendRichMessage')->assertRaw(
        static function (Request $request) use ($id): bool {
            $html = b16Rich($request);

            return str_contains($html, '48,600 تومان')
                && str_contains($html, 'sales:checkout:preview:'.$id.':45:35')
                && ! str_contains($html, 'sensitive-credential');
        },
    );

    expect(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('billing_postings')->count())->toBe(0);
    Http::assertNothingSent();
});

it('rechecks reseller access on old simple quote callbacks', function (): void {
    $root = b16Root();
    $id = b16Builder($root);
    $reseller = b16Reseller();
    $bot = b16SalesBot(966122, $reseller);
    $reseller->syncRoles(['member']);

    $bot->hearCallbackQueryData("builder:quote:simple:{$id}:30:45:1")->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
    expect(DB::table('sales_orders')->count())->toBe(0);
});

it('requires an active reseller for list, selection and price preview', function (): void {
    $root = b16Root();
    $id = b16Builder($root);

    $member = User::factory()->create();
    $bot = b16Bot(966010, $member);
    $bot->hearText('/builder')->reply()->assertReplyText('دسترسی مجاز نیست.');
    $bot->hearCallbackQueryData("builder:quote:{$id}:30:45:1")->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);

    expect(fn () => app(ListResellerBuilders::class)->handle($member->id))->toThrow(AuthorizationException::class);

    $reseller = b16Reseller();
    $bot = b16Bot(966011, $reseller);
    $bot->hearText('/builder')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData("builder:quote:{$id}:30:45:1")->reply()->assertCalled('editMessageText');

    $reseller->syncRoles(['member']);
    $bot->hearCallbackQueryData("builder:quote:{$id}:30:45:1")->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);

    $reseller->assignRole('reseller');
    $reseller->forceFill(['is_active' => false])->save();
    $bot->hearText('/builder')->reply()->assertReplyText('دسترسی مجاز نیست.');
});

it('discovers only published reseller builders and excludes missing or stale prices', function (): void {
    $root = b16Root();
    $id = b16Builder($root);
    $buyer = b16Reseller();
    $catalog = app(ListResellerBuilders::class);

    expect(collect($catalog->handle($buyer->id))->pluck('id')->all())->toBe([$id]);

    $draft = app(ManagePlans::class)->create($root->id, new PlanDefinition(
        'vpn-access', 'draft-builder', 'Draft Builder', 'hybrid', 'builder', 'reseller',
        null, null, 1, 100, 15, 90,
    ));
    expect(collect($catalog->handle($buyer->id))->pluck('id')->all())->toBe([$id]);

    $price = app(ManagePlanPricing::class);
    $token = $price->requestChange($root->id, $id, 2, 2, 'deactivate');
    $price->confirmChange($root->id, $token);
    expect($catalog->handle($buyer->id))->toBe([]);

    // Republish an up-to-date tariff.
    $revision = $price->configure($root->id, $id, 2, 3, new PlanPricingDefinition(
        'formula', null, 100_000, 10_000, 2_000, 10, 100_000,
    ));
    $token = $price->requestChange($root->id, $id, 2, $revision, 'activate');
    $price->confirmChange($root->id, $token);

    expect($catalog->handle($buyer->id))->toHaveCount(1);
    $plans = app(ManagePlans::class);
    $revision = $plans->find($root->id, $id)->revision;
    $current = $plans->find($root->id, $id);
    $plans->update($root->id, $id, $revision, new PlanDefinition(
        'vpn-access', 'builder-1', 'Updated', 'hybrid', 'builder', 'reseller',
        null, null, 1, 100, 15, 90,
    ));

    expect($catalog->handle($buyer->id))->toBe([]);
    expect(fn () => $catalog->find($buyer->id, $draft->id))->toThrow(ValidationException::class);
});

it('uses canonical integer-rial pricing with exactly one published reseller discount', function (): void {
    $root = b16Root();
    $id = b16Builder($root);
    $reseller = b16Reseller();
    $quote = app(CalculatePlanQuote::class)->handle($reseller->id, $id, 45, 30);

    expect($quote->publicPriceRials)->toBe(490_000)
        ->and($quote->discountPercent)->toBe(10)
        ->and($quote->discountRials)->toBe(49_000)
        ->and($quote->totalPriceRials)->toBe(441_000)
        ->and($quote->trafficGb)->toBe(30)
        ->and($quote->durationDays)->toBe(45);

    $bot = b16Bot(966012, $reseller);
    $bot->hearCallbackQueryData("builder:detail:{$id}:1")->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData("builder:days:{$id}:30:1")->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData("builder:quote:{$id}:30:45:1")->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
});

it('supports custom Persian numerals and a ten-minute owner-scoped input', function (): void {
    $root = b16Root();
    $id = b16Builder($root);
    $reseller = b16Reseller();
    $bot = b16Bot(966013, $reseller);
    Http::preventStrayRequests();
    Http::fake();

    $bot->hearCallbackQueryData("builder:custom:{$id}:1")->reply()->assertCalled('editMessageText');
    expect(app(PendingBuilderSelection::class)->peek($reseller->id))->not->toBeNull();

    $bot->hearText('bad text')->reply()->assertCalled('sendMessage');
    $bot->hearText('۳۵ | ۴۵')->reply()->assertCalled('sendMessage');
    expect(app(PendingBuilderSelection::class)->peek($reseller->id))->toBeNull();

    $bot->hearCallbackQueryData("builder:custom:{$id}:1")->reply();
    $this->travel(11)->minutes();
    expect(app(PendingBuilderSelection::class)->peek($reseller->id))->toBeNull();
    $bot->hearText('35 | 45')->reply()->assertNoReply();
    $this->travelBack();

    $bot->hearCallbackQueryData("builder:custom:{$id}:1")->reply();
    $bot->hearCallbackQueryData('builder:cancel')->reply();
    expect(app(PendingBuilderSelection::class)->peek($reseller->id))->toBeNull();

    expect(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(0)
        ->and(DB::table('billing_postings')->count())->toBe(0);
    Http::assertNothingSent();
});

it('accepts unlimited-time builder only with null traffic and validates bounds', function (): void {
    $root = b16Root();
    $id = b16Builder($root, 'time-builder', 'time');
    $reseller = b16Reseller();
    $bot = b16Bot(966014, $reseller);

    $bot->hearCallbackQueryData("builder:days:{$id}:0:1")->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData("builder:quote:{$id}:0:30:1")->reply()->assertCalled('editMessageText');

    $bot->hearCallbackQueryData("builder:quote:{$id}:30:30:1")->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData("builder:quote:{$id}:0:365:1")->reply()->assertCalled('editMessageText');

    $bot->hearCallbackQueryData("builder:custom:{$id}:1")->reply();
    $bot->hearText('- | ۳۰')->reply()->assertCalled('sendMessage');

    $quote = app(CalculatePlanQuote::class)->handle($reseller->id, $id, 30, null);
    expect($quote->trafficGb)->toBeNull()
        ->and($quote->totalPriceRials)->toBe(144_000);

    expect(fn () => app(CalculatePlanQuote::class)->handle($reseller->id, $id, 30, 10))
        ->toThrow(ValidationException::class);
});

it('blocks group, unlinked, stale, invalid and cross-user builder inputs', function (): void {
    $root = b16Root();
    $id = b16Builder($root);
    $reseller = b16Reseller();
    $bot = b16Bot(966015, $reseller);

    $bot->hearCallbackQueryData("builder:quote:{$id}:99999:30:1")->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData("builder:quote:{$id}:30:0:1")->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('builder:detail:bad:1')->reply()->assertCalled('editMessageText');

    $bot->hearCallbackQueryData("builder:custom:{$id}:1")->reply();
    $other = b16Reseller();
    $otherBot = b16Bot(966016, $other);
    $otherBot->hearText('35 | 45')->reply()->assertNoReply();
    expect(app(PendingBuilderSelection::class)->peek($reseller->id))->not->toBeNull()
        ->and(app(PendingBuilderSelection::class)->peek($other->id))->toBeNull();

    // Nutgram's fake is singleton: restore the first actor explicitly.
    $bot->setCommonUser(TelegramUser::make(966015, false, 'First'));
    $bot->setCommonChat(Chat::make(-966015, ChatType::GROUP));
    $bot->hearText('35 | 45')->reply()->assertNoReply();
    $bot->hearText('/builder')->reply()->assertNoReply();
    $bot->setCommonChat(Chat::make(966015, ChatType::PRIVATE));
    expect(app(PendingBuilderSelection::class)->peek($reseller->id))->not->toBeNull();

    $reseller->syncRoles(['member']);
    $bot->hearText('35 | 45')->reply()->assertCalled('sendMessage');
    expect(app(PendingBuilderSelection::class)->peek($reseller->id))->toBeNull();

    $unlinked = b16Bot(966017);
    $unlinked->hearText('/builder')->reply()->assertReplyText('دسترسی مجاز نیست.');
});

it('exposes builder only in reseller navigation and leaves F09 buying untouched', function (): void {
    $root = b16Root();
    $id = b16Builder($root);
    $reseller = b16Reseller();
    $bot = b16Bot(966018, $reseller);
    $actor = app(ResolveTelegramActor::class)->handle(966018);
    $keyboard = app(ReplyMenuRenderer::class)->homeKeyboard($actor);
    $labels = array_map(static fn ($button): string => $button->text, array_merge(...$keyboard->keyboard));
    expect(ReplyMenuLabels::BUILDER)->toBe('🧩 پلن سفارشی')
        ->and($labels)->toContain(ReplyMenuLabels::BUILDER)
        ->not->toContain(ReplyMenuLabels::PREVIOUS_BUILDER);

    $bot->hearText(ReplyMenuLabels::BUILDER)->reply()->assertCalled('sendMessage');
    $bot->hearText(ReplyMenuLabels::PREVIOUS_BUILDER)->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('menu:builder')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData("builder:detail:{$id}:1")->reply()->assertCalled('editMessageText');

    app(PendingShopVolume::class)->remember($reseller->id, 'vpn-access', 30);
    $bot->hearCallbackQueryData('menu:builder')->reply();
    expect(app(PendingShopVolume::class)->peek($reseller->id))->toBeNull();

    $bot->hearText('/buy')->reply()->assertCalled('sendMessage');
    config()->set('telegram.hybrid_menu_enabled', false);
    $bot->hearText(ReplyMenuLabels::BUILDER)->reply()->assertNoReply();
    $bot->hearText('/builder')->reply()->assertCalled('sendMessage');

    expect(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
});

it('renders custom plan button on Sales reseller home and Account without changing callback routing', function (): void {
    Http::preventStrayRequests();
    Http::fake();
    $reseller = b16Reseller();
    $bot = b16SalesBot(966178, $reseller);
    $actor = app(ResolveTelegramActor::class)->handle(966178);
    $keyboard = app(ReplyMenuRenderer::class)->salesKeyboard($actor);
    $labels = array_map(static fn ($button): string => $button->text, array_merge(...$keyboard->keyboard));

    expect($labels)->toContain('🧩 پلن سفارشی')
        ->not->toContain(ReplyMenuLabels::PREVIOUS_BUILDER);
    $bot->hearText(ReplyMenuLabels::PREVIOUS_BUILDER)->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('sales:home')->reply()->assertRaw(static function (Request $request): bool {
        $data = FakeNutgram::getActualData($request);
        $buttons = json_encode($data['reply_markup'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return str_contains($buttons, '🧩 پلن سفارشی')
            && str_contains($buttons, 'menu:builder');
    }, index: 1);
    $bot->hearCallbackQueryData('sales:account')->reply()->assertRaw(static function (Request $request): bool {
        $data = FakeNutgram::getActualData($request);
        $buttons = json_encode($data['reply_markup'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return str_contains($buttons, '🧩 پلن سفارشی')
            && str_contains($buttons, 'menu:builder');
    }, index: 1);
});

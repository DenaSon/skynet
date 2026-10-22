<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ReadResellerLiveSubscriptions;
use App\Modules\Sales\Application\CalculateQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Sales\Application\CreatePendingOrder;
use App\Modules\Sales\Application\ManageResellerServiceLabels;
use App\Modules\Sales\Application\ReadResellerSalesReport;
use GuzzleHttp\Psr7\Request;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
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
    Role::findOrCreate('reseller', 'web');
    Role::findOrCreate('member', 'web');
    config()->set('services.remnawave.writes_enabled', false);
    config()->set('services.remnawave.url', 'https://rw-label.test');
    config()->set('services.remnawave.token', 'fake-token');
    config()->set('sales.manual_bank_review_enabled', false);
    Http::preventStrayRequests();
    Http::fake();

    app(ConfigureProduct::class)->handle('rsl-product', 'پلن نمایندگی');
    app(ConfigureRate::class)->handle('rsl-product', 30, 100_000, 20_000);
    $route = new Route('POST', 'api/telegram/sales/webhook', static fn (): null => null);
    $route->defaults('bot_key', 'sales');
    $route->bind(request());
    request()->setRouteResolver(static fn (): Route => $route);
});

function rslUser(bool $reseller = true): User
{
    $buyer = User::factory()->create();
    $buyer->syncRoles([$reseller ? 'reseller' : 'member']);

    return $buyer;
}

function rslOrder(User $buyer, bool $linked = true): int
{
    $quote = app(CalculateQuote::class)->handle($buyer->id, 'rsl-product', 30, 10);
    $id = app(CreatePendingOrder::class)->handle($buyer->id, $quote, bin2hex(random_bytes(16)))->id;
    if ($linked) {
        DB::table('sales_orders')->where('id', $id)->update(['status' => 'fulfilled']);
        $subscription = DB::table('provisioning_subscriptions')->insertGetId([
            'reference' => 'rsl-label:'.$id,
            'remote_username' => 'sk_'.substr(hash('sha256', 'rsl-label:'.$id), 0, 24),
            'remote_user_id' => $id + 2000,
            'state' => 'active',
            'last_operation_key' => 'rsl-label:'.$id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('provisioning_order_links')->insert([
            'sales_order_id' => $id,
            'provisioning_subscription_id' => $subscription,
            'created_at' => now(),
        ]);
    }

    return $id;
}

function rslBot(User $user, int $telegramId): Nutgram
{
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/sales.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Sales'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

it('stores, replaces and deletes only reseller-owned service labels without modifying orders or subscriptions', function (): void {
    $buyer = rslUser();
    $order = rslOrder($buyer);
    $labels = app(ManageResellerServiceLabels::class);
    $snapshot = DB::table('sales_orders')->where('id', $order)->first();

    expect($labels->get($buyer->id, $order))->toBeNull();
    $labels->save($buyer->id, $order, '  علی    رضایی  ');
    expect($labels->get($buyer->id, $order))->toBe('علی رضایی')
        ->and($labels->forOrders($buyer->id, [$order]))->toBe([$order => 'علی رضایی']);

    $labels->save($buyer->id, $order, 'سارا');
    expect($labels->get($buyer->id, $order))->toBe('سارا')
        ->and(DB::table('sales_service_customer_labels')->count())->toBe(1)
        ->and(DB::table('sales_orders')->where('id', $order)->first())->toEqual($snapshot);

    $labels->save($buyer->id, $order, null);
    $labels->save($buyer->id, $order, null);
    expect($labels->get($buyer->id, $order))->toBeNull()
        ->and(DB::table('sales_service_customer_labels')->count())->toBe(0)
        ->and(DB::table('provisioning_order_links')->count())->toBe(1);
    Http::assertNothingSent();
});

it('rejects foreign, unpaid, unlinked, revoked and invalid label attempts', function (): void {
    $buyer = rslUser();
    $other = rslUser();
    $member = rslUser(false);
    $order = rslOrder($buyer);
    $unpaid = rslOrder($buyer, false);
    $labels = app(ManageResellerServiceLabels::class);

    expect(fn () => $labels->save($other->id, $order, 'غیرمجاز'))->toThrow(ValidationException::class)
        ->and(fn () => $labels->get($other->id, $order))->toThrow(ValidationException::class)
        ->and($labels->forOrders($other->id, [$order]))->toBe([])
        ->and(fn () => $labels->save($buyer->id, $unpaid, 'بدون اشتراک'))->toThrow(ValidationException::class)
        ->and(fn () => $labels->save($member->id, $order, 'member'))->toThrow(AuthorizationException::class)
        ->and(fn () => $labels->save($buyer->id, $order, str_repeat('x', 61)))->toThrow(ValidationException::class)
        ->and(fn () => $labels->save($buyer->id, $order, '  '))->toThrow(ValidationException::class);

    $labels->save($buyer->id, $order, 'مشتری');
    $buyer->syncRoles(['member']);
    expect(fn () => $labels->save($buyer->id, $order, 'تغییر'))->toThrow(AuthorizationException::class)
        ->and(fn () => $labels->get($buyer->id, $order))->toThrow(AuthorizationException::class)
        ->and(DB::table('sales_service_customer_labels')->where('sales_order_id', $order)->value('label'))->toBe('مشتری');

    $buyer->syncRoles(['reseller']);
    $buyer->forceFill(['is_active' => false])->save();
    expect(fn () => $labels->save($buyer->id, $order, null))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('lets a reseller name their service in Sales Bot and displays escaped labels only in their own list and details', function (): void {
    $buyer = rslUser();
    $order = rslOrder($buyer);
    $bot = rslBot($buyer, 977901);
    $key = TelegramBotContext::current()->cacheKey('telegram:subs:customer_label:'.$buyer->id);

    $bot->hearCallbackQueryData("sales:subs:label:edit:{$order}:0")->reply()
        ->assertCalled('editMessageText');
    expect(Cache::has($key))->toBeTrue();

    $bot->hearText('علی & <دوست>')->reply()->assertCalled('sendMessage');
    expect(Cache::has($key))->toBeFalse()
        ->and(DB::table('sales_service_customer_labels')->where('sales_order_id', $order)->value('label'))->toBe('علی & <دوست>');

    $bot->hearCallbackQueryData('sales:subs:list:0')->reply()
        ->assertRaw(static function (Request $request) use ($order): bool {
            $data = FakeNutgram::getActualData($request);
            $keyboard = json_decode($data['reply_markup'], true, 512, JSON_THROW_ON_ERROR);

            return str_contains($data['text'], '1. 👤 علی &amp; &lt;دوست&gt;')
                && str_contains($data['text'], 'پلن: پلن نمایندگی')
                && ! str_contains($data['text'], 'سرویس #')
                && ! str_contains($data['text'], 'علی & <دوست>')
                && ($keyboard['inline_keyboard'][0][0]['text'] ?? null) === '📋 جزئیات 1'
                && ($keyboard['inline_keyboard'][0][0]['callback_data'] ?? null) === "sales:subs:view:{$order}:0";
        }, index: 1);

    $bot->hearCallbackQueryData("sales:subs:view:{$order}:0")->reply()
        ->assertRaw(static function (Request $request) use ($order): bool {
            $data = FakeNutgram::getActualData($request);

            return str_contains($data['text'], '📋 👤 علی &amp; &lt;دوست&gt;')
                && ! str_contains($data['text'], "سرویس #{$order}")
                && str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), "sales:subs:label:edit:{$order}:0");
        }, index: 2);
});

it('confirms deletion, cancels drafts on Home and refuses forged edit or revoked-role input', function (): void {
    $buyer = rslUser();
    $other = rslUser();
    $order = rslOrder($buyer);
    $bot = rslBot($buyer, 977902);
    $foreignBot = rslBot($other, 977903);
    $labels = app(ManageResellerServiceLabels::class);
    $labels->save($buyer->id, $order, 'سرویس مشتری');

    $foreignBot->hearCallbackQueryData("sales:subs:label:edit:{$order}:0")->reply()->assertCalled('editMessageText');
    $foreignBot->hearText('غیرمجاز')->reply();
    expect($labels->get($buyer->id, $order))->toBe('سرویس مشتری');

    $bot->hearCallbackQueryData("sales:subs:label:remove:{$order}:0")->reply()
        ->assertCalled('editMessageText');
    expect($labels->get($buyer->id, $order))->toBe('سرویس مشتری');
    $bot->hearCallbackQueryData("sales:subs:label:clear:{$order}:0")->reply()
        ->assertCalled('editMessageText');
    expect($labels->get($buyer->id, $order))->toBeNull();

    $bot->hearCallbackQueryData("sales:subs:label:edit:{$order}:0")->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:home')->reply()->assertCalled('editMessageText');
    $bot->hearText('نباید ذخیره شود')->reply();
    expect(DB::table('sales_service_customer_labels')->count())->toBe(0);

    $bot->hearCallbackQueryData("sales:subs:label:edit:{$order}:0")->reply()->assertCalled('editMessageText');
    $buyer->syncRoles(['member']);
    $bot->hearText('نقش لغوشده')->reply()->assertCalled('sendMessage');
    expect(DB::table('sales_service_customer_labels')->count())->toBe(0);

    $bot->setCommonChat(Chat::make(-977902, ChatType::GROUP));
    $bot->hearCallbackQueryData("sales:subs:label:edit:{$order}:0")->reply()->assertNoReply();
    Http::assertNothingSent();
});

it('uses a plan-based title for unnamed services and page positions only for visible buttons', function (): void {
    $buyer = rslUser();
    $older = rslOrder($buyer);
    $newer = rslOrder($buyer);
    $bot = rslBot($buyer, 977904);

    $bot->hearCallbackQueryData('sales:subs:list:0')->reply()
        ->assertRaw(static function (Request $request) use ($older, $newer): bool {
            $data = FakeNutgram::getActualData($request);
            $keyboard = json_decode($data['reply_markup'], true, 512, JSON_THROW_ON_ERROR);

            return str_contains($data['text'], '1. پلن نمایندگی')
                && str_contains($data['text'], '2. پلن نمایندگی')
                && ! str_contains($data['text'], "سرویس #{$older}")
                && ! str_contains($data['text'], "سرویس #{$newer}")
                && ($keyboard['inline_keyboard'][0][0]['text'] ?? null) === '📋 جزئیات 1'
                && ($keyboard['inline_keyboard'][1][0]['text'] ?? null) === '📋 جزئیات 2'
                && ($keyboard['inline_keyboard'][0][0]['callback_data'] ?? null) === "sales:subs:view:{$newer}:0"
                && ($keyboard['inline_keyboard'][1][0]['callback_data'] ?? null) === "sales:subs:view:{$older}:0";
        }, index: 1);

    $bot->hearCallbackQueryData("sales:subs:view:{$newer}:0")->reply()
        ->assertRaw(static function (Request $request) use ($newer): bool {
            $data = FakeNutgram::getActualData($request);

            return str_contains($data['text'], '<b>📋 پلن نمایندگی</b>')
                && ! str_contains($data['text'], "سرویس #{$newer}")
                && str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), "sales:subs:label:edit:{$newer}:0");
        }, index: 2);
});

it('finds only linked paid reseller-owned labels by name fragment with bounded pagination', function (): void {
    $buyer = rslUser();
    $other = rslUser();
    $labels = app(ManageResellerServiceLabels::class);

    $first = rslOrder($buyer);
    $second = rslOrder($buyer);
    $third = rslOrder($buyer);
    $unnamed = rslOrder($buyer);
    $foreign = rslOrder($other);
    $unlinked = rslOrder($buyer, false);

    $labels->save($buyer->id, $first, 'علی احمدی');
    $labels->save($buyer->id, $second, 'علی رضایی');
    $labels->save($buyer->id, $third, 'رضا علی‌پور');
    $labels->save($other->id, $foreign, 'علی همسایه');
    DB::table('sales_service_customer_labels')->insert([
        'sales_order_id' => $unlinked,
        'label' => 'علی بدون اشتراک',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $page1 = $labels->search($buyer->id, ' علی ', 2);
    expect(array_column($page1['items'], 'orderId'))->toBe([$third, $second])
        ->and($page1['nextBeforeOrderId'])->toBe($second);
    $page2 = $labels->search($buyer->id, 'علی', 2, $page1['nextBeforeOrderId']);
    expect(array_column($page2['items'], 'orderId'))->toBe([$first])
        ->and($page2['nextBeforeOrderId'])->toBeNull()
        ->and($labels->search($buyer->id, 'غیرموجود')['items'])->toBe([])
        ->and($labels->search($other->id, 'علی')['items'])->toBe([['orderId' => $foreign, 'label' => 'علی همسایه', 'phone' => null]])
        ->and($unnamed)->toBeGreaterThan(0);

    Http::assertNothingSent();
});

it('treats percent and underscore as literal fragments and denies revoked roles and invalid terms', function (): void {
    $buyer = rslUser();
    $labels = app(ManageResellerServiceLabels::class);
    $literal = rslOrder($buyer);
    $plain = rslOrder($buyer);
    $labels->save($buyer->id, $literal, 'نام %_ خاص');
    $labels->save($buyer->id, $plain, 'نام ABC خاص');

    expect(array_column($labels->search($buyer->id, '%_')['items'], 'orderId'))->toBe([$literal])
        ->and(array_column($labels->search($buyer->id, '_')['items'], 'orderId'))->toBe([$literal])
        ->and(fn () => $labels->search($buyer->id, ''))->toThrow(ValidationException::class)
        ->and(fn () => $labels->search($buyer->id, str_repeat('a', 61)))->toThrow(ValidationException::class)
        ->and(fn () => $labels->search($buyer->id, "علی\nرضا"))->toThrow(ValidationException::class)
        ->and(fn () => $labels->search($buyer->id, 'علی', 6, -1))->toThrow(ValidationException::class);

    $buyer->syncRoles(['member']);
    expect(fn () => $labels->search($buyer->id, 'نام'))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('searches only private reseller customer names and routes results to original service details', function (): void {
    $buyer = rslUser();
    $labels = app(ManageResellerServiceLabels::class);
    $named = rslOrder($buyer);
    $unmatched = rslOrder($buyer);
    $labels->save($buyer->id, $named, 'علی & <دوست>');
    $labels->save($buyer->id, $unmatched, 'سارا');
    $bot = rslBot($buyer, 977905);
    $key = TelegramBotContext::current()->cacheKey('telegram:subs:customer_search:'.$buyer->id);

    $bot->hearCallbackQueryData('sales:subs:list:0')->reply()
        ->assertRaw(static function (Request $request): bool {
            $data = FakeNutgram::getActualData($request);

            return str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'sales:subs:search:start');
        }, index: 1);

    $bot->hearCallbackQueryData('sales:subs:search:start')->reply()
        ->assertCalled('editMessageText');
    expect(Cache::has($key))->toBeTrue();

    $bot->hearText('علی')->reply()
        ->assertRaw(static function (Request $request) use ($named, $unmatched): bool {
            $data = FakeNutgram::getActualData($request);
            $markup = is_string($data['reply_markup'])
                ? json_decode($data['reply_markup'], true, 512, JSON_THROW_ON_ERROR)
                : $data['reply_markup'];

            return str_contains($data['text'], 'علی &amp; &lt;دوست&gt;')
                && ! str_contains($data['text'], 'سارا')
                && ! str_contains($data['text'], 'علی & <دوست>')
                && ($markup['inline_keyboard'][0][0]['callback_data'] ?? null) === "sales:subs:view:{$named}:0"
                && ! str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), "sales:subs:view:{$unmatched}:0");
        });
    expect(Cache::get($key)['mode'])->toBe('results');

    $bot->hearCallbackQueryData('sales:subs:search:page:0')->reply()
        ->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:subs:search:cancel')->reply()
        ->assertCalled('editMessageText');
    expect(Cache::has($key))->toBeFalse();
    $bot->hearText('علی')->reply();
    expect(Cache::has($key))->toBeFalse();
    Http::assertNothingSent();
});

it('pages matching names and protects missing state, group chats and revoked reseller input', function (): void {
    $buyer = rslUser();
    $labels = app(ManageResellerServiceLabels::class);
    for ($i = 1; $i <= 8; $i++) {
        $labels->save($buyer->id, rslOrder($buyer), 'مشتری آزمایشی '.$i);
    }
    $bot = rslBot($buyer, 977906);
    $key = TelegramBotContext::current()->cacheKey('telegram:subs:customer_search:'.$buyer->id);

    $bot->hearCallbackQueryData('sales:subs:search:start')->reply();
    $bot->hearText('مشتری')->reply()
        ->assertRaw(static function (Request $request): bool {
            $data = FakeNutgram::getActualData($request);

            return str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'sales:subs:search:page:');
        });
    $cursor = $labels->search($buyer->id, 'مشتری', 6)['nextBeforeOrderId'];
    expect($cursor)->not->toBeNull();
    $bot->hearCallbackQueryData('sales:subs:search:page:'.$cursor)->reply()
        ->assertRaw(static function (Request $request): bool {
            $data = FakeNutgram::getActualData($request);

            return str_contains($data['text'], 'نتایج جستجو')
                && str_contains($data['text'], 'مشتری آزمایشی 2')
                && ! str_contains($data['text'], 'مشتری آزمایشی 8');
        }, index: 1);

    $bot->hearCallbackQueryData('sales:home')->reply();
    expect(Cache::has($key))->toBeFalse();
    $bot->hearCallbackQueryData('sales:subs:search:page:'.$cursor)->reply()
        ->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:subs:search:start')->reply();
    $buyer->syncRoles(['member']);
    $bot->hearText('مشتری')->reply()->assertCalled('sendMessage');
    expect(Cache::has($key))->toBeFalse();
    $bot->setCommonChat(Chat::make(-977906, ChatType::GROUP));
    $bot->hearCallbackQueryData('sales:subs:search:start')->reply()->assertNoReply();
    Http::assertNothingSent();
});

it('normalizes reseller phones and searches only owner-scoped customer names or mobile fragments', function (): void {
    $buyer = rslUser();
    $other = rslUser();
    $order = rslOrder($buyer);
    $action = app(ManageResellerServiceLabels::class);
    $before = DB::table('sales_orders')->where('id', $order)->first();

    $action->savePhone($buyer->id, $order, '۰۹۱۲۳۴۵۶۷۸۹');
    expect($action->phone($buyer->id, $order))->toBe('+989123456789')
        ->and($action->get($buyer->id, $order))->toBeNull()
        ->and($action->search($buyer->id, '0912')['items'][0]['phone'])->toBe('+989123456789')
        ->and($action->search($buyer->id, '۹۱۲۳۴')['items'][0]['orderId'])->toBe($order)
        ->and($action->search($buyer->id, '+989123456789')['items'][0]['orderId'])->toBe($order)
        ->and(DB::table('sales_orders')->where('id', $order)->first())->toEqual($before);
    $action->save($buyer->id, $order, 'محمد احمدی');
    $action->save($buyer->id, $order, null);
    expect($action->get($buyer->id, $order))->toBeNull()
        ->and($action->phone($buyer->id, $order))->toBe('+989123456789');
    $action->savePhone($buyer->id, $order, null);
    expect($action->phone($buyer->id, $order))->toBeNull()
        ->and(DB::table('sales_service_customer_labels')->where('sales_order_id', $order)->exists())->toBeFalse()
        ->and(fn () => $action->savePhone($other->id, $order, '09123456789'))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('rejects malformed and unlinked phone changes and revoked reseller access', function (): void {
    $buyer = rslUser();
    $other = rslUser();
    $order = rslOrder($buyer);
    $unlinked = rslOrder($buyer, false);
    $action = app(ManageResellerServiceLabels::class);

    expect(fn () => $action->savePhone($buyer->id, $order, '123456'))->toThrow(ValidationException::class)
        ->and(fn () => $action->savePhone($buyer->id, $order, '0912345678'))->toThrow(ValidationException::class)
        ->and(fn () => $action->savePhone($buyer->id, $unlinked, '09123456789'))->toThrow(ValidationException::class)
        ->and(fn () => $action->phone($other->id, $order))->toThrow(ValidationException::class)
        ->and($action->search($other->id, '0912')['items'])->toBe([]);
    $buyer->syncRoles(['member']);
    expect(fn () => $action->savePhone($buyer->id, $order, '09123456789'))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('edits and removes a reseller phone through the private Sales bot', function (): void {
    $buyer = rslUser();
    $order = rslOrder($buyer);
    $action = app(ManageResellerServiceLabels::class);
    $action->save($buyer->id, $order, 'محمد اسدی');
    $bot = rslBot($buyer, 977910);
    $key = TelegramBotContext::current()->cacheKey('telegram:subs:customer_phone:'.$buyer->id);

    $bot->hearCallbackQueryData("sales:subs:phone:edit:{$order}:0")->reply()
        ->assertCalled('editMessageText');
    expect(Cache::has($key))->toBeTrue();
    $bot->hearText('۰۹۱۲۳۴۵۶۷۸۹')->reply()->assertCalled('sendMessage');
    expect(Cache::has($key))->toBeFalse()
        ->and($action->phone($buyer->id, $order))->toBe('+989123456789')
        ->and($action->get($buyer->id, $order))->toBe('محمد اسدی');

    $bot->hearCallbackQueryData("sales:subs:phone:remove:{$order}:0")->reply()
        ->assertCalled('editMessageText');
    expect($action->phone($buyer->id, $order))->toBe('+989123456789');
    $bot->hearCallbackQueryData("sales:subs:phone:clear:{$order}:0")->reply()
        ->assertCalled('editMessageText');
    expect($action->phone($buyer->id, $order))->toBeNull()
        ->and($action->get($buyer->id, $order))->toBe('محمد اسدی');
    Http::assertNothingSent();
});

it('isolates reseller reports and counts verified services and distinct customer phones', function (): void {
    $buyer = rslUser();
    $other = rslUser();
    $first = rslOrder($buyer);
    $second = rslOrder($buyer);
    $unlinked = rslOrder($buyer, false);
    $foreign = rslOrder($other);
    $labels = app(ManageResellerServiceLabels::class);
    $labels->save($buyer->id, $first, 'محمد');
    $labels->savePhone($buyer->id, $first, '09123456789');
    $labels->save($buyer->id, $second, 'علی');
    $labels->savePhone($other->id, $foreign, '09123456789');
    $reports = app(ReadResellerSalesReport::class);
    $data = $reports->handle($buyer->id, 30);
    expect($data['services'])->toBe(2)
        ->and($data['phoneCustomers'])->toBe(1)
        ->and($data['withoutPhone'])->toBe(1)
        ->and($data['namedServices'])->toBe(2)
        ->and($data['pending'])->toBe(1)
        ->and($data['transactions'])->toBe(0)
        ->and($data['revenueRials'])->toBe(0)
        ->and($reports->handle($other->id, 7)['services'])->toBe(1)
        ->and($unlinked)->toBeGreaterThan(0);
    expect(fn () => $reports->handle($buyer->id, 365))->toThrow(ValidationException::class);
    $buyer->syncRoles(['member']);
    expect(fn () => $reports->handle($buyer->id, 7))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('makes the Sales report visible only to resellers and provides 7/30 day navigation', function (): void {
    $buyer = rslUser();
    $bot = rslBot($buyer, 977920);
    $bot->hearCallbackQueryData('sales:report:30')->reply()
        ->assertRaw(static function (Request $request): bool {
            $data = FakeNutgram::getActualData($request);

            return str_contains($data['text'], 'گزارش‌های من')
                && str_contains($data['text'], 'مجموع فروش')
                && str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'sales:report:7');
        }, index: 1);
    $buyer->syncRoles(['member']);
    $bot->hearCallbackQueryData('sales:report:30')->reply()
        ->assertRaw(static function (Request $request): bool {
            $data = FakeNutgram::getActualData($request);

            return str_contains($data['text'], 'فقط برای نمایندگان');
        }, index: 1);
    Http::assertNothingSent();
});

it('returns unknown live subscription metrics on unavailable provider without pretending zero', function (): void {
    $buyer = rslUser();
    $foreign = rslUser();
    $action = app(ReadResellerLiveSubscriptions::class);
    $empty = $action->handle($buyer->id);
    expect($empty['available'])->toBeTrue()
        ->and($empty['checked'])->toBe(0)
        ->and($empty['active'])->toBe(0);

    rslOrder($buyer);
    $result = $action->handle($buyer->id);
    expect($result['available'])->toBeFalse()
        ->and($result['active'])->toBeNull()
        ->and($result['expired'])->toBeNull()
        ->and($result['attentionOrderIds'])->toBe([])
        ->and($action->handle($foreign->id)['checked'])->toBe(0);
    $buyer->syncRoles(['member']);
    expect(fn () => $action->handle($buyer->id))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('does not scan the remote provider when a reseller exceeds the explicit live-report service bound', function (): void {
    $buyer = rslUser();
    for ($i = 0; $i < 51; $i++) {
        rslOrder($buyer);
    }

    $result = app(ReadResellerLiveSubscriptions::class)->handle($buyer->id);
    expect($result['available'])->toBeFalse()
        ->and($result['checked'])->toBeNull()
        ->and($result['active'])->toBeNull();
    Http::assertNothingSent();
});

it('shows an unknown-safe private subscription status page and denies a revoked reseller', function (): void {
    $buyer = rslUser();
    rslOrder($buyer);
    $bot = rslBot($buyer, 977922);
    $bot->hearCallbackQueryData('sales:report:subscriptions')->reply()
        ->assertRaw(static function (Request $request): bool {
            $data = FakeNutgram::getActualData($request);

            return str_contains($data['text'], 'دریافت وضعیت همه سرویس‌ها')
                && ! str_contains($data['text'], '🟢 فعال: 0');
        }, index: 1);

    $buyer->syncRoles(['member']);
    $bot->hearCallbackQueryData('sales:report:subscriptions')->reply()
        ->assertRaw(static function (Request $request): bool {
            $data = FakeNutgram::getActualData($request);

            return str_contains($data['text'], 'فقط برای نمایندگان فعال');
        }, index: 1);
    Http::assertNothingSent();
});

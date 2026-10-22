<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\CalculateQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Sales\Application\CreatePendingOrder;
use App\Modules\Sales\Application\ManageResellerServiceLabels;
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
        ->assertRaw(static function (Request $request): bool {
            $data = FakeNutgram::getActualData($request);

            return str_contains($data['text'], '1. 👤 علی &amp; &lt;دوست&gt;')
                && str_contains($data['text'], 'پلن: پلن نمایندگی')
                && ! str_contains($data['text'], 'سرویس #')
                && ! str_contains($data['text'], 'علی & <دوست>')
                && str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'جزئیات 1')
                && str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), "sales:subs:view:");
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
            $keyboard = json_encode($data['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains($data['text'], '1. پلن نمایندگی')
                && str_contains($data['text'], '2. پلن نمایندگی')
                && ! str_contains($data['text'], "سرویس #{$older}")
                && ! str_contains($data['text'], "سرویس #{$newer}")
                && str_contains($keyboard, 'جزئیات 1')
                && str_contains($keyboard, 'جزئیات 2')
                && str_contains($keyboard, "sales:subs:view:{$newer}:0")
                && str_contains($keyboard, "sales:subs:view:{$older}:0");
        }, index: 1);

    $bot->hearCallbackQueryData("sales:subs:view:{$newer}:0")->reply()
        ->assertRaw(static function (Request $request) use ($newer): bool {
            $data = FakeNutgram::getActualData($request);

            return str_contains($data['text'], '<b>📋 پلن نمایندگی</b>')
                && ! str_contains($data['text'], "سرویس #{$newer}")
                && str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), "sales:subs:label:edit:{$newer}:0");
        }, index: 2);
\n});

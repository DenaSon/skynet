<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Support\AdminOrderLookupUi;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ReadAdminOrderLookup;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
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

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['976001', '976002']);
    Role::findOrCreate('super-admin', 'web');
    Role::findOrCreate('member', 'web');
    Role::findOrCreate('reseller', 'web');
    Http::preventStrayRequests();
    Http::fake();
});

function orderLookupRoot(): User
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 976001]);

    return $root;
}

function orderLookupBot(int $tgId): Nutgram
{
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    require base_path('app/Interfaces/Telegram/routes/legacy_text.php');
    $bot->setCommonUser(TelegramUser::make($tgId, false, 'Operator'));
    $bot->setCommonChat(Chat::make($tgId, ChatType::PRIVATE));

    return $bot;
}

function orderLookupOrder(User $buyer, string $status = 'pending_payment', ?string $category = null, ?int $plan = null): int
{
    return DB::table('sales_orders')->insertGetId([
        'buyer_user_id' => $buyer->id,
        'buyer_category' => $category,
        'idempotency_key' => bin2hex(random_bytes(16)),
        'plan_id' => $plan,
        'product_slug' => 'order-lookup-test',
        'product_name' => 'پلن <آزمایشی> & Test',
        'duration_days' => 30,
        'traffic_gb' => 50,
        'public_price_rials' => 1_500_000,
        'discount_percent' => 35,
        'discount_rials' => 525_000,
        'total_price_rials' => 975_000,
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function orderLookupPlan(): int
{
    $product = DB::table('sales_products')->insertGetId([
        'slug' => 'lookup-product', 'name' => 'Lookup Product',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return DB::table('sales_plans')->insertGetId([
        'product_id' => $product, 'slug' => 'lookup-plan', 'name' => 'Lookup plan',
        'kind' => 'hybrid', 'mode' => 'preset', 'audience' => 'both', 'status' => 'active',
        'min_days' => 1, 'max_days' => 365,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @return array<string, mixed> */
function orderLookupPayload(NutgramRequest $request): array
{
    $payload = FakeNutgram::getActualData($request);
    if (is_string($payload['reply_markup'] ?? null)) {
        $payload['reply_markup'] = json_decode($payload['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }
    if (isset($payload['rich_message'])) {
        $rich = is_string($payload['rich_message'])
            ? json_decode($payload['rich_message'], true, flags: JSON_THROW_ON_ERROR)
            : $payload['rich_message'];
        $payload['text'] = (string) ($rich['html'] ?? '');
    }

    return $payload;
}

it('reads one exact order with the owner, snapshot category, optional label, and local fulfillment state', function (): void {
    $root = orderLookupRoot();
    $buyer = User::factory()->create(['name' => 'خریدار <A> & B']);
    $buyer->syncRoles(['reseller']);
    $plan = orderLookupPlan();
    $id = orderLookupOrder($buyer, 'paid', 'reseller', $plan);
    DB::table('sales_service_customer_labels')->insert([
        'sales_order_id' => $id, 'label' => 'مشتری <ویژه>',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('sales_fulfillment_outbox')->insert([
        'sales_order_id' => $id, 'event_key' => 'sales-order:'.$id.':create',
        'state' => 'needs_review', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $result = app(ReadAdminOrderLookup::class)->handle($root->id, $id);
    expect($result)->toMatchArray([
        'id' => $id,
        'buyerId' => $buyer->id,
        'buyerName' => 'خریدار <A> & B',
        'buyerCategory' => 'reseller',
        'customerLabel' => 'مشتری <ویژه>',
        'totalPriceRials' => 975_000,
        'status' => 'paid',
        'fulfillmentState' => 'needs_review',
        'planId' => $plan,
        'sourceOrderId' => null,
    ]);
    expect(DB::table('sales_orders')->where('id', $id)->value('status'))->toBe('paid')
        ->and(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();

    orderLookupBot(976001)->hearCallbackQueryData('admin:order:view:'.$id)->reply()
        ->assertRaw(static function (NutgramRequest $request) use ($id, $buyer): bool {
            $data = orderLookupPayload($request);
            $text = (string) $data['text'];
            $buttons = json_encode($data['reply_markup'] ?? [], JSON_THROW_ON_ERROR);

            return str_contains($text, '🧾 جزئیات سفارش #'.$id)
                && str_contains($text, 'خریدار &lt;A&gt; &amp; B')
                && str_contains($text, 'مشتری &lt;ویژه&gt;')
                && str_contains($text, 'پلن &lt;آزمایشی&gt; &amp; Test')
                && ! str_contains($text, 'خریدار <A> & B')
                && str_contains($text, 'نیازمند بررسی')
                && str_contains($buttons, 'users:view:'.$buyer->id.':1:a')
                && str_contains($buttons, 'users:profile:'.$buyer->id.':1:a')
                && str_contains($buttons, 'full:view:'.$id)
                && str_contains($buttons, 'admin:order:start');
        }, index: 1);

    Http::assertNothingSent();
});

it('finds an old unpaid direct order without inventing payment or an activation link', function (): void {
    $root = orderLookupRoot();
    $buyer = User::factory()->create();
    $id = orderLookupOrder($buyer, 'pending_payment', 'direct');
    DB::table('sales_orders')->where('id', $id)->update(['created_at' => now()->subDays(10)]);

    $data = app(ReadAdminOrderLookup::class)->handle($root->id, $id);
    expect($data['buyerCategory'])->toBe('direct')
        ->and($data['fulfillmentState'])->toBeNull()
        ->and($data['sourceOrderId'])->toBeNull();

    orderLookupBot(976001)->hearCallbackQueryData('admin:order:view:'.$id)->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $data = orderLookupPayload($request);
            $buttons = json_encode($data['reply_markup'] ?? [], JSON_THROW_ON_ERROR);

            return str_contains((string) $data['text'], 'خرید مستقیم')
                && str_contains((string) $data['text'], 'در انتظار پرداخت')
                && str_contains((string) $data['text'], 'ثبت نشده')
                && ! str_contains($buttons, 'full:view:');
        }, index: 1);
    Http::assertNothingSent();
});

it('resolves an actual renewal order to its source without assigning another buyer or exposing provider secrets', function (): void {
    $root = orderLookupRoot();
    $buyer = User::factory()->create();
    $source = orderLookupOrder($buyer, 'fulfilled', 'reseller');
    $renewal = orderLookupOrder($buyer, 'paid', 'reseller');
    $sub = DB::table('provisioning_subscriptions')->insertGetId([
        'reference' => 'lookup-ref', 'remote_username' => 'lookup_user',
        'state' => 'active', 'last_operation_key' => 'test-key',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('sales_renewal_orders')->insert([
        'sales_order_id' => $renewal,
        'source_order_id' => $source,
        'provisioning_subscription_id' => $sub,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $data = app(ReadAdminOrderLookup::class)->handle($root->id, $renewal);
    expect($data['sourceOrderId'])->toBe($source);
    orderLookupBot(976001)->hearCallbackQueryData('admin:order:view:'.$renewal)->reply()
        ->assertRaw(static function (NutgramRequest $request) use ($source): bool {
            $payload = orderLookupPayload($request);
            $markup = json_encode($payload['reply_markup'] ?? [], JSON_THROW_ON_ERROR);

            return str_contains((string) $payload['text'], 'تمدید')
                && str_contains($markup, 'admin:order:view:'.$source)
                && ! str_contains((string) $payload['text'], 'lookup_user')
                && ! str_contains((string) $payload['text'], 'lookup-ref');
        }, index: 1);
    Http::assertNothingSent();
});

it('exposes search only to the root directory and processes a Persian number in an expiring one-shot prompt', function (): void {
    $root = orderLookupRoot();
    $staff = User::factory()->admin()->create();
    TelegramAccount::query()->create(['user_id' => $staff->id, 'telegram_user_id' => 976003]);
    $buyer = User::factory()->create();
    $id = orderLookupOrder($buyer);

    orderLookupBot(976001)->hearCallbackQueryData('users:page:1')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => str_contains(
            json_encode(orderLookupPayload($r)['reply_markup'], JSON_THROW_ON_ERROR),
            'admin:order:start',
        ), index: 1);
    orderLookupBot(976003)->hearCallbackQueryData('users:page:1')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => ! str_contains(
            json_encode(orderLookupPayload($r)['reply_markup'], JSON_THROW_ON_ERROR),
            'admin:order:start',
        ), index: 1);

    $bot = orderLookupBot(976001);
    $bot->hearCallbackQueryData('admin:order:start')->reply()
        ->assertCalled('sendMessage');
    expect(Cache::has('admin:order-lookup:'.$root->id))->toBeTrue();

    $persianId = strtr((string) $id, [
        '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
    ]);
    $bot->hearText('#'.$persianId)->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => str_contains(
            (string) orderLookupPayload($r)['text'], 'جزئیات سفارش #'.$id,
        ));
    expect(Cache::has('admin:order-lookup:'.$root->id))->toBeFalse();

    $bot->hearCallbackQueryData('admin:order:start')->reply();
    $bot->hearText('-123')->reply()->assertReplyText('شماره سفارش معتبر نیست. فقط عدد سفارش را وارد کنید؛ مثلاً #490.');
    expect(Cache::has('admin:order-lookup:'.$root->id))->toBeTrue();
    $this->travel(11)->minutes();
    expect(Cache::has('admin:order-lookup:'.$root->id))->toBeFalse();
    Http::assertNothingSent();
});

it('denies forged callbacks and revoked roots at both the UI and application boundary', function (): void {
    $root = orderLookupRoot();
    $buyer = User::factory()->create();
    $id = orderLookupOrder($buyer);
    $staff = User::factory()->admin()->create();
    TelegramAccount::query()->create(['user_id' => $staff->id, 'telegram_user_id' => 976003]);

    expect(fn () => app(ReadAdminOrderLookup::class)->handle($staff->id, $id))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReadAdminOrderLookup::class)->handle($root->id, -1))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(ReadAdminOrderLookup::class)->handle($root->id, 99999999))
        ->toThrow(ValidationException::class);

    orderLookupBot(976003)->hearCallbackQueryData('admin:order:view:'.$id)->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);

    $bot = orderLookupBot(976001);
    $bot->hearCallbackQueryData('admin:order:start')->reply();
    $root->syncRoles(['member']);
    expect(fn () => app(ReadAdminOrderLookup::class)->handle($root->id, $id))
        ->toThrow(AuthorizationException::class);
    $bot->hearText((string) $id)->reply()
        ->assertReply('sendMessage', ['text' => 'دسترسی مجاز نیست.']);
    expect(Cache::has('admin:order-lookup:'.$root->id))->toBeFalse();

    $root->syncRoles(['super-admin']);
    config()->set('super_admin.telegram_ids', ['976003', '976004']);
    expect(fn () => app(ReadAdminOrderLookup::class)->handle($root->id, $id))
        ->toThrow(AuthorizationException::class);
    $bot->hearCallbackQueryData('admin:order:view:'.$id)->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);

    config()->set('super_admin.telegram_ids', ['976001', '976002']);
    $root->is_active = false;
    $root->save();
    expect(fn () => app(ReadAdminOrderLookup::class)->handle($root->id, $id))
        ->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('ignores group callbacks and never delivers a lookup result outside a private chat', function (): void {
    orderLookupRoot();
    $buyer = User::factory()->create();
    $id = orderLookupOrder($buyer);
    $bot = orderLookupBot(976001);
    $bot->setCommonChat(Chat::make(-976500, ChatType::GROUP));

    $bot->hearCallbackQueryData('admin:order:start')->reply()->assertNoReply();
    $bot->hearCallbackQueryData('admin:order:view:'.$id)->reply()->assertNoReply();
    expect(Cache::has('admin:order-lookup:1'))->toBeFalse();
    Http::assertNothingSent();
});

it('preserves the authorized native fallback with identical data and navigation on Rich failure', function (): void {
    orderLookupRoot();
    $buyer = User::factory()->create(['name' => 'نام <امن> & تست']);
    $orderId = orderLookupOrder($buyer, 'fulfilled', 'direct');

    $keyboard = null;
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('chat')->andReturn(Chat::make(976001, ChatType::PRIVATE));
    $bot->shouldReceive('user')->andReturn(TelegramUser::make(976001, false, 'Root'));
    $bot->shouldReceive('isCallbackQuery')->andReturn(false);
    $bot->shouldReceive('sendRichMessage')->once()->andReturnUsing(function (...$args) use (&$keyboard): void {
        foreach ($args as $arg) {
            if ($arg instanceof InputRichMessage) {
                expect($arg->html)->toContain('جزئیات سفارش', 'نام &lt;امن&gt; &amp; تست');
            }
            if ($arg instanceof InlineKeyboardMarkup) {
                $keyboard = $arg;
            }
        }
        throw new TelegramException('Bad Request: unsupported RichMessage', 400);
    });
    $bot->shouldReceive('sendMessage')->once()->andReturnUsing(function (...$args) use (&$keyboard, $buyer, $orderId): null {
        expect($args[0])->toContain('جزئیات سفارش #'.$orderId, 'نام <امن> & تست')
            ->not->toContain('<table', '<h2>');
        expect(in_array($keyboard, $args, true))->toBeTrue();
        $buttons = json_encode($keyboard, JSON_THROW_ON_ERROR);
        expect($buttons)->toContain(
            'users:view:'.$buyer->id.':1:a',
            'users:profile:'.$buyer->id.':1:a',
            'admin:order:start',
            'admin:order:view:'.$orderId,
            'users:page:1',
        )->not->toContain('full:view:'.$orderId);

        return null;
    });

    app(AdminOrderLookupUi::class)->detail($bot, (string) $orderId);
    Http::assertNothingSent();
});

it('retires lookup when returning to users, so later operational inputs are not stolen', function (): void {
    $root = orderLookupRoot();
    $bot = orderLookupBot(976001);

    $bot->hearCallbackQueryData('admin:order:start')->reply();
    expect(Cache::has('admin:order-lookup:'.$root->id))->toBeTrue();
    $bot->hearCallbackQueryData('users:page:1')->reply();
    expect(Cache::has('admin:order-lookup:'.$root->id))->toBeFalse();

    $bot->hearCallbackQueryData('admin:ops:edit:limit')->reply();
    $bot->hearText('17')->reply();
    $draft = Cache::get('admin:ops:draft:'.$root->id);
    expect($draft['step'])->toBe('confirm')
        ->and($draft['new'])->toBe(17)
        ->and(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});

it('retires competing Admin input when starting a lookup and clears lookup when settings replace it', function (): void {
    $root = orderLookupRoot();
    $buyer = User::factory()->create();
    $id = orderLookupOrder($buyer);
    $bot = orderLookupBot(976001);

    $bot->hearCallbackQueryData('admin:ops:edit:limit')->reply();
    expect(Cache::has('admin:ops:draft:'.$root->id))->toBeTrue();

    $bot->hearCallbackQueryData('admin:order:start')->reply();
    expect(Cache::has('admin:ops:draft:'.$root->id))->toBeFalse()
        ->and(Cache::has('admin:order-lookup:'.$root->id))->toBeTrue();

    $bot->hearCallbackQueryData('admin:ops:edit:limit')->reply();
    expect(Cache::has('admin:order-lookup:'.$root->id))->toBeFalse();

    $bot->hearText('17')->reply();
    $draft = Cache::get('admin:ops:draft:'.$root->id);
    expect($draft['step'])->toBe('confirm')
        ->and($draft['new'])->toBe(17);

    $bot->hearCallbackQueryData('admin:order:start')->reply();
    $bot->hearText((string) $id)->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => str_contains(
            (string) orderLookupPayload($r)['text'], 'جزئیات سفارش #'.$id,
        ));
    expect(Cache::has('admin:order-lookup:'.$root->id))->toBeFalse();
    Http::assertNothingSent();
});


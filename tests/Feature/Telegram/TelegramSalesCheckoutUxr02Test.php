<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Billing\Application\RequestTopup;
use App\Modules\Billing\Application\ReviewTopup;
use App\Modules\Billing\Application\SubmitTopupPhoto;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ManagePlanPricing;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\ManageResellerServiceLabels;
use App\Modules\Sales\Application\ManualBankPayments;
use App\Modules\Sales\Application\PlanDefinition;
use App\Modules\Sales\Application\PlanPricingDefinition;
use App\Modules\Sales\Application\SettleWalletOrder;
use GuzzleHttp\Psr7\Request;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['973001', '973002']);
    config()->set('cache.default', 'database');
    config()->set('sales.manual_bank_review_enabled', true);
    config()->set('billing.payment_card_number', '6037991234567890'); // Test fixture, not a production payee.
    config()->set('billing.payment_instructions', 'به نام <Test & Bank>');
    config()->set('services.remnawave.squad_bindings_live_verified', true);
    config()->set('services.remnawave.writes_enabled', false);
    config()->set('telegram_notifications.enabled', false);
    config()->set('telegram.sales.enabled', false);
    Http::preventStrayRequests();
    Http::fake();
    app(ConfigureProduct::class)->handle('vpn-access', 'VPN Access');
    $route = new Route('POST', 'api/telegram/sales/webhook', static fn (): null => null);
    $route->defaults('bot_key', TelegramBotContext::SALES);
    $route->bind(request());
    request()->setRouteResolver(static fn (): Route => $route);
});

function uxr02Bot(int $telegramId, User $user): Nutgram
{
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/sales.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Buyer'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/** @return array{root: User, plan: int} */
function uxr02Plan(): array
{
    $root = User::factory()->create();
    $root->assignRole('super-admin');
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 973001]);
    $plan = app(ManagePlans::class)->create($root->id, new PlanDefinition(
        'vpn-access', 'uxr02-plan', 'توضیح', 'hybrid', 'preset', 'both',
        30, 30, 1, 100, 15, 90,
    ));
    $plans = app(ManagePlans::class);
    $plans->confirmChange($root->id, $plans->requestChange($root->id, $plan->id, 1, 'activate'));
    $prices = app(ManagePlanPricing::class);
    $rev = $prices->configure($root->id, $plan->id, 2, 0,
        new PlanPricingDefinition('fixed', 1_000_000, 0, 0, 0, 10, 100_000));
    $prices->confirmChange($root->id, $prices->requestChange($root->id, $plan->id, 2, $rev, 'activate'));
    DB::table('sales_plans')->where('id', $plan->id)->update(['name' => '<Plan & Name>']);

    DB::table('sales_plan_internal_squad_bindings')->insert([
        'plan_id' => $plan->id,
        'plan_revision' => 2,
        'internal_squad_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'verified_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['root' => $root, 'plan' => $plan->id];
}

/** @return array<string, mixed> */
function uxr02Payload(Request $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $data;
}

/** Credit the existing real ledger for a member with a root-reviewed test receipt. */
function wal03Credit(User $buyer, User $root, int $amount, string $ref): void
{
    config()->set('billing.topups_enabled', true);
    $id = app(RequestTopup::class)->handle($buyer->id, $amount, 'wal03:topup:'.bin2hex(random_bytes(7)));
    app(SubmitTopupPhoto::class)->handle(
        $buyer->id, $id, 989001, 'wallet-photo-'.$ref, 'unique-wallet-'.$ref, random_int(1001, 90001),
    );
    app(ReviewTopup::class)->handle($root->id, $id, true, $ref);
}

function wal03Ready(): void
{
    config()->set('sales.wallet_settlement_enabled', true);
    config()->set('sales.auto_fulfillment_enabled', true);
    config()->set('services.remnawave.writes_enabled', true);
    config()->set('services.remnawave.squad_bindings_live_verified', true);
    config()->set('telegram_notifications.enabled', true);
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram.sales.token', '54321:sales_test');
    config()->set('nutgram.token', '12345:admin_test');
    Queue::fake();
}

it('confirms member wallet purchase once and retains existing bank payment as an alternative', function (): void {
    wal03Ready();
    $data = uxr02Plan();
    $buyer = User::factory()->create();
    $bot = uxr02Bot(973301, $buyer);
    wal03Credit($buyer, $data['root'], 1_500_000, 'WAL03REF101');
    config()->set('sales.wallet_settlement_enabled', true);

    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply()->assertRaw(static function (Request $req): bool {
        $body = uxr02Payload($req);
        $kb = json_encode($body['reply_markup'], JSON_THROW_ON_ERROR);

        return str_contains($kb, 'sales:checkout:wallet:')
            && str_contains($kb, 'sales:payment:receipt:')
            && str_contains($body['text'], 'شماره کارت: 6037991234567890')
            && str_contains($kb, 'copy_text')
            && ! str_contains($body['text'], 'پرداخت سفارش');
    }, index: 1);

    $order = (int) DB::table('sales_orders')->value('id');
    $bot->hearCallbackQueryData('sales:checkout:wallet:'.$order)->reply()->assertRaw(static function (Request $req): bool {
        $body = uxr02Payload($req);

        return str_contains($body['text'], 'تأیید پرداخت از کیف پول')
            && str_contains($body['text'], '<b>100,000 تومان</b>')
            && str_contains($body['text'], '<b>50,000 تومان</b>')
            && ($body['parse_mode'] ?? null) === 'HTML';
    }, index: 1);
    $key = TelegramBotContext::current()->cacheKey('telegram:sales:walletpay:'.$buyer->id.':'.$order);
    $token = Cache::get($key)['token'];
    expect(DB::table('sales_order_settlements')->count())->toBe(0);

    $bot->hearCallbackQueryData('sales:walletpay:confirm:'.$order.':'.$token)->reply()
        ->assertCalled('editMessageText');
    expect(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('paid')
        ->and(DB::table('sales_order_settlements')->where('sales_order_id', $order)->value('method'))->toBe('customer_wallet')
        ->and(DB::table('sales_order_settlements')->count())->toBe(1)
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(1)
        ->and(DB::table('billing_journals')->where('kind', 'purchase')->count())->toBe(1)
        ->and(DB::table('billing_postings')->where('account_code', 'order_liability')->count())->toBe(1)
        ->and(app(ViewWallet::class)->handle($buyer->id)->balanceRials)->toBe(500_000);

    $bot->hearCallbackQueryData('sales:walletpay:confirm:'.$order.':'.$token)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_order_settlements')->count())->toBe(1)
        ->and(DB::table('billing_journals')->where('kind', 'purchase')->count())->toBe(1);
    Http::assertNothingSent();
});

it('does not debit managed wallet orders when activation or customer notices are unavailable', function (string $key, mixed $value): void {
    wal03Ready();
    $data = uxr02Plan();
    $buyer = User::factory()->create();
    $bot = uxr02Bot(973306, $buyer);
    wal03Credit($buyer, $data['root'], 1_500_000, 'PATCH01READY');
    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply();
    $order = (int) DB::table('sales_orders')->value('id');
    $bot->hearCallbackQueryData('sales:checkout:wallet:'.$order)->reply();
    $tokenKey = TelegramBotContext::current()->cacheKey('telegram:sales:walletpay:'.$buyer->id.':'.$order);
    $token = Cache::get($tokenKey)['token'];
    config()->set($key, $value);

    $bot->hearCallbackQueryData('sales:walletpay:confirm:'.$order.':'.$token)->reply()->assertRaw(
        static fn (Request $request): bool => str_contains(uxr02Payload($request)['text'], 'مبلغی از کیف پول شما برداشت نشد'),
        index: 1,
    );
    expect(DB::table('sales_orders')->value('status'))->toBe('pending_payment')
        ->and(DB::table('sales_order_settlements')->count())->toBe(0)
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(0)
        ->and(DB::table('billing_journals')->where('kind', 'purchase')->count())->toBe(0)
        ->and(app(ViewWallet::class)->handle($buyer->id)->balanceRials)->toBe(1_500_000);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    ['sales.auto_fulfillment_enabled', false],
    ['services.remnawave.writes_enabled', false],
    ['services.remnawave.squad_bindings_live_verified', false],
    ['telegram_notifications.enabled', false],
    ['telegram.sales.enabled', false],
    ['telegram.sales.token', ''],
    ['telegram.sales.token', '12345:admin_test'],
]);

it('keeps a changed-plan wallet order unpaid and does not reprice its snapshot', function (): void {
    wal03Ready();
    $data = uxr02Plan();
    $buyer = User::factory()->create();
    $bot = uxr02Bot(973307, $buyer);
    wal03Credit($buyer, $data['root'], 1_500_000, 'PATCH01PLAN');
    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply();
    $order = (int) DB::table('sales_orders')->value('id');
    $snapshot = DB::table('sales_orders')->first();
    $bot->hearCallbackQueryData('sales:checkout:wallet:'.$order)->reply();
    $tokenKey = TelegramBotContext::current()->cacheKey('telegram:sales:walletpay:'.$buyer->id.':'.$order);
    $token = Cache::get($tokenKey)['token'];
    DB::table('sales_plans')->where('id', $data['plan'])->increment('revision');

    $bot->hearCallbackQueryData('sales:walletpay:confirm:'.$order.':'.$token)->reply()->assertRaw(
        static fn (Request $request): bool => str_contains(uxr02Payload($request)['text'], 'مشخصات این پلن تغییر کرده'),
        index: 1,
    );
    expect(DB::table('sales_orders')->first())->toEqual($snapshot)
        ->and(DB::table('billing_journals')->where('kind', 'purchase')->count())->toBe(0)
        ->and(app(ViewWallet::class)->handle($buyer->id)->balanceRials)->toBe(1_500_000);
    Http::assertNothingSent();
});

it('does not show or execute a wallet debit without enough credit or against an active bank receipt', function (): void {
    $data = uxr02Plan();
    $buyer = User::factory()->create();
    $bot = uxr02Bot(973302, $buyer);
    config()->set('sales.wallet_settlement_enabled', true);
    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply()->assertCalled('editMessageText');
    $order = (int) DB::table('sales_orders')->value('id');
    $bot->hearCallbackQueryData('sales:checkout:wallet:'.$order)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_order_settlements')->count())->toBe(0);

    wal03Credit($buyer, $data['root'], 1_500_000, 'WAL03REF102');
    $bot->hearCallbackQueryData('sales:checkout:view:'.$order)->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:checkout:wallet:'.$order)->reply()->assertCalled('editMessageText');
    $key = TelegramBotContext::current()->cacheKey('telegram:sales:walletpay:'.$buyer->id.':'.$order);
    $token = Cache::get($key)['token'];

    DB::table('sales_bank_payment_claims')->insert([
        'sales_order_id' => $order,
        'buyer_user_id' => $buyer->id,
        'photo_encrypted' => 'test-only',
        'photo_fingerprint' => hash('sha256', 'wal03-bank-claim'),
        'telegram_message_key' => 'tg:sales:973302:944',
        'source_bot_key' => 'sales',
        'status' => 'submitted',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $bot->hearCallbackQueryData('sales:walletpay:confirm:'.$order.':'.$token)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('pending_payment')
        ->and(DB::table('sales_order_settlements')->count())->toBe(0)
        ->and(DB::table('billing_journals')->where('kind', 'purchase')->count())->toBe(0);
});

it('allows wallet-only managed plan checkout without configured bank card, but not without funds', function (): void {
    $data = uxr02Plan();
    $buyer = User::factory()->create();
    $bot = uxr02Bot(973303, $buyer);
    config()->set('sales.wallet_settlement_enabled', true);
    config()->set('sales.manual_bank_review_enabled', false);
    config()->set('billing.payment_card_number', '');

    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_orders')->count())->toBe(0);

    wal03Credit($buyer, $data['root'], 1_000_000, 'WAL03REF103');
    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_orders')->count())->toBe(1);
    Http::assertNothingSent();
});

it('invalidates a cancelled wallet confirmation and cannot charge another buyer order', function (): void {
    $data = uxr02Plan();
    $buyer = User::factory()->create();
    $other = User::factory()->create();
    $bot = uxr02Bot(973304, $buyer);
    $otherBot = uxr02Bot(973305, $other);
    wal03Credit($buyer, $data['root'], 1_500_000, 'WAL03REF104');
    config()->set('sales.wallet_settlement_enabled', true);

    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply()->assertCalled('editMessageText');
    $id = (int) DB::table('sales_orders')->value('id');
    $otherBot->hearCallbackQueryData('sales:checkout:wallet:'.$id)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_order_settlements')->count())->toBe(0);

    $bot->hearCallbackQueryData('sales:checkout:wallet:'.$id)->reply()->assertCalled('editMessageText');
    $key = TelegramBotContext::current()->cacheKey('telegram:sales:walletpay:'.$buyer->id.':'.$id);
    $token = Cache::get($key)['token'];

    $bot->hearCallbackQueryData('sales:walletpay:cancel:'.$id)->reply()->assertCalled('editMessageText');
    expect(Cache::has($key))->toBeFalse();
    $bot->hearCallbackQueryData('sales:walletpay:confirm:'.$id.':'.$token)->reply()->assertCalled('editMessageText');

    expect(DB::table('sales_orders')->where('id', $id)->value('status'))->toBe('pending_payment')
        ->and(DB::table('sales_order_settlements')->count())->toBe(0)
        ->and(DB::table('billing_journals')->where('kind', 'purchase')->count())->toBe(0)
        ->and(app(ViewWallet::class)->handle($buyer->id)->balanceRials)->toBe(1_500_000);
    Http::assertNothingSent();
});

it('shows one copyable bank payment page without arming purchase receipt intake', function (): void {
    expect(Cache::getStore())->toBeInstanceOf(DatabaseStore::class);
    $data = uxr02Plan();
    $buyer = User::factory()->create();
    $bot = uxr02Bot(973101, $buyer);
    $bot->hearText('/start')->reply()->assertCalled('sendMessage', 2);
    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText')
        ->assertRaw(static function (Request $request): bool {
            $body = uxr02Payload($request);
            $keyboard = $body['reply_markup']['inline_keyboard'] ?? [];

            return $body['parse_mode'] === 'HTML'
                && str_contains($body['text'], 'پرداخت سفارش')
                && str_contains($body['text'], '&lt;Plan &amp; Name&gt;')
                && str_contains($body['text'], '<b>100,000 تومان</b>')
                && str_contains($body['text'], '۳۰') === false
                && str_contains($body['text'], '30 گیگابایت')
                && str_contains($body['text'], '&lt;Test &amp; Bank&gt;')
                && $keyboard[0][0]['copy_text']['text'] === '6037991234567890'
                && $keyboard[0][1]['copy_text']['text'] === '100000'
                && str_contains(json_encode($keyboard, JSON_THROW_ON_ERROR), 'sales:payment:receipt:');
        }, index: 1);
    $payKey = TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$buyer->id);
    expect(Cache::has($payKey))->toBeFalse();
    expect(DB::table('sales_orders')->count())->toBe(1)
        ->and(DB::table('sales_orders')->value('status'))->toBe('pending_payment')
        ->and(DB::table('sales_bank_payment_claims')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});

it('creates a fresh member order for every new plan tap and continues old only via details', function (): void {
    $plan = uxr02Plan()['plan'];
    $buyer = User::factory()->create();
    $bot = uxr02Bot(973102, $buyer);
    $bot->hearCallbackQueryData("catalog:detail:{$plan}:1")->reply()->assertCalled('editMessageText');
    $first = (int) DB::table('sales_orders')->value('id');
    $bot->hearCallbackQueryData("catalog:detail:{$plan}:1")->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData("sales:checkout:preview:{$plan}:30:30")->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:checkout:view:'.$first)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_orders')->count())->toBe(3);

    $bot->hearCallbackQueryData('sales:checkout:new:'.$first)->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:checkout:new:'.$first)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_orders')->count())->toBe(5)
        ->and(DB::table('sales_orders')->where('buyer_user_id', $buyer->id)->distinct()->count('id'))->toBe(5);
    $bot->hearCallbackQueryData("catalog:detail:{$plan}:1")->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_orders')->count())->toBe(6);
    $bot->hearCallbackQueryData('sales:checkout:view:'.$first)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_orders')->count())->toBe(6)
        ->and(DB::table('sales_orders')->where('id', $first)->value('status'))->toBe('pending_payment');
    Http::assertNothingSent();
});

it('rechecks the changed price before opening another payment and preserves the old immutable order', function (): void {
    $data = uxr02Plan();
    $buyer = User::factory()->create();
    $bot = uxr02Bot(973103, $buyer);
    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply()->assertCalled('editMessageText');
    $old = (int) DB::table('sales_orders')->value('id');

    $prices = app(ManagePlanPricing::class);
    $rev = $prices->configure($data['root']->id, $data['plan'], 2, 2,
        new PlanPricingDefinition('fixed', 1_500_000, 0, 0, 0, 10, 100_000));
    $prices->confirmChange($data['root']->id, $prices->requestChange($data['root']->id, $data['plan'], 2, $rev, 'activate'));
    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply()
        ->assertRaw(static function (Request $request): bool {
            $body = uxr02Payload($request);

            return str_contains($body['text'] ?? '', '<b>150,000 تومان</b>');
        }, index: 1);
    expect(DB::table('sales_orders')->count())->toBe(2)
        ->and(DB::table('sales_orders')->where('id', $old)->value('total_price_rials'))->toBe(1_000_000)
        ->and(DB::table('sales_orders')->where('id', '!=', $old)->value('total_price_rials'))->toBe(1_500_000);
    Http::assertNothingSent();
});

it('keeps receipt input private, scoped to Sales, expiring and cancelable without touching Admin drafts', function (): void {
    $plan = uxr02Plan()['plan'];
    $buyer = User::factory()->create();
    $other = User::factory()->create();
    $bot = uxr02Bot(973104, $buyer);
    $otherBot = uxr02Bot(973105, $other);
    $bot->hearCallbackQueryData("catalog:detail:{$plan}:1")->reply()->assertCalled('editMessageText');
    $order = (int) DB::table('sales_orders')->value('id');
    $otherBot->hearCallbackQueryData('sales:checkout:view:'.$order)->reply()->assertCalled('editMessageText');
    $otherBot->hearMessage(['message_id' => 99, 'photo' => [
        ['file_id' => 'unauthorized-photo', 'file_unique_id' => 'unauthorized-id', 'width' => 400, 'height' => 400],
    ]])->reply()->assertNoReply();
    expect(DB::table('sales_bank_payment_claims')->count())->toBe(0);

    $key = TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$buyer->id);
    Cache::put('telegram:payphoto:'.$buyer->id, 999, 600); // Admin-only legacy state.
    $bot->hearCallbackQueryData('sales:payment:receipt:'.$order)->reply()->assertCalled('editMessageText');
    $bot->hearText('فیش نوشتم')->reply()->assertReplyText(
        'لطفاً عکس رسید را بفرستید، یا از «خانه» خارج شوید. برای ادامه بعدی، «سفارش‌های من» را باز کنید.'
    );
    $bot->hearText('/cancel')->reply()->assertCalled('sendMessage', 2);
    expect(Cache::has($key))->toBeFalse()
        ->and(Cache::get('telegram:payphoto:'.$buyer->id))->toBe(999);
    $bot->hearCallbackQueryData('sales:checkout:view:'.$order)->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:payment:receipt:'.$order)->reply()->assertCalled('editMessageText');
    $this->travel(11)->minutes();
    $bot->hearMessage(['message_id' => 100, 'photo' => [
        ['file_id' => 'expired-photo', 'file_unique_id' => 'expired-id', 'width' => 400, 'height' => 400],
    ]])->reply()->assertCalled('sendMessage');
    expect(DB::table('sales_bank_payment_claims')->count())->toBe(0);
    Http::assertNothingSent();
});

it('takes a photo directly without requiring a reply and never turns repeat receipts into a second claim or payment', function (): void {
    $plan = uxr02Plan()['plan'];
    $buyer = User::factory()->create();
    $bot = uxr02Bot(973106, $buyer);
    $bot->hearCallbackQueryData("catalog:detail:{$plan}:1")->reply()->assertCalled('editMessageText');
    $order = (int) DB::table('sales_orders')->value('id');
    $key = TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$buyer->id);
    expect(Cache::has($key))->toBeFalse();
    $bot->hearMessage(['message_id' => 59, 'photo' => [
        ['file_id' => 'before-button-photo', 'file_unique_id' => 'before-button-unique', 'width' => 400, 'height' => 400],
    ]])->reply()->assertNoReply();
    expect(DB::table('sales_bank_payment_claims')->count())->toBe(0);
    $bot->hearCallbackQueryData('sales:payment:receipt:'.$order)->reply()->assertCalled('editMessageText');
    expect(Cache::has($key))->toBeTrue();
    $bot->hearMessage(['message_id' => 60, 'photo' => [
        ['file_id' => 'receipt-test-photo', 'file_unique_id' => 'receipt-unique-id', 'width' => 400, 'height' => 400],
    ]])->reply()->assertReplyText("✅ رسیدتان دریافت شد و برای بررسی ارسال شد.\nنتیجه را در همین بات دریافت می‌کنید.\nسفارش #{$order}");
    $bot->hearCallbackQueryData('sales:checkout:view:'.$order)->reply()->assertCalled('editMessageText');
    $bot->hearMessage(['message_id' => 61, 'photo' => [
        ['file_id' => 'receipt-test-photo', 'file_unique_id' => 'receipt-unique-id', 'width' => 400, 'height' => 400],
    ]])->reply()->assertCalled('sendMessage');
    expect(DB::table('sales_bank_payment_claims')->count())->toBe(1)
        ->and(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('pending_payment')
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(0);
    Http::assertNothingSent();
});

it('accepts a numeric-string order ID from cache and registers the member receipt and root notice', function (): void {
    $root = uxr02Plan()['root'];
    $buyer = User::factory()->create();
    $bot = uxr02Bot(973108, $buyer);
    $bot->hearCallbackQueryData('catalog:detail:'.DB::table('sales_plans')->value('id').':1')
        ->reply()->assertCalled('editMessageText');

    $orderId = (int) DB::table('sales_orders')->value('id');
    $key = TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$buyer->id);
    Cache::put($key, (string) $orderId, now()->addMinutes(10));
    expect(Cache::get($key))->toBe((string) $orderId);

    config()->set('telegram_notifications.enabled', true);
    Queue::fake();

    $bot->hearText('رسید پرداخت')->reply()->assertReplyText(
        'لطفاً عکس رسید را بفرستید، یا از «خانه» خارج شوید. برای ادامه بعدی، «سفارش‌های من» را باز کنید.'
    );
    $bot->hearMessage(['message_id' => 105, 'photo' => [
        ['file_id' => 'member-string-photo-105', 'file_unique_id' => 'member-string-unique-105', 'width' => 400, 'height' => 400],
    ]])->reply()->assertReplyText(
        "✅ رسیدتان دریافت شد و برای بررسی ارسال شد.\nنتیجه را در همین بات دریافت می‌کنید.\nسفارش #{$orderId}"
    );

    $claim = DB::table('sales_bank_payment_claims')->sole();
    $delivery = DB::table('telegram_notification_deliveries')->where('event_type', 'ReceiptSubmitted')->sole();

    expect((int) $claim->sales_order_id)->toBe($orderId)
        ->and((string) $claim->source_bot_key)->toBe('sales')
        ->and($claim->status)->toBe('submitted')
        ->and((int) $delivery->claim_id)->toBe((int) $claim->id)
        ->and((int) $delivery->order_id)->toBe($orderId)
        ->and((int) $delivery->recipient_user_id)->toBe($root->id)
        ->and($delivery->bot_key)->toBe('admin')
        ->and($delivery->state)->toBe('pending')
        ->and(Cache::has($key))->toBeFalse()
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(0);
    Http::assertNothingSent();
});

it('routes the next photo to the newly opened purchase without changing a pending wallet top-up', function (string $entry): void {
    config()->set('billing.topups_enabled', true);
    $data = uxr02Plan();
    $buyer = User::factory()->create();
    $other = User::factory()->create();
    $bot = uxr02Bot(973181, $buyer);
    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply();
    $orderId = (int) DB::table('sales_orders')->value('id');

    $bot->hearCallbackQueryData('sales:wallet:topup')->reply();
    $bot->hearCallbackQueryData('sales:wallet:topup:custom')->reply();
    $bot->hearText('250000')->reply();
    $topup = DB::table('billing_topups')->sole();
    $topupKey = TelegramBotContext::current()->cacheKey('telegram:wallet:topup:'.$buyer->id);
    $bot->hearCallbackQueryData('sales:wallet:topup:receipt:'.$topup->id)->reply();
    expect(Cache::get($topupKey))->toBe(['step' => 'photo', 'topup_id' => (int) $topup->id, 'amount_toman' => 250000]);

    $adminKey = 'telegram:wallet:topup:'.$buyer->id;
    $otherKey = TelegramBotContext::current()->cacheKey('telegram:wallet:topup:'.$other->id);
    $unrelatedDraft = ['step' => 'amount'];
    Cache::put($adminKey, $unrelatedDraft, now()->addMinutes(10));
    Cache::put($otherKey, $unrelatedDraft, now()->addMinutes(10));
    config()->set('telegram_notifications.enabled', true);
    Queue::fake();

    // Existing message buttons bypass Home and must still retire the old intake.
    $callback = match ($entry) {
        'receipt-button' => 'sales:payment:start:'.$orderId,
        'order-details' => 'sales:checkout:view:'.$orderId,
        'new-plan' => 'catalog:detail:'.$data['plan'].':1',
    };
    $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
        'id' => 'switch-wallet-to-purchase', 'message' => ['from' => [], 'date' => 1703892479],
        'data' => $callback,
    ])->reply()->assertCalled('editMessageText');
    $purchaseId = (int) DB::table('sales_orders')->orderByDesc('id')->value('id');
    $purchaseKey = TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$buyer->id);
    expect(Cache::has($purchaseKey))->toBeFalse();
    $bot->hearCallbackQueryData('sales:payment:receipt:'.$purchaseId)->reply()->assertCalled('editMessageText');
    expect(Cache::has($purchaseKey))->toBeTrue();

    $bot->hearMessage(['message_id' => 181, 'photo' => [
        ['file_id' => 'purchase-switch-photo', 'file_unique_id' => 'purchase-switch-unique', 'width' => 400, 'height' => 400],
    ]])->reply();

    // A receipt is evidence only: the correct destination changes, never money.
    expect(DB::table('billing_topup_photos')->count())->toBe(0)
        ->and(DB::table('billing_topups')->sole())->toEqual($topup)
        ->and(Cache::has($topupKey))->toBeFalse()
        ->and(Cache::get($adminKey))->toBe($unrelatedDraft)
        ->and(Cache::get($otherKey))->toBe($unrelatedDraft);
    $claim = DB::table('sales_bank_payment_claims')->sole();
    $notice = DB::table('telegram_notification_deliveries')->where('event_type', 'ReceiptSubmitted')->sole();
    expect((int) $claim->sales_order_id)->toBe($purchaseId)
        ->and((int) $claim->buyer_user_id)->toBe($buyer->id)
        ->and($claim->status)->toBe('submitted')
        ->and($claim->source_bot_key)->toBe('sales')
        ->and((int) $notice->claim_id)->toBe((int) $claim->id)
        ->and((int) $notice->recipient_user_id)->toBe($data['root']->id)
        ->and($notice->bot_key)->toBe('admin')
        ->and(DB::table('sales_orders')->where('id', $purchaseId)->value('status'))->toBe('pending_payment')
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('billing_postings')->count())->toBe(0)
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(0)
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(0);
    Http::assertNothingSent();
})->with(['receipt-button', 'order-details', 'new-plan']);

it('preserves wallet top-up intake when purchase receipt entry is rejected', function (string $reason): void {
    config()->set('billing.topups_enabled', true);
    Role::findOrCreate('reseller', 'web');
    $plan = uxr02Plan()['plan'];
    $buyer = User::factory()->create();
    $bot = uxr02Bot(973182, $buyer);
    $orderBot = $reason === 'foreign' ? uxr02Bot(973183, User::factory()->create()) : $bot;
    $orderBot->hearCallbackQueryData('catalog:detail:'.$plan.':1')->reply();
    $orderId = (int) DB::table('sales_orders')->value('id');

    if ($reason === 'already-submitted') {
        app(ManualBankPayments::class)->submit(SubscriptionViewer::telegram(973182), $orderId,
            'existing-purchase-photo', 'existing-purchase-unique', 182, 'sales');
    }
    $bot->hearCallbackQueryData('sales:wallet:topup')->reply();
    $bot->hearCallbackQueryData('sales:wallet:topup:custom')->reply();
    $bot->hearText('250000')->reply();
    $key = TelegramBotContext::current()->cacheKey('telegram:wallet:topup:'.$buyer->id);
    $draft = Cache::get($key);
    $topup = DB::table('billing_topups')->sole();

    match ($reason) {
        'paid' => DB::table('sales_orders')->where('id', $orderId)->update(['status' => 'paid']),
        'gate-off' => config()->set('sales.manual_bank_review_enabled', false),
        'missing-card' => config()->set('billing.payment_card_number', ''),
        'reseller' => $buyer->syncRoles(['reseller']),
        default => null,
    };
    $bot->hearCallbackQueryData('sales:payment:start:'.$orderId)->reply();

    expect(Cache::get($key))->toBe($draft)
        ->and(Cache::has(TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$buyer->id)))->toBeFalse()
        ->and(DB::table('billing_topups')->sole())->toEqual($topup)
        ->and(DB::table('billing_topup_photos')->count())->toBe(0)
        ->and(DB::table('sales_bank_payment_claims')->count())->toBe($reason === 'already-submitted' ? 1 : 0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(0);
    Http::assertNothingSent();
})->with(['foreign', 'paid', 'gate-off', 'missing-card', 'already-submitted', 'reseller']);

it('keeps the existing switch from purchase receipt intake to wallet top-up working', function (): void {
    config()->set('billing.topups_enabled', true);
    $plan = uxr02Plan()['plan'];
    $buyer = User::factory()->create();
    $bot = uxr02Bot(973184, $buyer);
    $bot->hearCallbackQueryData('catalog:detail:'.$plan.':1')->reply();
    $orderId = (int) DB::table('sales_orders')->value('id');
    $purchaseKey = TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$buyer->id);
    expect(Cache::has($purchaseKey))->toBeFalse();
    $bot->hearCallbackQueryData('sales:payment:receipt:'.$orderId)->reply();
    expect(Cache::has($purchaseKey))->toBeTrue();

    $bot->hearCallbackQueryData('sales:wallet:topup')->reply();
    $bot->hearCallbackQueryData('sales:wallet:topup:custom')->reply();
    $bot->hearText('250000')->reply();
    $topupId = (int) DB::table('billing_topups')->value('id');
    $bot->hearCallbackQueryData('sales:wallet:topup:receipt:'.$topupId)->reply();
    $bot->hearMessage(['message_id' => 184, 'photo' => [
        ['file_id' => 'wallet-switch-photo', 'file_unique_id' => 'wallet-switch-unique', 'width' => 400, 'height' => 400],
    ]])->reply();

    expect(Cache::has($purchaseKey))->toBeFalse()
        ->and(DB::table('billing_topup_photos')->count())->toBe(1)
        ->and(DB::table('billing_topups')->value('status'))->toBe('submitted')
        ->and(DB::table('sales_bank_payment_claims')->count())->toBe(0)
        ->and(DB::table('sales_orders')->value('status'))->toBe('pending_payment')
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(0);
    Http::assertNothingSent();
});

it('recognizes numeric-string recent orders after the active receipt window expires', function (): void {
    $plan = uxr02Plan()['plan'];
    $buyer = User::factory()->create();
    $bot = uxr02Bot(973109, $buyer);
    $bot->hearCallbackQueryData("catalog:detail:{$plan}:1")->reply()->assertCalled('editMessageText');
    $orderId = (int) DB::table('sales_orders')->value('id');
    Cache::forget(TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$buyer->id));
    Cache::put(
        TelegramBotContext::current()->cacheKey('telegram:payphoto:recent:'.$buyer->id),
        (string) $orderId,
        now()->addHour(),
    );

    $bot->hearMessage(['message_id' => 106, 'photo' => [
        ['file_id' => 'stale-member-photo-106', 'file_unique_id' => 'stale-member-unique-106', 'width' => 400, 'height' => 400],
    ]])->reply()->assertReplyText(
        'زمان دریافت رسید تمام شده است. از «سفارش‌های من» ادامه پرداخت را باز کنید.'
    );
    expect(DB::table('sales_bank_payment_claims')->count())->toBe(0);
    Http::assertNothingSent();
});

it('rejects malformed cached order identifiers without submitting an unauthorized receipt', function (string $invalid): void {
    $plan = uxr02Plan()['plan'];
    $buyer = User::factory()->create();
    $bot = uxr02Bot(973110, $buyer);
    $bot->hearCallbackQueryData("catalog:detail:{$plan}:1")->reply()->assertCalled('editMessageText');

    $key = TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$buyer->id);
    Cache::put($key, $invalid, now()->addMinutes(10));
    $bot->hearMessage(['message_id' => 107, 'photo' => [
        ['file_id' => 'invalid-member-photo-107', 'file_unique_id' => 'invalid-member-unique-107', 'width' => 400, 'height' => 400],
    ]])->reply()->assertNoReply();

    expect(DB::table('sales_bank_payment_claims')->count())->toBe(0)
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
})->with(['0', '-1', '01', '1e2', ' 1', '1 ', '1234567890123456']);

it('refuses to create a payment order without a real configured card or with the receipt gate off', function (): void {
    $plan = uxr02Plan()['plan'];
    $buyer = User::factory()->create();
    $bot = uxr02Bot(973107, $buyer);
    config()->set('billing.payment_card_number', '');
    $bot->hearCallbackQueryData("catalog:detail:{$plan}:1")->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_orders')->count())->toBe(0);

    config()->set('billing.payment_card_number', '6037991234567890');
    config()->set('sales.manual_bank_review_enabled', false);
    $bot->hearCallbackQueryData("catalog:detail:{$plan}:1")->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_orders')->count())->toBe(0);
    Http::assertNothingSent();
});
it('collects a single-word username with minimal copy and shows payment without a mobile step', function (): void {
    $data = uxr02Plan();
    config()->set('sales.wallet_settlement_enabled', true);
    Role::findOrCreate('reseller', 'web');
    $buyer = User::factory()->create();
    $buyer->syncRoles(['reseller']);
    $bot = uxr02Bot(973010, $buyer);

    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply()
        ->assertRaw(static function (Request $request): bool {
            $body = uxr02Payload($request);

            return str_contains($body['text'], '<b>👤 نام کاربری مشتری</b>')
                && str_contains($body['text'], 'نام کاربری دلخواه مشتری را وارد کنید.')
                && str_contains($body['text'], '<blockquote>این نام برای شناسایی سرویس در پنل فروش شما استفاده می‌شود.</blockquote>')
                && ! str_contains($body['text'], 'مرحله ۱ از ۲')
                && ! str_contains($body['text'], 'مثال')
                && ! str_contains(json_encode($body['reply_markup'], JSON_THROW_ON_ERROR), 'sales:payment:start:');
        }, index: 1);

    $order = (int) DB::table('sales_orders')->value('id');
    $key = TelegramBotContext::current()->cacheKey('telegram:sales:customer_name:'.$buyer->id);
    expect(Cache::get($key))->toBe(['order_id' => $order])
        ->and(DB::table('billing_journals')->where('kind', 'purchase')->count())->toBe(0);

    $bot->hearText('mohammad01')->reply()->assertRaw(static function (Request $request): bool {
        $body = uxr02Payload($request);

        return str_contains($body['text'], 'کیف پول')
            && ! str_contains($body['text'], 'شماره موبایل مشتری')
            && ! str_contains($body['text'], 'عکس رسید');
    }, index: 0);

    expect(Cache::has($key))->toBeFalse()
        ->and(DB::table('sales_service_customer_labels')->where('sales_order_id', $order)->value('label'))->toBe('mohammad01')
        ->and(DB::table('sales_service_customer_labels')->where('sales_order_id', $order)->value('phone'))->toBeNull()
        ->and(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('pending_payment')
        ->and(DB::table('billing_journals')->where('kind', 'purchase')->count())->toBe(0);
    Http::assertNothingSent();
});

it('rejects forged pending labels and keeps abandoned customer-name drafts payment-free', function (): void {
    $data = uxr02Plan();
    config()->set('sales.wallet_settlement_enabled', true);
    Role::findOrCreate('reseller', 'web');
    $buyer = User::factory()->create();
    $buyer->syncRoles(['reseller']);
    $foreign = User::factory()->create();
    $foreign->syncRoles(['reseller']);
    $bot = uxr02Bot(973011, $buyer);
    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply();
    $order = (int) DB::table('sales_orders')->value('id');
    $labels = app(ManageResellerServiceLabels::class);
    expect(fn () => $labels->savePending($foreign->id, $order, 'غیرمجاز'))
        ->toThrow(ValidationException::class);
    expect(fn () => $labels->savePending($buyer->id, $order, "دو خط\nغیرمجاز"))
        ->toThrow(ValidationException::class);

    $bot->hearCallbackQueryData('sales:checkout:name:cancel')->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_service_customer_labels')->count())->toBe(0)
        ->and(DB::table('billing_journals')->where('kind', 'purchase')->count())->toBe(0);
    $bot->hearText('نام بعد از انصراف')->reply();
    expect(DB::table('sales_service_customer_labels')->count())->toBe(0);
    Http::assertNothingSent();
});

it('accepts a numeric-string legacy checkout draft and proceeds directly to payment', function (): void {
    $data = uxr02Plan();
    config()->set('sales.wallet_settlement_enabled', true);
    Role::findOrCreate('reseller', 'web');
    $buyer = User::factory()->create();
    $buyer->syncRoles(['reseller']);
    $bot = uxr02Bot(973012, $buyer);

    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply();
    $order = (int) DB::table('sales_orders')->value('id');
    $key = TelegramBotContext::current()->cacheKey('telegram:sales:customer_name:'.$buyer->id);

    // RedisStore can return legacy numeric-string draft values.
    Cache::put($key, (string) $order, now()->addMinutes(10));
    $bot->hearText('محمد اسدی')->reply()->assertRaw(static function (Request $request): bool {
        $body = uxr02Payload($request);

        return str_contains($body['text'], 'کیف پول')
            && ! str_contains($body['text'], 'شماره موبایل مشتری');
    }, index: 0);

    expect(Cache::has($key))->toBeFalse()
        ->and(DB::table('sales_service_customer_labels')->where('sales_order_id', $order)->value('label'))->toBe('محمد اسدی')
        ->and(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('pending_payment')
        ->and(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});

it('ignores malformed checkout draft values without writing labels or payments', function (): void {
    $data = uxr02Plan();
    config()->set('sales.wallet_settlement_enabled', true);
    Role::findOrCreate('reseller', 'web');
    $buyer = User::factory()->create();
    $buyer->syncRoles(['reseller']);
    $bot = uxr02Bot(973013, $buyer);
    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply();

    $key = TelegramBotContext::current()->cacheKey('telegram:sales:customer_name:'.$buyer->id);
    Cache::put($key, ['order_id' => '1 OR 1=1'], now()->addMinutes(10));
    $bot->hearText('محمد اسدی')->reply();

    expect(DB::table('sales_service_customer_labels')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});

it('blocks reseller bank receipts before and after entering a username', function (): void {
    $data = uxr02Plan();
    config()->set('sales.wallet_settlement_enabled', true);
    Role::findOrCreate('reseller', 'web');
    $buyer = User::factory()->create();
    $buyer->syncRoles(['reseller']);
    $bot = uxr02Bot(973014, $buyer);
    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply();
    $orderId = (int) DB::table('sales_orders')->value('id');
    $action = app(ManualBankPayments::class);

    expect(fn () => $action->submit(SubscriptionViewer::telegram(973014), $orderId,
        'test-photo-file-guard', 'unique-photo-guard', 1404, 'sales'))
        ->toThrow(ValidationException::class);
    $bot->hearCallbackQueryData('sales:payment:receipt:'.$orderId)->reply()
        ->assertCalled('editMessageText');
    expect(Cache::has(TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$buyer->id)))->toBeFalse()
        ->and(DB::table('sales_bank_payment_claims')->count())->toBe(0);

    $bot->hearText('علی احمدی')->reply();
    expect(app(ManageResellerServiceLabels::class)->pendingName($buyer->id, $orderId))
        ->toBe('علی احمدی');
    expect(fn () => $action->submit(SubscriptionViewer::telegram(973014), $orderId,
        'test-photo-file-guard', 'unique-photo-guard', 1404, 'sales'))
        ->toThrow(ValidationException::class);


    $bot->hearCallbackQueryData('sales:payment:start:'.$orderId)->reply()
        ->assertCalled('editMessageText');
    expect(Cache::has(TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$buyer->id)))->toBeFalse()
        ->and(DB::table('sales_bank_payment_claims')->count())->toBe(0)
        ->and(DB::table('sales_orders')->where('id', $orderId)->value('status'))->toBe('pending_payment');
    Http::assertNothingSent();
});

it('blocks forged wallet confirmation and does not debit incomplete reseller orders', function (): void {
    $data = uxr02Plan();
    config()->set('sales.wallet_settlement_enabled', true);
    Role::findOrCreate('reseller', 'web');
    $buyer = User::factory()->create();
    $buyer->syncRoles(['reseller']);
    $bot = uxr02Bot(973015, $buyer);
    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply();
    $id = (int) DB::table('sales_orders')->value('id');

    config()->set('sales.wallet_settlement_enabled', true);
    expect(fn () => app(SettleWalletOrder::class)->handle(SubscriptionViewer::telegram(973015), $id))
        ->toThrow(ValidationException::class);
    expect(DB::table('sales_order_settlements')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('sales_bank_payment_claims')->count())->toBe(0);
});

it('resumes an old pending order with phone-only metadata by requesting a username', function (): void {
    $data = uxr02Plan();
    config()->set('sales.wallet_settlement_enabled', true);
    Role::findOrCreate('reseller', 'web');
    $buyer = User::factory()->create();
    $buyer->syncRoles(['reseller']);
    $bot = uxr02Bot(973016, $buyer);
    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply();
    $id = (int) DB::table('sales_orders')->value('id');
    $labels = app(ManageResellerServiceLabels::class);
    $labels->savePendingPhone($buyer->id, $id, '09123456789');

    // The old persisted phone is never overwritten; a new purchase needs only the label.
    $bot->hearCallbackQueryData('sales:checkout:view:'.$id)->reply()
        ->assertRaw(static fn (Request $request): bool => str_contains(uxr02Payload($request)['text'], 'نام کاربری مشتری'), index: 1);
    $bot->hearText('single_username')->reply()
        ->assertRaw(static fn (Request $request): bool => str_contains(uxr02Payload($request)['text'], 'کیف پول'));
    $labels->assertPendingDetails($buyer->id, $id);
    expect(DB::table('sales_service_customer_labels')->where('sales_order_id', $id)->value('phone'))->toBe('+989123456789')
        ->and($labels->pendingName($buyer->id, $id))->toBe('single_username')
        ->and(DB::table('sales_orders')->where('id', $id)->value('status'))->toBe('pending_payment')
        ->and(DB::table('billing_journals')->count())->toBe(0);
});

it('settles a credited reseller purchase from wallet without a bank claim', function (): void {
    wal03Ready();
    $data = uxr02Plan();
    Role::findOrCreate('reseller', 'web');
    $buyer = User::factory()->create();
    $buyer->syncRoles(['reseller']);
    $bot = uxr02Bot(973017, $buyer);
    wal03Credit($buyer, $data['root'], 1_500_000, 'RESELLERWALLET01');

    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply();
    $id = (int) DB::table('sales_orders')->value('id');
    $bot->hearText('sara01')->reply()->assertRaw(static function (Request $req): bool {
        $body = uxr02Payload($req);
        $keyboard = json_encode($body['reply_markup'], JSON_THROW_ON_ERROR);

        return str_contains($keyboard, 'sales:checkout:wallet:')
            && ! str_contains($keyboard, 'sales:payment:start:');
    }, index: 0);
    $bot->hearCallbackQueryData('sales:payment:start:'.$id)->reply()->assertCalled('editMessageText');
    expect(fn () => app(ManualBankPayments::class)->submit(
        SubscriptionViewer::telegram(973017), $id,
        'sample-receipt-file-id', 'sample-unique-receipt', 201, 'sales',
    ))->toThrow(ValidationException::class);

    $bot->hearCallbackQueryData('sales:checkout:wallet:'.$id)->reply()->assertCalled('editMessageText');
    $key = TelegramBotContext::current()->cacheKey('telegram:sales:walletpay:'.$buyer->id.':'.$id);
    $token = Cache::get($key)['token'];
    $bot->hearCallbackQueryData('sales:walletpay:confirm:'.$id.':'.$token)->reply()->assertCalled('editMessageText');

    expect(DB::table('sales_orders')->where('id', $id)->value('status'))->toBe('paid')
        ->and(DB::table('sales_bank_payment_claims')->count())->toBe(0)
        ->and(DB::table('sales_order_settlements')->where('sales_order_id', $id)->value('method'))->toBe('customer_wallet')
        ->and(app(ViewWallet::class)->handle($buyer->id)->balanceRials)->toBe(600_000);
});

it('sends zero-balance reseller to wallet top-up without purchase receipt', function (): void {
    config()->set('sales.wallet_settlement_enabled', true);
    config()->set('billing.topups_enabled', true);
    $data = uxr02Plan();
    Role::findOrCreate('reseller', 'web');
    $buyer = User::factory()->create();
    $buyer->syncRoles(['reseller']);
    $bot = uxr02Bot(973018, $buyer);
    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply();
    $id = (int) DB::table('sales_orders')->value('id');
    $bot->hearText('nima01')->reply()->assertRaw(static function (Request $req): bool {
        $body = uxr02Payload($req);
        $buttons = json_encode($body['reply_markup'], JSON_THROW_ON_ERROR);

        return str_contains($buttons, 'sales:wallet:topup')
            && ! str_contains($buttons, 'sales:payment:start:');
    }, index: 0);
    expect(DB::table('sales_orders')->where('id', $id)->value('status'))->toBe('pending_payment')
        ->and(DB::table('sales_bank_payment_claims')->count())->toBe(0);
});

it('creates a fresh reseller order per purchase tap and remains idempotent for the same callback', function (): void {
    config()->set('sales.wallet_settlement_enabled', true);
    $data = uxr02Plan();
    Role::findOrCreate('reseller', 'web');
    $buyer = User::factory()->create();
    $buyer->syncRoles(['reseller']);
    $bot = uxr02Bot(973019, $buyer);
    $buy = 'catalog:detail:'.$data['plan'].':1';

    $tap = static function (string $id, string $callback) use ($bot) {
        return $bot->hearUpdateType(UpdateType::CALLBACK_QUERY, [
            'id' => $id, 'message' => ['from' => [], 'date' => 1703892479],
            'data' => $callback,
        ])->reply();
    };

    $tap('purchase-1', $buy)->assertCalled('editMessageText');
    $first = (int) DB::table('sales_orders')->orderByDesc('id')->value('id');
    $bot->hearText('mina01')->reply();
    expect(DB::table('sales_service_customer_labels')->where('sales_order_id', $first)->value('label'))
        ->toBe('mina01');

    // Replaying the *same* Telegram callback is idempotent.
    $tap('purchase-1', $buy);
    expect(DB::table('sales_orders')->count())->toBe(1);

    // Tapping the same plan anew must start a NEW username prompt.
    $tap('purchase-2', $buy)->assertRaw(static function (Request $request): bool {
        return str_contains(uxr02Payload($request)['text'], 'نام کاربری مشتری');
    }, index: 1);
    $second = (int) DB::table('sales_orders')->orderByDesc('id')->value('id');
    expect($second)->not->toBe($first)
        ->and(DB::table('sales_service_customer_labels')->where('sales_order_id', $second)->exists())->toBeFalse()
        ->and(DB::table('sales_orders')->where('id', $first)->value('status'))->toBe('pending_payment');

    // The explicit "buy new with same plan" shortcut is another fresh intent.
    $tap('purchase-3', 'sales:checkout:new:'.$first)->assertRaw(static function (Request $request): bool {
        return str_contains(uxr02Payload($request)['text'], 'نام کاربری مشتری');
    }, index: 1);
    expect(DB::table('sales_orders')->count())->toBe(3)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('sales_bank_payment_claims')->count())->toBe(0);
    Http::assertNothingSent();
});

it('returns from receipt input to the payment screen without leaving a live photo draft', function (): void {
    $data = uxr02Plan();
    $buyer = User::factory()->create();
    $bot = uxr02Bot(973111, $buyer);
    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply();
    $orderId = (int) DB::table('sales_orders')->value('id');
    $key = TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$buyer->id);

    $bot->hearCallbackQueryData('sales:payment:start:'.$orderId)->reply()->assertCalled('editMessageText');
    expect(Cache::has($key))->toBeFalse();

    $bot->hearCallbackQueryData('sales:payment:receipt:'.$orderId)->reply()->assertRaw(static function (Request $request): bool {
        $body = uxr02Payload($request);

        return str_contains($body['text'], 'ارسال رسید پرداخت')
            && str_contains(json_encode($body['reply_markup'], JSON_THROW_ON_ERROR), 'sales:payment:start:');
    }, index: 1);
    expect(Cache::get($key))->toBe($orderId);

    $bot->hearCallbackQueryData('sales:payment:start:'.$orderId)->reply()->assertCalled('editMessageText');
    expect(Cache::has($key))->toBeFalse();
    $bot->hearMessage(['message_id' => 610, 'photo' => [
        ['file_id' => 'after-back-photo-id', 'file_unique_id' => 'after-back-photo-unique', 'width' => 400, 'height' => 400],
    ]])->reply()->assertNoReply();
    expect(DB::table('sales_bank_payment_claims')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);

    $bot->hearCallbackQueryData('sales:payment:receipt:'.$orderId)->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('menu:home')->reply()->assertCalled('editMessageText');
    expect(Cache::has($key))->toBeFalse()
        ->and(DB::table('sales_orders')->count())->toBe(1);
    Http::assertNothingSent();
});

it('invalidates the wallet debit confirmation after /cancel or navigating to order history', function (string $destination): void {
    wal03Ready();
    $data = uxr02Plan();
    $buyer = User::factory()->create();
    $bot = uxr02Bot(973121, $buyer);
    wal03Credit($buyer, $data['root'], 1_500_000, 'WUX03WALLET1');
    config()->set('sales.wallet_settlement_enabled', true);

    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply();
    $orderId = (int) DB::table('sales_orders')->value('id');
    $bot->hearCallbackQueryData('sales:checkout:wallet:'.$orderId)->reply();
    $key = TelegramBotContext::current()->cacheKey('telegram:sales:walletpay:'.$buyer->id.':'.$orderId);
    $pointer = TelegramBotContext::current()->cacheKey('telegram:sales:walletpay:active:'.$buyer->id);
    $token = Cache::get($key)['token'];
    expect(Cache::has($pointer))->toBeTrue();

    if ($destination === 'cancel') {
        $bot->hearText('/cancel')->reply()->assertCalled('sendMessage', 2);
    } else {
        $bot->hearCallbackQueryData('sales:checkout:orders')->reply()->assertCalled('editMessageText');
    }

    expect(Cache::has($key))->toBeFalse()
        ->and(Cache::has($pointer))->toBeFalse();
    $bot->hearCallbackQueryData('sales:walletpay:confirm:'.$orderId.':'.$token)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_orders')->where('id', $orderId)->value('status'))->toBe('pending_payment')
        ->and(DB::table('sales_order_settlements')->count())->toBe(0)
        ->and(DB::table('billing_journals')->where('kind', 'purchase')->count())->toBe(0);
    Http::assertNothingSent();
})->with(['cancel', 'orders']);

it('disarms the old purchase photo when selecting a fresh funded order without changing old receipts', function (): void {
    wal03Ready();
    $data = uxr02Plan();
    $buyer = User::factory()->create();
    $bot = uxr02Bot(973122, $buyer);
    wal03Credit($buyer, $data['root'], 1_500_000, 'WUX03WALLET2');
    config()->set('sales.wallet_settlement_enabled', true);

    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply();
    $old = (int) DB::table('sales_orders')->value('id');
    $bot->hearCallbackQueryData('sales:payment:receipt:'.$old)->reply();
    $key = TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$buyer->id);
    expect(Cache::has($key))->toBeTrue();

    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply();
    expect(DB::table('sales_orders')->count())->toBe(2)
        ->and(Cache::has($key))->toBeFalse();

    $bot->hearMessage(['message_id' => 950, 'photo' => [
        ['file_id' => 'other-order-photo-id', 'file_unique_id' => 'other-order-photo-unique', 'width' => 400, 'height' => 400],
    ]])->reply();
    expect(DB::table('sales_bank_payment_claims')->count())->toBe(0)
        ->and(DB::table('billing_topup_photos')->count())->toBe(1)
        ->and(DB::table('sales_order_settlements')->count())->toBe(0);
    Http::assertNothingSent();
});

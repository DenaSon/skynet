<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Billing\Application\RequestTopup;
use App\Modules\Billing\Application\ReviewTopup;
use App\Modules\Billing\Application\SubmitTopupPhoto;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ManagePlanPricing;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\PlanDefinition;
use App\Modules\Sales\Application\PlanPricingDefinition;
use GuzzleHttp\Psr7\Request;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

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

it('confirms member wallet purchase once and retains existing bank payment as an alternative', function (): void {
    $data = uxr02Plan();
    $buyer = User::factory()->create();
    $bot = uxr02Bot(973301, $buyer);
    wal03Credit($buyer, $data['root'], 1_500_000, 'WAL03REF101');
    config()->set('sales.wallet_settlement_enabled', true);

    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply()->assertRaw(static function (Request $req): bool {
        $body = uxr02Payload($req);
        $kb = json_encode($body['reply_markup'], JSON_THROW_ON_ERROR);

        return str_contains($kb, 'sales:checkout:wallet:')
            && str_contains($kb, 'sales:payment:start:')
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

it('does not show or execute a wallet debit without enough credit or against an active bank receipt', function (): void {
    $data = uxr02Plan();
    $buyer = User::factory()->create();
    $bot = uxr02Bot(973302, $buyer);
    config()->set('sales.wallet_settlement_enabled', true);
    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply()->assertCalled('sendMessage');
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

it('selects a ready plan from home or catalog into one price-and-card payment page with ForceReply', function (): void {
    expect(Cache::getStore())->toBeInstanceOf(DatabaseStore::class);
    $data = uxr02Plan();
    $buyer = User::factory()->create();
    $bot = uxr02Bot(973101, $buyer);
    $bot->hearText('/start')->reply()->assertCalled('sendMessage', 2);
    $bot->hearCallbackQueryData('catalog:detail:'.$data['plan'].':1')->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText')
        ->assertCalled('sendMessage')
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
                && $keyboard[0][1]['copy_text']['text'] === '100000';
        }, index: 1)
        ->assertRaw(static function (Request $request): bool {
            $body = uxr02Payload($request);

            return ($body['reply_markup']['force_reply'] ?? false) === true;
        }, index: 2);
    expect(DB::table('sales_orders')->count())->toBe(1)
        ->and(DB::table('sales_orders')->value('status'))->toBe('pending_payment')
        ->and(DB::table('sales_bank_payment_claims')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});

it('reuses a pending owned order on repeat taps and creates a distinct order only with explicit new purchase', function (): void {
    $plan = uxr02Plan()['plan'];
    $buyer = User::factory()->create();
    $bot = uxr02Bot(973102, $buyer);
    $bot->hearCallbackQueryData("catalog:detail:{$plan}:1")->reply()->assertCalled('editMessageText');
    $first = (int) DB::table('sales_orders')->value('id');
    $bot->hearCallbackQueryData("catalog:detail:{$plan}:1")->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData("sales:checkout:preview:{$plan}:30:30")->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:checkout:view:'.$first)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_orders')->count())->toBe(1);

    $bot->hearCallbackQueryData('sales:checkout:new:'.$first)->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:checkout:new:'.$first)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_orders')->count())->toBe(2)
        ->and(DB::table('sales_orders')->where('buyer_user_id', $buyer->id)->distinct()->count('id'))->toBe(2);
    $bot->hearCallbackQueryData("catalog:detail:{$plan}:1")->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_orders')->count())->toBe(2);
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
    $bot->hearText('فیش نوشتم')->reply()->assertReplyText(
        'لطفاً عکس رسید را بفرستید، یا از «خانه» خارج شوید. برای ادامه بعدی، «سفارش‌های من» را باز کنید.'
    );
    $bot->hearText('/cancel')->reply()->assertCalled('sendMessage', 2);
    expect(Cache::has($key))->toBeFalse()
        ->and(Cache::get('telegram:payphoto:'.$buyer->id))->toBe(999);
    $bot->hearCallbackQueryData('sales:checkout:view:'.$order)->reply()->assertCalled('editMessageText');
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
    $bot->hearMessage(['message_id' => 60, 'photo' => [
        ['file_id' => 'receipt-test-photo', 'file_unique_id' => 'receipt-unique-id', 'width' => 400, 'height' => 400],
    ]])->reply()->assertReplyText("✅ رسید دریافت شد؛ منتظر بررسی مدیر باشید.\nسفارش #{$order}");
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

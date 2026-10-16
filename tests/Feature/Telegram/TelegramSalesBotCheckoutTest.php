<?php

use App\Interfaces\Telegram\Support\PlanCheckoutTokens;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\CheckPlanSquadReadiness;
use App\Modules\Sales\Application\CalculatePlanQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\CreatePendingPlanOrder;
use App\Modules\Sales\Application\ManagePlanPricing;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\OrderReader;
use App\Modules\Sales\Application\PlanDefinition;
use App\Modules\Sales\Application\PlanPricingDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['979001', '979002']);
    config()->set('services.remnawave.squad_bindings_live_verified', false);
    config()->set('services.remnawave.writes_enabled', false);
    config()->set('telegram.sales.enabled', false);
    app(ConfigureProduct::class)->handle('vpn-access', 'VPN Access');
    Role::findOrCreate('reseller', 'web');
    Http::preventStrayRequests();
    Http::fake();
});

function sb03Root(): User
{
    $root = User::factory()->create();
    $root->assignRole('super-admin');
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 979001]);

    return $root;
}

function sb03Plan(User $root, string $name, string $kind = 'hybrid', string $mode = 'preset', string $audience = 'both'): int
{
    $plan = app(ManagePlans::class)->create($root->id, new PlanDefinition(
        'vpn-access', $name, 'Plan '.$name, $kind, $mode, $audience,
        $mode === 'builder' || $kind === 'time' ? null : 30,
        $mode === 'builder' ? null : 30,
        $kind === 'time' ? null : 1,
        $kind === 'time' ? null : 100,
        15, 90,
    ));
    $plans = app(ManagePlans::class);
    $token = $plans->requestChange($root->id, $plan->id, 1, 'activate');
    $plans->confirmChange($root->id, $token);
    $price = app(ManagePlanPricing::class);
    $revision = $price->configure($root->id, $plan->id, 2, 0,
        $mode === 'builder'
            ? new PlanPricingDefinition('formula', null, 100_000, $kind === 'time' ? 0 : 10_000, 2_000, 10, 100_000)
            : new PlanPricingDefinition('fixed', 1_000_000, 0, 0, 0, 10, 100_000),
    );
    $token = $price->requestChange($root->id, $plan->id, 2, $revision, 'activate');
    $price->confirmChange($root->id, $token);

    return $plan->id;
}

function sb03Binding(int $planId, bool $verified = true): void
{
    DB::table('sales_plan_internal_squad_bindings')->insert([
        'plan_id' => $planId,
        'plan_revision' => 2,
        'internal_squad_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'verified_at' => $verified ? now() : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function sb03Bot(int $id, ?User $user = null): Nutgram
{
    if ($user !== null) {
        TelegramAccount::query()->create(['telegram_user_id' => $id, 'user_id' => $user->id]);
    }

    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/sales.php');
    $bot->setCommonUser(TelegramUser::make($id, false, 'Buyer'));
    $bot->setCommonChat(Chat::make($id, ChatType::PRIVATE));

    return $bot;
}

it('fails closed until a current explicitly verified plan-to-squad binding exists', function (): void {
    $root = sb03Root();
    $id = sb03Plan($root, 'no-bind');
    $buyer = User::factory()->create();
    $quote = app(CalculatePlanQuote::class)->handle($buyer->id, $id, 30, 30);
    $create = app(CreatePendingPlanOrder::class);
    $key = str_repeat('a', 32);
    expect(fn () => $create->handle($buyer->id, $quote, $key))->toThrow(ValidationException::class);

    sb03Binding($id, false);
    config()->set('services.remnawave.squad_bindings_live_verified', true);
    expect(fn () => $create->handle($buyer->id, $quote, $key))->toThrow(ValidationException::class);
    DB::table('sales_plan_internal_squad_bindings')->where('plan_id', $id)->update(['verified_at' => now(), 'plan_revision' => 1]);
    expect(fn () => $create->handle($buyer->id, $quote, $key))->toThrow(ValidationException::class);
    DB::table('sales_plan_internal_squad_bindings')->where('plan_id', $id)->update(['plan_revision' => 2]);
    expect(app(CheckPlanSquadReadiness::class)->verifiedSquadForCheckout($id))->toBe('123e4567-e89b-12d3-a456-426614174000');

    $order = $create->handle($buyer->id, $quote, $key);
    expect($order->status)->toBe('pending_payment')
        ->and($order->planId)->toBe($id)
        ->and($order->planRevision)->toBe(2)
        ->and($order->pricingRevision)->toBe(2)
        ->and($order->internalSquadUuid)->toBe('123e4567-e89b-12d3-a456-426614174000')
        ->and($order->trafficGb)->toBe(30)
        ->and($order->totalPriceRials)->toBe(1_000_000)
        ->and(DB::table('sales_orders')->count())->toBe(1)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps identical repeated submissions one order and rejects a reused cross-buyer key', function (): void {
    $root = sb03Root();
    $id = sb03Plan($root, 'dedupe');
    sb03Binding($id);
    config()->set('services.remnawave.squad_bindings_live_verified', true);
    $buyer = User::factory()->create();
    $other = User::factory()->create();
    $quote = app(CalculatePlanQuote::class)->handle($buyer->id, $id, 30, 30);
    $key = str_repeat('b', 32);
    $action = app(CreatePendingPlanOrder::class);
    $first = $action->handle($buyer->id, $quote, $key);
    expect($action->handle($buyer->id, $quote, $key)->id)->toBe($first->id);

    $otherQuote = app(CalculatePlanQuote::class)->handle($other->id, $id, 30, 30);
    expect(fn () => $action->handle($other->id, $otherQuote, $key))->toThrow(ValidationException::class);
    expect(DB::table('sales_orders')->count())->toBe(1);
});

it('rejects role and price revision changes before first commit but retains accepted snapshot on retry', function (): void {
    $root = sb03Root();
    $id = sb03Plan($root, 'stale', 'hybrid', 'preset', 'reseller');
    sb03Binding($id);
    config()->set('services.remnawave.squad_bindings_live_verified', true);
    $buyer = User::factory()->create();
    $buyer->assignRole('reseller');
    $quote = app(CalculatePlanQuote::class)->handle($buyer->id, $id, 30, 30);
    $buyer->syncRoles(['member']);
    expect(fn () => app(CreatePendingPlanOrder::class)->handle($buyer->id, $quote, str_repeat('c', 32)))
        ->toThrow(ValidationException::class);

    $buyer->assignRole('reseller');
    $order = app(CreatePendingPlanOrder::class)->handle($buyer->id, $quote, str_repeat('c', 32));
    $prices = app(ManagePlanPricing::class);
    $rev = $prices->configure($root->id, $id, 2, 2, new PlanPricingDefinition(
        'fixed', 1_500_000, 0, 0, 0, 5, 100_000,
    ));
    $token = $prices->requestChange($root->id, $id, 2, $rev, 'activate');
    $prices->confirmChange($root->id, $token);

    expect(app(CreatePendingPlanOrder::class)->handle($buyer->id, $quote, str_repeat('c', 32))->id)->toBe($order->id);
    expect(fn () => app(CreatePendingPlanOrder::class)->handle($buyer->id, $quote, str_repeat('d', 32)))
        ->toThrow(ValidationException::class);
    expect($order->totalPriceRials)->toBe(900_000)
        ->and(DB::table('sales_orders')->count())->toBe(1);
});

it('preserves unlimited traffic as NULL and lets only the owner view managed-plan orders', function (): void {
    $root = sb03Root();
    $id = sb03Plan($root, 'unlimited', 'time');
    sb03Binding($id);
    config()->set('services.remnawave.squad_bindings_live_verified', true);
    $buyer = User::factory()->create();
    $other = User::factory()->create();
    $quote = app(CalculatePlanQuote::class)->handle($buyer->id, $id, 30, null);
    $order = app(CreatePendingPlanOrder::class)->handle($buyer->id, $quote, str_repeat('e', 32));

    expect($order->trafficGb)->toBeNull()
        ->and(DB::table('sales_orders')->where('id', $order->id)->value('traffic_gb'))->toBeNull()
        ->and(app(OrderReader::class)->recentPlans($buyer->id))->toHaveCount(1);
    expect(fn () => app(OrderReader::class)->findPlan($other->id, $order->id))->toThrow(ValidationException::class);

    $bot = sb03Bot(979010, $buyer);
    $bot->hearText('/orders')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('sales:checkout:view:'.$order->id)->reply()->assertCalled('editMessageText');
    $otherBot = sb03Bot(979011, $other);
    $otherBot->hearCallbackQueryData('sales:checkout:view:'.$order->id)->reply()->assertCalled('editMessageText');
    Http::assertNothingSent();
});

it('binds Sales preview and idempotent commit to a private user without exposing bank or provider operations', function (): void {
    $root = sb03Root();
    $id = sb03Plan($root, 'telegram');
    sb03Binding($id);
    config()->set('services.remnawave.squad_bindings_live_verified', true);
    $buyer = User::factory()->create();
    $bot = sb03Bot(979012, $buyer);
    $bot->hearCallbackQueryData("sales:checkout:preview:{$id}:30:30")->reply()->assertCalled('editMessageText');
    $quote = app(CalculatePlanQuote::class)->handle($buyer->id, $id, 30, 30);
    $token = app(PlanCheckoutTokens::class)->issue($buyer->id, $quote);
    $bot->hearCallbackQueryData('sales:checkout:commit:'.$token)->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:checkout:commit:'.$token)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_orders')->count())->toBe(1);

    $bot->setCommonChat(Chat::make(-979012, ChatType::GROUP));
    $bot->hearCallbackQueryData('sales:checkout:commit:'.$token)->reply()->assertNoReply();
    $bot->setCommonChat(Chat::make(979012, ChatType::PRIVATE));
    $bot->hearCallbackQueryData('bank:apply:forged')->reply()->assertNoReply();
    expect(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});

it('rejects unverified setup and expired tokens without creating any order', function (): void {
    $root = sb03Root();
    $id = sb03Plan($root, 'unready');
    $buyer = User::factory()->create();
    $bot = sb03Bot(979013, $buyer);
    $bot->hearCallbackQueryData("sales:checkout:preview:{$id}:30:30")->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:checkout:commit:'.str_repeat('f', 32))->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_orders')->count())->toBe(0);
    Http::assertNothingSent();
});


it('keeps Sales checkout blocked by an unverified Squad even if pricing is published', function (): void {
    $root = sb03Root();
    $planId = sb03Plan($root, 'ux-unverified');
    $buyer = User::factory()->create();
    $bot = sb03Bot(979031, $buyer);

    $bot->hearCallbackQueryData("sales:checkout:preview:{$planId}:30:30")->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText');

    expect(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(0);
    Http::assertNothingSent();
});

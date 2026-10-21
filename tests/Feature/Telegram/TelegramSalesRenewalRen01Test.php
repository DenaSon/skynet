<?php

declare(strict_types=1);

use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\CalculatePlanQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\CreatePendingPlanOrder;
use App\Modules\Sales\Application\ManagePlanPricing;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\PlanDefinition;
use App\Modules\Sales\Application\PlanPricingDefinition;
use App\Modules\Sales\Application\PrepareRenewalDraft;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

const REN01_SQUAD = '123e4567-e89b-12d3-a456-426614174000';

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['995001', '995002']);
    config()->set('services.remnawave.url', 'https://ren.test');
    config()->set('services.remnawave.token', 'test-only');
    config()->set('services.remnawave.writes_enabled', false);
    config()->set('services.remnawave.squad_bindings_live_verified', true);
    config()->set('sales.manual_bank_review_enabled', false);
    config()->set('sales.wallet_settlement_enabled', false);
    config()->set('telegram_notifications.enabled', false);
    Http::preventStrayRequests();
    app(ConfigureProduct::class)->handle('ren-plan', 'محصول تست');
});

/** @return array{order:int,plan:int,buyer:User,root:User,telegram:int} */
function ren01Fixture(bool $time = false): array
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 995001]);
    $buyer = User::factory()->create();
    $tg = 995100 + $buyer->id;
    TelegramAccount::query()->create(['user_id' => $buyer->id, 'telegram_user_id' => $tg]);

    $plan = app(ManagePlans::class)->create($root->id, new PlanDefinition(
        'ren-plan', 'ren-test-plan', '<Dena & Name>',
        $time ? 'time' : 'hybrid', 'preset', 'both',
        $time ? null : 20, 30, $time ? null : 1, $time ? null : 100, 15, 90,
    ));
    app(ManagePlans::class)->confirmChange($root->id, app(ManagePlans::class)->requestChange($root->id, $plan->id, 1, 'activate'));
    $prices = app(ManagePlanPricing::class);
    $rev = $prices->configure($root->id, $plan->id, 2, 0,
        new PlanPricingDefinition('fixed', 1_000_000, 0, 0, 0, 0, 100_000));
    $prices->confirmChange($root->id, $prices->requestChange($root->id, $plan->id, 2, $rev, 'activate'));

    DB::table('sales_plan_internal_squad_bindings')->insert([
        'plan_id' => $plan->id, 'plan_revision' => 2,
        'internal_squad_uuid' => REN01_SQUAD,
        'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $quote = app(CalculatePlanQuote::class)->handle($buyer->id, $plan->id, 30, $time ? null : 20);
    $order = app(CreatePendingPlanOrder::class)->handle($buyer->id, $quote, bin2hex(random_bytes(16)))->id;
    DB::table('sales_orders')->where('id', $order)->update(['status' => 'fulfilled']);
    $sub = DB::table('provisioning_subscriptions')->insertGetId([
        'reference' => 'sales-order:'.$order,
        'remote_username' => 'sk_'.substr(hash('sha256', 'sales-order:'.$order), 0, 24),
        'remote_user_id' => 52,
        'state' => 'active',
        'last_operation_key' => 'sales-order:'.$order.':create',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('provisioning_order_links')->insert([
        'sales_order_id' => $order, 'provisioning_subscription_id' => $sub, 'created_at' => now(),
    ]);

    return ['order' => $order, 'plan' => $plan->id, 'buyer' => $buyer, 'root' => $root, 'telegram' => $tg];
}

/** @return array<string,mixed> */
function ren01Remote(int $order, string $status = 'ACTIVE', string $strategy = 'NO_RESET'): array
{
    $reference = 'sales-order:'.$order;

    return [
        'id' => 52,
        'shortUuid' => 'ren01-short',
        'username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
        'status' => $status,
        'expireAt' => now()->addDays(3)->toIso8601String(),
        'trafficLimitBytes' => 20 * 1073741824,
        'trafficLimitStrategy' => $strategy,
        'subscriptionUrl' => 'https://private.example.test/sensitive-credential',
        'userTraffic' => ['usedTrafficBytes' => 1073741824],
        'description' => 'SkyNetwork ref:'.hash('sha256', $reference),
        'activeInternalSquads' => [['uuid' => REN01_SQUAD, 'name' => 'Staging']],
    ];
}

/** @param array<string,mixed> $remote */
function ren01FakeRemote(array $remote): void
{
    Http::fake(['https://ren.test/api/users/52' => Http::response(['response' => $remote])]);
}

function ren01Bot(int $id): Nutgram
{
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/sales.php');
    $bot->setCommonUser(TelegramUser::make($id, false, 'Buyer'));
    $bot->setCommonChat(Chat::make($id, ChatType::PRIVATE));

    return $bot;
}

/** @return array<string,mixed> */
function ren01Payload(NutgramRequest $request): array
{
    $payload = FakeNutgram::getActualData($request);
    if (is_string($payload['reply_markup'] ?? null)) {
        $payload['reply_markup'] = json_decode($payload['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $payload;
}

it('creates one non-payable draft from a verified existing managed service and never duplicates orders', function (): void {
    $data = ren01Fixture();
    ren01FakeRemote(ren01Remote($data['order']));

    $action = app(PrepareRenewalDraft::class);
    $viewer = SubscriptionViewer::telegram($data['telegram']);
    $first = $action->handle($viewer, $data['order']);
    $again = $action->handle($viewer, $data['order']);

    expect($again->id)->toBe($first->id)
        ->and($first->sourceOrderId)->toBe($data['order'])
        ->and($first->priceRials)->toBe(1_000_000)
        ->and($first->durationDays)->toBe(30)
        ->and($first->trafficGb)->toBe(20)
        ->and(DB::table('sales_renewal_drafts')->count())->toBe(1)
        ->and(DB::table('sales_renewal_drafts')->value('state'))->toBe('draft')
        ->and(DB::table('sales_orders')->count())->toBe(1)
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(1)
        ->and(DB::table('provisioning_order_links')->count())->toBe(1)
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(0)
        ->and(DB::table('sales_order_settlements')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'GET');
    Http::assertNotSent(fn (Request $r): bool => $r->method() !== 'GET');
});

it('shows an owner-only Sales preview with escaped plan name and no payment or provider credential', function (): void {
    $data = ren01Fixture();
    ren01FakeRemote(ren01Remote($data['order']));
    $bot = ren01Bot($data['telegram']);

    $bot->hearCallbackQueryData('sales:subs:view:'.$data['order'].':0')->reply()
        ->assertRaw(static function (NutgramRequest $r): bool {
            $data = ren01Payload($r);
            $markup = $data['reply_markup']['inline_keyboard'] ?? [];
            foreach ($markup as $row) {
                foreach ($row as $button) {
                    if (str_contains($button['callback_data'] ?? '', 'sales:subs:renew:')) {
                        return ($button['style'] ?? null) === 'primary';
                    }
                }
            }

            return false;
        }, index: 2);
    $bot->hearCallbackQueryData('sales:subs:renew:'.$data['order'].':0')->reply()
        ->assertRaw(static function (NutgramRequest $r): bool {
            $p = ren01Payload($r);
            $markup = json_encode($p['reply_markup'], JSON_THROW_ON_ERROR);
            return str_contains($p['text'], 'پیش‌نمایش تمدید')
                && str_contains($p['text'], '&lt;Dena &amp; Name&gt;')
                && str_contains($p['text'], '<b>100,000 تومان</b>')
                && str_contains($p['text'], 'هیچ مبلغی دریافت نشده است')
                && str_contains($markup, 'sales:subs:view:')
                && ! str_contains($markup, 'sales:payment:start:')
                && ! str_contains($markup, 'sales:checkout:wallet:')
                && ! str_contains($p['text'], 'sensitive-credential')
                && ($p['parse_mode'] ?? null) === 'HTML';
        }, index: 2);

    expect(DB::table('sales_renewal_drafts')->count())->toBe(1)
        ->and(DB::table('sales_orders')->count())->toBe(1)
        ->and(DB::table('sales_order_settlements')->count())->toBe(0);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'GET');
});

it('refuses foreign buyer, disabled account, group and forged order callbacks without any draft', function (): void {
    $data = ren01Fixture();
    ren01FakeRemote(ren01Remote($data['order']));
    $other = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $other->id, 'telegram_user_id' => 995900]);
    $foreign = ren01Bot(995900);
    $foreign->hearCallbackQueryData('sales:subs:renew:'.$data['order'].':0')->reply()->assertCalled('editMessageText');
    $foreign->hearCallbackQueryData('sales:subs:renew:0:0')->reply()->assertCalled('editMessageText');
    $foreign->hearCallbackQueryData('sales:subs:renew:invalid:0')->reply()->assertCalled('editMessageText');

    $owner = ren01Bot($data['telegram']);
    $owner->setCommonChat(Chat::make(-995901, ChatType::GROUP));
    $owner->hearCallbackQueryData('sales:subs:renew:'.$data['order'].':0')->reply()->assertNoReply();
    $owner->setCommonChat(Chat::make($data['telegram'], ChatType::PRIVATE));
    $data['buyer']->forceFill(['is_active' => false])->save();
    $owner->hearCallbackQueryData('sales:subs:renew:'.$data['order'].':0')->reply()->assertCalled('editMessageText');

    expect(DB::table('sales_renewal_drafts')->count())->toBe(0);
    Http::assertNothingSent();
});

it('blocks refunded, unlinked and legacy orders before the provider GET', function (): void {
    $data = ren01Fixture();
    $viewer = SubscriptionViewer::telegram($data['telegram']);
    Http::fake();

    DB::table('sales_orders')->where('id', $data['order'])->update(['status' => 'refunded']);
    expect(app(PrepareRenewalDraft::class)->available($viewer, $data['order']))->toBeFalse();
    expect(fn () => app(PrepareRenewalDraft::class)->handle($viewer, $data['order']))
        ->toThrow(ValidationException::class);

    DB::table('sales_orders')->where('id', $data['order'])->update(['status' => 'fulfilled']);
    DB::table('provisioning_subscriptions')->update(['state' => 'needs_review']);
    expect(app(PrepareRenewalDraft::class)->available($viewer, $data['order']))->toBeFalse();
    expect(DB::table('sales_renewal_drafts')->count())->toBe(0);
    Http::assertNothingSent();
});

it('does not allow intentionally disabled or changed remote squads or reset strategies', function (string $status, string $strategy, bool $foreignSquad): void {
    $data = ren01Fixture();
    $remote = ren01Remote($data['order'], $status, $strategy);
    if ($foreignSquad) {
        $remote['activeInternalSquads'] = [['uuid' => '123e4567-e89b-12d3-a456-426614174999', 'name' => 'Other']];
    }
    ren01FakeRemote($remote);

    expect(fn () => app(PrepareRenewalDraft::class)->handle(
        SubscriptionViewer::telegram($data['telegram']), $data['order'],
    ))->toThrow(ValidationException::class)
        ->and(DB::table('sales_renewal_drafts')->count())->toBe(0)
        ->and(DB::table('sales_orders')->count())->toBe(1);
    Http::assertSentCount(1);
})->with([
    ['DISABLED', 'NO_RESET', false],
    ['ACTIVE', 'MONTH', false],
    ['ACTIVE', 'NO_RESET', true],
]);

it('allows expired and limited original accounts to prepare a safe draft without reactivating them', function (string $status): void {
    $data = ren01Fixture();
    ren01FakeRemote(ren01Remote($data['order'], $status));
    $draft = app(PrepareRenewalDraft::class)->handle(SubscriptionViewer::telegram($data['telegram']), $data['order']);

    expect($draft->priceRials)->toBe(1_000_000)
        ->and(DB::table('sales_renewal_drafts')->count())->toBe(1)
        ->and(DB::table('provisioning_operations')->count())->toBe(0);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'GET');
})->with(['EXPIRED', 'LIMITED']);

it('supports time-limited unlimited-traffic plans without writing a fabricated volume', function (): void {
    $data = ren01Fixture(time: true);
    $remote = ren01Remote($data['order']);
    $remote['trafficLimitBytes'] = 0;
    ren01FakeRemote($remote);
    $draft = app(PrepareRenewalDraft::class)->handle(SubscriptionViewer::telegram($data['telegram']), $data['order']);

    expect($draft->trafficGb)->toBeNull()
        ->and(DB::table('sales_renewal_drafts')->value('traffic_gb'))->toBeNull();
});

it('refreshes only the unpayable draft when the active published price changes', function (): void {
    $data = ren01Fixture();
    ren01FakeRemote(ren01Remote($data['order']));
    $viewer = SubscriptionViewer::telegram($data['telegram']);
    $one = app(PrepareRenewalDraft::class)->handle($viewer, $data['order']);

    DB::table('sales_plan_prices')->where('plan_id', $data['plan'])
        ->update(['fixed_price_rials' => 2_000_000, 'revision' => DB::raw('revision + 1')]);
    $two = app(PrepareRenewalDraft::class)->handle($viewer, $data['order']);

    expect($two->id)->toBe($one->id)
        ->and($two->priceRials)->toBe(2_000_000)
        ->and(DB::table('sales_renewal_drafts')->count())->toBe(1)
        ->and(DB::table('sales_renewal_drafts')->value('total_price_rials'))->toBe(2_000_000)
        ->and(DB::table('sales_orders')->count())->toBe(1);
});

it('fails closed on provider outage and mismatched immutable remote identity', function (bool $outage): void {
    $data = ren01Fixture();
    $remote = ren01Remote($data['order']);
    $remote['description'] = 'foreign subscription marker';
    Http::fake(['https://ren.test/api/users/52' => $outage
        ? Http::response([], 503)
        : Http::response(['response' => $remote])]);

    expect(fn () => app(PrepareRenewalDraft::class)->handle(
        SubscriptionViewer::telegram($data['telegram']), $data['order'],
    ))->toThrow(RuntimeException::class)
        ->and(DB::table('sales_renewal_drafts')->count())->toBe(0)
        ->and(DB::table('sales_order_settlements')->count())->toBe(0);
})->with([true, false]);

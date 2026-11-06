<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\CalculatePlanQuote;
use App\Modules\Sales\Application\CalculateQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Sales\Application\CreatePendingOrder;
use App\Modules\Sales\Application\CreatePendingPlanOrder;
use App\Modules\Sales\Application\ManagePlanPricing;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\OrderReader;
use App\Modules\Sales\Application\PlanDefinition;
use App\Modules\Sales\Application\PlanPricingDefinition;
use App\Modules\Sales\Application\ReadAdminBusinessReport;
use App\Modules\Sales\Application\ReadAdminSalesChannelKpis;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-06 15:30:00', 'Asia/Tehran')->utc());
    config()->set('super_admin.telegram_ids', ['989501', '989502']);
    config()->set('services.remnawave.squad_bindings_live_verified', true);
    Role::findOrCreate('reseller', 'web');
    Http::preventStrayRequests();
});

/** Root identity must be trusted by current Telegram configuration. */
function channelRoot(): User
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 989501]);

    return $root;
}

function channelUser(bool $reseller = false): User
{
    $user = User::factory()->create();
    if ($reseller) {
        $user->assignRole('reseller');
    }

    return $user;
}

/** Existing real order schema, including deliberately unknown historical attribution. */
function channelOrder(User $buyer, ?string $category, int $rials, string $status = 'fulfilled', int $discount = 0): int
{
    return (int) DB::table('sales_orders')->insertGetId([
        'buyer_user_id' => $buyer->id,
        'buyer_category' => $category,
        'idempotency_key' => bin2hex(random_bytes(16)),
        'product_slug' => 'channel-fixture',
        'product_name' => 'Channel test',
        'duration_days' => 30,
        'traffic_gb' => 20,
        'public_price_rials' => $rials,
        'discount_percent' => $discount,
        'discount_rials' => 0,
        'total_price_rials' => $rials,
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function channelSettle(int $orderId, string $method, string $localTime, int $rials): void
{
    $settledAt = Carbon::parse($localTime, 'Asia/Tehran')->utc();
    $journalId = (int) DB::table('billing_journals')->insertGetId([
        'kind' => 'purchase',
        'idempotency_key' => 'channel-journal-'.$orderId,
        'created_at' => now(),
    ]);
    if ($method === 'bank') {
        $buyerId = (int) DB::table('sales_orders')->where('id', $orderId)->value('buyer_user_id');
        $claimId = (int) DB::table('sales_bank_payment_claims')->insertGetId([
            'sales_order_id' => $orderId,
            'buyer_user_id' => $buyerId,
            'photo_encrypted' => 'fixture',
            'photo_fingerprint' => hash('sha256', 'channel-'.$orderId),
            'telegram_message_key' => 'channel-claim-'.$orderId,
            'status' => 'approved',
            'reviewed_by' => $buyerId,
            'reviewed_at' => $settledAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('sales_bank_settlements')->insert([
            'sales_order_id' => $orderId,
            'payment_claim_id' => $claimId,
            'billing_journal_id' => $journalId,
            'verified_by' => $buyerId,
            'payment_reference' => 'CHANNELBANK'.$orderId,
            'amount_rials' => $rials,
            'settled_at' => $settledAt,
        ]);

        return;
    }

    DB::table('sales_order_settlements')->insert([
        'sales_order_id' => $orderId,
        'billing_journal_id' => $journalId,
        'method' => 'wallet',
        'amount_rials' => $rials,
        'settled_at' => $settledAt,
    ]);
}

it('snapshots direct and reseller buyers for legacy and managed orders independently of discounts and later roles', function (): void {
    $root = channelRoot();
    $direct = channelUser();
    $reseller = channelUser(true);
    app(ConfigureProduct::class)->handle('channel-product', 'Channel product');
    app(ConfigureRate::class)->handle('channel-product', 30, 100_000, 20_000);

    $legacyDirect = app(CreatePendingOrder::class)->handle(
        $direct->id, app(CalculateQuote::class)->handle($direct->id, 'channel-product', 30, 10),
        bin2hex(random_bytes(16)),
    );
    $legacyReseller = app(CreatePendingOrder::class)->handle(
        $reseller->id, app(CalculateQuote::class)->handle($reseller->id, 'channel-product', 30, 10),
        bin2hex(random_bytes(16)),
    );
    expect($legacyDirect->buyerCategory)->toBe('direct')
        ->and($legacyReseller->buyerCategory)->toBe('reseller')
        ->and($legacyReseller->discountPercent)->toBe(35);

    $plans = app(ManagePlans::class);
    $plan = $plans->create($root->id, new PlanDefinition(
        'channel-product', 'channel-preset', 'Channel preset', 'hybrid', 'preset', 'both',
        20, 30, 1, 100, 7, 180,
    ));
    $plans->confirmChange($root->id, $plans->requestChange($root->id, $plan->id, 1, 'activate'));
    $pricing = app(ManagePlanPricing::class);
    $revision = $pricing->configure($root->id, $plan->id, 2, 0,
        new PlanPricingDefinition('fixed', 1_000_000, 0, 0, 0, 0, 1));
    $pricing->confirmChange($root->id, $pricing->requestChange($root->id, $plan->id, 2, $revision, 'activate'));
    DB::table('sales_plan_internal_squad_bindings')->insert([
        'plan_id' => $plan->id,
        'plan_revision' => 2,
        'internal_squad_uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'verified_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $planDirect = app(CreatePendingPlanOrder::class)->handle(
        $direct->id, app(CalculatePlanQuote::class)->handle($direct->id, $plan->id, 30, 20),
        bin2hex(random_bytes(16)),
    );
    $resellerQuote = app(CalculatePlanQuote::class)->handle($reseller->id, $plan->id, 30, 20);
    $key = bin2hex(random_bytes(16));
    $planReseller = app(CreatePendingPlanOrder::class)->handle($reseller->id, $resellerQuote, $key);

    expect($planDirect->buyerCategory)->toBe('direct')
        ->and($planReseller->buyerCategory)->toBe('reseller')
        ->and($planReseller->discountPercent)->toBe(0)
        ->and(DB::table('sales_orders')->whereNull('buyer_category')->count())->toBe(0);

    $reseller->syncRoles(['member']);
    $repeat = app(CreatePendingPlanOrder::class)->handle($reseller->id, $resellerQuote, $key);
    expect($repeat->id)->toBe($planReseller->id)
        ->and($repeat->buyerCategory)->toBe('reseller')
        ->and(app(OrderReader::class)->find($reseller->id, $legacyReseller->id)->buyerCategory)->toBe('reseller')
        ->and(DB::table('sales_orders')->count())->toBe(4)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('billing_wallets')->count())->toBe(0);
    Http::assertNothingSent();
});

it('attributes canonical bank and wallet settlements without guessing unknown history or counting corrected wallet sales', function (): void {
    $root = channelRoot();
    $direct = channelUser();
    $reseller = channelUser(true);
    $historical = channelUser();
    $fourth = channelUser();

    channelSettle(channelOrder($direct, 'direct', 1_000_000), 'bank', '2026-11-06 09:00:00', 1_000_000);
    channelSettle(channelOrder($direct, 'direct', 500_000), 'wallet', '2026-11-06 10:00:00', 500_000);
    channelSettle(channelOrder($reseller, 'reseller', 2_000_000), 'wallet', '2026-11-06 11:00:00', 2_000_000);
    // Old rows may have reseller discounts or mutable roles; still genuinely unknown.
    channelSettle(channelOrder($historical, null, 3_000_000, discount: 35), 'bank', '2026-11-06 12:00:00', 3_000_000);
    channelSettle(channelOrder($fourth, 'direct', 100_000), 'bank', '2026-11-06 13:00:00', 100_000);
    channelSettle(channelOrder($direct, 'direct', 250_000, status: 'refunded'), 'bank', '2026-11-06 14:00:00', 250_000);
    channelSettle(channelOrder($direct, 'direct', 4_000_000, status: 'refunded'), 'wallet', '2026-11-06 14:30:00', 4_000_000);
    channelSettle(channelOrder($reseller, 'reseller', 300_000), 'wallet', '2026-11-05 10:00:00', 300_000);
    // Exactly on yesterday's upper bound is outside the prior same-clock period.
    channelSettle(channelOrder($reseller, 'reseller', 900_000), 'bank', '2026-11-05 15:30:00', 900_000);
    channelOrder($fourth, 'reseller', 99_000_000, 'pending_payment');
    $historical->assignRole('reseller');

    $before = [
        DB::table('billing_journals')->count(),
        DB::table('sales_orders')->count(),
        DB::table('sales_bank_settlements')->count(),
        DB::table('sales_order_settlements')->count(),
    ];
    $snapshot = app(ReadAdminSalesChannelKpis::class)->handle($root->id, 'day');
    $current = $snapshot['current'];

    expect($snapshot['management']['current']['revenueRials'])->toBe(6_850_000)
        ->and($snapshot['management']['current']['transactions'])->toBe(6)
        ->and($current['channels']['direct'])->toBe([
            'revenueRials' => 1_850_000, 'transactions' => 4, 'uniqueBuyers' => 2,
        ])
        ->and($current['channels']['reseller'])->toBe([
            'revenueRials' => 2_000_000, 'transactions' => 1, 'uniqueBuyers' => 1,
        ])
        ->and($current['channels']['unknown'])->toBe([
            'revenueRials' => 3_000_000, 'transactions' => 1, 'uniqueBuyers' => 1,
        ])
        ->and($current['topBuyers'])->toBe([
            ['buyerUserId' => $historical->id, 'revenueRials' => 3_000_000, 'transactions' => 1],
            ['buyerUserId' => $reseller->id, 'revenueRials' => 2_000_000, 'transactions' => 1],
            ['buyerUserId' => $direct->id, 'revenueRials' => 1_750_000, 'transactions' => 3],
        ])
        ->and($current['topThreeRevenueRials'])->toBe(6_750_000)
        ->and($current['topThreeSharePercent'])->toBe(98.5)
        ->and($snapshot['previous']['channels']['reseller']['revenueRials'])->toBe(300_000)
        ->and($snapshot['previous']['channels']['unknown']['transactions'])->toBe(0)
        ->and(app(ReadAdminBusinessReport::class)->settledTotalsBetween(
            $root->id,
            Carbon::parse('2026-11-06 00:00:00', 'Asia/Tehran')->utc(),
            Carbon::parse('2026-11-06 15:30:00', 'Asia/Tehran')->utc(),
        )['revenueRials'])->toBe(6_850_000)
        ->and([
            DB::table('billing_journals')->count(),
            DB::table('sales_orders')->count(),
            DB::table('sales_bank_settlements')->count(),
            DB::table('sales_order_settlements')->count(),
        ])->toBe($before);
    Http::assertNothingSent();
});

it('provides empty and stable top-three windows without fabricating concentration percentages', function (): void {
    $root = channelRoot();
    $empty = app(ReadAdminSalesChannelKpis::class)->handle($root->id, 'week');
    expect($empty['current']['channels']['unknown']['transactions'])->toBe(0)
        ->and($empty['current']['topBuyers'])->toBe([])
        ->and($empty['current']['topThreeSharePercent'])->toBeNull();

    $a = channelUser();
    $b = channelUser();
    $idA = channelOrder($a, 'direct', 500_000);
    $idB = channelOrder($b, 'direct', 500_000);
    channelSettle($idA, 'bank', '2026-11-04 10:00:00', 500_000);
    channelSettle($idB, 'wallet', '2026-11-04 12:00:00', 500_000);

    $data = app(ReadAdminSalesChannelKpis::class)->handle($root->id, 'week');
    expect($data['current']['topBuyers'])->toBe([
        ['buyerUserId' => min($a->id, $b->id), 'revenueRials' => 500_000, 'transactions' => 1],
        ['buyerUserId' => max($a->id, $b->id), 'revenueRials' => 500_000, 'transactions' => 1],
    ])
        ->and($data['current']['topThreeSharePercent'])->toBe(100.0)
        ->and($data['previous']['topBuyers'])->toBe([]);
    Http::assertNothingSent();
});

it('keeps all channel windows private to trusted root and rejects invalid periods', function (): void {
    $root = channelRoot();
    $notRoot = channelUser();
    $untrusted = channelUser();
    $untrusted->assignRole('super-admin');
    $reader = app(ReadAdminSalesChannelKpis::class);

    expect(fn () => $reader->handle($notRoot->id, 'day'))->toThrow(AuthorizationException::class)
        ->and(fn () => $reader->handle($untrusted->id, 'week'))->toThrow(AuthorizationException::class)
        ->and(fn () => $reader->handle($root->id, 'year'))->toThrow(ValidationException::class)
        ->and(fn () => app(ReadAdminBusinessReport::class)->settledChannelBreakdownBetween(
            $notRoot->id, now()->subDay(), now(),
        ))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReadAdminBusinessReport::class)->settledChannelBreakdownBetween(
            $root->id, now(), now()->subDay(),
        ))->toThrow(ValidationException::class);

    $this->travelTo(Carbon::parse('2026-11-06 00:00:00', 'Asia/Tehran')->utc());
    $midnight = $reader->handle($root->id, 'day');
    expect($midnight['current']['channels']['direct']['revenueRials'])->toBe(0)
        ->and($midnight['current']['topBuyers'])->toBe([])
        ->and($midnight['previous']['topThreeSharePercent'])->toBeNull();
    Http::assertNothingSent();
});

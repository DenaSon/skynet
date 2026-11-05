<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ReadAdminBusinessReport;
use App\Modules\Sales\Application\ReadAdminManagementKpis;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-05 15:30:00', 'Asia/Tehran')->utc());
    config()->set('super_admin.telegram_ids', ['991801']);
    Http::preventStrayRequests();
});

function kpiRoot(): User
{
    $root = User::factory()->create([
        'created_at' => Carbon::parse('2024-01-01 12:00:00', 'Asia/Tehran')->utc(),
    ]);
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 991801]);

    return $root;
}

function kpiUser(string $localTime): User
{
    return User::factory()->create([
        'created_at' => Carbon::parse($localTime, 'Asia/Tehran')->utc(),
    ]);
}

function kpiBareOrder(User $buyer, string $status, int $amount): int
{
    return (int) DB::table('sales_orders')->insertGetId([
        'buyer_user_id' => $buyer->id,
        'idempotency_key' => bin2hex(random_bytes(16)),
        'product_slug' => 'kpi-fixture',
        'product_name' => 'KPI test order',
        'duration_days' => 30, 'traffic_gb' => 20,
        'public_price_rials' => $amount, 'discount_percent' => 0,
        'discount_rials' => 0, 'total_price_rials' => $amount,
        'status' => $status, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function kpiSettlement(
    User $buyer,
    string $method,
    string $localTime,
    int $amount,
    string $status = 'fulfilled',
    bool $renewal = false,
): int {
    $order = kpiBareOrder($buyer, $status, $amount);
    $settledAt = Carbon::parse($localTime, 'Asia/Tehran')->utc();
    $journal = (int) DB::table('billing_journals')->insertGetId([
        'kind' => 'purchase', 'idempotency_key' => 'kpi-journal-'.$order,
        'created_at' => now(),
    ]);

    if ($method === 'bank') {
        $claim = (int) DB::table('sales_bank_payment_claims')->insertGetId([
            'sales_order_id' => $order, 'buyer_user_id' => $buyer->id,
            'photo_encrypted' => 'fixture-only',
            'photo_fingerprint' => hash('sha256', 'kpi-bank-'.$order),
            'telegram_message_key' => 'kpi-claim-'.$order,
            'status' => 'approved',
            'reviewed_by' => $buyer->id, 'reviewed_at' => $settledAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sales_bank_settlements')->insert([
            'sales_order_id' => $order, 'payment_claim_id' => $claim,
            'billing_journal_id' => $journal,
            'verified_by' => $buyer->id, 'payment_reference' => 'KPIBANK'.$order,
            'amount_rials' => $amount, 'settled_at' => $settledAt,
        ]);
    } else {
        DB::table('sales_order_settlements')->insert([
            'sales_order_id' => $order, 'billing_journal_id' => $journal,
            'method' => 'wallet', 'amount_rials' => $amount,
            'settled_at' => $settledAt,
        ]);
    }

    if ($renewal) {
        $source = kpiBareOrder($buyer, 'fulfilled', $amount);
        $subscription = (int) DB::table('provisioning_subscriptions')->insertGetId([
            'reference' => 'kpi-source-'.$order,
            'remote_username' => 'kpi_'.$order,
            'state' => 'active', 'last_operation_key' => 'fixture',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sales_renewal_orders')->insert([
            'sales_order_id' => $order, 'source_order_id' => $source,
            'provisioning_subscription_id' => $subscription,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $order;
}

it('compares Tehran day-to-now fairly, excludes corrected wallet rows and reuses the existing sales report', function (): void {
    $root = kpiRoot();
    $buyer = kpiUser('2026-09-01 12:00:00');
    kpiUser('2026-11-04 10:00:00');
    kpiUser('2026-11-05 11:00:00');
    kpiUser('2026-11-05 14:30:00');
    kpiUser('2026-11-04 16:00:00');
    kpiUser('2026-11-05 16:00:00');

    kpiSettlement($buyer, 'bank', '2026-11-04 12:00:00', 1_000_000);
    kpiSettlement($buyer, 'bank', '2026-11-04 15:30:00', 900_000);
    kpiSettlement($buyer, 'bank', '2026-11-05 10:00:00', 2_000_000);
    kpiSettlement($buyer, 'wallet', '2026-11-05 12:00:00', 1_000_000, renewal: true);
    kpiSettlement($buyer, 'wallet', '2026-11-05 13:00:00', 5_000_000, status: 'refunded');
    kpiSettlement($buyer, 'wallet', '2026-11-05 15:30:00', 800_000);
    kpiBareOrder($buyer, 'pending_payment', 99_000_000);

    // A verified top-up is incoming bank cash, not another purchase.
    DB::table('billing_journals')->insert([
        'kind' => 'topup', 'idempotency_key' => 'kpi-wallet-credit',
        'created_at' => now(),
    ]);
    $before = [
        DB::table('billing_journals')->count(),
        DB::table('sales_bank_settlements')->count(),
        DB::table('sales_order_settlements')->count(),
    ];

    $read = app(ReadAdminManagementKpis::class)->handle($root->id, 'day');
    expect($read['period'])->toBe('day')
        ->and($read['timezone'])->toBe('Asia/Tehran')
        ->and($read['generatedAt'])->toBe('2026-11-05 15:30')
        ->and($read['current']['from'])->toBe('2026-11-05 00:00:00')
        ->and($read['current']['until'])->toBe('2026-11-05 15:30:00')
        ->and($read['previous']['from'])->toBe('2026-11-04 00:00:00')
        ->and($read['previous']['until'])->toBe('2026-11-04 15:30:00')
        ->and($read['current']['revenueRials'])->toBe(3_000_000)
        ->and($read['previous']['revenueRials'])->toBe(1_000_000)
        ->and($read['current']['transactions'])->toBe(2)
        ->and($read['current']['uniqueBuyers'])->toBe(1)
        ->and($read['current']['newPurchases'])->toBe(1)
        ->and($read['current']['renewals'])->toBe(1)
        ->and($read['current']['newUsers'])->toBe(2)
        ->and($read['previous']['newUsers'])->toBe(1)
        ->and($read['growth']['salesPercent'])->toBe(200.0)
        ->and($read['growth']['newUsersPercent'])->toBe(100.0);

    // The old 7-day report still counts the same genuine settlements, not
    // pending orders, top-ups, or corrected wallet spending.
    $existing = app(ReadAdminBusinessReport::class)->handle($root->id, 7);
    expect($existing['revenueRials'])->toBe(5_700_000)
        ->and($existing['settled'])->toBe(5)
        ->and([
            DB::table('billing_journals')->count(),
            DB::table('sales_bank_settlements')->count(),
            DB::table('sales_order_settlements')->count(),
        ])->toBe($before);
    Http::assertNothingSent();
});

it('uses adjacent complete seven-day Tehran periods, excluding today and upper boundaries', function (): void {
    $root = kpiRoot();
    $buyer = kpiUser('2026-09-01 12:00:00');
    kpiUser('2026-10-22 00:00:00');
    kpiUser('2026-10-29 00:00:00');
    kpiUser('2026-11-04 23:59:59');
    kpiUser('2026-11-05 00:00:00');

    kpiSettlement($buyer, 'bank', '2026-10-22 00:00:00', 2_000_000);
    kpiSettlement($buyer, 'bank', '2026-10-29 00:00:00', 1_000_000);
    kpiSettlement($buyer, 'wallet', '2026-11-04 23:59:59', 3_000_000);
    kpiSettlement($buyer, 'bank', '2026-11-05 00:00:00', 9_000_000);

    $data = app(ReadAdminManagementKpis::class)->handle($root->id, 'week');
    expect($data['current']['from'])->toBe('2026-10-29 00:00:00')
        ->and($data['current']['until'])->toBe('2026-11-05 00:00:00')
        ->and($data['previous']['from'])->toBe('2026-10-22 00:00:00')
        ->and($data['previous']['until'])->toBe('2026-10-29 00:00:00')
        ->and($data['current']['revenueRials'])->toBe(4_000_000)
        ->and($data['previous']['revenueRials'])->toBe(2_000_000)
        ->and($data['current']['newUsers'])->toBe(2)
        ->and($data['previous']['newUsers'])->toBe(1)
        ->and($data['growth']['salesPercent'])->toBe(100.0)
        ->and($data['growth']['newUsersPercent'])->toBe(100.0);
    Http::assertNothingSent();
});

it('aligns Persian month boundaries through the new year, not rolling thirty-day buckets', function (): void {
    $this->travelTo(Carbon::parse('2026-04-15 10:00:00', 'Asia/Tehran')->utc());
    $root = kpiRoot();
    $buyer = kpiUser('2025-09-01 12:00:00');

    kpiUser('2026-01-21 00:00:00');
    kpiUser('2026-02-20 00:00:00');
    kpiUser('2026-03-20 23:59:59');
    kpiUser('2026-03-21 00:00:00');

    kpiSettlement($buyer, 'bank', '2026-01-21 00:00:00', 1_000_000);
    kpiSettlement($buyer, 'bank', '2026-02-20 00:00:00', 2_000_000);
    kpiSettlement($buyer, 'bank', '2026-03-21 00:00:00', 9_000_000);

    $data = app(ReadAdminManagementKpis::class)->handle($root->id, 'month');
    expect($data['current']['from'])->toBe('2026-02-20 00:00:00')
        ->and($data['current']['until'])->toBe('2026-03-21 00:00:00')
        ->and($data['previous']['from'])->toBe('2026-01-21 00:00:00')
        ->and($data['previous']['until'])->toBe('2026-02-20 00:00:00')
        ->and($data['current']['revenueRials'])->toBe(2_000_000)
        ->and($data['previous']['revenueRials'])->toBe(1_000_000)
        ->and($data['current']['newUsers'])->toBe(2)
        ->and($data['previous']['newUsers'])->toBe(1)
        ->and($data['growth']['salesPercent'])->toBe(100.0);
    Http::assertNothingSent();
});

it('includes the 30-day leap Esfand without merging it into Farvardin', function (): void {
    $this->travelTo(Carbon::parse('2025-04-15 10:00:00', 'Asia/Tehran')->utc());
    $root = kpiRoot();

    $data = app(ReadAdminManagementKpis::class)->handle($root->id, 'month');
    expect($data['current']['from'])->toBe('2025-02-19 00:00:00')
        ->and($data['current']['until'])->toBe('2025-03-21 00:00:00')
        ->and($data['previous']['from'])->toBe('2025-01-20 00:00:00')
        ->and($data['previous']['until'])->toBe('2025-02-19 00:00:00');
});

it('marks zero comparison baselines undefined and negative changes correctly', function (): void {
    $root = kpiRoot();
    $buyer = kpiUser('2026-09-01 12:00:00');
    kpiUser('2026-11-05 12:00:00');
    kpiSettlement($buyer, 'bank', '2026-11-05 10:00:00', 500_000);

    $first = app(ReadAdminManagementKpis::class)->handle($root->id, 'day');
    expect($first['previous']['revenueRials'])->toBe(0)
        ->and($first['previous']['newUsers'])->toBe(0)
        ->and($first['growth']['salesPercent'])->toBeNull()
        ->and($first['growth']['newUsersPercent'])->toBeNull();

    kpiUser('2026-11-04 12:00:00');
    kpiSettlement($buyer, 'wallet', '2026-11-04 12:00:00', 1_000_000);
    $decline = app(ReadAdminManagementKpis::class)->handle($root->id, 'day');
    expect($decline['growth']['salesPercent'])->toBe(-50.0)
        ->and($decline['growth']['newUsersPercent'])->toBe(0.0);

    $this->travelTo(Carbon::parse('2026-11-05 13:00:00', 'Asia/Tehran')->utc());
    // A root created long before both periods does not inflate new signups.
    expect(app(ReadAdminManagementKpis::class)->handle($root->id, 'month')['growth']['newUsersPercent'])
        ->toBeNull();
});

it('protects management KPI reads from untrusted roles and invalid period inputs', function (): void {
    $root = kpiRoot();
    $ordinary = User::factory()->admin()->create();
    $member = User::factory()->create();
    $reader = app(ReadAdminManagementKpis::class);

    expect(fn () => $reader->handle($ordinary->id, 'day'))->toThrow(AuthorizationException::class)
        ->and(fn () => $reader->handle($member->id, 'month'))->toThrow(AuthorizationException::class)
        ->and(fn () => $reader->handle($root->id, '0'))->toThrow(ValidationException::class)
        ->and(fn () => $reader->handle($root->id, '30'))->toThrow(ValidationException::class)
        ->and(fn () => $reader->handle($root->id, 'year'))->toThrow(ValidationException::class)
        ->and(fn () => app(ReadAdminBusinessReport::class)->settledTotalsBetween(
            $member->id, now()->subDay(), now(),
        ))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReadAdminBusinessReport::class)->settledTotalsBetween(
            $root->id, now(), now()->subDay(),
        ))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

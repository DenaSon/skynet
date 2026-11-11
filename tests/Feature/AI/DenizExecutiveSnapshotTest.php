<?php

declare(strict_types=1);

use App\Modules\AI\Application\ReadDenizExecutiveSnapshot;
use App\Modules\Billing\Application\ReadAdminAccountingReport;
use App\Modules\Billing\Application\ReadAdminCashReceipts;
use App\Modules\Billing\Application\ReadAdminWalletSummary;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
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
    $this->travelTo(Carbon::parse('2026-11-10 15:30:00', 'Asia/Tehran')->utc());
    config()->set('super_admin.telegram_ids', ['199001', '199002']);
    Http::preventStrayRequests();
});

function executiveRoot(): User
{
    Role::findOrCreate('super-admin', 'web');
    $root = User::factory()->create(['is_active' => true]);
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 199001]);

    return $root;
}

function executiveJournal(string $kind, string $key, ?int $walletId = null): int
{
    return (int) DB::table('billing_journals')->insertGetId([
        'wallet_id' => $walletId,
        'kind' => $kind,
        'idempotency_key' => $key,
        'created_at' => now(),
    ]);
}

function executivePost(int $journal, ?int $walletId, string $account, string $side, int $amount): void
{
    DB::table('billing_postings')->insert([
        'journal_id' => $journal,
        'wallet_id' => $walletId,
        'account_code' => $account,
        'side' => $side,
        'amount_rials' => $amount,
    ]);
}

function executiveOrder(User $buyer, string $category, string $method, int $amount, string $when, ?int $walletId = null): void
{
    $at = Carbon::parse($when, 'Asia/Tehran')->utc();
    $orderId = (int) DB::table('sales_orders')->insertGetId([
        'buyer_user_id' => $buyer->id,
        'buyer_category' => $category,
        'idempotency_key' => bin2hex(random_bytes(16)),
        'product_slug' => 'executive',
        'product_name' => 'Executive fixture',
        'duration_days' => 30,
        'traffic_gb' => 20,
        'public_price_rials' => $amount,
        'discount_percent' => 0,
        'discount_rials' => 0,
        'total_price_rials' => $amount,
        'status' => 'fulfilled',
        'created_at' => $at,
        'updated_at' => $at,
    ]);
    $journal = executiveJournal($method === 'bank' ? 'direct_bank_sale' : 'wallet_order', 'executive:order:'.$orderId, $walletId);

    if ($method === 'bank') {
        $claim = (int) DB::table('sales_bank_payment_claims')->insertGetId([
            'sales_order_id' => $orderId,
            'buyer_user_id' => $buyer->id,
            'photo_encrypted' => 'test-fixture',
            'photo_fingerprint' => hash('sha256', 'executive-'.$orderId),
            'telegram_message_key' => 'executive-claim-'.$orderId,
            'status' => 'approved',
            'reviewed_by' => $buyer->id,
            'reviewed_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        DB::table('sales_bank_settlements')->insert([
            'sales_order_id' => $orderId,
            'payment_claim_id' => $claim,
            'billing_journal_id' => $journal,
            'verified_by' => $buyer->id,
            'payment_reference' => 'EXECBANK'.$orderId,
            'amount_rials' => $amount,
            'settled_at' => $at,
        ]);
        executivePost($journal, null, 'cash_clearing', 'debit', $amount);
        executivePost($journal, null, 'order_liability', 'credit', $amount);

        return;
    }

    DB::table('sales_order_settlements')->insert([
        'sales_order_id' => $orderId,
        'billing_journal_id' => $journal,
        'method' => 'wallet',
        'amount_rials' => $amount,
        'settled_at' => $at,
    ]);
    executivePost($journal, $walletId, 'wallet_liability', 'debit', $amount);
    executivePost($journal, $walletId, 'order_liability', 'credit', $amount);
}

function executiveFixture(User $root): void
{
    $buyer = User::factory()->create(['name' => 'Sensitive customer name']);
    $reseller = User::factory()->create(['name' => 'Private reseller name']);
    $walletId = (int) DB::table('billing_wallets')->insertGetId([
        'user_id' => $reseller->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $topup = (int) DB::table('billing_topups')->insertGetId([
        'wallet_id' => $walletId,
        'request_key' => 'executive-topup',
        'amount_rials' => 2_000_000,
        'origin' => 'customer',
        'status' => 'approved',
        'payment_reference' => 'EXECTOPUP1',
        'reviewed_by' => $root->id,
        'reviewed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('billing_verified_receipts')->insert([
        'topup_id' => $topup,
        'payment_reference' => 'EXECTOPUP1',
        'verified_at' => Carbon::parse('2026-11-08 10:00', 'Asia/Tehran')->utc(),
    ]);
    $topupJournal = executiveJournal('topup', 'executive:topup:'.$topup, $walletId);
    executivePost($topupJournal, $walletId, 'cash_clearing', 'debit', 2_000_000);
    executivePost($topupJournal, $walletId, 'wallet_liability', 'credit', 2_000_000);

    // The canonical cash reader must net the correction without deleting original evidence.
    $correction = executiveJournal('topup_amount_correction', 'executive:correction:'.$topup, $walletId);
    executivePost($correction, $walletId, 'wallet_liability', 'debit', 500_000);
    executivePost($correction, $walletId, 'cash_clearing', 'credit', 500_000);
    DB::table('billing_topup_amount_corrections')->insert([
        'topup_id' => $topup,
        'billing_journal_id' => $correction,
        'verified_amount_rials' => 1_500_000,
        'adjustment_amount_rials' => 500_000,
        'corrected_by' => $root->id,
        'reason' => 'test corrected verified cash amount',
        'created_at' => now(),
    ]);

    executiveOrder($buyer, 'direct', 'bank', 3_000_000, '2026-11-08 12:00');
    executiveOrder($reseller, 'reseller', 'wallet', 1_000_000, '2026-11-05 12:00', $walletId);
    executiveOrder($buyer, 'unknown', 'bank', 1_000_000, '2026-10-30 12:00');

    foreach ([['expense', 'server', 500_000], ['owner_draw', 'owner', 300_000]] as [$kind, $category, $amount]) {
        DB::table('accounting_entries')->insert([
            'kind' => $kind,
            'category' => $category,
            'amount_rials' => $amount,
            'created_by' => $root->id,
            'idempotency_key' => bin2hex(random_bytes(16)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

it('returns canonical comparative sales and channel numbers without buyer identities', function (): void {
    $root = executiveRoot();
    executiveFixture($root);

    $canonical = app(ReadAdminSalesChannelKpis::class)->handle($root->id, 'week');
    $snapshot = app(ReadDenizExecutiveSnapshot::class)->handle($root->id, 'sales', 'week');

    expect($snapshot['dataset'])->toBe('sales')
        ->and($snapshot['schemaVersion'])->toBe(1)
        ->and($snapshot['currency'])->toBe('IRR')
        ->and($snapshot['timezone'])->toBe('Asia/Tehran')
        ->and($snapshot['source'])->toBe('ReadAdminSalesChannelKpis')
        ->and($snapshot['windows']['current'])->toBe($canonical['management']['current'])
        ->and($snapshot['windows']['previous'])->toBe($canonical['management']['previous'])
        ->and($snapshot['growth'])->toBe($canonical['management']['growth'])
        ->and($snapshot['channels']['current'])->toBe($canonical['current']['channels'])
        ->and($snapshot['channels']['previous'])->toBe($canonical['previous']['channels'])
        ->and($snapshot['windows']['current']['revenueRials'])->toBe(4_000_000)
        ->and($snapshot['windows']['previous']['revenueRials'])->toBe(1_000_000)
        ->and($snapshot['channels']['current']['reseller']['revenueRials'])->toBe(1_000_000)
        ->and($snapshot['channels']['previous']['unknown']['revenueRials'])->toBe(1_000_000)
        ->and($snapshot['concentration']['current']['topThreeRevenueRials'])->toBe(4_000_000)
        ->and($snapshot['concentration']['current']['topBuyersCount'])->toBe(2);

    $serialized = json_encode($snapshot, JSON_THROW_ON_ERROR);
    expect($serialized)->not->toContain('buyerUserId', 'Sensitive customer name', 'Private reseller name',
        'payment_reference', 'reviewed_by', '\"topBuyers\":');

    Http::assertNothingSent();
});

it('preserves corrected cash receipts and separates historical flows from current wallet liability', function (): void {
    $root = executiveRoot();
    executiveFixture($root);

    $cash = app(ReadAdminCashReceipts::class)->handle($root->id, '7');
    $snapshot = app(ReadDenizExecutiveSnapshot::class)->handle($root->id, 'cash', '7');

    expect($snapshot['window'])->toBe(['start' => $cash['start'], 'endInclusive' => $cash['end']])
        ->and($snapshot['metrics']['topupsRials'])->toBe(1_500_000)
        ->and($snapshot['metrics']['cashInRials'])->toBe(4_500_000)
        ->and($snapshot['metrics']['walletSalesRials'])->toBe(1_000_000)
        ->and($snapshot['metrics']['salesRials'])->toBe(4_000_000)
        ->and($snapshot['pointInTime']['walletBalanceRials'])->toBe(500_000)
        ->and($snapshot['metrics']['cashInRials'])->toBe($cash['cashInRials'])
        ->and($snapshot['pointInTime']['walletBalanceRials'])->toBe($cash['currentWalletBalanceRials']);

    $all = app(ReadDenizExecutiveSnapshot::class)->handle($root->id, 'cash', 'all');
    expect($all['window']['start'])->toBeNull()
        ->and($all['metrics']['cashInRials'])->toBe(5_500_000);

    Http::assertNothingSent();
});

it('uses approved corrected top-ups and direct bank receipts in same-hour daily cash comparisons', function (): void {
    $root = executiveRoot();
    executiveFixture($root);
    $buyer = User::factory()->create();
    $todayAt = Carbon::parse('2026-11-10 12:00', 'Asia/Tehran')->utc();
    DB::table('billing_verified_receipts')->where('payment_reference', 'EXECTOPUP1')
        ->update(['verified_at' => $todayAt]);

    executiveOrder($buyer, 'direct', 'bank', 3_000_000, '2026-11-10 12:00');
    executiveOrder($buyer, 'direct', 'bank', 2_000_000, '2026-11-09 12:00');
    executiveOrder($buyer, 'direct', 'bank', 9_000_000, '2026-11-09 17:00'); // after yesterday cutoff
    executiveOrder($buyer, 'direct', 'wallet', 1_000_000, '2026-11-10 13:00');

    $before = DB::table('billing_journals')->count();
    $snapshot = app(ReadDenizExecutiveSnapshot::class)->handle($root->id, 'cash', 'day');
    $today = app(ReadAdminCashReceipts::class)->handle($root->id, 'today');
    $yesterday = app(ReadAdminCashReceipts::class)->handle($root->id, 'yesterday_same_time');

    expect($snapshot['period'])->toBe('day')
        ->and($snapshot['source'])->toBe('ReadAdminCashReceipts')
        ->and($snapshot['sameTehranTime'])->toBe('15:30')
        ->and($snapshot['windows']['current']['date'])->toBe('2026-11-10')
        ->and($snapshot['windows']['previous']['date'])->toBe('2026-11-09')
        ->and($snapshot['windows']['current']['cashInRials'])->toBe(4_500_000)
        ->and($snapshot['windows']['previous']['cashInRials'])->toBe(2_000_000)
        ->and($snapshot['windows']['current']['topupsRials'])->toBe(1_500_000)
        ->and($snapshot['windows']['current']['bankSalesRials'])->toBe(3_000_000)
        ->and($snapshot['windows']['current']['topupsCount'])->toBe(1)
        ->and($snapshot['windows']['previous']['bankSalesCount'])->toBe(1)
        ->and($snapshot['windows']['current']['cashInRials'])->toBe($today['cashInRials'])
        ->and($snapshot['windows']['previous']['cashInRials'])->toBe($yesterday['cashInRials'])
        ->and($snapshot['growthPercent'])->toBe(125.0)
        ->and(DB::table('billing_journals')->count())->toBe($before);

    $serialized = json_encode($snapshot, JSON_THROW_ON_ERROR);
    expect($serialized)->not->toContain('Sensitive customer name', 'Private reseller name',
        'payment_reference', 'billing_journals', 'topBuyers');
    Http::assertNothingSent();
});

it('keeps signed negative growth for a real decline in verified daily cash', function (): void {
    $root = executiveRoot();
    $buyer = User::factory()->create();
    executiveOrder($buyer, 'direct', 'bank', 1_000_000, '2026-11-10 12:00');
    executiveOrder($buyer, 'direct', 'bank', 2_000_000, '2026-11-09 12:00');

    $snapshot = app(ReadDenizExecutiveSnapshot::class)->handle($root->id, 'cash', 'day');
    expect($snapshot['windows']['current']['cashInRials'])->toBe(1_000_000)
        ->and($snapshot['windows']['previous']['cashInRials'])->toBe(2_000_000)
        ->and($snapshot['growthPercent'])->toBe(-50.0);
    Http::assertNothingSent();
});

it('uses undefined daily cash growth when yesterday has no verified deposits', function (): void {
    $root = executiveRoot();
    $buyer = User::factory()->create();
    executiveOrder($buyer, 'direct', 'bank', 1_000_000, '2026-11-10 13:00');

    $snapshot = app(ReadDenizExecutiveSnapshot::class)->handle($root->id, 'cash', 'day');
    expect($snapshot['windows']['current']['cashInRials'])->toBe(1_000_000)
        ->and($snapshot['windows']['previous']['cashInRials'])->toBe(0)
        ->and($snapshot['growthPercent'])->toBeNull();
    Http::assertNothingSent();
});

it('reuses accounting and wallet read models without inventing profit or adding writes', function (): void {
    $root = executiveRoot();
    executiveFixture($root);
    $before = [];
    foreach (['billing_journals', 'billing_postings', 'billing_topups', 'sales_orders', 'accounting_entries'] as $table) {
        $before[$table] = DB::table($table)->count();
    }

    $accounting = app(ReadAdminAccountingReport::class)->handle($root->id, '7');
    $snapshot = app(ReadDenizExecutiveSnapshot::class)->handle($root->id, 'accounting', '7');
    expect($snapshot['metrics']['salesRials'])->toBe($accounting['salesRials'])
        ->and($snapshot['metrics']['expensesRials'])->toBe($accounting['expensesRials'])
        ->and($snapshot['metrics']['drawsRials'])->toBe($accounting['drawsRials'])
        ->and($snapshot['metrics']['profitRials'])->toBe($accounting['profitRials'])
        ->and($snapshot['metrics']['afterDrawsRials'])->toBe($accounting['afterDrawsRials'])
        ->and($snapshot['metrics']['profitRials'])->toBe(3_500_000)
        ->and($snapshot['metrics']['afterDrawsRials'])->toBe(3_200_000);

    $wallet = app(ReadAdminWalletSummary::class)->handle($root->id);
    $walletSnapshot = app(ReadDenizExecutiveSnapshot::class)->handle($root->id, 'wallet', 'current');
    expect($walletSnapshot['metrics'])->toBe($wallet)
        ->and($walletSnapshot['metrics']['balanceRials'])->toBe(500_000)
        ->and($walletSnapshot['period'])->toBe('current');

    foreach ($before as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
    Http::assertNothingSent();
});

it('rejects invalid datasets or incompatible periods instead of silently substituting a window', function (): void {
    $root = executiveRoot();
    $reader = app(ReadDenizExecutiveSnapshot::class);

    foreach ([
        ['sales', '7'], ['cash', 'week'], ['cash', 'month'],
        ['accounting', 'all'], ['wallet', 'day'], ['users', 'week'], ['unknown', 'week'],
    ] as [$dataset, $period]) {
        expect(fn () => $reader->handle($root->id, $dataset, $period))
            ->toThrow(ValidationException::class);
    }

    foreach ([['sales', 'day'], ['sales', 'month'], ['cash', 'day'], ['cash', '30'], ['accounting', 'month']] as [$dataset, $period]) {
        expect($reader->handle($root->id, $dataset, $period)['period'])->toBe($period);
    }

    Http::assertNothingSent();
});

it('blocks ordinary admins, unallowlisted roots and revoked or inactive super admins for every dataset', function (): void {
    $root = executiveRoot();
    $other = User::factory()->create(['is_active' => true]);
    $other->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $other->id, 'telegram_user_id' => 199003]);

    $ordinary = User::factory()->create(['is_active' => true]);
    Role::findOrCreate('admin', 'web');
    $ordinary->syncRoles(['admin']);
    TelegramAccount::query()->create(['user_id' => $ordinary->id, 'telegram_user_id' => 199004]);

    $reader = app(ReadDenizExecutiveSnapshot::class);
    foreach ([$other->id, $ordinary->id] as $id) {
        foreach ([['sales', 'week'], ['cash', 'day'], ['cash', '7'], ['accounting', '7'], ['wallet', 'current'], ['users', 'current']] as [$dataset, $period]) {
            expect(fn () => $reader->handle($id, $dataset, $period))
                ->toThrow(AuthorizationException::class);
        }
    }

    DB::table('users')->where('id', $root->id)->update(['is_active' => false]);
    expect(fn () => $reader->handle($root->id, 'sales', 'week'))->toThrow(AuthorizationException::class);

    DB::table('users')->where('id', $root->id)->update(['is_active' => true]);
    $root->syncRoles(['admin']);
    expect(fn () => $reader->handle($root->id, 'wallet', 'current'))->toThrow(AuthorizationException::class);

    Http::assertNothingSent();
});

<?php

declare(strict_types=1);

use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Application\BrowseUsers;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function directoryFilterRoot(): User
{
    config()->set('super_admin.telegram_ids', ['984411']);
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 984411]);

    return $root;
}

function directoryFilterOrder(User $buyer, string $status): int
{
    return DB::table('sales_orders')->insertGetId([
        'buyer_user_id' => $buyer->id,
        'idempotency_key' => bin2hex(random_bytes(16)),
        'product_slug' => 'directory-filter', 'product_name' => 'Directory filter',
        'duration_days' => 30, 'traffic_gb' => 10,
        'public_price_rials' => 100000, 'discount_percent' => 0,
        'discount_rials' => 0, 'total_price_rials' => 100000,
        'status' => $status, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function directoryFilterFund(User $user, int $amount): void
{
    app(ViewWallet::class)->handle($user->id);
    $walletId = (int) DB::table('billing_wallets')->where('user_id', $user->id)->value('id');
    $journalId = DB::table('billing_journals')->insertGetId([
        'wallet_id' => $walletId, 'kind' => 'topup',
        'idempotency_key' => 'directory:fund:'.$user->id, 'created_at' => now(),
    ]);
    DB::table('billing_postings')->insert([
        ['journal_id' => $journalId, 'wallet_id' => $walletId, 'account_code' => 'wallet_liability', 'side' => 'credit', 'amount_rials' => $amount],
        ['journal_id' => $journalId, 'wallet_id' => $walletId, 'account_code' => 'cash', 'side' => 'debit', 'amount_rials' => $amount],
    ]);
}

it('finds linked numeric Telegram IDs without losing internal-ID collisions or existing searches', function (): void {
    $admin = User::factory()->admin()->create();
    $internal = User::factory()->create(['name' => 'Legacy numeric user']);
    $linked = User::factory()->create(['name' => 'Telegram numeric user']);
    TelegramAccount::query()->create(['user_id' => $linked->id, 'telegram_user_id' => $internal->id]);

    $directory = app(BrowseUsers::class);
    $result = $directory->page($admin->id, (string) $internal->id);
    expect($result->total)->toBe(2)
        ->and(collect($result->users)->pluck('id')->all())->toContain($internal->id, $linked->id)
        ->and($directory->page($admin->id, (string) $linked->id)->users[0]->id)->toBe($linked->id)
        ->and($directory->page($admin->id, $internal->name)->users[0]->id)->toBe($internal->id)
        ->and($directory->page($admin->id, $internal->email)->total)->toBe(1)
        ->and($directory->page($admin->id, str_repeat('9', 64))->total)->toBe(0);
});

it('filters by verified purchase history, pending checkout, review and ledger-positive nonbuyers', function (): void {
    $root = directoryFilterRoot();
    $buyer = User::factory()->create();
    $pending = User::factory()->create();
    $review = User::factory()->create();
    $credit = User::factory()->create();
    $never = User::factory()->create();

    directoryFilterOrder($buyer, 'fulfilled');
    directoryFilterOrder($pending, 'pending_payment');
    $reviewOrder = directoryFilterOrder($review, 'paid');
    DB::table('sales_fulfillment_outbox')->insert([
        'sales_order_id' => $reviewOrder,
        'event_key' => 'sales-order:'.$reviewOrder.':create',
        'state' => 'needs_review', 'attempts' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    directoryFilterFund($credit, 800000);

    $directory = app(BrowseUsers::class);
    $nonbuyers = collect($directory->page($root->id, mode: 'ab')->users)->pluck('id')->all();
    $pendingIds = collect($directory->page($root->id, mode: 'aq')->users)->pluck('id')->all();
    $reviewIds = collect($directory->page($root->id, mode: 'av')->users)->pluck('id')->all();
    $creditIds = collect($directory->page($root->id, mode: 'ac')->users)->pluck('id')->all();

    expect($nonbuyers)->toContain($never->id, $credit->id, $pending->id)
        ->not->toContain($buyer->id, $review->id)
        ->and($pendingIds)->toContain($pending->id)->not->toContain($buyer->id)
        ->and($reviewIds)->toContain($review->id)->not->toContain($pending->id)
        ->and($creditIds)->toContain($credit->id)->not->toContain($buyer->id, $pending->id);
});

it('filters inactive accounts and linked paid services and includes trial review without claiming live state', function (): void {
    $root = directoryFilterRoot();
    $inactive = User::factory()->create(['is_active' => false]);
    $serviceOwner = User::factory()->create();
    $trialOwner = User::factory()->create();
    $orderId = directoryFilterOrder($serviceOwner, 'fulfilled');
    $reference = 'sales-order:'.$orderId;
    $subscriptionId = DB::table('provisioning_subscriptions')->insertGetId([
        'reference' => $reference,
        'remote_username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
        'remote_user_id' => null, 'state' => 'active',
        'last_operation_key' => $reference.':create',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('provisioning_order_links')->insert([
        'sales_order_id' => $orderId, 'provisioning_subscription_id' => $subscriptionId,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('trial_claims')->insert([
        'user_id' => $trialOwner->id, 'telegram_user_id' => 975621123,
        'phone_fingerprint' => hash('sha256', 'filter-trial'),
        'state' => 'needs_review', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $directory = app(BrowseUsers::class);
    $inactiveIds = collect($directory->page($root->id, mode: 'ai')->users)->pluck('id')->all();
    $linkedIds = collect($directory->page($root->id, mode: 'ah')->users)->pluck('id')->all();
    $reviewIds = collect($directory->page($root->id, mode: 'av')->users)->pluck('id')->all();

    expect($inactiveIds)->toContain($inactive->id)->not->toContain($serviceOwner->id)
        ->and($linkedIds)->toContain($serviceOwner->id)->not->toContain($trialOwner->id)
        ->and($reviewIds)->toContain($trialOwner->id)->not->toContain($serviceOwner->id);
});

it('supports asc and desc for every ranking with stable pagination and retained operational filtering', function (): void {
    $root = directoryFilterRoot();
    $older = User::factory()->create();
    $newer = User::factory()->create();
    directoryFilterFund($older, 900000);
    directoryFilterFund($newer, 400000);

    $directory = app(BrowseUsers::class);
    $recent = $directory->page($root->id, mode: 'a');
    $oldest = $directory->page($root->id, mode: 'au');
    $descWallet = $directory->page($root->id, mode: 'aw');
    $ascWallet = $directory->page($root->id, mode: 'awu');
    $ascFilter = $directory->page($root->id, mode: 'awuc');

    expect($recent->users[0]->id)->toBe($newer->id)
        ->and($oldest->users[0]->id)->toBe($root->id)
        ->and($descWallet->users[0]->id)->toBe($older->id)
        ->and($ascWallet->users[0]->directoryMetricRials)->toBe(0)
        ->and($ascWallet->users[count($ascWallet->users) - 1]->id)->toBe($older->id)
        ->and($ascFilter->total)->toBe(2)
        ->and(collect($ascFilter->users)->pluck('id')->all())->toBe([$newer->id, $older->id])
        ->and($directory->page($root->id, mode: 'apu')->users[0]->directoryMetricRials)->toBe(0);
});

it('keeps new filters and rankings trusted-root-only while preserving legacy delegated browsing', function (): void {
    directoryFilterRoot();
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    $directory = app(BrowseUsers::class);

    expect($directory->page($admin->id, mode: 'a')->total)->toBe(3)
        ->and($directory->page($admin->id, mode: 'r')->total)->toBe(0)
        ->and(fn () => $directory->page($admin->id, mode: 'ab'))->toThrow(AuthorizationException::class)
        ->and(fn () => $directory->page($admin->id, mode: 'awu'))->toThrow(AuthorizationException::class)
        ->and(fn () => $directory->page($admin->id, mode: 'ai'))->toThrow(AuthorizationException::class)
        ->and(fn () => $directory->page($member->id, mode: 'a'))->toThrow(AuthorizationException::class)
        ->and(fn () => $directory->page($admin->id, mode: 'auu'))->toThrow(ValidationException::class);
});

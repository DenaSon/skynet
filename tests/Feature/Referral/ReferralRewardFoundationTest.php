<?php

declare(strict_types=1);

use App\Modules\Billing\Application\CreditReferralReward;
use App\Modules\Billing\Application\ReadAdminAccountingReport;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Onboarding\Application\JoinFromTelegram;
use App\Modules\Referral\Application\GetReferralInvite;
use App\Modules\Referral\Application\GrantReferralReward;
use App\Modules\Trial\Application\ReserveFreeTrial;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\Support\ReferralRewardFixture as Fixture;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('referral', [
        'rewards_enabled' => true, 'reward_tomans' => 30000,
        'max_rewards_per_referrer' => 7, 'rewards_start_at' => '2026-10-26T00:00:00Z',
    ]);
    $this->travelTo(now()->setDate(2026, 10, 26)->setTime(12, 0));
    Http::preventStrayRequests();
    Queue::fake();
});

it('credits an immutable balanced reward once and counts it as cost not bank cash', function (): void {
    $referrer = Fixture::member();
    $order = Fixture::order(Fixture::member(), $referrer);
    $action = app(GrantReferralReward::class);
    $reward = $action->handle($order);
    config()->set('referral.reward_tomans', '45000');
    expect($action->handle($order))->toBe($reward)
        ->and(DB::table('referral_rewards')->count())->toBe(1)
        ->and((int) DB::table('referral_rewards')->value('amount_rials'))->toBe(300000)
        ->and(app(ViewWallet::class)->handle($referrer->id)->balanceRials)->toBe(300000)
        ->and(DB::table('billing_topups')->count())->toBe(0)
        ->and(DB::table('billing_verified_receipts')->count())->toBe(0)
        ->and(DB::table('accounting_entries')->count())->toBe(0);
    $journal = DB::table('referral_rewards')->value('billing_journal_id');
    expect(DB::table('billing_postings')->where('journal_id', $journal)->count())->toBe(2)
        ->and((int) DB::table('billing_postings')->where('journal_id', $journal)->where('side', 'debit')->sum('amount_rials'))->toBe(300000);
    $root = Fixture::member();
    $root->syncRoles(['super-admin']);
    config()->set('super_admin.telegram_ids', [(string) (9800000 + $root->id), '99999999']);
    $report = app(ReadAdminAccountingReport::class)->handle($root->id, '7');
    expect($report['expensesRials'])->toBe(300000)->and($report['expenseEntries'])->toBe(1)
        ->and($report['salesRials'])->toBe(650000)->and($report['profitRials'])->toBe(350000);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('limits a referrer to seven distinct buyers and respects later limit changes', function (): void {
    $referrer = Fixture::member();
    $orders = [];
    for ($i = 0; $i < 8; $i++) {
        $orders[] = Fixture::order(Fixture::member(), $referrer);
    }
    foreach (array_slice($orders, 0, 7) as $order) {
        expect(app(GrantReferralReward::class)->handle($order))->toBeInt();
    }
    expect(app(GrantReferralReward::class)->handle($orders[7]))->toBeNull()
        ->and(app(ViewWallet::class)->handle($referrer->id)->balanceRials)->toBe(2100000);
    config()->set('referral.max_rewards_per_referrer', 1);
    expect(app(GrantReferralReward::class)->handle($orders[0]))->toBeInt()
        ->and(app(GrantReferralReward::class)->handle($orders[7]))->toBeNull();
    config()->set('referral.max_rewards_per_referrer', '8');
    config()->set('referral.reward_tomans', '40000');
    expect(app(GrantReferralReward::class)->handle($orders[7]))->toBeInt()
        ->and(app(ViewWallet::class)->handle($referrer->id)->balanceRials)->toBe(2500000);
});

it('rejects incomplete or altered activation evidence', function (string $table, array $fields): void {
    $order = Fixture::order(Fixture::member(), Fixture::member());
    DB::table($table)->update($fields);
    expect(app(GrantReferralReward::class)->handle($order))->toBeNull()
        ->and(DB::table('referral_rewards')->count())->toBe(0);
})->with([
    ['sales_orders', ['status' => 'paid']],
    ['sales_orders', ['status' => 'pending_payment']],
    ['sales_orders', ['total_price_rials' => 0]],
    ['sales_orders', ['discount_percent' => 35]],
    ['sales_orders', ['created_at' => '2026-10-25 23:59:59']],
    ['sales_fulfillment_outbox', ['state' => 'needs_review']],
    ['provisioning_operations', ['state' => 'reserved']],
    ['provisioning_operations', ['kind' => 'renewal']],
    ['provisioning_subscriptions', ['reference' => 'free-trial:1']],
    ['provisioning_subscriptions', ['remote_user_id' => null]],
]);

it('does not trust fulfilled status when financial evidence is broken', function (): void {
    $order = Fixture::order(Fixture::member(), Fixture::member());
    DB::table('billing_postings')->where('side', 'debit')->update(['amount_rials' => 1]);
    expect(fn () => app(GrantReferralReward::class)->handle($order))->toThrow(RuntimeException::class);
    expect(DB::table('referral_rewards')->count())->toBe(0);
});

it('does not reward a second purchase or renewal from the same invitee', function (): void {
    $referrer = Fixture::member();
    $buyer = Fixture::member();
    $first = Fixture::order($buyer, $referrer);
    $this->travel(1)->minutes();
    $second = Fixture::order($buyer, $referrer);
    expect(app(GrantReferralReward::class)->handle($second))->toBeNull();
    expect(app(GrantReferralReward::class)->handle($first))->toBeInt();
    expect(app(GrantReferralReward::class)->handle($second))->toBeNull()
        ->and(DB::table('referral_rewards')->count())->toBe(1);
});

it('checks both participants current roles and activity', function (string $target, string $state): void {
    $referrer = Fixture::member();
    $buyer = Fixture::member();
    $order = Fixture::order($buyer, $referrer);
    $user = $target === 'referrer' ? $referrer : $buyer;
    if ($state === 'inactive') {
        $user->forceFill(['is_active' => false])->save();
    } elseif ($state === 'unlinked') {
        TelegramAccount::query()->where('user_id', $user->id)->delete();
    } else {
        Role::findOrCreate($state, 'web');
        $user->syncRoles([$state]);
    }
    expect(fn () => app(GrantReferralReward::class)->handle($order))->toThrow(AuthorizationException::class);
    expect(DB::table('referral_rewards')->count())->toBe(0);
})->with(['referrer', 'buyer'])->with(['reseller', 'admin', 'inactive', 'unlinked']);

it('retains signup-only attribution and gives no reward to an existing unreferenced account', function (): void {
    $referrer = Fixture::member();
    $code = app(GetReferralInvite::class)->handle($referrer->id)->code;
    $old = app(JoinFromTelegram::class)->handle(990001, 'Old account');
    app(JoinFromTelegram::class)->handle(990001, 'Renamed', $code);
    $oldUser = User::findOrFail($old->userId);
    expect(app(GrantReferralReward::class)->handle(Fixture::order($oldUser)))->toBeNull();
    $new = app(JoinFromTelegram::class)->handle(990002, 'New account', $code);
    $newUser = User::findOrFail($new->userId);
    expect(app(GrantReferralReward::class)->handle(Fixture::order($newUser)))->toBeInt();
});

it('fails closed for disabled or invalid configuration', function (string $key, mixed $value): void {
    config()->set('referral.'.$key, $value);
    expect(fn () => app(GrantReferralReward::class)->handle(1))->toThrow(ValidationException::class);
    expect(DB::table('billing_journals')->count())->toBe(0);
})->with([
    ['rewards_enabled', false], ['reward_tomans', 0], ['reward_tomans', -1],
    ['reward_tomans', 3.5], ['reward_tomans', '30000oops'], ['reward_tomans', true],
    ['reward_tomans', '100000000001'], ['max_rewards_per_referrer', 0],
    ['max_rewards_per_referrer', '7people'], ['max_rewards_per_referrer', 7.5],
    ['rewards_start_at', null], ['rewards_start_at', 'tomorrow'],
    ['rewards_start_at', '2026-02-30T00:00:00Z'],
]);

it('rolls back the wallet credit if inserting the durable reward fails', function (): void {
    $referrer = Fixture::member();
    $order = Fixture::order(Fixture::member(), $referrer);
    DB::unprepared("CREATE TRIGGER fail_reward BEFORE INSERT ON referral_rewards BEGIN SELECT RAISE(ABORT, 'fixture'); END");
    try {
        app(GrantReferralReward::class)->handle($order);
        test()->fail('Expected SQL failure');
    } catch (QueryException) {
        expect(DB::table('billing_journals')->where('kind', 'referral_reward')->count())->toBe(0)
            ->and(DB::table('referral_rewards')->count())->toBe(0)
            ->and(app(ViewWallet::class)->handle($referrer->id)->balanceRials)->toBe(0);
    } finally {
        DB::unprepared('DROP TRIGGER fail_reward');
    }
});

it('guards standalone credit and destructive rollback', function (): void {
    config()->set('referral.rewards_enabled', false);
    expect(fn () => app(CreditReferralReward::class)->handle(1, 2, 300000))->toThrow(AuthorizationException::class);
    config()->set('referral.rewards_enabled', true);
    $order = Fixture::order(Fixture::member(), Fixture::member());
    app(GrantReferralReward::class)->handle($order);
    $migration = require database_path('migrations/2026_10_26_000005_create_referral_rewards.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class)
        ->and(DB::table('referral_rewards')->count())->toBe(1);
});

it('ignores self referral and a missing order without financial side effects', function (): void {
    $buyer = Fixture::member();
    $order = Fixture::order($buyer, $buyer);
    expect(app(GrantReferralReward::class)->handle($order))->toBeNull()
        ->and(app(GrantReferralReward::class)->handle(999999))->toBeNull()
        ->and(DB::table('billing_journals')->where('kind', 'referral_reward')->count())->toBe(0);
});

it('excludes an explicit renewal even when its evidence looks like a completed purchase', function (): void {
    $buyer = Fixture::member();
    $referrer = Fixture::member();
    $source = Fixture::order($buyer);
    DB::table('sales_orders')->where('id', $source)->update(['status' => 'paid']);
    $order = Fixture::order($buyer, $referrer);
    DB::table('sales_renewal_orders')->insert([
        'sales_order_id' => $order, 'source_order_id' => $source,
        'provisioning_subscription_id' => DB::table('provisioning_order_links')->where('sales_order_id', $source)->value('provisioning_subscription_id'),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(app(GrantReferralReward::class)->handle($order))->toBeNull();
});

it('allows a first paid purchase after trial admission without rewarding the trial', function (): void {
    $referrer = Fixture::member();
    $buyer = Fixture::member();
    config()->set('trial.enabled', true);
    config()->set('trial.phone_hmac_key', str_repeat('test-key-', 5));
    config()->set('trial.daily_limit', 20);
    $telegram = 9800000 + $buyer->id;
    app(ReserveFreeTrial::class)->handle($buyer->id, $telegram, $telegram, '+989123456789');
    expect(DB::table('referral_rewards')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
    expect(app(GrantReferralReward::class)->handle(Fixture::order($buyer, $referrer)))->toBeInt();
});

it('keeps reward references protected against user and referral deletion', function (): void {
    $referrer = Fixture::member();
    $buyer = Fixture::member();
    $order = Fixture::order($buyer, $referrer);
    app(GrantReferralReward::class)->handle($order);
    expect(fn () => DB::table('referrals')->where('invited_user_id', $buyer->id)->delete())
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('users')->where('id', $referrer->id)->delete())
        ->toThrow(QueryException::class);
});

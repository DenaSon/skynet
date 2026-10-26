<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;

/** Synthetic persisted settlement/activation evidence; no real bank or provider calls. */
final class ReferralRewardFixture
{
    public static function member(): User
    {
        $user = User::factory()->create();
        $user->syncRoles(['member']);
        TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 9800000 + $user->id]);

        return $user;
    }

    public static function order(User $buyer, ?User $referrer = null): int
    {
        if ($referrer !== null && ! DB::table('referrals')->where('invited_user_id', $buyer->id)->exists()) {
            DB::table('referrals')->insert([
                'referrer_user_id' => $referrer->id, 'invited_user_id' => $buyer->id, 'created_at' => now(),
            ]);
        }
        $key = bin2hex(random_bytes(12));
        $product = DB::table('sales_products')->insertGetId(['slug' => $key, 'name' => 'Referral test']);
        $plan = DB::table('sales_plans')->insertGetId([
            'product_id' => $product, 'slug' => $key, 'name' => 'Referral test',
            'kind' => 'hybrid', 'mode' => 'preset', 'audience' => 'public', 'min_days' => 1, 'max_days' => 90,
        ]);
        $order = (int) DB::table('sales_orders')->insertGetId([
            'buyer_user_id' => $buyer->id, 'idempotency_key' => $key, 'plan_id' => $plan,
            'product_slug' => $key, 'product_name' => 'Referral test', 'duration_days' => 30, 'traffic_gb' => 10,
            'public_price_rials' => 650000, 'discount_percent' => 0, 'discount_rials' => 0,
            'total_price_rials' => 650000, 'status' => 'fulfilled', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('billing_wallets')->insertOrIgnore(['user_id' => $buyer->id]);
        $wallet = DB::table('billing_wallets')->where('user_id', $buyer->id)->value('id');
        $journal = DB::table('billing_journals')->insertGetId([
            'wallet_id' => $wallet, 'kind' => 'purchase', 'idempotency_key' => 'purchase:order:'.$order, 'created_at' => now(),
        ]);
        DB::table('billing_postings')->insert([
            ['journal_id' => $journal, 'wallet_id' => $wallet, 'account_code' => 'wallet_liability', 'side' => 'debit', 'amount_rials' => 650000],
            ['journal_id' => $journal, 'wallet_id' => null, 'account_code' => 'order_liability', 'side' => 'credit', 'amount_rials' => 650000],
        ]);
        DB::table('sales_order_settlements')->insert([
            'sales_order_id' => $order, 'billing_journal_id' => $journal, 'method' => 'customer_wallet',
            'amount_rials' => 650000, 'settled_at' => now(),
        ]);
        $sub = DB::table('provisioning_subscriptions')->insertGetId([
            'reference' => 'sales-order:'.$order, 'remote_username' => $key, 'remote_user_id' => 900000 + $order,
            'state' => 'active', 'last_operation_key' => 'sales-order:'.$order.':create',
        ]);
        DB::table('provisioning_operations')->insert([
            'subscription_id' => $sub, 'idempotency_key' => 'sales-order:'.$order.':create', 'kind' => 'create',
            'payload_hash' => str_repeat('a', 64), 'expected_payload' => '{}', 'state' => 'completed',
        ]);
        DB::table('provisioning_order_links')->insert([
            'sales_order_id' => $order, 'provisioning_subscription_id' => $sub, 'created_at' => now(),
        ]);
        DB::table('sales_fulfillment_outbox')->insert([
            'sales_order_id' => $order, 'event_key' => 'sales-order:'.$order.':create', 'state' => 'completed',
            'attempts' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $order;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Referral\Application;

use App\Modules\Billing\Application\CreditReferralReward;
use App\Modules\Identity\Application\AssertReferralMember;
use App\Modules\Sales\Application\ReadReferralPurchase;
use Illuminate\Support\Facades\DB;

/** REF-01 internal foundation. No route, listener, scheduler or job calls this yet. */
final class GrantReferralReward
{
    public function handle(int $orderId): ?int
    {
        $policy = app(ReferralRewardPolicy::class)->settings();

        return DB::transaction(function () use ($orderId, $policy): ?int {
            $buyerId = app(ReadReferralPurchase::class)->buyerId($orderId, $policy['start']);
            if ($buyerId === null) {
                return null;
            }
            $referral = DB::table('referrals')->where('invited_user_id', $buyerId)->first();
            if ($referral === null || (int) $referral->referrer_user_id === $buyerId) {
                return null;
            }
            $referrerId = (int) $referral->referrer_user_id;
            $ids = [$buyerId, $referrerId];
            sort($ids);
            foreach ($ids as $id) {
                app(AssertReferralMember::class)->handle($id);
            }
            // Locking reads are essential after waiting on MySQL REPEATABLE READ.
            $current = DB::table('referrals')->where('id', $referral->id)->lockForUpdate()->first();
            if ($current === null || (int) $current->referrer_user_id !== $referrerId
                || (int) $current->invited_user_id !== $buyerId) {
                return null;
            }
            $rewards = DB::table('referral_rewards')->where('referrer_user_id', $referrerId)
                ->orderBy('id')->lockForUpdate()->get();
            $existing = $rewards->firstWhere('invited_user_id', $buyerId);
            if ($existing !== null) {
                return (int) $existing->sales_order_id === $orderId ? (int) $existing->id : null;
            }
            if ($rewards->count() >= $policy['limit']) {
                return null;
            }
            $journalId = app(CreditReferralReward::class)->handle($referrerId, $buyerId, $policy['amount']);

            return (int) DB::table('referral_rewards')->insertGetId([
                'referral_id' => $referral->id, 'referrer_user_id' => $referrerId,
                'invited_user_id' => $buyerId, 'sales_order_id' => $orderId,
                'amount_rials' => $policy['amount'], 'billing_journal_id' => $journalId, 'created_at' => now(),
            ]);
        }, 3);
    }
}

<?php

namespace App\Modules\Referral\Application;

use App\Modules\Identity\Application\ResolveActiveUser;
use App\Modules\Referral\Infrastructure\Models\Referral;
use App\Modules\Referral\Infrastructure\Models\ReferralCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class GetReferralInvite
{
    public function handle(int $userId): ReferralInvite
    {
        app(ResolveActiveUser::class)->handle($userId);

        $code = DB::transaction(function () use ($userId): string {
            $existing = ReferralCode::query()->where('user_id', $userId)->first();

            if ($existing !== null) {
                return $existing->code;
            }

            // Lock the parent user to serialize two simultaneous code-generation requests.
            app(ResolveActiveUser::class)->handle($userId, lock: true);
            $existing = ReferralCode::query()->where('user_id', $userId)->first();

            if ($existing !== null) {
                return $existing->code;
            }

            $code = bin2hex(random_bytes(12));
            ReferralCode::query()->create(['user_id' => $userId, 'code' => $code]);

            return $code;
        });

        // Read only the caller's immutable earned awards. Never expose invitee data.
        $summary = Schema::hasTable('referral_rewards')
            ? DB::table('referral_rewards')->where('referrer_user_id', $userId)
                ->selectRaw('COUNT(*) as rewarded_count, COALESCE(SUM(amount_rials), 0) as earned_rials')
                ->first()
            : null;
        $rewarded = (int) ($summary->rewarded_count ?? 0);
        $earned = (int) ($summary->earned_rials ?? 0);
        $policy = null;
        try {
            $policy = app(ReferralRewardPolicy::class)->settings();
        } catch (Throwable) {
            // Rewards are disabled/misconfigured until a controlled rollout.
        }

        return new ReferralInvite(
            code: $code,
            joinedCount: Referral::query()->where('referrer_user_id', $userId)->count(),
            rewardedCount: $rewarded,
            earnedRials: $earned,
            remainingRewards: $policy === null ? null : max(0, $policy['limit'] - $rewarded),
            rewardAmountRials: $policy['amount'] ?? null,
        );
    }
}

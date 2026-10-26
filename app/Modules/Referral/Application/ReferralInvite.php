<?php

namespace App\Modules\Referral\Application;

final readonly class ReferralInvite
{
    public function __construct(
        public string $code,
        public int $joinedCount,
        public int $rewardedCount = 0,
        public int $earnedRials = 0,
        public ?int $remainingRewards = null,
        public ?int $rewardAmountRials = null,
    ) {}
}

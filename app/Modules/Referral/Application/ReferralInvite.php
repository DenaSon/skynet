<?php

namespace App\Modules\Referral\Application;

final readonly class ReferralInvite
{
    public function __construct(
        public string $code,
        public int $joinedCount,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\Referral\Application;

use Illuminate\Validation\ValidationException;

final class ReferralRewardPolicy
{
    /** @return array{amount:int,limit:int,start:string} */
    public function settings(): array
    {
        $amount = $this->integer(config('referral.reward_tomans'), 100_000_000_000);
        $limit = $this->integer(config('referral.max_rewards_per_referrer'), 1_000_000);
        $start = config('referral.rewards_start_at');
        $date = is_string($start) ? \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $start, new \DateTimeZone('UTC')) : false;
        if (config('referral.rewards_enabled') !== true || $amount === null || $limit === null
            || $date === false || $date->format('Y-m-d\TH:i:s\Z') !== $start) {
            throw ValidationException::withMessages(['referral' => 'Referral rewards are disabled or misconfigured.']);
        }

        return ['amount' => $amount * 10, 'limit' => $limit, 'start' => $date->format('Y-m-d H:i:s')];
    }

    private function integer(mixed $value, int $max): ?int
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/\A[1-9][0-9]{0,11}\z/D', (string) $value) !== 1) {
            return null;
        }
        $number = (int) $value;

        return $number <= $max ? $number : null;
    }
}

<?php

namespace App\Interfaces\Telegram\Support;

use Illuminate\Support\Facades\Cache;

final class BillingReviewTokens
{
    public function issue(int $operatorId, int $topupId, bool $approve, string $reference): string
    {
        $token = bin2hex(random_bytes(12));

        Cache::put($this->key($token), [
            'operator' => $operatorId,
            'topup' => $topupId,
            'approve' => $approve,
            'reference' => $reference,
        ], now()->addMinutes(3));

        return $token;
    }

    /**
     * @return array{operator:int,topup:int,approve:bool,reference:string}|null
     */
    public function consume(int $operatorId, string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{24}$/D', $token) !== 1) {
            return null;
        }

        $key = $this->key($token);

        return Cache::lock($key.':lock', 10)->block(2, function () use ($operatorId, $key): ?array {
            $data = Cache::get($key);

            if (! is_array($data) || ($data['operator'] ?? null) !== $operatorId) {
                return null;
            }

            Cache::forget($key);

            return $data;
        });
    }

    private function key(string $token): string
    {
        return 'telegram:billing:review:'.$token;
    }
}

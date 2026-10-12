<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use Illuminate\Support\Facades\Cache;

final class PaymentDecisionTokens
{
    public function issue(int $operatorId, int $claimId, bool $approve, ?string $reference, ?int $photoMessageId): string
    {
        $token = bin2hex(random_bytes(16));
        Cache::put('telegram:bankreview:'.$token, [
            'operator' => $operatorId,
            'claim' => $claimId,
            'approve' => $approve,
            'reference' => $reference,
            'photo_message_id' => $photoMessageId,
        ], now()->addMinutes(3));

        return $token;
    }

    /** @return array{operator:int,claim:int,approve:bool,reference:?string,photo_message_id:?int}|null */
    public function consume(int $operatorId, string $token): ?array
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1) {
            return null;
        }

        $key = 'telegram:bankreview:'.$token;

        return Cache::lock($key.':lock', 10)->block(2, function () use ($key, $operatorId): ?array {
            $value = Cache::get($key);

            if (! is_array($value) || ($value['operator'] ?? null) !== $operatorId) {
                return null;
            }

            Cache::forget($key);

            return $value;
        });
    }
}

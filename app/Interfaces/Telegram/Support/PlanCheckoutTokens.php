<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Sales\Application\PlanPriceQuote;
use Illuminate\Support\Facades\Cache;

/** Opaque, buyer-bound short-lived pointer, never a price lock. */
final class PlanCheckoutTokens
{
    public function issue(int $buyerId, PlanPriceQuote $quote): string
    {
        $token = bin2hex(random_bytes(16));
        Cache::put($this->key($token), $quote, now()->addMinutes(10));

        return $token;
    }

    public function peek(int $buyerId, string $token): ?PlanPriceQuote
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1) {
            return null;
        }

        $quote = Cache::get($this->key($token));

        return $quote instanceof PlanPriceQuote && $quote->buyerUserId === $buyerId ? $quote : null;
    }

    private function key(string $token): string
    {
        return TelegramBotContext::current()->cacheKey('telegram:plan:checkout:'.$token);
    }
}

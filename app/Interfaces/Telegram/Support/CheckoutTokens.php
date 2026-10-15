<?php

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;

use App\Modules\Sales\Application\PriceQuote;
use Illuminate\Support\Facades\Cache;

final class CheckoutTokens
{
    public function issue(int $buyerId, PriceQuote $quote): string
    {
        $token = bin2hex(random_bytes(16));

        Cache::put($this->key($token), [
            'buyer' => $buyerId,
            'slug' => $quote->productSlug,
            'name' => $quote->productName,
            'days' => $quote->durationDays,
            'gb' => $quote->trafficGb,
            'public' => $quote->publicPriceRials,
            'discount_percent' => $quote->discountPercent,
            'discount' => $quote->discountRials,
            'total' => $quote->totalPriceRials,
        ], now()->addMinutes(10));

        return $token;
    }

    public function peek(int $buyerId, string $token): ?PriceQuote
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
            return null;
        }

        $value = Cache::get($this->key($token));

        if (! is_array($value) || ($value['buyer'] ?? null) !== $buyerId) {
            return null;
        }

        return new PriceQuote(
            productSlug: (string) $value['slug'],
            productName: (string) $value['name'],
            durationDays: (int) $value['days'],
            trafficGb: (int) $value['gb'],
            publicPriceRials: (int) $value['public'],
            discountPercent: (int) $value['discount_percent'],
            discountRials: (int) $value['discount'],
            totalPriceRials: (int) $value['total'],
        );
    }

    private function key(string $token): string
    {
        return TelegramBotContext::current()->cacheKey('telegram:shop:checkout:'.$token);
    }
}

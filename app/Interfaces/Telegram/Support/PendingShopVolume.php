<?php

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use Illuminate\Support\Facades\Cache;

final class PendingShopVolume
{
    public function remember(int $buyerId, string $productSlug, int $durationDays): void
    {
        Cache::put($this->key($buyerId), [
            'product' => $productSlug,
            'days' => $durationDays,
        ], now()->addMinutes(10));
    }

    /**
     * @return array{product:string,days:int}|null
     */
    public function peek(int $buyerId): ?array
    {
        $value = Cache::get($this->key($buyerId));

        if (! is_array($value) || ! is_string($value['product'] ?? null) || ! is_int($value['days'] ?? null)) {
            return null;
        }

        return ['product' => $value['product'], 'days' => $value['days']];
    }

    public function forget(int $buyerId): void
    {
        Cache::forget($this->key($buyerId));
    }

    private function key(int $buyerId): string
    {
        return TelegramBotContext::current()->cacheKey('telegram:shop:volume:'.$buyerId);
    }
}

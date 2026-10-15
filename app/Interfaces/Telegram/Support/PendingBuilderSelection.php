<?php

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use Illuminate\Support\Facades\Cache;

final class PendingBuilderSelection
{
    public function remember(int $buyerId, int $planId, int $page): void
    {
        Cache::put($this->key($buyerId), ['plan_id' => $planId, 'page' => $page], now()->addMinutes(10));
    }

    /** @return array{plan_id:int,page:int}|null */
    public function peek(int $buyerId): ?array
    {
        $value = Cache::get($this->key($buyerId));

        if (! is_array($value) || ! is_int($value['plan_id'] ?? null)
            || ! is_int($value['page'] ?? null)) {
            return null;
        }

        return ['plan_id' => $value['plan_id'], 'page' => $value['page']];
    }

    public function forget(int $buyerId): void
    {
        Cache::forget($this->key($buyerId));
    }

    private function key(int $buyerId): string
    {
        return TelegramBotContext::current()->cacheKey('telegram:builder:selection:'.$buyerId);
    }
}

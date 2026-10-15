<?php

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;

use Illuminate\Support\Facades\Cache;

final class PendingReferral
{
    public function remember(int $telegramId, string $payload): void
    {
        if (preg_match('/^ref_([a-f0-9]{24})$/D', $payload, $matches) !== 1) {
            Cache::forget($this->key($telegramId));

            return;
        }

        Cache::put($this->key($telegramId), $matches[1], now()->addMinutes(15));
    }

    public function take(int $telegramId): ?string
    {
        $value = Cache::pull($this->key($telegramId));

        return is_string($value) && preg_match('/^[a-f0-9]{24}$/D', $value) === 1 ? $value : null;
    }

    private function key(int $telegramId): string
    {
        return TelegramBotContext::current()->cacheKey('telegram:signup:referral:'.$telegramId);
    }
}

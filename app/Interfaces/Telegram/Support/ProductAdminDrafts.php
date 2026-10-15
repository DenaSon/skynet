<?php

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;

use Illuminate\Support\Facades\Cache;

final class ProductAdminDrafts
{
    /** @param array{operation:string,id:int,days:int,input:string,stage:string,token:string,snapshot:string} $state */
    public function store(int $actorId, array $state, bool $preview = false): void
    {
        Cache::put($this->key($actorId), $state, now()->addMinutes($preview ? 3 : 10));
    }

    /** @return array{operation:string,id:int,days:int,input:string,stage:string,token:string,snapshot:string}|null */
    public function peek(int $actorId): ?array
    {
        $s = Cache::get($this->key($actorId));
        if (! is_array($s) || ! is_string($s['operation'] ?? null)
            || ! is_int($s['id'] ?? null) || ! is_int($s['days'] ?? null)
            || ! is_string($s['input'] ?? null) || ! is_string($s['stage'] ?? null)
            || ! is_string($s['token'] ?? null) || ! is_string($s['snapshot'] ?? null)) {
            return null;
        }

        return $s;
    }

    /** @return array{operation:string,id:int,days:int,input:string,stage:string,token:string,snapshot:string}|null */
    public function consume(int $actorId, string $token): ?array
    {
        return Cache::lock($this->key($actorId).':lock', 10)->block(2, function () use ($actorId, $token): ?array {
            $s = $this->peek($actorId);
            if ($s === null || $s['stage'] !== 'preview' || ! hash_equals($s['token'], $token)) {
                return null;
            }

            Cache::forget($this->key($actorId));

            return $s;
        });
    }

    public function forget(int $actorId): void
    {
        Cache::forget($this->key($actorId));
    }

    private function key(int $actorId): string
    {
        return TelegramBotContext::current()->cacheKey('telegram:productadmin:draft:'.$actorId);
    }
}

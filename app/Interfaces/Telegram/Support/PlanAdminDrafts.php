<?php

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;

use Illuminate\Support\Facades\Cache;

final class PlanAdminDrafts
{
    /**
     * @param  array{type:string,plan_id:int,plan_revision:int,price_revision:int,form:string,stage:string,token:string}  $data
     */
    public function store(int $actorId, array $data): void
    {
        Cache::put($this->key($actorId), $data, now()->addMinutes(10));
    }

    /** @return array{type:string,plan_id:int,plan_revision:int,price_revision:int,form:string,stage:string,token:string}|null */
    public function peek(int $actorId): ?array
    {
        $value = Cache::get($this->key($actorId));

        if (! is_array($value) || ! is_string($value['type'] ?? null)
            || ! is_int($value['plan_id'] ?? null) || ! is_int($value['plan_revision'] ?? null)
            || ! is_int($value['price_revision'] ?? null) || ! is_string($value['form'] ?? null)
            || ! is_string($value['stage'] ?? null) || ! is_string($value['token'] ?? null)) {
            return null;
        }

        return $value;
    }

    /**
     * A Redis/database cache lock prevents two concurrent webhook retries from
     * consuming the same draft and issuing duplicate writes.
     *
     * @return array{type:string,plan_id:int,plan_revision:int,price_revision:int,form:string,stage:string,token:string}|null
     */
    public function consume(int $actorId, string $token): ?array
    {
        return Cache::lock($this->key($actorId).':lock', 10)->block(2, function () use ($actorId, $token): ?array {
            $state = $this->peek($actorId);

            if ($state === null || $state['stage'] !== 'preview' || ! hash_equals($state['token'], $token)) {
                return null;
            }

            Cache::forget($this->key($actorId));

            return $state;
        });
    }

    public function forget(int $actorId): void
    {
        Cache::forget($this->key($actorId));
    }

    private function key(int $actorId): string
    {
        return TelegramBotContext::current()->cacheKey('telegram:planadmin:draft:'.$actorId);
    }
}

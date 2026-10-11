<?php

namespace App\Interfaces\Telegram\Support;

use Illuminate\Support\Facades\Cache;

final class RbacConfirmations
{
    public function issue(int $actorId, string $type, int $subject = 0, int $option = 0, bool $expected = false, int $page = 1, string $name = ''): string
    {
        $token = bin2hex(random_bytes(12));

        Cache::put($this->key($token), [
            'actor' => $actorId,
            'type' => $type,
            'subject' => $subject,
            'option' => $option,
            'expected' => $expected,
            'page' => $page,
            'name' => $name,
        ], now()->addMinutes(3));

        return $token;
    }

    /**
     * @return array{actor:int,type:string,subject:int,option:int,expected:bool,page:int,name:string}|null
     */
    public function consume(string $token, int $actorId): ?array
    {
        if (preg_match('/^[a-f0-9]{24}$/D', $token) !== 1) {
            return null;
        }

        $key = $this->key($token);

        return Cache::lock($key.':lock', 10)->block(2, function () use ($key, $actorId): ?array {
            $data = Cache::get($key);

            if (! is_array($data) || ($data['actor'] ?? null) !== $actorId) {
                return null;
            }

            Cache::forget($key);

            return $data;
        });
    }

    private function key(string $token): string
    {
        return 'tg:rbac:confirmation:'.$token;
    }
}

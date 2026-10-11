<?php

namespace App\Interfaces\Telegram\Support;

use Illuminate\Support\Facades\Cache;

final class UserStatusConfirmations
{
    public function issue(int $actorId, int $subjectId, bool $expectedActive, int $page, bool $search = false): string
    {
        $token = bin2hex(random_bytes(12));

        Cache::put($this->key($token), [
            'actor' => $actorId,
            'subject' => $subjectId,
            'expected' => $expectedActive,
            'page' => max(1, $page),
            'search' => $search,
        ], now()->addMinutes(3));

        return $token;
    }

    /**
     * @return array{actor:int, subject:int, expected:bool, page:int, search:bool}|null
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
        return 'tg:user-status:'.$token;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use Illuminate\Contracts\Session\Session;

final class TelegramSecondFactor
{
    private const SESSION_KEY = 'identity.telegram_second_factor';

    public function isVerifiedFor(Session $session, int $userId, string $telegramUserId): bool
    {
        $state = $session->get(self::SESSION_KEY);

        return is_array($state)
            && ($state['user_id'] ?? null) === $userId
            && ($state['telegram_user_id'] ?? null) === $telegramUserId
            && ($state['method'] ?? null) === 'telegram_oidc'
            && is_int($state['verified_at'] ?? null)
            && $state['verified_at'] <= now()->timestamp;
    }

    public function markVerifiedFor(Session $session, int $userId, string $telegramUserId): void
    {
        $session->put(self::SESSION_KEY, [
            'user_id' => $userId,
            'telegram_user_id' => $telegramUserId,
            'method' => 'telegram_oidc',
            'verified_at' => now()->timestamp,
        ]);
    }

    public function clear(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }
}

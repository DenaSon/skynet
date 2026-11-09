<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Processing;

use App\Modules\Identity\Application\ResolveTelegramActor;

final readonly class DenixGroupAccess
{
    public function __construct(private ResolveTelegramActor $actors) {}

    /** @phpstan-impure */
    public function allowed(int $chatId, int $telegramUserId): bool
    {
        if (config('denagate_ai.enabled') !== true || config('telegram.denix.enabled') !== true) {
            return false;
        }

        $token = config('telegram.denix.token');
        $secret = config('telegram.denix.webhook_secret');

        if (! is_string($token) || $token === ''
            || $token === config('nutgram.token') || $token === config('telegram.sales.token')
            || ! is_string($secret) || $secret === ''
            || $secret === config('telegram.webhook_secret')
            || $secret === config('telegram.sales.webhook_secret')) {
            return false;
        }

        $groupId = filter_var(config('telegram.denix.group_chat_id'), FILTER_VALIDATE_INT);

        if ($groupId === false || $groupId >= 0 || $groupId !== $chatId || $telegramUserId < 1) {
            return false;
        }

        // Existing linked, active, trusted Super Admin policy (including root allowlist).
        return $this->actors->handle($telegramUserId)?->canManageRoles() === true;
    }
}

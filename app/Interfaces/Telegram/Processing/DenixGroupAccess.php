<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Processing;

use App\Modules\Identity\Application\ResolveTelegramActor;

final readonly class DenixGroupAccess
{
    public function __construct(private ResolveTelegramActor $actors) {}

    public function allowed(int $chatId, int $telegramUserId): bool
    {
        if (config('denagate_ai.enabled') !== true || config('telegram.denix.enabled') !== true) {
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

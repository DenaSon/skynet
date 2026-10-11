<?php

namespace App\Interfaces\Telegram\Handlers;

use App\Interfaces\Telegram\Support\MenuRenderer;
use App\Interfaces\Telegram\Support\PrivateTelegramUser;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Referral\Application\GetReferralInvite;
use SergiX44\Nutgram\Nutgram;

final class InviteHandler
{
    public function __construct(
        private readonly PrivateTelegramUser $privateUser,
        private readonly ResolveTelegramActor $resolve,
        private readonly GetReferralInvite $referrals,
        private readonly MenuRenderer $menus,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $id = $this->privateUser->id($bot);

        if ($id === null) {
            return;
        }

        $actor = $this->resolve->handle($id);

        if ($actor === null) {
            $this->menus->showHome($bot, null);

            return;
        }

        $username = config('telegram.bot_username');

        if (! is_string($username) || preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/D', $username) !== 1) {
            $this->menus->showInviteUnavailable($bot);

            return;
        }

        $invite = $this->referrals->handle($actor->userId);
        $this->menus->showInvite($bot, "https://t.me/{$username}?start=ref_{$invite->code}", $invite->joinedCount);
    }
}

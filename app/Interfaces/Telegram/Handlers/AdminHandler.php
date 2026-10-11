<?php

namespace App\Interfaces\Telegram\Handlers;

use App\Interfaces\Telegram\Support\MenuRenderer;
use App\Interfaces\Telegram\Support\PrivateTelegramUser;
use App\Modules\Identity\Application\ResolveTelegramActor;
use SergiX44\Nutgram\Nutgram;

final class AdminHandler
{
    public function __construct(
        private readonly PrivateTelegramUser $privateUser,
        private readonly ResolveTelegramActor $resolve,
        private readonly MenuRenderer $menus,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $id = $this->privateUser->id($bot);

        if ($id === null) {
            return;
        }

        $actor = $this->resolve->handle($id);

        if ($actor?->isAdmin() !== true) {
            $this->menus->denyAccess($bot);

            return;
        }

        $this->menus->showAdmin($bot, $actor);
    }
}

<?php

namespace App\Interfaces\Telegram\Handlers;

use App\Interfaces\Telegram\Support\MenuRenderer;
use App\Interfaces\Telegram\Support\PrivateTelegramUser;
use App\Modules\Identity\Application\ResolveTelegramActor;
use SergiX44\Nutgram\Nutgram;

final class AdminRolesHandler
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

        if ($this->resolve->handle($id)?->canManageRoles() !== true) {
            $this->menus->denyAccess($bot);

            return;
        }

        $this->menus->showAdminRoles($bot);
    }
}

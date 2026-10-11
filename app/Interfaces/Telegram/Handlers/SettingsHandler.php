<?php

namespace App\Interfaces\Telegram\Handlers;

use App\Interfaces\Telegram\Support\MenuRenderer;
use App\Interfaces\Telegram\Support\PrivateTelegramUser;
use App\Modules\Identity\Application\ResolveTelegramActor;
use SergiX44\Nutgram\Nutgram;

final class SettingsHandler
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

        if ($this->resolve->handle($id) === null) {
            $this->menus->showHome($bot, null);

            return;
        }

        $this->menus->showSettings($bot);
    }
}

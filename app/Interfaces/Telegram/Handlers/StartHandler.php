<?php

namespace App\Interfaces\Telegram\Handlers;

use App\Interfaces\Telegram\Support\MenuRenderer;
use App\Interfaces\Telegram\Support\PendingReferral;
use App\Interfaces\Telegram\Support\PrivateTelegramUser;
use App\Modules\Identity\Application\ResolveTelegramActor;
use SergiX44\Nutgram\Nutgram;

final class StartHandler
{
    public function __construct(
        private readonly PrivateTelegramUser $privateUser,
        private readonly ResolveTelegramActor $resolve,
        private readonly MenuRenderer $menus,
        private readonly PendingReferral $pending,
    ) {}

    public function __invoke(Nutgram $bot, ?string $payload = null): void
    {
        $id = $this->privateUser->id($bot);

        if ($id === null) {
            return;
        }

        $actor = $this->resolve->handle($id);

        if ($actor === null && ! $bot->isCallbackQuery()) {
            // A direct /start clears an older invitation; only the latest explicit link matters.
            $this->pending->remember($id, $payload ?? '');
        }

        $this->menus->showHome($bot, $actor);
    }
}

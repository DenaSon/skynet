<?php

namespace App\Interfaces\Telegram\Handlers;

use App\Interfaces\Telegram\Support\MenuRenderer;
use App\Interfaces\Telegram\Support\PendingBuilderSelection;
use App\Interfaces\Telegram\Support\PendingReferral;
use App\Interfaces\Telegram\Support\PrivateTelegramUser;
use App\Interfaces\Telegram\Support\ProductAdminDrafts;
use App\Interfaces\Telegram\Support\ReplyMenuRenderer;
use App\Modules\Identity\Application\ResolveTelegramActor;
use SergiX44\Nutgram\Nutgram;

final class StartHandler
{
    public function __construct(
        private readonly PrivateTelegramUser $privateUser,
        private readonly ResolveTelegramActor $resolve,
        private readonly MenuRenderer $menus,
        private readonly PendingReferral $pending,
        private readonly ReplyMenuRenderer $replyMenus,
        private readonly PendingBuilderSelection $pendingBuilder,
        private readonly ProductAdminDrafts $productDrafts,
    ) {}

    public function __invoke(Nutgram $bot, ?string $payload = null): void
    {
        $id = $this->privateUser->id($bot);

        if ($id === null) {
            return;
        }

        $actor = $this->resolve->handle($id);
        if ($actor !== null) {
            $this->pendingBuilder->forget($actor->userId);
            $this->productDrafts->forget($actor->userId);
        }

        if ($actor === null && ! $bot->isCallbackQuery()) {
            // A direct /start clears an older invitation; only the latest explicit link matters.
            $this->pending->remember($id, $payload ?? '');
        }

        if (! $bot->isCallbackQuery() && config('telegram.hybrid_menu_enabled', false) === true) {
            if ($actor === null) {
                $this->replyMenus->showUnauthenticated($bot);
            } else {
                $this->replyMenus->showHome($bot, $actor);
            }

            return;
        }

        $this->menus->showHome($bot, $actor);
    }
}

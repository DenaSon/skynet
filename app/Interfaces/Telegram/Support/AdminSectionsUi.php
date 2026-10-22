<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Billing\Application\ReadAdminWalletSummary;
use App\Modules\Identity\Application\ResolveTelegramActor;
use Illuminate\Auth\Access\AuthorizationException;
use App\Modules\Identity\Application\TelegramActor;
use SergiX44\Nutgram\Nutgram;

final readonly class AdminSectionsUi
{
    public function __construct(
        private PrivateTelegramUser $privateUser,
        private ResolveTelegramActor $resolve,
        private ReadAdminWalletSummary $walletSummary,
        private MenuRenderer $menus,
    ) {}

    public function sales(Nutgram $bot): void
    {
        if ($this->actor($bot) !== null) {
            $this->menus->showAdminSales($bot);
        }
    }

    public function finance(Nutgram $bot): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        try {
            $summary = $this->walletSummary->handle($actor->userId);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (\Throwable) {
            // Preserve existing finance navigation when a read fails.
            $summary = null;
        }

        $this->menus->showAdminFinance($bot, $summary);
    }

    private function actor(Nutgram $bot): ?TelegramActor
    {
        $telegramId = $this->privateUser->id($bot);
        if ($telegramId === null) {
            return null;
        }

        $actor = $this->resolve->handle($telegramId);
        if ($actor?->canManageRoles() !== true) {
            $this->menus->denyAccess($bot);

            return null;
        }

        return $actor;
    }
}

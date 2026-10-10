<?php

namespace App\Interfaces\Telegram\Handlers;

use App\Interfaces\Telegram\Support\MenuRenderer;
use App\Interfaces\Telegram\Support\PrivateTelegramUser;
use App\Modules\Identity\Application\LinkTelegramAccount;
use App\Modules\Identity\Application\ResolveTelegramActor;
use SergiX44\Nutgram\Nutgram;

final class LinkHandler
{
    public function __construct(
        private readonly PrivateTelegramUser $privateUser,
        private readonly LinkTelegramAccount $link,
        private readonly ResolveTelegramActor $resolve,
        private readonly MenuRenderer $menus,
    ) {}

    public function __invoke(Nutgram $bot, string $code): void
    {
        $id = $this->privateUser->id($bot);

        if ($id === null) {
            return;
        }

        if (! $this->link->handle($code, $id)) {
            $bot->sendMessage('کد اتصال نامعتبر، منقضی یا قبلاً مصرف شده است.');

            return;
        }

        $bot->sendMessage('✅ اتصال حساب شما با موفقیت انجام شد.');
        $this->menus->showHome($bot, $this->resolve->handle($id));
    }
}

<?php

namespace App\Interfaces\Telegram\Handlers;

use App\Interfaces\Telegram\Support\MenuRenderer;
use App\Interfaces\Telegram\Support\PendingReferral;
use App\Interfaces\Telegram\Support\PrivateTelegramUser;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Onboarding\Application\JoinFromTelegram;
use SergiX44\Nutgram\Nutgram;

final class JoinHandler
{
    public function __construct(
        private readonly PrivateTelegramUser $privateUser,
        private readonly PendingReferral $pending,
        private readonly JoinFromTelegram $onboard,
        private readonly ResolveTelegramActor $resolve,
        private readonly MenuRenderer $menus,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $id = $this->privateUser->id($bot);

        if ($id === null) {
            return;
        }

        if (! config('telegram.public_signup_enabled', false)) {
            $this->menus->showSignupNotice($bot, 'ثبت‌نام عمومی در حال حاضر غیرفعال است.');

            return;
        }

        $actor = $this->resolve->handle($id);

        if ($actor !== null) {
            $this->menus->showHome($bot, $actor);

            return;
        }

        $this->onboard->handle(
            $id,
            (string) ($bot->user()->first_name ?? 'Telegram member'),
            $this->pending->take($id),
        );

        $actor = $this->resolve->handle($id);

        if ($actor === null) {
            $this->menus->showSignupNotice($bot, 'حساب شما غیرفعال است. برای پیگیری با مدیر تماس بگیرید.');

            return;
        }

        $this->menus->showHome($bot, $actor);
    }
}

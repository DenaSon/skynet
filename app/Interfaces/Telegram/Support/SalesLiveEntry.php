<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\WebApp\WebAppInfo;

/** Opt-in, private Sales-only Mini App launch; no business writes. */
final readonly class SalesLiveEntry
{
    public function __construct(
        private PrivateTelegramUser $privateUser,
        private ResolveTelegramActor $actors,
    ) {}

    public function show(Nutgram $bot): void
    {
        $telegramId = $this->privateUser->id($bot);
        if ($telegramId === null) {
            return;
        }

        if (config('denagate_live.enabled') !== true) {
            $bot->sendMessage('پنل وب نمایندگی فعلاً در دسترس نیست.');

            return;
        }

        $actor = $this->actors->handle($telegramId);
        if ($actor === null || ! in_array('reseller', $actor->roles, true)) {
            $bot->sendMessage('این بخش مخصوص نمایندگان فعال است.');

            return;
        }

        $bot->sendMessage(
            text: '🚀 پنل نمایندگی دناگیت را از دکمه زیر باز کنید.',
            reply_markup: InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('ورود به پنل', web_app: WebAppInfo::make((string) config('denagate_live.url'))),
            ),
        );
    }
}

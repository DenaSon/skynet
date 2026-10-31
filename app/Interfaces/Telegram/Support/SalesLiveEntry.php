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

        // A callback must be acknowledged even when the Live gate or role was
        // revoked since the reseller opened the original inline keyboard.
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
        }

        if (config('denagate_live.enabled') !== true) {
            $bot->sendMessage('اپلیکیشن دناگیت فعلاً در دسترس نیست.');

            return;
        }

        $actor = $this->actors->handle($telegramId);
        if ($actor === null || ! in_array('reseller', $actor->roles, true)) {
            $bot->sendMessage('این بخش مخصوص نمایندگان فعال است.');

            return;
        }

        $bot->sendMessage(
            text: '📱 اپلیکیشن دناگیت را باز کنید.',
            reply_markup: InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make(ReplyMenuLabels::SALES_APP, web_app: WebAppInfo::make((string) config('denagate_live.url'))),
            ),
        );
    }
}

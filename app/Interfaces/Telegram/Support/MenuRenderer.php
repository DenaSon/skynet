<?php

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\TelegramActor;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class MenuRenderer
{
    public function showHome(Nutgram $bot, ?TelegramActor $actor): void
    {
        if ($actor === null) {
            $this->render($bot, '🔒 حساب شما به SkyNet متصل نیست. برای اتصال، کد یک‌بارمصرف صادرشده توسط مدیر را در گفت‌وگوی خصوصی با دستور /link وارد کنید.');

            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('👤 حساب من', callback_data: 'menu:account'));

        if ($actor->isAdmin()) {
            $keyboard->addRow(InlineKeyboardButton::make('🛡️ مدیریت', callback_data: 'menu:admin'));
        }

        $this->render($bot, "🏠 SkyNet\n\nخوش آمدید، {$actor->name}.", $keyboard);
    }

    public function showAccount(Nutgram $bot, TelegramActor $actor): void
    {
        $role = $actor->isAdmin() ? 'مدیر' : 'کاربر';
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'menu:home'));

        $this->render(
            $bot,
            "👤 حساب من\n\nنام: {$actor->name}\nشناسه SkyNet: {$actor->userId}\nنقش: {$role}",
            $keyboard,
        );
    }

    public function showAdmin(Nutgram $bot): void
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'menu:home'));

        $this->render($bot, "🛡️ مدیریت SkyNet\n\nرابط تلگرام متصل است. عملیات مدیریتی در فازهای بعدی فعال خواهند شد.", $keyboard);
    }

    private function render(Nutgram $bot, string $text, ?InlineKeyboardMarkup $keyboard = null): void
    {
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
            $bot->editMessageText(text: $text, reply_markup: $keyboard);

            return;
        }

        $bot->sendMessage(text: $text, reply_markup: $keyboard);
    }
}

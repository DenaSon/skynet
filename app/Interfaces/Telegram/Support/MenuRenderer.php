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
            ->addRow(InlineKeyboardButton::make('👤 حساب من', callback_data: 'menu:account'))
            ->addRow(InlineKeyboardButton::make('⚙️ تنظیمات', callback_data: 'menu:settings'));

        if ($actor->isAdmin()) {
            $keyboard->addRow(InlineKeyboardButton::make('🛡️ مدیریت', callback_data: 'menu:admin'));
        }

        $name = $this->displayName($actor->name);

        $this->render($bot, "🏠 SkyNet\n\nخوش آمدید، {$name}.", $keyboard);
    }

    public function showAccount(Nutgram $bot, TelegramActor $actor): void
    {
        $name = $this->displayName($actor->name);
        $roles = implode('، ', $actor->roles);

        $this->render(
            $bot,
            "👤 حساب من\n\nنام: {$name}\nشناسه SkyNet: {$actor->userId}\nنقش‌ها: {$roles}\nوضعیت: 🟢 فعال",
            $this->homeKeyboard(),
        );
    }

    public function showSettings(Nutgram $bot): void
    {
        $this->render(
            $bot,
            "⚙️ تنظیمات\n\nدر حال حاضر تنظیمات قابل تغییری وجود ندارد. گزینه‌های اعلان و زبان پس از تعریف نیازهای واقعی اضافه می‌شوند.",
            $this->homeKeyboard(),
        );
    }

    public function showAdmin(Nutgram $bot, TelegramActor $actor): void
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('👥 کاربران', callback_data: 'menu:admin:users'));

        if ($actor->canManageRoles()) {
            $keyboard->addRow(InlineKeyboardButton::make('🔐 نقش‌ها و دسترسی‌ها', callback_data: 'menu:admin:roles'));
        }

        $keyboard->addRow(InlineKeyboardButton::make('◀️ صفحه اصلی', callback_data: 'menu:home'));

        $this->render($bot, "🛡️ مدیریت SkyNet\n\nبخش‌های مدیریتی در حال آماده‌سازی هستند.", $keyboard);
    }

    public function showAdminUsers(Nutgram $bot): void
    {
        $this->render(
            $bot,
            "👥 مدیریت کاربران\n\nفهرست، جست‌وجو و عملیات کاربران در F06-B فعال می‌شوند.",
            $this->adminBackKeyboard(),
        );
    }

    public function showAdminRoles(Nutgram $bot): void
    {
        $this->render(
            $bot,
            "🔐 نقش‌ها و دسترسی‌ها\n\nمدیریت نقش‌ها و مجوزها در F06-C فعال می‌شود.",
            $this->adminBackKeyboard(),
        );
    }

    public function denyAccess(Nutgram $bot): void
    {
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery(text: 'دسترسی مجاز نیست.');

            return;
        }

        $bot->sendMessage('دسترسی مجاز نیست.');
    }

    private function homeKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ صفحه اصلی', callback_data: 'menu:home'));
    }

    private function adminBackKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'))
            ->addRow(InlineKeyboardButton::make('🏠 صفحه اصلی', callback_data: 'menu:home'));
    }

    private function displayName(string $name): string
    {
        // Plain-text messages; normalize newlines to keep user-controlled names from
        // spoofing the menu's system-authored labels.
        return trim((string) preg_replace('/[\r\n\x00-\x1F\x7F]+/u', ' ', $name));
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

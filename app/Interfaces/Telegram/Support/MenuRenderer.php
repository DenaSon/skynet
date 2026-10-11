<?php

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\TelegramActor;
use App\Modules\Identity\Application\UserDirectoryEntry;
use App\Modules\Identity\Application\UserDirectoryPage;
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

        $this->render($bot, "🏠 SkyNet\n\nخوش آمدید، {$this->displayName($actor->name)}.", $keyboard);
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

    public function showUserList(Nutgram $bot, UserDirectoryPage $results, bool $search = false): void
    {
        $mode = $search ? 's' : 'a';
        $header = $search ? '🔎 نتایج جست‌وجوی کاربران' : '👥 مدیریت کاربران';
        $lines = [$header, '', "تعداد: {$results->total} | صفحه {$results->page} از {$results->lastPage}", ''];

        $keyboard = InlineKeyboardMarkup::make();

        foreach ($results->users as $user) {
            $status = $user->active ? '🟢' : '🔴';
            $name = mb_substr($this->displayName($user->name), 0, 35);
            $lines[] = "{$status} #{$user->id} — {$name}";
            $keyboard->addRow(InlineKeyboardButton::make(
                "#{$user->id} | {$name}",
                callback_data: "users:view:{$user->id}:{$results->page}:{$mode}",
            ));
        }

        if ($results->users === []) {
            $lines[] = 'کاربری پیدا نشد.';
        }

        $previous = $results->page > 1 ? InlineKeyboardButton::make('◀️ قبلی', callback_data: $this->pageCallback($results->page - 1, $search)) : null;
        $next = $results->page < $results->lastPage ? InlineKeyboardButton::make('بعدی ▶️', callback_data: $this->pageCallback($results->page + 1, $search)) : null;

        if ($previous !== null && $next !== null) {
            $keyboard->addRow($previous, $next);
        } elseif ($previous !== null) {
            $keyboard->addRow($previous);
        } elseif ($next !== null) {
            $keyboard->addRow($next);
        }

        $keyboard->addRow(InlineKeyboardButton::make('🔎 جست‌وجو', callback_data: 'users:search'));
        $keyboard->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'));

        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    public function showUserDetails(Nutgram $bot, UserDirectoryEntry $user, int $page = 1, bool $search = false): void
    {
        $name = $this->displayName($user->name);
        $email = $this->displayName($user->email);
        $roles = implode('، ', $user->roles);
        $status = $user->active ? '🟢 فعال' : '🔴 غیرفعال';
        $linked = $user->telegramLinked ? 'متصل' : 'متصل نیست';
        $mode = $search ? 's' : 'a';

        $keyboard = InlineKeyboardMarkup::make();

        if ($user->canChangeStatus) {
            $label = $user->active ? '⛔ غیرفعال‌کردن' : '✅ فعال‌کردن';
            $keyboard->addRow(InlineKeyboardButton::make($label, callback_data: "users:confirm:{$user->id}:{$page}:{$mode}"));
        }

        $keyboard->addRow(InlineKeyboardButton::make('◀️ بازگشت به فهرست', callback_data: $this->pageCallback($page, $search)));
        $keyboard->addRow(InlineKeyboardButton::make('🏠 مدیریت', callback_data: 'menu:admin'));

        $this->render(
            $bot,
            "👤 جزئیات کاربر\n\nشناسه: {$user->id}\nنام: {$name}\nایمیل: {$email}\nنقش‌ها: {$roles}\nوضعیت: {$status}\nتلگرام: {$linked}",
            $keyboard,
        );
    }

    public function showUserStatusConfirmation(Nutgram $bot, UserDirectoryEntry $user, string $token, int $page, bool $search = false): void
    {
        $action = $user->active ? 'غیرفعال' : 'فعال';
        $mode = $search ? 's' : 'a';
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('✅ تأیید عملیات', callback_data: 'users:apply:'.$token))
            ->addRow(InlineKeyboardButton::make('انصراف', callback_data: "users:view:{$user->id}:{$page}:{$mode}"));

        $this->render(
            $bot,
            "⚠️ تأیید تغییر وضعیت\n\nآیا حساب #{$user->id} ({$this->displayName($user->name)}) {$action} شود؟\n\nاین تأیید فقط ۳ دقیقه اعتبار دارد.",
            $keyboard,
        );
    }

    public function showUserSearchHelp(Nutgram $bot): void
    {
        $this->render(
            $bot,
            "🔎 جست‌وجوی کاربران\n\nبرای جست‌وجو با نام، ایمیل یا شناسه داخلی، در همین گفت‌وگوی خصوصی بنویسید:\n/finduser ali\n\nعبارت جست‌وجو یک کلمه و حداکثر ۶۴ کاراکتر باشد.",
            $this->userBackKeyboard(),
        );
    }

    public function showUserNotice(Nutgram $bot, string $message): void
    {
        $this->render($bot, "👥 مدیریت کاربران\n\n{$message}", $this->userBackKeyboard());
    }

    public function showAdminRoles(Nutgram $bot): void
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'))
            ->addRow(InlineKeyboardButton::make('🏠 صفحه اصلی', callback_data: 'menu:home'));

        $this->render($bot, "🔐 نقش‌ها و دسترسی‌ها\n\nمدیریت نقش‌ها و مجوزها در F06-C فعال می‌شود.", $keyboard);
    }

    public function denyAccess(Nutgram $bot): void
    {
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery(text: 'دسترسی مجاز نیست.');

            return;
        }

        $bot->sendMessage('دسترسی مجاز نیست.');
    }

    private function pageCallback(int $page, bool $search): string
    {
        return ($search ? 'users:findpage:' : 'users:page:').$page;
    }

    private function userBackKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ کاربران', callback_data: 'menu:admin:users'))
            ->addRow(InlineKeyboardButton::make('🏠 مدیریت', callback_data: 'menu:admin'));
    }

    private function homeKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ صفحه اصلی', callback_data: 'menu:home'));
    }

    private function displayName(string $name): string
    {
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

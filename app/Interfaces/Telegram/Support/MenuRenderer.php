<?php

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\PermissionEntry;
use App\Modules\Identity\Application\RoleDirectoryPage;
use App\Modules\Identity\Application\RoleEntry;
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
            if (config('telegram.public_signup_enabled', false)) {
                $keyboard = InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('✅ ایجاد حساب رایگان', callback_data: 'onboarding:join'));

                $this->render($bot, "👋 خوش آمدید به SkyNet\n\nبرای ایجاد حساب عادی، دکمه زیر را انتخاب کنید.\nاگر قبلاً توسط مدیر حسابی ساخته شده است، به‌جای ثبت‌نام با دستور /link کد اتصال آن را وارد کنید.", $keyboard);

                return;
            }

            $this->render($bot, '🔒 حساب شما به SkyNet متصل نیست. برای اتصال، کد یک‌بارمصرف صادرشده توسط مدیر را در گفت‌وگوی خصوصی با دستور /link وارد کنید.');

            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('👤 حساب من', callback_data: 'menu:account'))
            ->addRow(InlineKeyboardButton::make('⚙️ تنظیمات', callback_data: 'menu:settings'))
            ->addRow(InlineKeyboardButton::make('🎁 دعوت دوستان', callback_data: 'menu:invite'));

        if (in_array('reseller', $actor->roles, true)) {
            $keyboard->addRow(InlineKeyboardButton::make('💼 کیف پول نماینده', callback_data: 'menu:wallet'));
        }

        if ($actor->isAdmin()) {
            $keyboard->addRow(InlineKeyboardButton::make('🛡️ مدیریت', callback_data: 'menu:admin'));
        }

        $this->render($bot, "🏠 SkyNet\n\nخوش آمدید، {$this->displayName($actor->name)}.", $keyboard);
    }

    public function showInvite(Nutgram $bot, string $url, int $invitedCount): void
    {
        $this->render(
            $bot,
            "🎁 دعوت دوستان\n\nلینک اختصاصی شما:\n{$url}\n\n👥 دعوت‌های ثبت‌شده: {$invitedCount}\n\nدر این مرحله پاداش یا اعتبار مالی برای دعوت تعریف نشده است.",
            $this->homeKeyboard(),
        );
    }

    public function showInviteUnavailable(Nutgram $bot): void
    {
        $this->render($bot, 'امکان ساخت لینک دعوت هنوز تنظیم نشده است. مدیر باید TELEGRAM_BOT_USERNAME را تنظیم کند.', $this->homeKeyboard());
    }

    public function showSignupNotice(Nutgram $bot, string $message): void
    {
        $this->render($bot, "👤 ثبت‌نام\n\n{$message}", $this->homeKeyboard());
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
            $keyboard->addRow(InlineKeyboardButton::make('💳 بررسی شارژها', callback_data: 'menu:billing-reviews'));
        }

        $keyboard->addRow(InlineKeyboardButton::make('◀️ صفحه اصلی', callback_data: 'menu:home'));

        $this->render($bot, "🛡️ مدیریت SkyNet\n\nمدیریت کاربران، نقش‌ها و مجوزها فعال است.", $keyboard);
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

    public function showUserDetails(Nutgram $bot, UserDirectoryEntry $user, int $page = 1, bool $search = false, bool $manageRoles = false): void
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

        if ($manageRoles && ! in_array('super-admin', $user->roles, true)) {
            $keyboard->addRow(InlineKeyboardButton::make('🔐 مدیریت نقش‌های کاربر', callback_data: "rbac:user:{$user->id}:1"));
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

    public function showRolesList(Nutgram $bot, RoleDirectoryPage $page): void
    {
        $text = "🔐 نقش‌ها و دسترسی‌ها\n\nتعداد: {$page->total} | صفحه {$page->page} از {$page->lastPage}\n";
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($page->roles as $role) {
            $mark = $role->protected ? '🔒' : '🧩';
            $text .= "\n{$mark} {$role->name} — {$role->userCount} کاربر";
            $keyboard->addRow(InlineKeyboardButton::make(
                "{$mark} {$role->name}",
                callback_data: "rbac:role:{$role->id}:{$page->page}",
            ));
        }

        if ($page->roles === []) {
            $text .= "\nنقشی پیدا نشد.";
        }

        $keyboard->addRow(InlineKeyboardButton::make('➕ نقش جدید', callback_data: 'rbac:new'));

        if ($page->page > 1) {
            $keyboard->addRow(InlineKeyboardButton::make('◀️ قبلی', callback_data: 'rbac:page:'.($page->page - 1)));
        }

        if ($page->page < $page->lastPage) {
            $keyboard->addRow(InlineKeyboardButton::make('بعدی ▶️', callback_data: 'rbac:page:'.($page->page + 1)));
        }

        $keyboard->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'));
        $this->render($bot, $text, $keyboard);
    }

    /**
     * @param  list<PermissionEntry>  $available
     */
    public function showRoleDetails(Nutgram $bot, RoleEntry $role, array $available, int $page, int $permissionPage = 1): void
    {
        $assigned = $role->permissions === [] ? 'هیچ مجوزی' : implode('، ', $role->permissions);
        $text = "🔐 نقش: {$role->name}\n\nکاربران: {$role->userCount}\nمجوزها: {$assigned}";
        $keyboard = InlineKeyboardMarkup::make();

        if ($role->protected) {
            $text .= "\n\n🔒 نقش سیستمی محافظت‌شده است و از ربات قابل تغییر نیست.";
        } else {
            $permissionPages = max(1, (int) ceil(count($available) / 8));
            $permissionPage = max(1, min($permissionPage, $permissionPages));
            $text .= "\nصفحه مجوزها: {$permissionPage} از {$permissionPages}";

            foreach (array_slice($available, ($permissionPage - 1) * 8, 8) as $permission) {
                $on = in_array($permission->name, $role->permissions, true);
                $keyboard->addRow(InlineKeyboardButton::make(
                    ($on ? '✅ ' : '⬜ ').$permission->name,
                    callback_data: "rbac:perm:{$role->id}:{$permission->id}",
                ));
            }

            if ($permissionPage > 1) {
                $keyboard->addRow(InlineKeyboardButton::make('◀️ مجوزهای قبلی', callback_data: "rbac:p:{$role->id}:{$page}:".($permissionPage - 1)));
            }

            if ($permissionPage < $permissionPages) {
                $keyboard->addRow(InlineKeyboardButton::make('مجوزهای بعدی ▶️', callback_data: "rbac:p:{$role->id}:{$page}:".($permissionPage + 1)));
            }
        }

        $keyboard->addRow(InlineKeyboardButton::make('◀️ همه نقش‌ها', callback_data: "rbac:page:{$page}"));
        $this->render($bot, $text, $keyboard);
    }

    public function showUserRoles(Nutgram $bot, UserDirectoryEntry $user, RoleDirectoryPage $page): void
    {
        $text = "🔐 نقش‌های کاربر #{$user->id}\n\n{$this->displayName($user->name)}\nنقش‌های فعلی: ".implode('، ', $user->roles);
        $keyboard = InlineKeyboardMarkup::make();
        $protected = in_array('super-admin', $user->roles, true);

        foreach ($page->roles as $role) {
            if ($protected || $role->name === 'super-admin') {
                continue;
            }

            $on = in_array($role->name, $user->roles, true);
            $keyboard->addRow(InlineKeyboardButton::make(
                ($on ? '✅ ' : '⬜ ').$role->name,
                callback_data: "rbac:member:{$user->id}:{$role->id}:{$page->page}",
            ));
        }

        if ($protected) {
            $text .= "\n\n🔒 حساب Super Admin محافظت شده است.";
        }

        if ($page->page > 1) {
            $keyboard->addRow(InlineKeyboardButton::make('◀️ قبلی', callback_data: "rbac:user:{$user->id}:".($page->page - 1)));
        }

        if ($page->page < $page->lastPage) {
            $keyboard->addRow(InlineKeyboardButton::make('بعدی ▶️', callback_data: "rbac:user:{$user->id}:".($page->page + 1)));
        }

        $keyboard->addRow(InlineKeyboardButton::make('◀️ صفحه کاربر', callback_data: "users:view:{$user->id}:1:a"));
        $this->render($bot, $text, $keyboard);
    }

    public function showRoleCreateHelp(Nutgram $bot): void
    {
        $this->render(
            $bot,
            "➕ ایجاد نقش سفارشی\n\nدر گفت‌وگوی خصوصی ارسال کنید:\n/newrole reseller\n\nنام نقش فقط از حروف انگلیسی کوچک، اعداد و خط تیره (۳ تا ۵۰ نویسه) تشکیل می‌شود.\n\nپس از تأیید، نقش بدون مجوز ایجاد خواهد شد؛ مجوزها را از صفحه نقش تخصیص دهید.",
            $this->rbacBackKeyboard(),
        );
    }

    public function showRbacConfirmation(Nutgram $bot, string $operation, string $token): void
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('✅ تأیید عملیات', callback_data: 'rbac:apply:'.$token))
            ->addRow(InlineKeyboardButton::make('انصراف', callback_data: 'menu:admin:roles'));

        $this->render($bot, "⚠️ تأیید عملیات نقش و دسترسی\n\n{$operation}\n\nاین تأیید یک‌بارمصرف و به‌مدت ۳ دقیقه معتبر است.", $keyboard);
    }

    public function showRoleNotice(Nutgram $bot, string $message): void
    {
        $this->render($bot, "🔐 مدیریت نقش‌ها\n\n{$message}", $this->rbacBackKeyboard());
    }

    private function rbacBackKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ نقش‌ها', callback_data: 'menu:admin:roles'))
            ->addRow(InlineKeyboardButton::make('🏠 مدیریت', callback_data: 'menu:admin'));
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

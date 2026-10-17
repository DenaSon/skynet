<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ReadAdminConfigProfiles;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/** CFG-01: read-only, private Super Admin profile/inbound navigation. */
final readonly class AdminConfigProfilesUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ReadAdminConfigProfiles $profiles,
        private MenuRenderer $menus,
    ) {}

    public function browse(Nutgram $bot, string $requested = '1'): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }

        if (! ctype_digit($requested) || strlen($requested) > 3 || (int) $requested < 1 || (int) $requested > 100) {
            $this->render($bot, 'صفحه معتبر نیست.', $this->home());

            return;
        }

        try {
            $profiles = $this->profiles->all($root);
        } catch (Throwable) {
            $this->render($bot, "🧩 پروفایل‌های اتصال\n\nدریافت فهرست ممکن نیست.", $this->home());

            return;
        }

        $pages = max(1, (int) ceil(count($profiles) / 10));
        $page = min((int) $requested, $pages);
        $keyboard = InlineKeyboardMarkup::make();
        foreach (array_slice($profiles, ($page - 1) * 10, 10) as $profile) {
            $keyboard->addRow(InlineKeyboardButton::make(
                '🧩 '.self::safe($profile['name']), callback_data: 'cfg1:view:'.$profile['uuid'].':'.$page,
            ));
        }

        $nav = [];
        if ($page > 1) {
            $nav[] = InlineKeyboardButton::make('◀️ قبلی', callback_data: 'cfg1:list:'.($page - 1));
        }
        if ($page < $pages) {
            $nav[] = InlineKeyboardButton::make('بعدی ▶️', callback_data: 'cfg1:list:'.($page + 1));
        }
        if ($nav !== []) {
            $keyboard->addRow(...$nav);
        }

        $keyboard->addRow(InlineKeyboardButton::make('➕ پروفایل جدید', callback_data: 'cfg2:begin'));
        $keyboard->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'cfg1:list:'.$page));
        $keyboard->addRow(InlineKeyboardButton::make('◀️ وضعیت سرورها', callback_data: 'menu:admin:servers'));

        $this->render($bot,
            "🧩 پروفایل‌های اتصال\n\n"
            .'تعداد: '.count($profiles)." | صفحه {$page} از {$pages}\n\n"
            .($profiles === [] ? 'هنوز پروفایلی تعریف نشده است.' : 'برای مشاهده ورودی‌ها، یک پروفایل انتخاب کنید.'),
            $keyboard,
        );
    }

    public function detail(Nutgram $bot, string $uuid, string $page = '1'): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }

        if (! ctype_digit($page) || strlen($page) > 3 || (int) $page < 1 || (int) $page > 100) {
            $this->render($bot, 'این انتخاب معتبر نیست.', $this->home());

            return;
        }

        $back = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ فهرست پروفایل‌ها', callback_data: 'cfg1:list:'.$page));

        try {
            $profile = $this->profiles->detail($root, $uuid);
        } catch (Throwable) {
            $this->render($bot, 'دریافت اطلاعات این پروفایل ممکن نیست.', $back);

            return;
        }
        if ($profile === null) {
            $this->render($bot, 'این پروفایل پیدا نشد. فهرست را تازه‌سازی کنید.', $back);

            return;
        }

        $lines = [
            '🧩 جزئیات پروفایل',
            '',
            'نام: '.self::safe($profile['name']),
            'ورودی‌ها: '.count($profile['inbounds']),
            '',
            '📥 ورودی‌های اتصال:',
        ];
        if ($profile['inbounds'] === []) {
            $lines[] = 'ورودی‌ای تعریف نشده است.';
        } else {
            foreach (array_slice($profile['inbounds'], 0, 20) as $inbound) {
                $lines[] = '• '.self::safe($inbound['name']);
            }
            if (count($profile['inbounds']) > 20) {
                $lines[] = 'نمایش ۲۰ ورودی از '.count($profile['inbounds']);
            }
        }

        $lines[] = '';
        $lines[] = '🖥️ نودهای مرتبط:';
        if ($profile['linkedNodes'] === null) {
            $lines[] = 'اطلاعات ارتباط نودها در دسترس نیست.';
        } elseif ($profile['linkedNodes'] === []) {
            $lines[] = 'نودی با این پروفایل گزارش نشده است.';
        } else {
            foreach (array_slice($profile['linkedNodes'], 0, 10) as $name) {
                $lines[] = '• '.self::safe($name);
            }
            if (count($profile['linkedNodes']) > 10) {
                $lines[] = 'نمایش ۱۰ نود از '.count($profile['linkedNodes']);
            }
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('✏️ تغییر نام', callback_data: 'cfg2:prepare:'.$profile['uuid'].':rename'))
            ->addRow(InlineKeyboardButton::make('🗑️ حذف پروفایل', callback_data: 'cfg2:prepare:'.$profile['uuid'].':delete'))
            ->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'cfg1:view:'.$profile['uuid'].':'.$page))
            ->addRow(InlineKeyboardButton::make('◀️ فهرست پروفایل‌ها', callback_data: 'cfg1:list:'.$page));
        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    private function root(Nutgram $bot): ?int
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null) {
            return null;
        }
        $actor = $this->actors->handle($telegramId);
        if ($actor?->canManageRoles() !== true) {
            $this->menus->denyAccess($bot);

            return null;
        }

        return $actor->userId;
    }

    private function home(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ وضعیت سرورها', callback_data: 'menu:admin:servers'));
    }

    private static function safe(string $label): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $label)), 0, 55);
    }

    private function render(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $text, $keyboard);

            return;
        }
        $bot->sendMessage(text: $text, reply_markup: $keyboard);
    }
}

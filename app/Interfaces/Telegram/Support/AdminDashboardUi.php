<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ReadAdminDashboard;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/** STAT-01: private read-only Super Admin overview. */
final readonly class AdminDashboardUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ReadAdminDashboard $dashboard,
        private MenuRenderer $menus,
    ) {}

    public function show(Nutgram $bot): void
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null) {
            return;
        }

        $actor = $this->actors->handle($telegramId);
        if ($actor?->canManageRoles() !== true) {
            $this->menus->denyAccess($bot);

            return;
        }

        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
        }

        try {
            $report = $this->dashboard->handle($actor->userId);
            $text = '<b>📊 آمار DenaGate</b>'
                ."\n\n<b>🖥️ زیرساخت</b>"
                ."\nنودها: ".self::count($report['nodes'])
                .' | متصل: '.self::count($report['connected'])
                ."\nهاست‌ها: ".self::count($report['hosts'])
                ."\nکاربران آنلاین: ".self::count($report['online'])
                ."\n\n<b>👥 کاربران و اشتراک‌ها</b>"
                ."\nحساب‌های دناگیت: ".self::count($report['localUsers'])
                .' | فعال: '.self::count($report['localActive'])
                ."\nکاربران پنل سرویس: ".self::count($report['panelUsers'])
                ."\nاشتراک‌های فعال پنل: ".self::count($report['subscriptionsActive'])
                ."\nمنقضی‌شده: ".self::count($report['subscriptionsExpired'])
                .' | اتمام حجم: '.self::count($report['subscriptionsLimited'])
                ."\n\n<b>🤖 تلگرام</b>"
                ."\nشناسه‌های متصل: ".self::count($report['telegramLinked'])
                ."\nقابل دسترس در فروش: ".self::count($report['salesReachable'])
                ."\nقابل دسترس در مدیریت: ".self::count($report['adminReachable'])
                ."\nتعامل با بات فروش در ۳۰ روز: ".self::count($report['salesRecent'])
                ."\n\n<b>💳 فروش امروز</b>"
                ."\n<b>".number_format(intdiv($report['salesTodayRials'], 10)).' تومان</b>'
                ."\n\n<b>⚠️ پیگیری‌ها</b>"
                ."\nعملیات اشتراک: ".self::count($report['provisioningReview'])
                .' | سفارش‌ها: '.self::count($report['fulfillmentReview'])
                ."\nرسیدهای منتظر بررسی: ".self::count($report['receiptsPending'])
                ."\nکارهای ناموفق: ".self::count($report['failedJobs'])
                ."\nاعلان‌های ناموفق: ".self::count($report['notificationIssues'])
                ."\n\n<blockquote>آمار لحظه دریافت است. «نامشخص» یعنی اطلاعات کامل در دسترس نیست؛ صفر نیست.</blockquote>"
                ."\n🕒 ".$report['receivedAt'].' (تهران)';
        } catch (\Throwable) {
            $text = "📊 آمار DenaGate\n\nدریافت آمار فعلاً ممکن نیست. کمی بعد دوباره تلاش کنید.";
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'stats:refresh'))
            ->addRow(
                InlineKeyboardButton::make('🖥️ نودها', callback_data: 'metrics:refresh'),
                InlineKeyboardButton::make('📈 گزارش مصرف', callback_data: 'metrics:range:7'),
            )
            ->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'));

        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $text, $keyboard, 'HTML');

            return;
        }

        $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: 'HTML');
    }

    private static function count(?int $value): string
    {
        return $value === null ? 'نامشخص' : number_format($value);
    }
}

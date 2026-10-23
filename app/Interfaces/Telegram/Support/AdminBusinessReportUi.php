<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Sales\Application\ReadAdminBusinessReport;
use App\Support\PersianCalendar;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/** STAT-02: bounded 7/30-day business reports for trusted Super Admin only. */
final readonly class AdminBusinessReportUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ReadAdminBusinessReport $reports,
        private MenuRenderer $menus,
    ) {}

    public function show(Nutgram $bot, string $section, string $days): void
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

        $keyboard = $this->keyboard($section, $days);
        if (! in_array($section, ['sales', 'people'], true) || ! in_array($days, ['7', '30'], true)) {
            $this->render($bot, 'بازه یا بخش گزارش معتبر نیست.', $this->keyboard('sales', '7'));

            return;
        }

        try {
            $data = $this->reports->handle($actor->userId, (int) $days);
            $text = $section === 'sales' ? $this->sales($data) : $this->people($data);
        } catch (\Throwable) {
            $text = "📊 گزارش مدیریتی\n\nدریافت گزارش فعلاً ممکن نیست. دوباره تلاش کنید.";
        }

        $this->render($bot, $text, $keyboard);
    }

    /** @param array<string,int|string> $data */
    private function sales(array $data): string
    {
        return '<b>💳 گزارش فروش · '.$data['days'].' روز اخیر</b>'
            ."\n📅 ".PersianCalendar::date((string) $data['start']).' تا '.PersianCalendar::date((string) $data['end']).' (شمسی، تهران)'
            ."\n\n<b>مجموع پرداخت‌های تأییدشده</b>"
            ."\n<b>".number_format(intdiv((int) $data['revenueRials'], 10)).' تومان</b>'
            ."\n\n🧾 تراکنش‌های فروش: ".self::n($data['settled'])
            ."\n🛒 خرید جدید: ".self::n($data['newPurchases'])
            ."\n🔄 تمدیدها: ".self::n($data['renewals'])
            ."\n👤 خریداران یکتا: ".self::n($data['buyers'])
            ."\n✅ سفارش‌های تکمیل‌شده از این پرداخت‌ها: ".self::n($data['fulfilled'])
            ."\n⏳ سفارش‌های در انتظار پرداخت (اکنون): ".self::n($data['pendingNow'])
            ."\n\n<blockquote>مبالغ ناخالص پرداخت‌های تسویه‌شده هستند؛ شارژ کیف پول و سفارش‌های پرداخت‌نشده حساب نشده‌اند. تکمیل‌شدن سفارش با زمان تسویه فرق دارد.</blockquote>"
            ."\n🕒 ".PersianCalendar::localDateTime((string) $data['generatedAt']);
    }

    /** @param array<string,int|string> $data */
    private function people(array $data): string
    {
        return '<b>👥 گزارش کاربران و تلگرام · '.$data['days'].' روز اخیر</b>'
            ."\n📅 ".PersianCalendar::date((string) $data['start']).' تا '.PersianCalendar::date((string) $data['end']).' (شمسی، تهران)'
            ."\n\n👤 حساب‌های جدید: ".self::n($data['newUsers'])
            ."\n🔗 Telegram IDهای متصل (اکنون): ".self::n($data['linkedTelegram'])
            ."\n\n<b>🤖 بات فروش</b>"
            ."\nمخاطبان ثبت‌شده (اکنون): ".self::n($data['salesContacts'])
            ."\nقابل دسترس (اکنون): ".self::n($data['salesReachable'])
            ."\nمخاطبان جدید در بازه: ".self::n($data['salesNewContacts'])
            ."\nتعامل در بازه: ".self::n($data['salesEngaged'])
            ."\n\n<b>🛡️ بات مدیریت</b>"
            ."\nمخاطبان ثبت‌شده (اکنون): ".self::n($data['adminContacts'])
            ."\nقابل دسترس (اکنون): ".self::n($data['adminReachable'])
            ."\nمخاطبان جدید در بازه: ".self::n($data['adminNewContacts'])
            ."\nتعامل در بازه: ".self::n($data['adminEngaged'])
            ."\n\n<blockquote>تعامل یعنی آخرین ارتباط ثبت‌شده در بازه؛ نه کاربر آنلاین یا تعداد پیام‌ها. مخاطبان هر بات مستقل شمارش شده‌اند.</blockquote>"
            ."\n🕒 ".PersianCalendar::localDateTime((string) $data['generatedAt']);
    }

    private function keyboard(string $section, string $days): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('۷ روز', callback_data: 'stats:detail:'.$section.':7'),
                InlineKeyboardButton::make('۳۰ روز', callback_data: 'stats:detail:'.$section.':30'),
            )
            ->addRow(
                InlineKeyboardButton::make('💳 فروش', callback_data: 'stats:detail:sales:'.$days),
                InlineKeyboardButton::make('👥 کاربران', callback_data: 'stats:detail:people:'.$days),
            )
            ->addRow(InlineKeyboardButton::make('📊 آمار کلی', callback_data: 'stats:overview'))
            ->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'));
    }

    private function render(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $text, $keyboard, 'HTML');

            return;
        }

        $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: 'HTML');
    }

    private static function n(int|string $value): string
    {
        return number_format((int) $value);
    }
}

<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Sales\Application\ReadAdminBusinessReport;
use App\Support\PersianCalendar;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;

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

        $rich = null;
        try {
            $data = $this->reports->handle($actor->userId, (int) $days);
            $text = $section === 'sales' ? $this->sales($data) : $this->people($data);
            $rich = $section === 'sales' ? $this->richSales($data) : $this->richPeople($data);
        } catch (\Throwable) {
            $text = "📊 گزارش مدیریتی\n\nدریافت گزارش فعلاً ممکن نیست. دوباره تلاش کنید.";
        }

        $this->render($bot, $text, $keyboard, $rich);
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

    /** @param array<string,int|string> $data */
    private function richSales(array $data): string
    {
        $period = PersianCalendar::date((string) $data['start']).' تا '
            .PersianCalendar::date((string) $data['end']);
        $rows = [
            ['تراکنش‌های فروش', self::n($data['settled'])],
            ['خرید جدید', self::n($data['newPurchases'])],
            ['تمدیدها', self::n($data['renewals'])],
            ['خریداران یکتا', self::n($data['buyers'])],
            ['سفارش‌های تکمیل‌شده', self::n($data['fulfilled'])],
            ['در انتظار پرداخت · اکنون', self::n($data['pendingNow'])],
        ];

        return '<h2>💳 گزارش فروش · '.$data['days'].' روز اخیر</h2>'
            .'<p>📅 '.$period.' · تهران</p>'
            .'<h3>مجموع پرداخت‌های تأییدشده</h3><p><b>'
            .number_format(intdiv((int) $data['revenueRials'], 10)).' تومان</b></p>'
            .'<table bordered striped compact>'.self::rows($rows).'</table>'
            .'<footer>🕒 '.PersianCalendar::localDateTime((string) $data['generatedAt'])
            .' · فروش تسویه‌شده؛ شارژ کیف پول و سفارش پرداخت‌نشده محاسبه نشده‌اند.'
            .' تکمیل سفارش با زمان تسویه متفاوت است.</footer>';
    }

    /** @param array<string,int|string> $data */
    private function richPeople(array $data): string
    {
        $period = PersianCalendar::date((string) $data['start']).' تا '
            .PersianCalendar::date((string) $data['end']);
        $identity = [
            ['حساب‌های جدید', self::n($data['newUsers'])],
            ['شناسه‌های تلگرام متصل · اکنون', self::n($data['linkedTelegram'])],
        ];
        $sales = [
            ['مخاطبان ثبت‌شده · اکنون', self::n($data['salesContacts'])],
            ['قابل دسترس · اکنون', self::n($data['salesReachable'])],
            ['مخاطبان جدید در بازه', self::n($data['salesNewContacts'])],
            ['تعامل در بازه', self::n($data['salesEngaged'])],
        ];
        $admin = [
            ['مخاطبان ثبت‌شده · اکنون', self::n($data['adminContacts'])],
            ['قابل دسترس · اکنون', self::n($data['adminReachable'])],
            ['مخاطبان جدید در بازه', self::n($data['adminNewContacts'])],
            ['تعامل در بازه', self::n($data['adminEngaged'])],
        ];

        return '<h2>👥 گزارش کاربران و تلگرام · '.$data['days'].' روز اخیر</h2>'
            .'<p>📅 '.$period.' · تهران</p>'
            .'<h3>👤 کاربران</h3><table bordered striped compact>'.self::rows($identity).'</table>'
            .'<h3>🤖 بات فروش</h3><table bordered striped compact>'.self::rows($sales).'</table>'
            .'<h3>🛡️ بات مدیریت</h3><table bordered striped compact>'.self::rows($admin).'</table>'
            .'<footer>🕒 '.PersianCalendar::localDateTime((string) $data['generatedAt'])
            .' · تعامل یعنی آخرین ارتباط در بازه، نه تعداد پیام‌ها یا افراد آنلاین.'
            .' مخاطبان هر بات جداگانه شمارش شده‌اند.</footer>';
    }

    /** @param list<array{string,string}> $rows */
    private static function rows(array $rows): string
    {
        $html = '';
        foreach ($rows as [$label, $value]) {
            $html .= '<tr><td>'.TelegramUi::escape($label).'</td><td><b>'
                .TelegramUi::escape($value).'</b></td></tr>';
        }

        return $html;
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

    private function render(
        Nutgram $bot,
        string $text,
        InlineKeyboardMarkup $keyboard,
        ?string $rich = null,
    ): void {
        if ($rich !== null) {
            try {
                TelegramUi::editRichText($bot, new InputRichMessage(html: $rich, is_rtl: true), $keyboard);

                return;
            } catch (TelegramException $exception) {
                report($exception);
                // Same authorized data, keyboard and date limits in native HTML.
            }
        }

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

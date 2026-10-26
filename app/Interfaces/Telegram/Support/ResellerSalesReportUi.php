<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Sales\Application\ReadResellerSalesReport;
use App\Support\PersianCalendar;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/** Private, read-only reseller reports; never the Admin/global report. */
final readonly class ResellerSalesReportUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ReadResellerSalesReport $reports,
    ) {}

    public function show(Nutgram $bot, string $days = '30'): void
    {
        $id = $this->private->id($bot);
        if ($id === null) {
            return;
        }
        $actor = $this->actors->handle($id);
        if ($actor === null || ! in_array('reseller', $actor->roles, true)) {
            if ($bot->isCallbackQuery()) {
                TelegramUi::answerCallback($bot);
            }
            $this->render($bot, 'گزارش‌ها فقط برای نمایندگان فعال در دسترس است.', $this->keyboard('30'));

            return;
        }

        if (! in_array($days, ['7', '30'], true)) {
            $this->render($bot, 'بازه انتخاب‌شده معتبر نیست.', $this->keyboard('30'));

            return;
        }

        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
        }
        try {
            $r = $this->reports->handle($actor->userId, (int) $days);
            $text = '<b>📊 گزارش‌های من · '.$days.' روز اخیر</b>'
                ."\n📅 ".PersianCalendar::date($r['from']).' تا '.PersianCalendar::date($r['to']).' (شمسی، تهران)'
                ."\n\n<b>👥 مشتریان و سرویس‌ها</b>"
                ."\n📦 کل سرویس‌های ثبت‌شده: ".number_format($r['services'])
                ."\n👤 سرویس‌های دارای نام: ".number_format($r['namedServices'])
                ."\n\n<b>💳 فروش تأییدشده</b>"
                ."\n🛒 خریدهای جدید: ".number_format($r['sales'])
                ."\n🔄 تمدیدها: ".number_format($r['renewals'])
                ."\n🧾 تراکنش‌ها: ".number_format($r['transactions'])
                ."\n💰 مجموع فروش: <b>".number_format(intdiv($r['revenueRials'], 10)).' تومان</b>'
                ."\n⏳ سفارش‌های در انتظار پرداخت: ".number_format($r['pending'])
                ."\n\n<blockquote>آمار سرویس‌ها وضعیت فعلی را نشان می‌دهد؛ فروش مربوط به بازه انتخاب‌شده و فقط پرداخت‌های تأییدشده است.</blockquote>"
                ."\n🕒 ".PersianCalendar::localDateTime($r['generatedAt']);
        } catch (Throwable) {
            $text = '<b>📊 گزارش‌های من</b>'."\n\nاطلاعات فعلاً در دسترس نیست؛ کمی بعد دوباره تلاش کنید.";
        }
        $this->render($bot, $text, $this->keyboard($days));
    }

    private function keyboard(string $days): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('۷ روز', callback_data: 'sales:report:7'),
                InlineKeyboardButton::make('۳۰ روز', callback_data: 'sales:report:30'),
            )
            ->addRow(InlineKeyboardButton::make('📡 وضعیت سرویس‌ها', callback_data: 'sales:health:0'))
            ->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'sales:report:'.$days))
            ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home'));
    }

    private function render(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $text, $keyboard, 'HTML');

            return;
        }
        $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: 'HTML');
    }
}

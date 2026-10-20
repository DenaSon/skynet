<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/** Small static guides and one configured Telegram contact; no ticket system. */
final readonly class SalesSupportUi
{
    public function __construct(private SalesHomeUi $home) {}

    public function show(Nutgram $bot, string $page = 'support'): void
    {
        if ($this->home->navigationActor($bot) === null) {
            return;
        }
        $operator = $this->operatorUrl();
        $keyboard = InlineKeyboardMarkup::make();
        switch ($page) {
            case 'support':
                $text = '<b>💬 پشتیبانی</b>'."\n\n".($operator === null
                    ? 'ارتباط مستقیم فعلاً در دسترس نیست. راهنمای اتصال و خرید را ببینید.'
                    : 'برای گفتگو با اپراتور، دکمه «ارتباط با پشتیبان» را بزنید. گفتگوی خصوصی تلگرام باز می‌شود.');
                $keyboard->addRow(InlineKeyboardButton::make('📚 آموزش اتصال و خرید', callback_data: 'sales:help:apps'));
                break;

            case 'apps':
                $text = '<b>📚 آموزش اتصال و خرید</b>'."\n\n<blockquote>دستگاه خود را انتخاب کنید تا راهنمای نصب و اتصال را ببینید.</blockquote>";
                $keyboard->addRow(
                    InlineKeyboardButton::make('🤖 اندروید', callback_data: 'sales:help:android'),
                    InlineKeyboardButton::make('🍎 آیفون / آیپد', callback_data: 'sales:help:ios'),
                )->addRow(InlineKeyboardButton::make('💻 کامپیوتر (ویندوز / مک)', callback_data: 'sales:help:desktop'))
                    ->addRow(InlineKeyboardButton::make('🛒 آموزش خرید', callback_data: 'sales:help:purchase'))
                    ->addRow(InlineKeyboardButton::make('🔧 رفع مشکل اتصال', callback_data: 'sales:help:troubleshoot'));
                break;

            case 'android':
            case 'ios':
            case 'desktop':
                $device = match ($page) {
                    'android' => 'اندروید',
                    'ios' => 'آیفون / آیپد',
                    default => 'ویندوز / مک',
                };
                $text = '<b>📱 اتصال در '.$device.'</b>'."\n\n"
                    ."۱. از سایت رسمی Hiddify، نسخه مناسب دستگاه خود را نصب کنید.\n\n"
                    ."۲. در «سرویس‌های من»، سرویس فعال را باز کنید و لینک اختصاصی را کپی کنید.\n\n"
                    ."۳. در Hiddify دکمه + و سپس «افزودن از کلیپ‌بورد» (Add from clipboard) را بزنید.\n\n"
                    ."۴. پروفایل را انتخاب کنید و دکمه اتصال را بزنید؛ اگر دستگاه اجازه اتصال خواست، آن را تأیید کنید.\n\n"
                    .'<blockquote>لینک اتصال شخصی است؛ آن را برای دیگران نفرستید.</blockquote>';
                $keyboard->addRow(InlineKeyboardButton::make('⬇️ دریافت Hiddify از سایت رسمی', url: 'https://hiddify.com/'))
                    ->addRow(InlineKeyboardButton::make('📖 راهنمای رسمی برنامه', url: 'https://hiddify.com/app/How-to-use-Hiddify-app/'))
                    ->addRow(InlineKeyboardButton::make('📋 سرویس‌های من', callback_data: 'sales:subs:list:0'));
                break;

            case 'purchase':
                $text = '<b>🛒 آموزش خرید سرویس</b>'."\n\n"
                    ."۱. از «خرید سرویس»، پلن مناسب را انتخاب کنید.\n\n"
                    ."۲. مشخصات و قیمت را ببینید و مراحل ثبت سفارش را دنبال کنید.\n\n"
                    ."۳. طبق اطلاعات پرداخت همان سفارش واریز کنید و عکس رسید را در بخش ارسال رسید بفرستید.\n\n"
                    ."۴. پس از بررسی پرداخت و فعال‌سازی، از «سرویس‌های من» لینک اتصال را دریافت کنید.\n\n"
                    .'<blockquote>اگر رسید را فرستاده‌اید، وضعیت همان سفارش را بررسی کنید.</blockquote>';
                $keyboard->addRow(InlineKeyboardButton::make('🛒 خرید سرویس', callback_data: 'menu:plan-catalog'))
                    ->addRow(InlineKeyboardButton::make('🧾 سوابق خرید', callback_data: 'sales:checkout:orders'));
                break;

            case 'troubleshoot':
                $text = '<b>🔧 رفع مشکل اتصال</b>'."\n\n"
                    ."📋 وضعیت، حجم باقی‌مانده و انقضای سرویس را در «سرویس‌های من» بررسی کنید.\n\n"
                    ."🔄 پروفایل را در برنامه به‌روز کنید و یک بار اتصال را قطع و وصل کنید.\n\n"
                    ."🌐 اتصال اینترنت را بررسی کنید؛ در صورت امکان با اینترنت دیگری هم امتحان کنید.\n\n"
                    .'<blockquote>اگر مشکل ادامه داشت، شماره سرویس و نوع دستگاه را برای پشتیبان بفرستید؛ لینک اتصال را عمومی منتشر نکنید.</blockquote>';
                $keyboard->addRow(InlineKeyboardButton::make('📋 سرویس‌های من', callback_data: 'sales:subs:list:0'));
                break;

            default:
                $this->home->fallback($bot);

                return;
        }
        if ($operator !== null) {
            $keyboard->addRow(InlineKeyboardButton::make('💬 ارتباط با پشتیبان', url: $operator));
        }
        if (in_array($page, ['android', 'ios', 'desktop', 'purchase', 'troubleshoot'], true)) {
            $keyboard->addRow(InlineKeyboardButton::make('◀️ آموزش‌ها', callback_data: 'sales:help:apps'));
        } elseif ($page === 'apps') {
            $keyboard->addRow(InlineKeyboardButton::make('◀️ پشتیبانی', callback_data: 'sales:support'));
        }
        $keyboard->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home'));
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $text, $keyboard, 'HTML');

            return;
        }
        $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: 'HTML');
    }

    private function operatorUrl(): ?string
    {
        $username = config('telegram.sales.support_username');
        if (! is_string($username)) {
            return null;
        }
        $username = ltrim(trim($username), '@');

        return preg_match('/\A[A-Za-z][A-Za-z0-9_]{4,31}\z/D', $username) === 1
            ? 'https://t.me/'.$username : null;
    }
}

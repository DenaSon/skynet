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
                )->addRow(
                    InlineKeyboardButton::make('🪟 ویندوز', callback_data: 'sales:help:windows'),
                    InlineKeyboardButton::make('💻 مک', callback_data: 'sales:help:macos'),
                )->addRow(InlineKeyboardButton::make('🛒 آموزش خرید', callback_data: 'sales:help:purchase'))
                    ->addRow(InlineKeyboardButton::make('🔧 رفع مشکل اتصال', callback_data: 'sales:help:troubleshoot'));
                break;

            case 'desktop':
                // Existing keyboards may still carry the combined desktop callback.
                $text = '<b>💻 اتصال در کامپیوتر</b>'."\n\n".'سیستم‌عامل خود را انتخاب کنید.';
                $keyboard->addRow(
                    InlineKeyboardButton::make('🪟 ویندوز', callback_data: 'sales:help:windows'),
                    InlineKeyboardButton::make('💻 مک', callback_data: 'sales:help:macos'),
                );
                break;

            case 'android':
            case 'ios':
            case 'windows':
            case 'macos':
                $device = match ($page) {
                    'android' => '🤖 اندروید',
                    'ios' => '🍎 آیفون / آیپد',
                    'windows' => '🪟 ویندوز',
                    default => '💻 مک',
                };
                $client = match ($page) {
                    'android' => 'v2rayNG',
                    'windows' => 'v2rayN',
                    default => 'V2Box',
                };
                $download = match ($page) {
                    'android' => 'https://github.com/2dust/v2rayNG/releases/latest',
                    'ios', 'macos' => 'https://apps.apple.com/app/v2box-v2ray-client/id6446814690',
                    default => 'https://github.com/2dust/v2rayN/releases/latest',
                };
                $import = match ($page) {
                    'android' => 'در v2rayNG، بخش گروه‌های اشتراک (Subscription group) را باز کنید، URL را اضافه کنید و اشتراک را به‌روز کنید.',
                    'windows' => 'در v2rayN، بخش گروه اشتراک (Subscription group) را باز کنید، URL را وارد و گروه را به‌روز کنید.',
                    default => 'در V2Box، بخش اشتراک‌ها (Subscriptions) را باز کنید، URL را اضافه و سپس به‌روز کنید.',
                };
                $text = '<b>📱 اتصال در '.$device.'</b>'."\n\n"
                    .'برنامه پیشنهادی: <b>'.$client.'</b>'."\n\n"
                    ."۱. از لینک رسمی زیر برنامه را نصب کنید.\n\n"
                    ."۲. از «سرویس‌های من» لینک <b>اشتراک</b> اختصاصی خود را کپی کنید.\n\n"
                    .'۳. '.$import."\n\n"
                    ."۴. سرور دریافت‌شده را انتخاب و اتصال را فعال کنید. اگر مجوز VPN درخواست شد، تأیید کنید.\n\n"
                    .'<blockquote>نام منوها ممکن است بین نسخه‌ها فرق کند. سازگاری فرمت اشتراک با نوع کلاینت و تنظیمات Remnawave بررسی می‌شود؛ لینک خصوصی خود را به کسی ندهید.</blockquote>';
                $keyboard->addRow(InlineKeyboardButton::make('⬇️ دریافت '.$client, url: $download))
                    ->addRow(InlineKeyboardButton::make('📚 فهرست رسمی کلاینت‌های Remnawave', url: 'https://docs.rw/clients/'))
                    ->addRow(InlineKeyboardButton::make('📋 سرویس‌های من', callback_data: 'sales:subs:list:0'));
                break;

            case 'purchase':
                $text = '<b>🛒 آموزش خرید سرویس</b>'."\n\n"
                    ."۱. از «خرید سرویس»، پلن مناسب را انتخاب کنید.\n\n"
                    ."۲. با انتخاب پلن، مبلغ و شماره کارت را در همان صفحه پرداخت ببینید.\n\n"
                    ."۳. واریز را انجام دهید و عکس رسید را مستقیم در همین گفت‌وگو بفرستید.\n\n"
                    ."۴. منتظر بررسی مدیر بمانید؛ سپس وضعیت را از «سوابق خرید» بررسی کنید.\n\n"
                    .'<blockquote>پرداخت ناتمام را از «سوابق خرید» با «ادامه پرداخت» باز کنید.</blockquote>';
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
        if (in_array($page, ['android', 'ios', 'windows', 'macos', 'desktop', 'purchase', 'troubleshoot'], true)) {
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

<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;

/** Small static guides and one configured Telegram contact; no ticket system. */
final readonly class SalesSupportUi
{
    public function __construct(private SalesHomeUi $home) {}

    public function show(Nutgram $bot, string $page = 'support', bool $rich = true): void
    {
        $actor = $this->home->navigationActor($bot);
        if ($actor === null) {
            return;
        }
        if ($page === 'builder' && ! in_array('reseller', $actor->roles, true)) {
            $this->home->fallback($bot);

            return;
        }
        $canSetupPanel = in_array('member', $actor->roles, true)
            && ! in_array('reseller', $actor->roles, true)
            && ! in_array('admin', $actor->roles, true)
            && ! in_array('super-admin', $actor->roles, true);
        if ($page === 'panel-setup' && ! $canSetupPanel) {
            $this->home->fallback($bot);

            return;
        }
        $operator = $this->operatorUrl();
        $keyboard = InlineKeyboardMarkup::make();
        switch ($page) {
            case 'support':
                $text = '<b>💬 پشتیبانی</b>'."\n\n".($operator === null
                    ? 'ارتباط مستقیم فعلاً در دسترس نیست. راهنمای اتصال و خرید را ببینید.'
                    : 'برای گفتگو با اپراتور، دکمه «ارتباط با پشتیبان» را بزنید. گفتگوی خصوصی تلگرام باز می‌شود.');
                $keyboard->addRow(InlineKeyboardButton::make('📚 آموزش اتصال و خرید', callback_data: 'sales:help:apps'))
                    ->addRow(InlineKeyboardButton::make('🔧 رفع مشکل اتصال', callback_data: 'sales:help:troubleshoot'))
                    ->addRow(InlineKeyboardButton::make('📋 سرویس‌های من', callback_data: 'sales:subs:list:0'));
                if (in_array('reseller', $actor->roles, true)) {
                    $keyboard->addRow(InlineKeyboardButton::make('🧩 راهنمای پلن‌ساز', callback_data: 'sales:help:builder'));
                }
                break;

            case 'apps':
                $text = '<b>📚 آموزش اتصال و خرید</b>'."\n\n<blockquote>دستگاه خود را انتخاب کنید تا راهنمای نصب و اتصال را ببینید.</blockquote>";
                $keyboard->addRow(
                    InlineKeyboardButton::make('🤖 اندروید', callback_data: 'sales:help:android'),
                    InlineKeyboardButton::make('🍎 آیفون / آیپد', callback_data: 'sales:help:ios'),
                )->addRow(
                    InlineKeyboardButton::make('🪟 ویندوز', callback_data: 'sales:help:windows'),
                    InlineKeyboardButton::make('💻 مک', callback_data: 'sales:help:macos'),
                )->addRow(InlineKeyboardButton::make('🛒 آموزش خرید', callback_data: 'sales:help:purchase'));
                if ($canSetupPanel) {
                    $keyboard->addRow(InlineKeyboardButton::make('🧩 راه‌اندازی پنل فروش', callback_data: 'sales:help:panel-setup', style: ButtonStyle::PRIMARY));
                }
                $keyboard->addRow(InlineKeyboardButton::make('🔧 رفع مشکل اتصال', callback_data: 'sales:help:troubleshoot'));
                if (in_array('reseller', $actor->roles, true)) {
                    $keyboard->addRow(InlineKeyboardButton::make('🧩 راهنمای پلن‌ساز', callback_data: 'sales:help:builder'));
                }
                break;

            case 'panel-setup':
                $text = '<b>🧩 راه‌اندازی پنل فروش</b>'."\n\n"
                    ."<b>۱. 💰 افزایش اعتبار</b>\n"
                    ."از بخش «اعتبار من»، موجودی خود را به حداقل <b>۳۵۰٬۰۰۰ تومان</b> برسانید. رسید پرداخت را ارسال کنید و منتظر تأیید شارژ بمانید.\n\n"
                    ."<b>۲. 📝 ثبت درخواست</b>\n"
                    ."از منوی اصلی، «درخواست پنل فروش» را انتخاب کنید؛ قوانین را بپذیرید و نام، نام خانوادگی و شماره موبایل خود را ثبت و تأیید کنید.\n\n"
                    ."<b>۳. ✅ شروع فروش</b>\n"
                    ."درخواست شما در کمتر از <b>۱ ساعت</b> بررسی و در صورت تأیید، پنل فعال می‌شود. سپس اشتراک‌ها را با <b>۳۵٪ تخفیف نمایندگی</b> تهیه کنید.\n\n"
                    .'<blockquote>۳۵۰ هزار تومان، اعتبار اولیه شماست؛ هزینه راه‌اندازی نیست و بابت درخواست کسر نمی‌شود. این اعتبار برای خرید اشتراک و فروش به مشتریانتان در حساب شما باقی می‌ماند.</blockquote>';
                $keyboard->addRow(InlineKeyboardButton::make('💰 افزایش اعتبار', callback_data: 'sales:wallet:topup', style: ButtonStyle::SUCCESS))
                    ->addRow(InlineKeyboardButton::make('📝 درخواست پنل فروش', callback_data: 'sales:panel:request', style: ButtonStyle::PRIMARY));
                break;

            case 'builder':
                $text = '<b>🧩 راهنمای پلن‌ساز</b>'."\n\n"
                    ."۱. از منوی نماینده، «پلن‌ساز» را انتخاب کنید.\n\n"
                    ."۲. پلن مناسب و حجم و مدت دلخواه مشتری را مشخص کنید.\n\n"
                    ."۳. قیمت نهایی را بررسی کنید و ثبت سفارش را ادامه دهید.\n\n"
                    .'۴. پس از پرداخت و تأیید، سرویس در «سرویس‌های من» نمایش داده می‌شود.'
                    ."\n\n<blockquote>قیمت و امکان خرید قبل از ثبت سفارش بررسی می‌شود.</blockquote>";
                $keyboard->addRow(InlineKeyboardButton::make('🧩 ورود به پلن‌ساز', callback_data: 'menu:builder'));
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
                    ."۱. از دکمه «دریافت برنامه» نسخه معتبر برنامه را نصب کنید.\n\n"
                    ."۲. وارد «سرویس‌های من» شوید و <b>لینک اتصال</b> خود را کپی کنید.\n\n"
                    .'۳. '.$import."\n\n"
                    ."۴. یک سرور را انتخاب کنید و اتصال را روشن کنید. در صورت درخواست مجوز اتصال، آن را تأیید کنید.\n\n"
                    .'<blockquote>نام گزینه‌ها در نسخه‌های مختلف ممکن است متفاوت باشد. اگر وارد کردن لینک انجام نشد، از پشتیبانی کمک بگیرید؛ لینک خود را برای دیگران نفرستید.</blockquote>';
                $keyboard->addRow(InlineKeyboardButton::make('⬇️ دریافت '.$client, url: $download))
                    ->addRow(InlineKeyboardButton::make('📋 سرویس‌های من', callback_data: 'sales:subs:list:0'));
                break;

            case 'purchase':
                $text = '<b>🛒 آموزش خرید سرویس</b>'."\n\n"
                    ."۱. از «خرید سرویس»، پلن مناسب را انتخاب کنید.\n\n"
                    ."۲. با انتخاب پلن، مبلغ و شماره کارت را در همان صفحه پرداخت ببینید.\n\n"
                    ."۳. واریز را انجام دهید، «ارسال رسید پرداخت» را بزنید و عکس رسید را در همین گفت‌وگو بفرستید.\n\n"
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

        $richHtml = match ($page) {
            'support' => $this->richSupport($operator !== null),
            'apps' => $this->richIndex($canSetupPanel, in_array('reseller', $actor->roles, true)),
            'android', 'ios', 'windows', 'macos', 'purchase', 'troubleshoot', 'panel-setup', 'builder' => $this->richGuide($page, $text),
            default => null,
        };

        // A plain HTML guide remains accessible even if a client cannot show Rich.
        $plainKeyboard = clone $keyboard;
        $this->appendNavigation($plainKeyboard, $page);
        if ($rich && $richHtml !== null) {
            $keyboard->addRow(InlineKeyboardButton::make('↩️ نمایش ساده', callback_data: 'sales:help:simple:'.$page));
        }
        $this->appendNavigation($keyboard, $page);

        if ($rich && $richHtml !== null) {
            try {
                TelegramUi::answerCallback($bot);
                TelegramUi::editRichText($bot, new InputRichMessage(html: $richHtml, is_rtl: true), $keyboard);
            } catch (TelegramException $exception) {
                report($exception);
                $this->renderPlain($bot, $text, $plainKeyboard, false);
            }

            return;
        }

        $this->renderPlain($bot, $text, $keyboard);
    }

    private function appendNavigation(InlineKeyboardMarkup $keyboard, string $page): void
    {
        if (in_array($page, ['android', 'ios', 'windows', 'macos', 'desktop', 'purchase', 'troubleshoot', 'builder', 'panel-setup'], true)) {
            $keyboard->addRow(InlineKeyboardButton::make('◀️ آموزش‌ها', callback_data: 'sales:help:apps'));
        } elseif ($page === 'apps') {
            $keyboard->addRow(InlineKeyboardButton::make('◀️ پشتیبانی', callback_data: 'sales:support'));
        }
        $keyboard->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home'));
    }

    private function renderPlain(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard, bool $acknowledge = true): void
    {
        if ($bot->isCallbackQuery()) {
            if ($acknowledge) {
                TelegramUi::answerCallback($bot);
            }
            TelegramUi::editText($bot, $text, $keyboard, 'HTML');

            return;
        }
        $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: 'HTML');
    }

    private function richSupport(bool $hasOperator): string
    {
        return '<h4>💬 پشتیبانی دناگیت</h4>'
            .'<p>'.($hasOperator
                ? 'برای گفتگو با پشتیبان، دکمه «ارتباط با پشتیبان» را انتخاب کنید.'
                : 'ارتباط مستقیم فعلاً در دسترس نیست. از راهنمای اتصال و خرید استفاده کنید.')
            .'</p>'
            .'<details><summary>🔧 مشکل اتصال دارید؟</summary><p>'
            .'ابتدا وضعیت و حجم سرویس را در «سرویس‌های من» بررسی کنید، '
            .'سپس راهنمای رفع مشکل اتصال را باز کنید.'
            .'</p></details>'
            .'<details><summary>📚 راهنمای دستگاه و خرید</summary><p>'
            .'راهنمای نصب برنامه، واردکردن لینک اتصال و خرید را از بخش آموزش‌ها ببینید.'
            .'</p></details>';
    }

    private function richIndex(bool $canSetupPanel, bool $reseller): string
    {
        $html = '<h4>📚 آموزش‌های دناگیت</h4>'
            .'<p>برای مشاهده مراحل کامل، دستگاه یا موضوع را از دکمه‌های پایین انتخاب کنید.</p>'
            .'<details><summary>🤖 اندروید</summary><p>با v2rayNG لینک سرویس را وارد کنید و سرور را انتخاب کنید.</p></details>'
            .'<details><summary>🍎 آیفون و آیپد</summary><p>در V2Box اشتراک را اضافه، به‌روز و فعال کنید.</p></details>'
            .'<details><summary>🪟 ویندوز</summary><p>در v2rayN لینک را به گروه اشتراک اضافه و سرور را انتخاب کنید.</p></details>'
            .'<details><summary>💻 مک</summary><p>در V2Box لینک سرویس را وارد و اتصال را فعال کنید.</p></details>'
            .'<details><summary>🛒 خرید سرویس</summary><p>پلن را انتخاب کنید؛ بعد از پرداخت، رسید را با دکمه مخصوص ارسال و نتیجه را پیگیری کنید.</p></details>'
            .'<details><summary>🔧 رفع مشکل اتصال</summary><p>وضعیت سرویس، به‌روزبودن اشتراک و اینترنت خود را بررسی کنید.</p></details>';

        if ($canSetupPanel) {
            $html .= '<details><summary>🧩 راه‌اندازی پنل فروش</summary><p>با اعتبار اولیه ۳۵۰٬۰۰۰ تومان درخواست دهید؛ پس از تأیید، تخفیف نمایندگی ۳۵٪ اعمال می‌شود.</p></details>';
        } elseif ($reseller) {
            $html .= '<details><summary>🧩 راهنمای پلن‌ساز</summary><p>حجم و مدت دلخواه مشتری را انتخاب کنید و پیش از ثبت سفارش قیمت را ببینید.</p></details>';
        }

        return $html;
    }

    /** Reuse the existing guide copy for Rich and plain HTML presentation. */
    private function richGuide(string $page, string $text): string
    {
        $blocks = explode("\n\n", $text);
        $title = TelegramUi::escape(strip_tags((string) array_shift($blocks)));
        $intro = '';
        if (in_array($page, ['android', 'ios', 'windows', 'macos'], true)) {
            $intro = '<p>'.array_shift($blocks).'</p>';
        } elseif ($page === 'panel-setup') {
            $intro = '<p>اعتبار اولیه: <b>۳۵۰٬۰۰۰ تومان</b><br>تخفیف نمایندگی: <b>۳۵٪</b></p>';
        }

        $steps = [];
        $note = '';
        foreach ($blocks as $block) {
            if (str_starts_with($block, '<blockquote>')) {
                $note = str_replace(['<blockquote>', '</blockquote>'], '', $block);
            } else {
                $steps[] = $block;
            }
        }

        $html = '<h4>'.$title.'</h4>'.$intro;
        if ($page === 'panel-setup') {
            foreach ($steps as $step) {
                if (preg_match('~\A<b>(.*?)</b>\n(.*)\z~us', $step, $matches) === 1) {
                    $html .= '<details><summary>'.$matches[1].'</summary><p>'.$matches[2].'</p></details>';
                }
            }
        } else {
            $label = match ($page) {
                'purchase' => '🛒 مراحل خرید',
                'troubleshoot' => '🔧 بررسی و رفع مشکل',
                'builder' => '🧩 مراحل ساخت پلن',
                default => '📱 مراحل نصب و اتصال',
            };
            $html .= '<details><summary>'.$label.'</summary><p>'
                .implode('<br><br>', $steps).'</p></details>';
        }
        if ($note !== '') {
            $html .= '<details><summary>💡 نکته مهم</summary><p>'.$note.'</p></details>';
        }

        return $html;
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

<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Sales\Application\ReadAdminOrderLookup;
use App\Support\PersianCalendar;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ForceReply;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use Throwable;

/** Exact-order support lookup for the private Admin Bot; not an order manager. */
final readonly class AdminOrderLookupUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ReadAdminOrderLookup $orders,
        private MenuRenderer $menus,
    ) {}

    public function start(Nutgram $bot): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }

        // Another pending directory search must not consume this numeric answer.
        Cache::forget(TelegramBotContext::current()->cacheKey('tg:users:search-prompt:'.$root));
        Cache::put($this->key($root), true, now()->addMinutes(10));

        $this->render($bot, "🔎 جست‌وجوی سفارش\n\nشماره سفارش را وارد کنید؛ مثلاً #490.\nفقط اطلاعات ثبت‌شده نمایش داده می‌شوند.",
            $this->back());
        $bot->sendMessage(
            text: '✍️ شماره سفارش را ارسال کنید:',
            reply_markup: ForceReply::make(force_reply: true, input_field_placeholder: 'مثلاً #490', selective: false),
        );
    }

    /** Consume only an active private, root-bound prompt; never treat arbitrary text as a lookup. */
    public function text(Nutgram $bot, string $text): bool
    {
        if (TelegramBotContext::current()->key !== TelegramBotContext::ADMIN) {
            return false;
        }
        $telegramId = $this->private->id($bot);
        if ($telegramId === null) {
            return false;
        }
        $actor = $this->actors->handle($telegramId);
        if ($actor === null || ! Cache::has($this->key($actor->userId))) {
            return false;
        }
        if (! $actor->canManageRoles()) {
            Cache::forget($this->key($actor->userId));
            $this->menus->denyAccess($bot);

            return true;
        }

        if (str_starts_with(trim($text), '/') || in_array($text, ReplyMenuLabels::all(), true)) {
            Cache::forget($this->key($actor->userId));

            return false;
        }

        $id = self::parseId($text);
        if ($id === null) {
            $this->render($bot, 'شماره سفارش معتبر نیست. فقط عدد سفارش را وارد کنید؛ مثلاً #490.',
                $this->cancelButtons());

            return true;
        }

        Cache::forget($this->key($actor->userId));
        $this->detail($bot, (string) $id);

        return true;
    }

    public function cancel(Nutgram $bot): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }

        Cache::forget($this->key($root));
        $this->render($bot, 'جست‌وجوی سفارش لغو شد.', $this->back());
    }

    public function detail(Nutgram $bot, string $id): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $orderId = self::parseId($id);
        if ($orderId === null) {
            $this->render($bot, 'شماره سفارش معتبر نیست.', $this->back());

            return;
        }

        try {
            $order = $this->orders->handle($root, $orderId);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (ValidationException) {
            $this->render($bot, 'سفارشی با این شماره پیدا نشد.', $this->back());

            return;
        } catch (Throwable) {
            $this->render($bot, 'دریافت اطلاعات سفارش ممکن نیست. دوباره تلاش کنید.', $this->back());

            return;
        }

        $type = match ($order['buyerCategory']) {
            'reseller' => 'نمایندگی',
            'direct' => 'خرید مستقیم',
            default => 'ثبت نشده',
        };
        $status = match ($order['status']) {
            'pending_payment' => 'در انتظار پرداخت',
            'paid' => 'پرداخت تأییدشده؛ در انتظار فعال‌سازی',
            'fulfilled' => 'فعال‌سازی ثبت‌شده',
            'rejected' => 'تأییدنشده',
            default => 'نامشخص',
        };
        $fulfillment = match ($order['fulfillmentState']) {
            'pending' => 'در صف فعال‌سازی',
            'processing' => 'در حال فعال‌سازی',
            'needs_review' => 'نیازمند بررسی',
            'completed' => 'تکمیل‌شده',
            null => 'ثبت نشده',
            default => 'نامشخص',
        };

        $fields = [
            'خریدار' => self::clean($order['buyerName']),
            'شناسه کاربر' => '#'.$order['buyerId'],
            'نوع خرید' => $type,
            'پلن / محصول' => self::clean($order['productName']),
            'حجم' => $order['trafficGb'] === null ? 'نامحدود' : $order['trafficGb'].' گیگابایت',
            'مدت' => $order['durationDays'].' روز',
            'مبلغ سفارش' => TelegramUi::toman($order['totalPriceRials']).' تومان',
            'وضعیت سفارش' => $status,
            'فعال‌سازی' => $fulfillment,
            'زمان ثبت' => PersianCalendar::utcDateTime($order['createdAt']),
        ];
        if ($order['customerLabel'] !== null && trim($order['customerLabel']) !== '') {
            $fields = ['نام مشتری نماینده' => self::clean($order['customerLabel'])] + $fields;
        }
        if ($order['sourceOrderId'] !== null) {
            $fields['نوع سفارش'] = 'تمدید';
            $fields['سفارش اصلی'] = '#'.$order['sourceOrderId'];
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('👤 جزئیات کاربر', callback_data: 'users:view:'.$order['buyerId'].':1:a'),
                InlineKeyboardButton::make('📋 پرونده مشتری', callback_data: 'users:profile:'.$order['buyerId'].':1:a'),
            );
        $fulfillmentEligible = $order['planId'] !== null && (
            ($order['status'] === 'paid' && in_array($order['fulfillmentState'], ['pending', 'processing', 'needs_review'], true))
            || ($order['status'] === 'fulfilled' && $order['fulfillmentState'] === 'completed')
        );
        if ($fulfillmentEligible) {
            $keyboard->addRow(InlineKeyboardButton::make('🔌 جزئیات فعال‌سازی',
                callback_data: 'full:view:'.$order['id']));
        }
        if ($order['sourceOrderId'] !== null) {
            $keyboard->addRow(InlineKeyboardButton::make('🔄 مشاهده سفارش اصلی',
                callback_data: 'admin:order:view:'.$order['sourceOrderId']));
        }
        $keyboard->addRow(
            InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'admin:order:view:'.$order['id']),
            InlineKeyboardButton::make('🔎 جست‌وجوی مجدد', callback_data: 'admin:order:start'),
        )->addRow(InlineKeyboardButton::make('◀️ کاربران', callback_data: 'users:page:1'));

        $plain = ['🧾 جزئیات سفارش #'.$order['id'], ''];
        $rows = '';
        foreach ($fields as $name => $value) {
            $plain[] = $name.': '.$value;
            $rows .= '<tr><td>'.TelegramUi::escape($name).'</td><td>'
                .TelegramUi::escape($value).'</td></tr>';
        }
        $html = '<h2>🧾 جزئیات سفارش #'.$order['id'].'</h2>'
            .'<table bordered striped compact>'.$rows.'</table>'
            .'<footer>وضعیت‌ها بر اساس سوابق ثبت‌شده دناگیت هستند، نه بررسی لحظه‌ای سرور.</footer>';

        $this->render($bot, implode("\n", $plain), $keyboard, $html);
    }

    private function root(Nutgram $bot): ?int
    {
        if (TelegramBotContext::current()->key !== TelegramBotContext::ADMIN) {
            return null;
        }
        $id = $this->private->id($bot);
        if ($id === null) {
            return null;
        }
        $actor = $this->actors->handle($id);
        if ($actor?->canManageRoles() !== true) {
            $this->menus->denyAccess($bot);

            return null;
        }

        return $actor->userId;
    }

    private static function parseId(string $input): ?int
    {
        $normalized = trim(strtr($input, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]));
        if (preg_match('/\A#?([1-9][0-9]{0,14})\z/D', $normalized, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private static function clean(string $value): string
    {
        $clean = (string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', ' ', $value);

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $clean)), 0, 80);
    }

    private function key(int $actorId): string
    {
        return TelegramBotContext::current()->cacheKey('admin:order-lookup:'.$actorId);
    }

    private function back(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔎 جست‌وجوی سفارش', callback_data: 'admin:order:start'))
            ->addRow(InlineKeyboardButton::make('◀️ کاربران', callback_data: 'users:page:1'));
    }

    private function cancelButtons(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'admin:order:cancel'))
            ->addRow(InlineKeyboardButton::make('◀️ کاربران', callback_data: 'users:page:1'));
    }

    private function render(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard, ?string $rich = null): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
        }
        if ($rich !== null) {
            try {
                TelegramUi::editRichText($bot, new InputRichMessage(html: $rich, is_rtl: true), $keyboard);

                return;
            } catch (TelegramException $exception) {
                report($exception);
            }
        }

        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $text, $keyboard);
        } else {
            $bot->sendMessage(text: $text, reply_markup: $keyboard);
        }
    }
}

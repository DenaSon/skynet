<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Support\PersianCalendar;

use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Provisioning\Application\GetMySubscription;
use App\Modules\Provisioning\Application\GetSubscriptionLink;
use App\Modules\Provisioning\Application\ListAccessibleNodes;
use App\Modules\Provisioning\Application\ListMySubscriptions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\LinkPreviewOptions;
use Throwable;

/**
 * F10-E3: Telegram-only presenter. All ownership, status and provider checks
 * remain in the shared Application actions; callbacks contain only local IDs.
 */
final readonly class SubscriptionUi
{
    private const PER_PAGE = 6;

    public function __construct(
        private PrivateTelegramUser $privateUser,
        private MenuRenderer $menus,
        private ListMySubscriptions $list,
        private GetMySubscription $get,
        private GetSubscriptionLink $connection,
        private ListAccessibleNodes $nodes,
    ) {}

    public function browse(Nutgram $bot, string $cursor = '0'): void
    {
        $viewer = $this->viewer($bot);

        if ($viewer === null) {
            return;
        }

        $before = $this->parseId($cursor, true);

        if ($before === null) {
            $this->notice($bot, 'صفحه موردنظر معتبر نیست.', $this->listKeyboard());

            return;
        }

        try {
            $page = $this->list->handle($viewer, self::PER_PAGE, $before === 0 ? null : $before);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (Throwable) {
            $this->notice($bot, 'دریافت فهرست فعلاً ممکن نیست. دوباره تلاش کنید.', $this->listKeyboard());

            return;
        }

        if ($page->items === []) {
            $this->notice(
                $bot,
                $before === 0
                    ? "هنوز سرویس یا سفارشی ندارید.\nاز بخش خرید می‌توانید سفارش جدید ثبت کنید."
                    : 'در این صفحه مورد دیگری وجود ندارد.',
                $this->listKeyboard(),
            );

            return;
        }

        $lines = ['📦 سرویس‌های من', '', 'سفارش‌ها و وضعیت ثبت‌شده سرویس‌های شما:', ''];
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($page->items as $item) {
            $status = $this->status($item->status);
            $name = $this->safeText($item->productName, 42);
            $lines[] = "{$status} · #{$item->orderId}";
            $lines[] = $name.' | '.($item->trafficGb === null ? 'نامحدود' : $item->trafficGb.' گیگ').' · '.$item->durationDays.' روز';
            $lines[] = '';
            $keyboard->addRow(InlineKeyboardButton::make(
                "{$this->statusIcon($item->status)} #{$item->orderId} · {$this->safeText($name, 23)}",
                callback_data: "subs:view:{$item->orderId}:{$cursor}",
            ));
        }

        $lines[] = 'لینک اتصال فقط برای اشتراک تأییدشده و فعال قابل دریافت است.';

        if ($page->nextBeforeOrderId !== null) {
            $keyboard->addRow(InlineKeyboardButton::make(
                'موارد بیشتر ◀️',
                callback_data: 'subs:list:'.$page->nextBeforeOrderId,
            ));
        }

        $keyboard->addRow(
            InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'subs:list:'.$cursor),
            InlineKeyboardButton::make('🏠 خانه', callback_data: 'menu:home'),
        );

        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    public function detail(Nutgram $bot, string $id, string $cursor = '0'): void
    {
        $viewer = $this->viewer($bot);

        if ($viewer === null) {
            return;
        }

        $orderId = $this->parseId($id);
        $before = $this->parseId($cursor, true);

        if ($orderId === null || $before === null) {
            $this->notice($bot, 'سرویس موردنظر معتبر نیست.', $this->listKeyboard());

            return;
        }

        try {
            $detail = $this->get->handle($viewer, $orderId);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (ValidationException) {
            $this->notice($bot, 'سرویس موردنظر پیدا نشد یا به حساب شما تعلق ندارد.', $this->backKeyboard($cursor));

            return;
        } catch (Throwable) {
            $this->notice($bot, 'نمایش جزئیات فعلاً ممکن نیست.', $this->backKeyboard($cursor));

            return;
        }

        $item = $detail->summary;
        $lines = [
            '📋 جزئیات سرویس #'.$item->orderId,
            '',
            'سرویس: '.$this->safeText($item->productName, 80),
            'وضعیت: '.$this->status($item->status),
            'حجم: '.($item->trafficGb === null ? 'نامحدود' : $item->trafficGb.' گیگابایت'),
            'دوره: '.$item->durationDays.' روز',
            'مبلغ سفارش: '.$this->toman($item->totalPriceRials).' تومان',
            'تاریخ سفارش: '.PersianCalendar::date(substr($item->orderedAt, 0, 10)).' (شمسی)',
            '',
            $detail->canRequestLink
                ? 'برای اتصال، لینک اختصاصی یا سرورهای مجاز را انتخاب کنید.'
                : 'لینک اتصال پس از پرداخت و فعال‌سازی تأییدشده در دسترس خواهد بود.',
        ];
        $keyboard = InlineKeyboardMarkup::make();

        if ($detail->canRequestLink) {
            $keyboard->addRow(InlineKeyboardButton::make(
                '🔗 دریافت لینک اتصال',
                callback_data: "subs:link:{$orderId}:{$cursor}",
            ));
            $keyboard->addRow(InlineKeyboardButton::make(
                '🌐 سرورهای در دسترس',
                callback_data: "subs:nodes:{$orderId}:{$cursor}",
            ));
        }

        $keyboard->addRow(
            InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'subs:list:'.$cursor),
            InlineKeyboardButton::make('🏠 خانه', callback_data: 'menu:home'),
        );

        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    public function link(Nutgram $bot, string $id, string $cursor = '0'): void
    {
        $viewer = $this->viewer($bot);

        if ($viewer === null) {
            return;
        }

        $orderId = $this->parseId($id);

        if ($orderId === null || $this->parseId($cursor, true) === null) {
            $this->notice($bot, 'درخواست معتبر نیست.', $this->listKeyboard());

            return;
        }

        try {
            // A previous inline button is never proof of active service access.
            $link = $this->connection->handle($viewer, $orderId);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (ValidationException) {
            $this->notice($bot, 'سرویس موردنظر در دسترس نیست.', $this->backKeyboard($cursor));

            return;
        } catch (Throwable) {
            $this->notice($bot, 'لینک اتصال فعلاً در دسترس نیست. کمی بعد دوباره تلاش کنید.', $this->backKeyboard($cursor));

            return;
        }

        $this->render(
            $bot,
            "🔗 لینک اتصال #{$orderId}\n\nلینک اختصاصی در یک پیام جداگانه ارسال شد.",
            $this->backKeyboard($cursor, $orderId),
        );

        // Only explicit private-chat requests reach this point; prevent Telegram
        // from fetching URL previews. Never log or put credentials in callback_data.
        $bot->sendMessage(
            text: "🔐 لینک اختصاصی سرویس #{$orderId}\n\n{$link->subscriptionUrl}\n\nاین لینک خصوصی است؛ آن را در اختیار دیگران قرار ندهید.",
            link_preview_options: LinkPreviewOptions::make(is_disabled: true),
        );
    }

    public function nodes(Nutgram $bot, string $id, string $cursor = '0'): void
    {
        $viewer = $this->viewer($bot);

        if ($viewer === null) {
            return;
        }

        $orderId = $this->parseId($id);

        if ($orderId === null || $this->parseId($cursor, true) === null) {
            $this->notice($bot, 'درخواست معتبر نیست.', $this->listKeyboard());

            return;
        }

        try {
            $nodes = $this->nodes->handle($viewer, $orderId);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (ValidationException) {
            $this->notice($bot, 'سرویس موردنظر در دسترس نیست.', $this->backKeyboard($cursor));

            return;
        } catch (Throwable) {
            $this->notice($bot, 'فهرست سرورها فعلاً در دسترس نیست.', $this->backKeyboard($cursor, $orderId));

            return;
        }

        $lines = ["🌐 سرورهای در دسترس · #{$orderId}", ''];

        if ($nodes === []) {
            $lines[] = 'در حال حاضر سروری برای این سرویس گزارش نشده است.';
        }

        foreach ($nodes as $node) {
            $lines[] = '• '.$this->safeText($node->name, 80).' · '.$node->countryCode;
        }

        $this->render($bot, implode("\n", $lines), $this->backKeyboard($cursor, $orderId));
    }

    private function viewer(Nutgram $bot): ?SubscriptionViewer
    {
        $id = $this->privateUser->id($bot);

        return $id === null ? null : SubscriptionViewer::telegram($id);
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

    private function notice(Nutgram $bot, string $message, InlineKeyboardMarkup $keyboard): void
    {
        $this->render($bot, "📦 سرویس‌های من\n\n{$message}", $keyboard);
    }

    private function listKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('📦 فهرست سرویس‌ها', callback_data: 'subs:list:0'))
            ->addRow(InlineKeyboardButton::make('🛒 خرید اشتراک', callback_data: 'menu:shop'))
            ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'menu:home'));
    }

    private function backKeyboard(string $cursor, ?int $orderId = null): InlineKeyboardMarkup
    {
        $back = $orderId === null ? 'subs:list:'.$cursor : "subs:view:{$orderId}:{$cursor}";

        return InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make('◀️ بازگشت', callback_data: $back),
            InlineKeyboardButton::make('🏠 خانه', callback_data: 'menu:home'),
        );
    }

    private function parseId(string $value, bool $zeroAllowed = false): ?int
    {
        if ($zeroAllowed && $value === '0') {
            return 0;
        }

        return preg_match('/\A[1-9][0-9]{0,14}\z/D', $value) === 1 ? (int) $value : null;
    }

    private function safeText(string $text, int $max): string
    {
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}]+/u', ' ', $text));

        return mb_substr($clean === '' ? 'سرویس' : $clean, 0, $max);
    }

    private function status(string $status): string
    {
        return match ($status) {
            'pending_payment' => '🟠 در انتظار پرداخت',
            'provisioning' => '🔵 در حال آماده‌سازی',
            'active' => '🟢 فعال (وضعیت ثبت‌شده)',
            'needs_review' => '🟡 در حال بررسی',
            'failed' => '🔴 ناموفق یا لغوشده',
            default => '⚪ نامشخص',
        };
    }

    private function statusIcon(string $status): string
    {
        return match ($status) {
            'pending_payment' => '🟠',
            'provisioning' => '🔵',
            'active' => '🟢',
            'needs_review' => '🟡',
            'failed' => '🔴',
            default => '⚪',
        };
    }

    private function toman(int $rials): string
    {
        return number_format(intdiv($rials, 10) + ($rials % 10 === 0 ? 0 : 1));
    }
}

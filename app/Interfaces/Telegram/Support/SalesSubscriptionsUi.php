<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Provisioning\Application\GetMySubscription;
use App\Modules\Provisioning\Application\GetSubscriptionLink;
use App\Modules\Provisioning\Application\GetSubscriptionUsage;
use App\Modules\Provisioning\Application\ListMyServices;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\LinkPreviewOptions;
use Throwable;

/**
 * Sales-only navigation over the shared subscription ownership/read actions.
 * No Admin menu, raw provider DTO, new business rules or provider writes.
 */
final readonly class SalesSubscriptionsUi
{
    private const PER_PAGE = 6;

    public function __construct(
        private PrivateTelegramUser $privateUser,
        private ListMyServices $list,
        private GetMySubscription $details,
        private GetSubscriptionUsage $usage,
        private GetSubscriptionLink $link,
    ) {}

    public function browse(Nutgram $bot, string $cursor = '0'): void
    {
        $viewer = $this->viewer($bot);
        if ($viewer === null) {
            return;
        }

        $before = $this->id($cursor, true);
        if ($before === null) {
            $this->notice($bot, 'صفحه انتخاب‌شده معتبر نیست.', $this->homeButtons());

            return;
        }

        try {
            $counts = $this->list->counts($viewer);
            $page = $this->list->handle($viewer, self::PER_PAGE, $before === 0 ? null : $before);
        } catch (AuthorizationException) {
            $this->notice($bot, 'برای استفاده، حساب فعال خود را متصل کنید.', $this->homeButtons());

            return;
        } catch (Throwable) {
            $this->notice($bot, 'اطلاعات سرویس‌ها فعلاً در دسترس نیست؛ دوباره تلاش کنید.', $this->homeButtons());

            return;
        }

        $lines = [
            '<b>📋 سرویس‌های من</b>',
            '',
            'سرویس‌های ثبت‌شده: <b>'.$counts['total'].'</b>',
            'فعال در سوابق: '.$counts['activeRecorded'],
        ];
        if ($counts['needsReview'] > 0) {
            $lines[] = 'در انتظار بررسی: '.$counts['needsReview'];
        }
        $lines[] = '';
        $keyboard = InlineKeyboardMarkup::make();

        if ($page->items === []) {
            $lines[] = $before === 0 ? 'هنوز سرویس فعالی برای این حساب ثبت نشده است.'
                : 'مورد دیگری در این صفحه وجود ندارد.';
            $lines[] = 'سفارش‌های در انتظار پرداخت در «سوابق خرید» هستند.';
        } else {
            foreach ($page->items as $item) {
                $label = 'سرویس #'.$item->orderId;
                $summary = $item->trafficGb === null ? 'حجم نامحدود' : $item->trafficGb.' گیگ';
                $lines[] = '🔹 <b>'.TelegramUi::escape($label).'</b> · '.TelegramUi::escape($this->clean($item->productName));
                $lines[] = $summary.' · '.$item->durationDays.' روز · '.$this->status($item->status);
                $lines[] = '';
                $keyboard->addRow(InlineKeyboardButton::make(
                    $label.' · '.$this->shortStatus($item->status),
                    callback_data: 'sales:subs:view:'.$item->orderId.':'.$cursor,
                ));
            }
            $lines[] = '<blockquote>وضعیت لحظه‌ای و تاریخ انقضا در جزئیات هر سرویس بررسی می‌شود.</blockquote>';
            if ($page->nextBeforeOrderId !== null) {
                $keyboard->addRow(InlineKeyboardButton::make('موارد بیشتر ◀️',
                    callback_data: 'sales:subs:list:'.$page->nextBeforeOrderId));
            }
        }

        $keyboard->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'sales:subs:list:'.$cursor))
            ->addRow(InlineKeyboardButton::make('🛒 خرید سرویس', callback_data: 'menu:plan-catalog'))
            ->addRow(InlineKeyboardButton::make('🧾 سوابق خرید', callback_data: 'sales:checkout:orders'))
            ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home'));

        $this->render($bot, trim(implode("\n", $lines)), $keyboard);
    }

    public function detail(Nutgram $bot, string $id, string $cursor = '0'): void
    {
        $viewer = $this->viewer($bot);
        if ($viewer === null) {
            return;
        }

        $orderId = $this->id($id);
        if ($orderId === null || $this->id($cursor, true) === null) {
            $this->notice($bot, 'اشتراک انتخاب‌شده معتبر نیست.', $this->homeButtons());

            return;
        }

        try {
            $details = $this->details->handle($viewer, $orderId);
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'این اشتراک در دسترس حساب شما نیست.', $this->backButtons($cursor));

            return;
        } catch (Throwable) {
            $this->notice($bot, 'نمایش جزئیات فعلاً ممکن نیست.', $this->backButtons($cursor));

            return;
        }

        $item = $details->summary;
        $lines = [
            '<b>📋 سرویس #'.$item->orderId.'</b>',
            '',
            'پلن: '.TelegramUi::escape($this->clean($item->productName)),
            'وضعیت ثبت‌شده: '.$this->status($item->status),
            'حجم خرید: '.($item->trafficGb === null ? 'نامحدود' : $item->trafficGb.' گیگابایت'),
            'مدت خرید: '.$item->durationDays.' روز',
            'مبلغ: <b>'.TelegramUi::toman($item->totalPriceRials).' تومان</b>',
            '',
        ];
        $keyboard = InlineKeyboardMarkup::make();
        $acknowledged = false;

        if ($details->canRequestLink) {
            TelegramUi::answerCallback($bot);
            $acknowledged = $bot->isCallbackQuery();
            TelegramUi::activity($bot);
            try {
                $live = $this->usage->handle($viewer, $orderId);
                $lines[] = 'وضعیت لحظه‌ای: 🟢 فعال (بررسی‌شده)';
                $lines[] = 'مصرف: '.$this->gb($live->usedBytes).' گیگابایت';
                $lines[] = 'باقی‌مانده: '.($live->limitBytes === 0
                    ? 'نامحدود'
                    : $this->gb(max(0, $live->limitBytes - $live->usedBytes)).' گیگابایت');
                $lines[] = 'انقضا (UTC): '.substr(str_replace('T', ' ', $live->expireAt), 0, 16);
                $keyboard->addRow(InlineKeyboardButton::make(
                    '🔐 نمایش کد اتصال',
                    callback_data: 'sales:subs:link:'.$orderId.':'.$cursor,
                ));
            } catch (Throwable) {
                $lines[] = 'اطلاعات لحظه‌ای مصرف و انقضا در دسترس نیست.';
                $lines[] = 'وضعیت ثبت‌شده به معنی اتصال تأییدشده نیست.';
                $lines[] = 'برای دریافت لینک، کمی بعد دوباره بررسی کنید.';
            }
        } else {
            $lines[] = 'لینک اتصال فقط پس از پرداخت و فعال‌سازی در دسترس است.';
            $keyboard->addRow(InlineKeyboardButton::make(
                '📋 وضعیت سفارش و پرداخت',
                callback_data: 'sales:checkout:view:'.$orderId,
            ));
        }

        $keyboard->addRow(InlineKeyboardButton::make('📚 آموزش اتصال', callback_data: 'sales:help:apps'))
            ->addRow(InlineKeyboardButton::make('💬 پشتیبانی', callback_data: 'sales:support'))
            ->addRow(InlineKeyboardButton::make('🔄 بررسی دوباره', callback_data: 'sales:subs:view:'.$orderId.':'.$cursor))
            ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'sales:subs:list:'.$cursor))
            ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home'));

        $this->render($bot, implode("\n", $lines), $keyboard, ! $acknowledged);
    }

    public function link(Nutgram $bot, string $id, string $cursor = '0'): void
    {
        $viewer = $this->viewer($bot);
        if ($viewer === null) {
            return;
        }

        $orderId = $this->id($id);
        if ($orderId === null || $this->id($cursor, true) === null) {
            $this->notice($bot, 'درخواست معتبر نیست.', $this->homeButtons());

            return;
        }

        try {
            // A visible button is never authorization; every request rechecks
            // local ownership, active provider identity and expiry.
            $link = $this->link->handle($viewer, $orderId);
            if (strlen($link->subscriptionUrl) > 2048) {
                throw new \RuntimeException('Invalid connection URL length.');
            }
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'لینک این اشتراک برای حساب شما در دسترس نیست.', $this->backButtons($cursor));

            return;
        } catch (Throwable) {
            $this->notice($bot, 'لینک اتصال فعلاً در دسترس نیست. کمی بعد دوباره تلاش کنید.', $this->backButtons($cursor));

            return;
        }

        // Credentials only in the explicit private delivery message. The native
        // Telegram copy button is available for URLs within its 256-char limit.
        // Longer URLs stay selectable in the message, never in callback data.
        $canCopy = mb_strlen($link->subscriptionUrl) <= 256;
        $keyboard = InlineKeyboardMarkup::make();
        if ($canCopy) {
            $keyboard->addRow(TelegramUi::copy($link->subscriptionUrl, '📋 کپی لینک'));
        }
        $keyboard->addRow(InlineKeyboardButton::make('📚 آموزش اتصال', callback_data: 'sales:help:apps'));
        $keyboard->addRow(InlineKeyboardButton::make('💬 پشتیبانی', callback_data: 'sales:support'));
        $keyboard->addRow(InlineKeyboardButton::make(
            '◀️ اشتراک من', callback_data: 'sales:subs:view:'.$orderId.':'.$cursor,
        ));

        TelegramUi::answerCallback($bot);
        $bot->sendMessage(
            text: "🔐 لینک اختصاصی اشتراک #{$orderId}\n\n{$link->subscriptionUrl}\n\n"
                .($canCopy ? 'برای کپی، دکمه زیر را انتخاب کنید.' : 'برای کپی، متن لینک را نگه دارید.')
                ."\nاین لینک خصوصی است؛ با دیگران به اشتراک نگذارید.",
            link_preview_options: LinkPreviewOptions::make(is_disabled: true),
            reply_markup: $keyboard,
        );
    }

    private function viewer(Nutgram $bot): ?SubscriptionViewer
    {
        $id = $this->privateUser->id($bot);

        return $id === null ? null : SubscriptionViewer::telegram($id);
    }

    private function id(string $value, bool $allowZero = false): ?int
    {
        if ($allowZero && $value === '0') {
            return 0;
        }

        return preg_match('/\A[1-9][0-9]{0,14}\z/D', $value) === 1 ? (int) $value : null;
    }

    private function clean(string $value): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}]+/u', ' ', $value)), 0, 70);
    }

    private function shortStatus(string $status): string
    {
        return match ($status) {
            'active' => 'فعال ثبت‌شده',
            'provisioning' => 'در حال آماده‌سازی',
            'needs_review' => 'در حال بررسی',
            'failed' => 'غیرفعال',
            default => 'نامشخص',
        };
    }

    private function status(string $status): string
    {
        return match ($status) {
            'pending_payment' => '🟠 در انتظار پرداخت',
            'provisioning' => '🔵 در انتظار فعال‌سازی',
            'active' => '🟢 فعال ثبت‌شده',
            'needs_review' => '🟡 در حال بررسی',
            'failed' => '🔴 غیرفعال یا ناموفق',
            default => '⚪ نامشخص',
        };
    }

    private function gb(int $bytes): string
    {
        return number_format($bytes / 1073741824, 2);
    }

    private function homeButtons(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('📦 اشتراک‌ها', callback_data: 'sales:subs:list:0'))
            ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home'));
    }

    private function backButtons(string $cursor): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'sales:subs:list:'.$cursor))
            ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home'));
    }

    private function notice(Nutgram $bot, string $message, InlineKeyboardMarkup $keyboard): void
    {
        $this->render($bot, "📋 سرویس‌های من\n\n".$message, $keyboard);
    }

    private function render(Nutgram $bot, string $message, InlineKeyboardMarkup $keyboard, bool $acknowledge = true): void
    {
        if ($bot->isCallbackQuery()) {
            if ($acknowledge) {
                TelegramUi::answerCallback($bot);
            }
            TelegramUi::editText($bot, $message, $keyboard, 'HTML');

            return;
        }

        $bot->sendMessage(text: $message, reply_markup: $keyboard, parse_mode: 'HTML');
    }
}

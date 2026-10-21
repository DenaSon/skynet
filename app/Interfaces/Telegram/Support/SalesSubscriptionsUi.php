<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Provisioning\Application\GetMySubscription;
use App\Modules\Provisioning\Application\GetSubscriptionLink;
use App\Modules\Provisioning\Application\GetSubscriptionUsage;
use App\Modules\Provisioning\Application\ListMyServices;
use App\Modules\Sales\Application\CreatePendingRenewalOrder;
use App\Modules\Sales\Application\PrepareRenewalDraft;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
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
        private SubscriptionPageLink $pages,
        private PrepareRenewalDraft $renewals,
        private CreatePendingRenewalOrder $renewalOrders,
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
                if ($item->status === 'active') {
                    // Never expose bearer credentials in the directory. The
                    // private link Action rechecks owner and remote expiry.
                    $keyboard->addRow(
                        InlineKeyboardButton::make('📋 جزئیات #'.$item->orderId,
                            callback_data: 'sales:subs:view:'.$item->orderId.':'.$cursor),
                        InlineKeyboardButton::make('🔐 لینک #'.$item->orderId,
                            callback_data: 'sales:subs:link:'.$item->orderId.':'.$cursor),
                    );
                } else {
                    $keyboard->addRow(InlineKeyboardButton::make(
                        $label.' · '.$this->shortStatus($item->status),
                        callback_data: 'sales:subs:view:'.$item->orderId.':'.$cursor,
                    ));
                }
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
            ...($details->canRequestLink ? [] : ['وضعیت ثبت‌شده: '.$this->status($item->status)]),
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
                $expired = (new DateTimeImmutable($live->expireAt)) <= new DateTimeImmutable('now');
                $exhausted = $live->limitBytes > 0 && $live->usedBytes >= $live->limitBytes;
                $usable = $live->status === 'ACTIVE' && ! $expired && ! $exhausted;

                $status = match (true) {
                    $live->status === 'EXPIRED', $expired => '🔴 منقضی شده',
                    $live->status === 'DISABLED' => '⏸️ غیرفعال',
                    $live->status === 'LIMITED' => '🟠 محدود شده',
                    $exhausted => '🟠 حجم به پایان رسیده',
                    default => '🟢 فعال',
                };
                $lines[] = 'وضعیت سرویس: <b>'.$status.'</b>';
                $lines[] = 'مصرف: '.$this->gb($live->usedBytes).' گیگابایت';
                $lines[] = 'باقی‌مانده: '.($live->limitBytes === 0
                    ? 'نامحدود'
                    : $this->gb(max(0, $live->limitBytes - $live->usedBytes)).' گیگابایت');
                $lines[] = 'تاریخ انقضا (UTC): '.substr(str_replace('T', ' ', $live->expireAt), 0, 16);
                $canRenew = in_array($live->status, ['ACTIVE', 'LIMITED', 'EXPIRED'], true)
                    && $this->renewals->available($viewer, $orderId);
                if ($canRenew) {
                    $keyboard->addRow(InlineKeyboardButton::make(
                        '🔄 بررسی تمدید',
                        callback_data: 'sales:subs:renew:'.$orderId.':'.$cursor,
                        style: ButtonStyle::PRIMARY,
                    ));
                }
                if ($usable) {
                    $keyboard->addRow(InlineKeyboardButton::make(
                        '🔐 نمایش کد اتصال',
                        callback_data: 'sales:subs:link:'.$orderId.':'.$cursor,
                    ));
                    if ($this->pages->configured()) {
                        $keyboard->addRow(InlineKeyboardButton::make(
                            '🌐 صفحه اشتراک',
                            callback_data: 'sales:subs:page:'.$orderId.':'.$cursor,
                        ));
                    }
                } else {
                    $lines[] = '';
                    $lines[] = '<blockquote>این سرویس در حال حاضر قابل اتصال نیست. برای خرید سرویس جدید یا دریافت راهنمایی از دکمه‌های زیر استفاده کنید.</blockquote>';
                    $keyboard->addRow(InlineKeyboardButton::make('🟢 خرید سرویس', callback_data: 'sales:buy'));
                }
            } catch (Throwable) {
                $lines[] = 'وضعیت سرویس: <b>⚪ فعلاً قابل بررسی نیست</b>';
                $lines[] = '<blockquote>دریافت وضعیت لحظه‌ای ممکن نشد. کمی بعد «بررسی دوباره» را بزنید.</blockquote>';
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

    /** REN-01: quote a non-payable renewal draft; no bank, wallet or RW mutation. */
    public function renewal(Nutgram $bot, string $id, string $cursor = '0'): void
    {
        $viewer = $this->viewer($bot);
        if ($viewer === null) {
            return;
        }

        $sourceId = $this->id($id);
        if ($sourceId === null || $this->id($cursor, true) === null) {
            $this->notice($bot, 'این سرویس برای تمدید در دسترس نیست.', $this->homeButtons());

            return;
        }

        TelegramUi::answerCallback($bot);
        $acknowledged = $bot->isCallbackQuery();
        TelegramUi::activity($bot);

        try {
            $draft = $this->renewals->handle($viewer, $sourceId);
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'تمدید این سرویس با پلن فعلی ممکن نیست. برای راهنمایی از پشتیبانی کمک بگیرید.',
                $this->renewalButtons($sourceId, $cursor));

            return;
        } catch (Throwable) {
            $this->notice($bot, 'استعلام تمدید فعلاً در دسترس نیست. کمی بعد دوباره تلاش کنید.',
                $this->renewalButtons($sourceId, $cursor));

            return;
        }

        $name = TelegramUi::escape($this->clean($draft->planName));
        $traffic = $draft->trafficGb === null ? 'نامحدود' : $draft->trafficGb.' گیگابایت';
        $message = '<b>🔄 پیش‌نمایش تمدید سرویس #'.$draft->sourceOrderId.'</b>'
            ."\n\nپلن: {$name}"
            ."\nمدت تمدید: {$draft->durationDays} روز"
            ."\nحجم پلن: {$traffic}"
            ."\nقیمت فعلی: <b>".TelegramUi::toman($draft->priceRials).' تومان</b>'
            ."\n\n<blockquote>این پیش‌نمایش فقط برای همین سرویس ذخیره شد."
            .(config('sales.renewal_enabled', false) === true
                ? ' پرداخت بعد از تأیید شما آغاز می‌شود؛ اعتبار همان سرویس افزایش خواهد یافت.</blockquote>'
                : ' پرداخت تمدید هنوز فعال نیست و هیچ مبلغی دریافت نشده است.</blockquote>');

        $buttons = $this->renewalButtons($sourceId, $cursor);
        if (config('sales.renewal_enabled', false) === true) {
            $buttons = InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ ادامه و انتخاب پرداخت',
                    callback_data: 'sales:subs:renew:checkout:'.$sourceId.':'.$cursor,
                    style: ButtonStyle::SUCCESS))
                ->addRow(InlineKeyboardButton::make('🔄 بررسی دوباره',
                    callback_data: 'sales:subs:renew:'.$sourceId.':'.$cursor))
                ->addRow(InlineKeyboardButton::make('◀️ بازگشت به سرویس',
                    callback_data: 'sales:subs:view:'.$sourceId.':'.$cursor))
                ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home'));
        }

        $this->render($bot, $message, $buttons, ! $acknowledged);
    }

    /** Explicit, private conversion. Never posts a payment without a separate confirmation. */
    public function renewalCheckout(Nutgram $bot, string $id, string $cursor = '0'): void
    {
        $viewer = $this->viewer($bot);
        if ($viewer === null) {
            return;
        }
        $sourceId = $this->id($id);
        if ($sourceId === null || $this->id($cursor, true) === null) {
            $this->notice($bot, 'این سرویس برای تمدید در دسترس نیست.', $this->homeButtons());

            return;
        }

        try {
            $order = $this->renewalOrders->handle($viewer, $sourceId);
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'قیمت یا وضعیت اشتراک تغییر کرده است. دوباره تمدید را بررسی کنید.',
                $this->renewalButtons($sourceId, $cursor));

            return;
        } catch (Throwable) {
            $this->notice($bot, 'آماده‌سازی تمدید فعلاً ممکن نیست. دوباره تلاش کنید.',
                $this->renewalButtons($sourceId, $cursor));

            return;
        }

        app(SalesCheckoutUi::class)->viewOrder($bot, (string) $order->id);
    }

    private function renewalButtons(int $sourceId, string $cursor): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 بررسی دوباره',
                callback_data: 'sales:subs:renew:'.$sourceId.':'.$cursor))
            ->addRow(InlineKeyboardButton::make('◀️ بازگشت به سرویس',
                callback_data: 'sales:subs:view:'.$sourceId.':'.$cursor))
            ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home'));
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

    public function page(Nutgram $bot, string $id, string $cursor = '0'): void
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

        if (! $this->pages->configured()) {
            $this->notice($bot, 'صفحه اشتراک هنوز آماده نیست. از نمایش کد اتصال استفاده کنید.',
                $this->backButtons($cursor));

            return;
        }

        try {
            // The existing credential Action freshly verifies the private buyer,
            // linked order, current provider status, expiry and unchanged identity.
            $url = $this->link->handle($viewer, $orderId)->subscriptionUrl;
            if (! $this->pages->matches($url)) {
                throw new \RuntimeException('Subscription Page host mismatch.');
            }
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'صفحه این اشتراک برای حساب شما در دسترس نیست.', $this->backButtons($cursor));

            return;
        } catch (Throwable) {
            $this->notice($bot, 'صفحه اشتراک فعلاً در دسترس نیست. برای اتصال از لینک اختصاصی استفاده کنید.',
                $this->backButtons($cursor));

            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🌐 باز کردن صفحه اشتراک', url: $url));

        if (mb_strlen($url) <= 256) {
            $keyboard->addRow(TelegramUi::copy($url, '📋 کپی لینک اشتراک'));
        }

        $keyboard->addRow(InlineKeyboardButton::make('🔐 نمایش کد اتصال',
            callback_data: 'sales:subs:link:'.$orderId.':'.$cursor))
            ->addRow(InlineKeyboardButton::make('📚 آموزش اتصال', callback_data: 'sales:help:apps'))
            ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'sales:subs:view:'.$orderId.':'.$cursor));

        TelegramUi::answerCallback($bot);
        $bot->sendMessage(
            text: "🌐 صفحه اختصاصی سرویس #{$orderId}\n\n"
                ."با دکمه زیر صفحه وضعیت و راهنمای اتصال را باز کنید.\n"
                .(mb_strlen($url) <= 256 ? 'برای کپی لینک از دکمه استفاده کنید.' : "لینک قابل انتخاب:\n{$url}")
                ."\nاین لینک خصوصی است؛ آن را به دیگران ندهید.",
            link_preview_options: LinkPreviewOptions::make(is_disabled: true),
            reply_markup: $keyboard,
            protect_content: true,
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

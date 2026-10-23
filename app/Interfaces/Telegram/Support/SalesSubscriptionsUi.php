<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Provisioning\Application\GetMySubscription;
use App\Modules\Provisioning\Application\GetSubscriptionLink;
use App\Modules\Provisioning\Application\GetSubscriptionUsage;
use App\Modules\Provisioning\Application\ListMyServices;
use App\Modules\Sales\Application\CreatePendingRenewalOrder;
use App\Modules\Sales\Application\ManageResellerServiceLabels;
use App\Modules\Sales\Application\PrepareRenewalDraft;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
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
        private SubscriptionQrPng $qr,
        private SubscriptionExpiryDisplay $expiryDisplay,
        private SubscriptionPageLink $pages,
        private PrepareRenewalDraft $renewals,
        private CreatePendingRenewalOrder $renewalOrders,
        private ResolveTelegramActor $actors,
        private ManageResellerServiceLabels $customerLabels,
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

        $resellerId = $this->resellerId($bot);
        $labels = $resellerId === null ? [] : $this->customerLabels->forOrders(
            $resellerId, array_map(static fn ($item): int => $item->orderId, $page->items),
        );

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
            foreach ($page->items as $index => $item) {
                // Page position is only a visible navigation aid, never the order identifier.
                $position = $index + 1;
                $planName = $this->clean($item->productName);
                $customerLabel = $labels[$item->orderId] ?? null;
                $title = $customerLabel === null ? $planName : $this->clean($customerLabel);
                $summary = $item->trafficGb === null ? 'حجم نامحدود' : $item->trafficGb.' گیگ';
                $lines[] = '🔹 <b>'.$position.'. '.($customerLabel === null ? '' : '👤 ').TelegramUi::escape($title).'</b>';
                if ($customerLabel !== null) {
                    $lines[] = 'پلن: '.TelegramUi::escape($planName);
                }
                $lines[] = $summary.' · '.$item->durationDays.' روز · '.$this->status($item->status);
                $lines[] = '';
                // One entry per service: the detail view retains link, renewal
                // and customer-edit actions, with fresh owner/provider checks.
                $buttonTitle = $resellerId !== null
                    ? '👤 '.($customerLabel === null
                        ? 'مشتری بدون نام'
                        : mb_strimwidth($this->clean($customerLabel), 0, 36, '…', 'UTF-8'))
                    : '📦 '.mb_strimwidth($planName, 0, 36, '…', 'UTF-8');
                $keyboard->addRow(InlineKeyboardButton::make(
                    $buttonTitle.' · '.$position,
                    callback_data: 'sales:subs:view:'.$item->orderId.':'.$cursor,
                ));
            }
            $lines[] = '<blockquote>وضعیت لحظه‌ای و تاریخ انقضا در جزئیات هر سرویس بررسی می‌شود.</blockquote>';
            if ($page->nextBeforeOrderId !== null) {
                $keyboard->addRow(InlineKeyboardButton::make('موارد بیشتر ◀️',
                    callback_data: 'sales:subs:list:'.$page->nextBeforeOrderId));
            }
        }

        if ($resellerId !== null) {
            $keyboard->addRow(InlineKeyboardButton::make('🔎 جستجوی مشتری', callback_data: 'sales:subs:search:start'));
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
        $resellerId = $this->resellerId($bot);
        $customerLabel = $resellerId === null ? null : $this->customerLabels->get($resellerId, $orderId);
        $customerPhone = $resellerId === null ? null : $this->customerLabels->phone($resellerId, $orderId);
        $lines = [
            '<b>📋 '.($customerLabel === null ? TelegramUi::escape($this->clean($item->productName)) : '👤 '.TelegramUi::escape($this->clean($customerLabel))).'</b>',
            '',
            'پلن: '.TelegramUi::escape($this->clean($item->productName)),
            ...($customerPhone === null ? [] : ['📱 موبایل مشتری: <code>'.TelegramUi::escape($customerPhone).'</code>']),
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
                $expiresAt = new DateTimeImmutable($live->expireAt);
                $now = new DateTimeImmutable('now');
                $expired = $expiresAt <= $now;
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
                array_push($lines, ...$this->expiryDisplay->lines($expiresAt, $now));
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

        if ($resellerId !== null) {
            $keyboard->addRow(InlineKeyboardButton::make(
                '✏️ ویرایش نام مشتری',
                callback_data: 'sales:subs:label:edit:'.$orderId.':'.$cursor,
            ));
            $keyboard->addRow(InlineKeyboardButton::make(
                $customerPhone === null ? '📱 افزودن شماره موبایل' : '📱 ویرایش شماره موبایل',
                callback_data: 'sales:subs:phone:edit:'.$orderId.':'.$cursor,
            ));
        }

        $keyboard->addRow(InlineKeyboardButton::make('🔄 بررسی دوباره', callback_data: 'sales:subs:view:'.$orderId.':'.$cursor))
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

        // Both roles use the same owner-checked Action and presentation path.
        // The media caption contains the exact URL below its QR, not a second
        // independent credential message.
        TelegramUi::answerCallback($bot);
        $this->deliverLinkCard($bot, $orderId, $link->subscriptionUrl, $cursor, false);
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

        TelegramUi::answerCallback($bot);
        $this->deliverLinkCard($bot, $orderId, $url, $cursor, true);
    }

    /**
     * Presentation only: never retrieve or persist credentials here. Caller has
     * already passed fresh ownership and remote subscription verification.
     */
    private function deliverLinkCard(
        Nutgram $bot,
        int $orderId,
        string $url,
        string $cursor,
        bool $subscriptionPage,
    ): void {
        $canCopy = mb_strlen($url) <= 256;
        $keyboard = InlineKeyboardMarkup::make();
        if ($subscriptionPage) {
            $keyboard->addRow(InlineKeyboardButton::make('🌐 باز کردن صفحه اشتراک', url: $url));
        }
        if ($canCopy) {
            $keyboard->addRow(TelegramUi::copy($url, '📋 کپی لینک'));
        }
        if ($subscriptionPage) {
            $keyboard->addRow(InlineKeyboardButton::make('🔐 نمایش کد اتصال',
                callback_data: 'sales:subs:link:'.$orderId.':'.$cursor));
        }
        $keyboard->addRow(InlineKeyboardButton::make('📚 آموزش اتصال', callback_data: 'sales:help:apps'));
        if (! $subscriptionPage) {
            $keyboard->addRow(InlineKeyboardButton::make('💬 پشتیبانی', callback_data: 'sales:support'));
        }
        $keyboard->addRow(InlineKeyboardButton::make(
            '◀️ بازگشت به سرویس', callback_data: 'sales:subs:view:'.$orderId.':'.$cursor,
        ));

        $heading = $subscriptionPage ? '🌐 صفحه اشتراک' : '🔐 لینک اتصال';
        $caption = "<b>{$heading} #{$orderId}</b>\n\n".TelegramUi::code($url)
            ."\n\n📷 کد بالا را اسکن کنید یا لینک را کپی کنید."
            ."\n🔒 این لینک اختصاصی است؛ فقط با فرد موردنظر به اشتراک بگذارید.";

        // Telegram limits media captions to 1024 UTF-16 code units *after*
        // entity parsing. Use a conservative ceiling for emoji and Unicode.
        // Ordinary URLs fit in one photo+caption; exceptionally long URLs
        // retain their full selectable message and receive a separate QR.
        if (mb_strlen($caption) <= 900
            && $this->sendLinkPhoto($bot, $url, $caption, $keyboard, $subscriptionPage)) {
            return;
        }

        if (mb_strlen($caption) > 900) {
            $this->sendLinkPhoto(
                $bot, $url, '📷 کد اتصال این سرویس را اسکن کنید.', null, $subscriptionPage,
            );
        }

        // A failed GD render or Telegram media upload must never hide the URL.
        $bot->sendMessage(
            text: "<b>{$heading} #{$orderId}</b>\n\n".TelegramUi::code($url)
                ."\n\n".($canCopy ? 'برای کپی از دکمه زیر استفاده کنید.' : 'برای کپی، متن لینک را نگه دارید.')
                ."\n🔒 این لینک اختصاصی است؛ فقط با فرد موردنظر به اشتراک بگذارید.",
            parse_mode: 'HTML',
            link_preview_options: LinkPreviewOptions::make(is_disabled: true),
            reply_markup: $keyboard,
            protect_content: $subscriptionPage,
        );
    }

    private function sendLinkPhoto(
        Nutgram $bot,
        string $url,
        string $caption,
        ?InlineKeyboardMarkup $keyboard,
        bool $protectContent,
    ): bool {
        $stream = null;
        try {
            $png = $this->qr->render($url);
            $stream = fopen('php://memory', 'w+b');
            if ($stream === false || fwrite($stream, $png) !== strlen($png)) {
                throw new \RuntimeException('Unable to prepare connection QR.');
            }
            rewind($stream);
            $bot->sendPhoto(
                photo: InputFile::make($stream, 'denagate-subscription-qr.png'),
                caption: $caption,
                parse_mode: 'HTML',
                reply_markup: $keyboard,
                protect_content: $protectContent,
            );

            return true;
        } catch (Throwable) {
            // Media is best-effort. Never log the subscription URL or PNG.
            return false;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** Begin a short private customer-name search; no query is exposed in callback data. */
    public function beginCustomerSearch(Nutgram $bot): void
    {
        $buyerId = $this->resellerId($bot);
        if ($buyerId === null) {
            if ($this->privateUser->id($bot) !== null) {
                $this->notice($bot, 'جستجو فقط برای نمایندگان فعال است.', $this->homeButtons());
            }

            return;
        }

        $this->clearCustomerLabelDraft($buyerId);
        $this->clearCustomerPhoneDraft($buyerId);
        app(PendingBuilderSelection::class)->forget($buyerId);
        app(SalesPanelRequestUi::class)->clear($buyerId);
        Cache::forget(TelegramBotContext::current()->cacheKey('telegram:wallet:topup:'.$buyerId));
        Cache::put($this->searchKey($buyerId), ['mode' => 'input'], now()->addMinutes(10));

        $this->render($bot, '<b>🔎 جستجوی مشتری</b>'
            ."\n\nنام مشتری، بخشی از نام یا شماره موبایل را ارسال کنید."
            ."\nفقط اطلاعات مشتریان سرویس‌های شما جستجو می‌شوند."
            ."\n\n<blockquote>فرم تا ۱۰ دقیقه اعتبار دارد.</blockquote>",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'sales:subs:search:cancel'))
                ->addRow(InlineKeyboardButton::make('◀️ بازگشت به سرویس‌ها', callback_data: 'sales:subs:search:cancel')));
    }

    /** Consume only the pending search input; unrelated free-text routes remain untouched. */
    public function customerSearchText(Nutgram $bot, string $text): bool
    {
        $telegramId = $this->privateUser->id($bot);
        if ($telegramId === null || str_starts_with(trim($text), '/')) {
            return false;
        }

        $actor = $this->actors->handle($telegramId);
        if ($actor === null) {
            return false;
        }

        $state = Cache::get($this->searchKey($actor->userId));
        if (! is_array($state) || ($state['mode'] ?? null) !== 'input') {
            return false;
        }

        if (! in_array('reseller', $actor->roles, true)) {
            $this->clearCustomerSearch($actor->userId);
            $this->notice($bot, 'دسترسی پنل فروش برای این حساب فعال نیست.', $this->homeButtons());

            return true;
        }

        try {
            // Reuse the owner-scoped Action to validate search input before caching it.
            $this->customerLabels->search($actor->userId, $text, 1);
        } catch (ValidationException) {
            $this->notice($bot, 'عبارت جستجو باید بین ۱ تا ۶۰ کاراکتر و در یک خط باشد. دوباره وارد کنید.',
                InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'sales:subs:search:cancel')));

            return true;
        } catch (AuthorizationException) {
            $this->clearCustomerSearch($actor->userId);
            $this->notice($bot, 'جستجو برای این حساب در دسترس نیست.', $this->homeButtons());

            return true;
        }

        Cache::put($this->searchKey($actor->userId),
            ['mode' => 'results', 'term' => trim($text)], now()->addMinutes(10));
        $this->customerSearchResults($bot);

        return true;
    }

    public function customerSearchResults(Nutgram $bot, string $cursor = '0'): void
    {
        $buyerId = $this->resellerId($bot);
        if ($buyerId === null) {
            if ($this->privateUser->id($bot) !== null) {
                $this->notice($bot, 'جستجو برای این حساب در دسترس نیست.', $this->homeButtons());
            }

            return;
        }

        $before = $this->id($cursor, true);
        if ($before === null) {
            $this->notice($bot, 'صفحه انتخاب‌شده معتبر نیست.', $this->searchButtons());

            return;
        }

        $state = Cache::get($this->searchKey($buyerId));
        if (! is_array($state) || ($state['mode'] ?? null) !== 'results'
            || ! is_string($state['term'] ?? null)) {
            $this->notice($bot, 'زمان جستجو پایان یافته است. دوباره جستجو کنید.', $this->searchButtons());

            return;
        }

        try {
            $page = $this->customerLabels->search($buyerId, $state['term'], self::PER_PAGE, $before ?: null);
            $viewer = $this->viewer($bot);
            if ($viewer === null) {
                return;
            }

            $items = [];
            foreach ($page['items'] as $row) {
                // Existing Provisioning read Action freshly rechecks service ownership.
                $details = $this->details->handle($viewer, $row['orderId']);
                $items[] = [
                    'orderId' => $row['orderId'], 'name' => $row['label'],
                    'phone' => $row['phone'], 'summary' => $details->summary,
                ];
            }
        } catch (Throwable) {
            $this->notice($bot, 'نمایش نتایج فعلاً ممکن نیست. دوباره تلاش کنید.', $this->searchButtons());

            return;
        }

        $lines = ['<b>🔎 نتایج جستجو</b>', 'جستجو: <b>'.TelegramUi::escape($state['term']).'</b>', ''];
        $keyboard = InlineKeyboardMarkup::make();
        if ($items === []) {
            $lines[] = 'مشتری‌ای با این نام یا شماره در سرویس‌های شما پیدا نشد.';
        } else {
            foreach ($items as $index => $item) {
                $position = $index + 1;
                $summary = $item['summary'];
                $display = $item['name'] ?? $item['phone'] ?? 'مشتری بدون نام';
                $lines[] = '🔹 <b>'.$position.'. 👤 '.TelegramUi::escape($this->clean($display)).'</b>';
                if ($item['phone'] !== null && $item['name'] !== null) {
                    $lines[] = '📱 '.TelegramUi::escape($item['phone']);
                }
                $lines[] = TelegramUi::escape($this->clean($summary->productName))
                    .' · '.($summary->trafficGb === null ? 'نامحدود' : $summary->trafficGb.' گیگ')
                    .' · '.$this->status($summary->status);
                $lines[] = '';
                $keyboard->addRow(InlineKeyboardButton::make('📋 جزئیات '.$position,
                    callback_data: 'sales:subs:view:'.$item['orderId'].':0'));
            }

            if ($page['nextBeforeOrderId'] !== null) {
                $keyboard->addRow(InlineKeyboardButton::make('موارد بیشتر ◀️',
                    callback_data: 'sales:subs:search:page:'.$page['nextBeforeOrderId']));
            }
        }

        $keyboard->addRow(InlineKeyboardButton::make('🔎 جستجوی جدید', callback_data: 'sales:subs:search:start'))
            ->addRow(InlineKeyboardButton::make('◀️ بازگشت به سرویس‌ها', callback_data: 'sales:subs:search:cancel'))
            ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home'));
        $this->render($bot, trim(implode("\n", $lines)), $keyboard);
    }

    public function cancelCustomerSearch(Nutgram $bot): void
    {
        $id = $this->privateUser->id($bot);
        if ($id === null) {
            return;
        }
        $actor = $this->actors->handle($id);
        if ($actor !== null) {
            $this->clearCustomerSearch($actor->userId);
        }
        $this->browse($bot);
    }

    public function clearCustomerSearch(int $userId): void
    {
        Cache::forget($this->searchKey($userId));
    }

    private function searchKey(int $userId): string
    {
        return TelegramBotContext::current()->cacheKey('telegram:subs:customer_search:'.$userId);
    }

    private function searchButtons(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔎 جستجوی جدید', callback_data: 'sales:subs:search:start'))
            ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'sales:subs:list:0'));
    }

    /** Private, 10-minute phone editor for an owned, provisioned reseller service. */
    public function beginCustomerPhone(Nutgram $bot, string $id, string $cursor): void
    {
        $buyerId = $this->resellerId($bot);
        $orderId = $this->id($id);
        if ($buyerId === null || $orderId === null || $this->id($cursor, true) === null) {
            $this->notice($bot, 'این سرویس برای ویرایش اطلاعات مشتری در دسترس نیست.', $this->homeButtons());

            return;
        }
        try {
            $phone = $this->customerLabels->phone($buyerId, $orderId);
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'این سرویس متعلق به حساب شما نیست.', $this->homeButtons());

            return;
        }
        $this->clearCustomerLabelDraft($buyerId);
        $this->clearCustomerSearch($buyerId);
        app(PendingBuilderSelection::class)->forget($buyerId);
        app(SalesPanelRequestUi::class)->clear($buyerId);
        Cache::put($this->phoneKey($buyerId), [
            'order_id' => $orderId, 'cursor' => $cursor,
        ], now()->addMinutes(10));

        $buttons = InlineKeyboardMarkup::make();
        if ($phone !== null) {
            $buttons->addRow(InlineKeyboardButton::make('🗑 حذف شماره',
                callback_data: "sales:subs:phone:remove:{$orderId}:{$cursor}"));
        }
        $buttons->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'sales:subs:phone:cancel'))
            ->addRow(InlineKeyboardButton::make('◀️ بازگشت به سرویس', callback_data: "sales:subs:view:{$orderId}:{$cursor}"));
        $this->render($bot, '<b>📱 شماره موبایل مشتری</b>'
            .($phone === null ? '' : "\nشماره فعلی: <code>".TelegramUi::escape($phone).'</code>')
            ."\n\nشماره موبایل ایرانی را ارسال کنید."
            ."\nمثال: <code>09123456789</code>"
            ."\n\n<blockquote>این شماره فقط برای مدیریت مشتریان خودتان نمایش داده می‌شود.</blockquote>",
            $buttons);
    }

    public function customerPhoneText(Nutgram $bot, string $text): bool
    {
        $telegramId = $this->privateUser->id($bot);
        if ($telegramId === null || str_starts_with(trim($text), '/')) {
            return false;
        }
        $actor = $this->actors->handle($telegramId);
        if ($actor === null) {
            return false;
        }
        $draft = Cache::get($this->phoneKey($actor->userId));
        if (! is_array($draft) || ! is_int($draft['order_id'] ?? null)
            || ! is_string($draft['cursor'] ?? null)) {
            return false;
        }
        if (! in_array('reseller', $actor->roles, true)) {
            $this->clearCustomerPhoneDraft($actor->userId);
            $this->notice($bot, 'این بخش فقط برای نمایندگان فعال است.', $this->homeButtons());

            return true;
        }
        try {
            $this->customerLabels->savePhone($actor->userId, $draft['order_id'], $text);
        } catch (ValidationException) {
            $this->notice($bot, 'شماره معتبر نیست. لطفاً یک موبایل ایرانی مثل ۰۹۱۲۳۴۵۶۷۸۹ بفرستید.',
                InlineKeyboardMarkup::make()->addRow(
                    InlineKeyboardButton::make('❌ انصراف', callback_data: 'sales:subs:phone:cancel'),
                ));

            return true;
        } catch (AuthorizationException) {
            $this->clearCustomerPhoneDraft($actor->userId);
            $this->notice($bot, 'امکان ویرایش اطلاعات این سرویس وجود ندارد.', $this->homeButtons());

            return true;
        }
        $this->clearCustomerPhoneDraft($actor->userId);
        $this->notice($bot, '✅ شماره موبایل مشتری ذخیره شد.',
            $this->labelBackButtons($draft['order_id'], $draft['cursor']));

        return true;
    }

    public function confirmCustomerPhoneRemoval(Nutgram $bot, string $id, string $cursor): void
    {
        $buyerId = $this->resellerId($bot);
        $orderId = $this->id($id);
        if ($buyerId === null || $orderId === null || $this->id($cursor, true) === null) {
            $this->notice($bot, 'این سرویس در دسترس شما نیست.', $this->homeButtons());

            return;
        }
        try {
            $phone = $this->customerLabels->phone($buyerId, $orderId);
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'امکان ویرایش اطلاعات این سرویس وجود ندارد.', $this->homeButtons());

            return;
        }
        if ($phone === null) {
            $this->notice($bot, 'شماره‌ای برای این سرویس ثبت نشده است.', $this->labelBackButtons($orderId, $cursor));

            return;
        }
        $this->render($bot, 'شماره <code>'.TelegramUi::escape($phone).'</code> از اطلاعات مشتری حذف شود؟'
            ."\n\nخود سرویس و نام مشتری بدون تغییر باقی می‌مانند.",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('🗑 تأیید حذف شماره',
                    callback_data: "sales:subs:phone:clear:{$orderId}:{$cursor}", style: ButtonStyle::DANGER))
                ->addRow(InlineKeyboardButton::make('◀️ انصراف',
                    callback_data: "sales:subs:view:{$orderId}:{$cursor}")));
    }

    public function clearCustomerPhone(Nutgram $bot, string $id, string $cursor): void
    {
        $buyerId = $this->resellerId($bot);
        $orderId = $this->id($id);
        if ($buyerId === null || $orderId === null || $this->id($cursor, true) === null) {
            $this->notice($bot, 'این سرویس در دسترس شما نیست.', $this->homeButtons());

            return;
        }
        try {
            $this->customerLabels->savePhone($buyerId, $orderId, null);
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'امکان حذف این شماره وجود ندارد.', $this->homeButtons());

            return;
        }
        $this->clearCustomerPhoneDraft($buyerId);
        $this->notice($bot, '✅ شماره حذف شد؛ نام مشتری و سرویس تغییری نکرد.',
            $this->labelBackButtons($orderId, $cursor));
    }

    public function cancelCustomerPhone(Nutgram $bot): void
    {
        $buyerId = $this->resellerId($bot);
        if ($buyerId === null) {
            return;
        }
        $this->clearCustomerPhoneDraft($buyerId);
        $this->notice($bot, 'ویرایش شماره لغو شد.', $this->homeButtons());
    }

    public function clearCustomerPhoneDraft(int $userId): void
    {
        Cache::forget($this->phoneKey($userId));
    }

    private function phoneKey(int $userId): string
    {
        return TelegramBotContext::current()->cacheKey('telegram:subs:customer_phone:'.$userId);
    }

    /** Start a private, 10-minute customer-name draft for an existing owned service. */
    public function beginCustomerLabel(Nutgram $bot, string $id, string $cursor): void
    {
        if ($this->privateUser->id($bot) === null) {
            return;
        }

        $buyerId = $this->resellerId($bot);
        $orderId = $this->id($id);
        if ($buyerId === null || $orderId === null || $this->id($cursor, true) === null) {
            $this->notice($bot, 'این سرویس برای ثبت نام مشتری در دسترس نیست.', $this->homeButtons());

            return;
        }

        try {
            $current = $this->customerLabels->get($buyerId, $orderId);
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'این سرویس برای حساب شما در دسترس نیست.', $this->homeButtons());

            return;
        }

        // This form takes precedence over other pending Sales text forms.
        $this->clearCustomerSearch($buyerId);
        $this->clearCustomerPhoneDraft($buyerId);
        app(PendingBuilderSelection::class)->forget($buyerId);
        app(SalesPanelRequestUi::class)->clear($buyerId);
        Cache::forget(TelegramBotContext::current()->cacheKey('telegram:wallet:topup:'.$buyerId));
        Cache::put($this->labelKey($buyerId), ['order_id' => $orderId, 'cursor' => $cursor], now()->addMinutes(10));

        $buttons = InlineKeyboardMarkup::make();
        if ($current !== null) {
            $buttons->addRow(InlineKeyboardButton::make('🗑 حذف نام مشتری', callback_data: "sales:subs:label:remove:{$orderId}:{$cursor}"));
        }
        $buttons->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'sales:subs:label:cancel'))
            ->addRow(InlineKeyboardButton::make('◀️ بازگشت به سرویس', callback_data: "sales:subs:view:{$orderId}:{$cursor}"));

        $this->render($bot, '<b>👤 نام مشتری سرویس #'.$orderId.'</b>'
            .($current === null ? '' : "\nنام فعلی: <b>".TelegramUi::escape($current).'</b>')
            ."\n\nنام یا عنوان دلخواه مشتری را ارسال کنید (حداکثر ۶۰ کاراکتر)."
            ."\nاین نام فقط برای مدیریت داخلی سرویس‌های شماست و مالکیت سرویس را تغییر نمی‌دهد."
            ."\n\n<blockquote>این فرم ۱۰ دقیقه اعتبار دارد.</blockquote>", $buttons);
    }

    /** Consume text only while the authenticated reseller has a live label form. */
    public function customerLabelText(Nutgram $bot, string $text): bool
    {
        $telegramId = $this->privateUser->id($bot);
        if ($telegramId === null || str_starts_with(trim($text), '/')) {
            return false;
        }
        $actor = $this->actors->handle($telegramId);
        if ($actor === null) {
            return false;
        }

        $draft = Cache::get($this->labelKey($actor->userId));
        if (! is_array($draft) || ! is_int($draft['order_id'] ?? null) || ! is_string($draft['cursor'] ?? null)) {
            return false;
        }
        if (! in_array('reseller', $actor->roles, true)) {
            $this->clearCustomerLabelDraft($actor->userId);
            $this->notice($bot, 'دسترسی پنل فروش برای این حساب فعال نیست.', $this->homeButtons());

            return true;
        }

        try {
            $this->customerLabels->save($actor->userId, $draft['order_id'], $text);
        } catch (ValidationException) {
            $this->notice($bot, 'نام مشتری باید بین ۱ تا ۶۰ کاراکتر و بدون خط جدید باشد. دوباره ارسال کنید یا انصراف بزنید.',
                InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'sales:subs:label:cancel')));

            return true;
        } catch (AuthorizationException) {
            $this->clearCustomerLabelDraft($actor->userId);
            $this->notice($bot, 'امکان ویرایش این سرویس وجود ندارد.', $this->homeButtons());

            return true;
        }
        $this->clearCustomerLabelDraft($actor->userId);
        $this->notice($bot, '✅ نام مشتری ذخیره شد.', $this->labelBackButtons($draft['order_id'], $draft['cursor']));

        return true;
    }

    public function confirmCustomerLabelRemoval(Nutgram $bot, string $id, string $cursor): void
    {
        if ($this->privateUser->id($bot) === null) {
            return;
        }

        $buyerId = $this->resellerId($bot);
        $orderId = $this->id($id);
        if ($buyerId === null || $orderId === null || $this->id($cursor, true) === null) {
            $this->notice($bot, 'درخواست معتبر نیست.', $this->homeButtons());

            return;
        }
        try {
            $current = $this->customerLabels->get($buyerId, $orderId);
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'این سرویس در دسترس شما نیست.', $this->homeButtons());

            return;
        }
        if ($current === null) {
            $this->notice($bot, 'نامی برای این سرویس ثبت نشده است.', $this->labelBackButtons($orderId, $cursor));

            return;
        }
        $this->render($bot, 'نام مشتری «'.TelegramUi::escape($current).'» از این سرویس حذف شود؟'
            ."\n\nخود سرویس و اشتراک مشتری بدون تغییر باقی می‌مانند.",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('🗑 تأیید حذف نام', callback_data: "sales:subs:label:clear:{$orderId}:{$cursor}", style: ButtonStyle::DANGER))
                ->addRow(InlineKeyboardButton::make('◀️ انصراف', callback_data: "sales:subs:view:{$orderId}:{$cursor}")));
    }

    public function clearCustomerLabel(Nutgram $bot, string $id, string $cursor): void
    {
        if ($this->privateUser->id($bot) === null) {
            return;
        }

        $buyerId = $this->resellerId($bot);
        $orderId = $this->id($id);
        if ($buyerId === null || $orderId === null || $this->id($cursor, true) === null) {
            $this->notice($bot, 'درخواست معتبر نیست.', $this->homeButtons());

            return;
        }
        try {
            $this->customerLabels->save($buyerId, $orderId, null);
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'امکان حذف این نام وجود ندارد.', $this->homeButtons());

            return;
        }
        $this->clearCustomerLabelDraft($buyerId);
        $this->notice($bot, '✅ نام مشتری حذف شد؛ سرویس تغییری نکرد.', $this->labelBackButtons($orderId, $cursor));
    }

    public function cancelCustomerLabel(Nutgram $bot): void
    {
        if ($this->privateUser->id($bot) === null) {
            return;
        }

        $buyerId = $this->resellerId($bot);
        if ($buyerId !== null) {
            $this->clearCustomerLabelDraft($buyerId);
        }
        $this->notice($bot, 'ثبت نام مشتری لغو شد.', $this->homeButtons());
    }

    public function clearCustomerLabelDraft(int $userId): void
    {
        Cache::forget($this->labelKey($userId));
    }

    private function labelKey(int $userId): string
    {
        return TelegramBotContext::current()->cacheKey('telegram:subs:customer_label:'.$userId);
    }

    private function resellerId(Nutgram $bot): ?int
    {
        $id = $this->privateUser->id($bot);
        if ($id === null) {
            return null;
        }
        $actor = $this->actors->handle($id);

        return $actor !== null && in_array('reseller', $actor->roles, true) ? $actor->userId : null;
    }

    private function labelBackButtons(int $orderId, string $cursor): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ بازگشت به سرویس', callback_data: "sales:subs:view:{$orderId}:{$cursor}"))
            ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home'));
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

<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Notifications\TelegramNotificationSender;
use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Identity\Application\SuperAdminTrust;
use App\Modules\Identity\Application\TelegramActor;
use App\Modules\Sales\Application\ManageResellerServiceLabels;
use App\Modules\Sales\Application\ManualBankPayments;
use App\Modules\Sales\Application\OrderReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ForceReply;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

final readonly class ManualPaymentUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private SuperAdminTrust $trust,
        private TelegramNotificationSender $sender,
        private ManualBankPayments $payments,
        private OrderReader $orders,
        private MenuRenderer $menus,
        private ManageResellerServiceLabels $customerLabels,
        private PaymentDecisionTokens $decisionTokens,
    ) {}

    public function start(Nutgram $bot, string $orderId): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        if (config('sales.manual_bank_review_enabled', false) !== true) {
            $this->notice($bot, 'ارسال رسید در حال حاضر غیرفعال است.');

            return;
        }

        if (preg_match('/\A[1-9][0-9]{0,14}\z/D', $orderId) !== 1) {
            $this->notice($bot, 'شماره سفارش معتبر نیست.');

            return;
        }

        try {
            // Sales accepts only the managed-plan orders created in SB-03.
            // Legacy F09 orders remain reachable on the original bot only.
            $order = TelegramBotContext::current()->key === TelegramBotContext::SALES
                ? $this->orders->findPlan($actor->userId, (int) $orderId)
                : $this->orders->find($actor->userId, (int) $orderId);

            if ($order->status !== 'pending_payment') {
                throw ValidationException::withMessages(['order' => 'Not payable']);
            }
            if (DB::table('sales_renewal_orders')->where('sales_order_id', $order->id)->exists()
                && (config('sales.renewal_enabled', false) !== true
                    || config('sales.auto_fulfillment_enabled') !== true
                    || config('services.remnawave.writes_enabled') !== true
                    || config('services.remnawave.squad_bindings_live_verified') !== true)) {
                throw ValidationException::withMessages(['renewal' => 'Renewal is not ready.']);
            }
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'سفارش قابل پرداخت نیست.');

            return;
        }

        $sales = TelegramBotContext::current()->key === TelegramBotContext::SALES;
        if ($sales) {
            if ($this->payments->latestReceiptStatus(SubscriptionViewer::telegram((int) $this->private->id($bot)), $order->id) === 'submitted') {
                $this->notice($bot, 'رسید این سفارش قبلاً دریافت شده و در انتظار بررسی مدیر است.');

                return;
            }
            if (in_array('reseller', $actor->roles, true)) {
                Cache::forget(TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$actor->userId));
                $this->notice($bot, 'پرداخت خرید سرویس از کیف پول انجام می‌شود. ارسال رسید فقط در بخش «افزایش موجودی» کیف پول است.');

                return;
            }
            $card = $this->cardNumber();
            if ($card === null) {
                $this->notice($bot, 'اطلاعات پرداخت هنوز تنظیم نشده است. با پشتیبانی تماس بگیرید.');

                return;
            }
            try {
                $this->customerLabels->assertPendingDetails($actor->userId, $order->id);
            } catch (ValidationException $error) {
                if (isset($error->errors()['customer'])) {
                    app(SalesCheckoutUi::class)->viewOrder($bot, (string) $order->id);

                    return;
                }
                throw $error;
            }
        }

        Cache::put(TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$actor->userId), $order->id, now()->addMinutes(10));
        if ($sales) {
            Cache::put(TelegramBotContext::current()->cacheKey('telegram:payphoto:recent:'.$actor->userId), $order->id, now()->addHour());
        }
        $instructions = trim((string) config('billing.payment_instructions', ''));
        $instructions = mb_substr($instructions, 0, 350);

        $sourceRenewal = DB::table('sales_renewal_orders')
            ->where('sales_order_id', $order->id)->value('source_order_id');
        $back = $sales ? ($sourceRenewal === null ? 'menu:plan-catalog' : 'sales:subs:view:'.$sourceRenewal.':0')
            : 'shop:order:view:'.$order->id;
        $keyboard = InlineKeyboardMarkup::make();
        if ($sales) {
            $amount = (string) intdiv($order->totalPriceRials + 9, 10);
            $keyboard->addRow(TelegramUi::copy($card, '📋 کپی شماره کارت'), TelegramUi::copy($amount, '📋 کپی مبلغ'));
            if ($sourceRenewal === null) {
                $keyboard->addRow(InlineKeyboardButton::make('🛒 خرید جدید همین پلن',
                    callback_data: 'sales:checkout:new:'.$order->id));
            }
        }
        $keyboard->addRow(InlineKeyboardButton::make($sourceRenewal === null ? '◀️ پلن‌ها' : '◀️ سرویس اصلی', callback_data: $back))
            ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'menu:home'));

        if ($sales) {
            $details = '<b>💳 پرداخت سفارش #'.$order->id.'</b>'
                ."\n\n📦 پلن: <b>".TelegramUi::escape($order->productName).'</b>'
                ."\nحجم: ".($order->trafficGb === null ? 'نامحدود' : $order->trafficGb.' گیگابایت')
                ." · مدت: {$order->durationDays} روز"
                ."\n\nمبلغ دقیق: <b>".TelegramUi::toman($order->totalPriceRials).' تومان</b>'
                ."\nشماره کارت: ".TelegramUi::code($card)
                .($instructions === '' ? '' : "\n".TelegramUi::escape($instructions))
                ."\n\n<blockquote>✅ بعد از واریز، عکس رسید را همین‌جا بفرستید تا پرداختتان بررسی شود. نتیجه را از همین بات دریافت می‌کنید.</blockquote>";
            $this->renderPayment($bot, $details, $keyboard);
            // ForceReply is a lightweight prompt, not another required step.
            // Ownership and order state are rechecked on receipt submission.
            $bot->sendMessage(
                text: '📷 لطفاً عکس رسید واریز را همین‌جا بفرستید (۱۰ دقیقه فرصت دارید).',
                reply_markup: ForceReply::make(
                    force_reply: true,
                    input_field_placeholder: 'عکس رسید پرداخت',
                    selective: true,
                ),
            );

            return;
        }

        $this->render($bot, "🧾 ثبت رسید سفارش #{$order->id}\n\nمبلغ دقیق: ".TelegramUi::toman($order->totalPriceRials)." تومان\n\n{$instructions}\n\nبعد از واریز، تصویر رسید را همین‌جا بفرستید تا پرداخت بررسی شود.\nبرای ارسال رسید ۱۰ دقیقه فرصت دارید.", $keyboard);
    }

    /** The configured card is real operator data, never a default/demo identity. */
    public function cardNumber(): ?string
    {
        $card = trim((string) config('billing.payment_card_number', ''));
        if ($card !== '') {
            $card = preg_replace('/[\\s-]+/u', '', $card);

            return is_string($card) && preg_match('/\\A[0-9]{16}\\z/D', $card) === 1 ? $card : null;
        }

        // Backward-compatible: extract only one unambiguous card from the
        // existing payment instructions. Never infer an account from arbitrary digits.
        $instructions = (string) config('billing.payment_instructions', '');
        preg_match_all('/(?<![0-9])(?:[0-9][ -]?){15}[0-9](?![0-9])/u', $instructions, $matches);
        if (count($matches[0]) !== 1) {
            return null;
        }
        $extracted = preg_replace('/[ -]/', '', $matches[0][0]);

        return is_string($extracted) && preg_match('/\\A[0-9]{16}\\z/D', $extracted) === 1 ? $extracted : null;
    }

    /** Do not treat unrelated text/documents as a receipt while awaiting a photo. */
    public function invalidInput(Nutgram $bot): bool
    {
        $actor = $this->actor($bot);
        if ($actor === null || TelegramBotContext::current()->key !== TelegramBotContext::SALES
            || config('sales.manual_bank_review_enabled', false) !== true) {
            return false;
        }
        $orderId = $this->cachedOrderId(Cache::get(TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$actor->userId)));
        if ($orderId === null) {
            return false;
        }
        $this->notice($bot, 'لطفاً عکس رسید را بفرستید، یا از «خانه» خارج شوید. برای ادامه بعدی، «سفارش‌های من» را باز کنید.');

        return true;
    }

    private function renderPayment(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $text, $keyboard, 'HTML');

            return;
        }
        $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: 'HTML');
    }

    public function photo(Nutgram $bot): void
    {
        $actor = $this->actor($bot);

        if ($actor === null || config('sales.manual_bank_review_enabled', false) !== true) {
            return;
        }

        $orderId = $this->cachedOrderId(Cache::get(TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$actor->userId)));

        if ($orderId === null) {
            $recent = TelegramBotContext::current()->key === TelegramBotContext::SALES
                ? $this->cachedOrderId(Cache::get(TelegramBotContext::current()->cacheKey('telegram:payphoto:recent:'.$actor->userId)))
                : null;
            if ($recent !== null) {
                $receipt = $this->payments->latestReceiptStatus(
                    SubscriptionViewer::telegram((int) $this->private->id($bot)), $recent,
                );
                $this->notice($bot, $receipt === 'submitted'
                    ? 'رسید این سفارش قبلاً دریافت شده؛ منتظر بررسی مدیر باشید.'
                    : 'زمان دریافت رسید تمام شده است. از «سفارش‌های من» ادامه پرداخت را باز کنید.');
            }

            return;
        }

        $photos = $bot->message()->photo ?? [];
        $photo = $photos === [] ? null : $photos[array_key_last($photos)];
        $messageId = $bot->message()?->message_id;

        if ($photo === null || is_int($messageId) === false) {
            $this->notice($bot, 'تصویر معتبر دریافت نشد. یک عکس معمولی بفرستید.');

            return;
        }

        try {
            $claimId = $this->payments->submit(
                SubscriptionViewer::telegram($this->private->id($bot)),
                $orderId,
                $photo->file_id,
                $photo->file_unique_id,
                $messageId,
                TelegramBotContext::current()->key,
            );
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $reason = ($errors['customer'][0] ?? null) ?: ($errors['receipt'][0] ?? null);
            $message = match ($reason) {
                'This order already has a receipt pending review.' => 'رسید این سفارش قبلاً دریافت شده و در انتظار بررسی است.',
                'This receipt was already submitted.' => 'این تصویر قبلاً برای یک رسید ثبت شده است؛ تصویر جدید ارسال کنید.',
                'Complete customer name and mobile before payment.' => 'ابتدا نام و شماره موبایل مشتری را در صفحه سفارش تکمیل کنید؛ رسید ثبت نشد.',
                default => 'سفارش دیگر قابل پرداخت نیست یا تصویر معتبر نیست. وضعیت سفارش را در «سوابق خرید» بررسی کنید.',
            };
            $this->notice($bot, $message);

            return;
        } catch (AuthorizationException|UniqueConstraintViolationException) {
            $this->notice($bot, 'این سفارش قابل پرداخت نیست یا قبلاً رسید دریافت شده است. «سوابق خرید» را بررسی کنید.');

            return;
        }

        Cache::forget(TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$actor->userId));
        $this->notice($bot, TelegramBotContext::current()->key === TelegramBotContext::SALES
            ? "✅ رسیدتان دریافت شد و برای بررسی ارسال شد.\nنتیجه را در همین بات دریافت می‌کنید.\nسفارش #{$orderId}"
            : "🕘 رسید سفارش #{$orderId} ثبت شد.\nشماره بررسی: #{$claimId}\n\nتنها پس از تطبیق واقعی گردش بانک و تأیید مدیر، سفارش تسویه می‌شود.");
        // Keep the already deployed best-effort alerts until the durable
        // notification worker is explicitly enabled in Production.
        if (config('telegram_notifications.enabled', false) !== true) {
            if (TelegramBotContext::current()->key === TelegramBotContext::ADMIN) {
                $this->alertRoots($bot, $claimId);
            } elseif (config('sales.one_click_bank_review_enabled', false) === true) {
                // Sales file IDs are token-specific: use the existing secure
                // bounded downloader and Admin-token reupload.
                foreach ($this->trust->allowedTelegramIds() as $id) {
                    $root = $this->actors->handle((int) $id);
                    if ($root === null || ! $root->canManageRoles()) {
                        continue;
                    }
                    try {
                        $this->sender->showReceiptToRoot(
                            $claimId, $root->userId, (int) $id,
                            $this->caption($this->payments->detail($root->userId, $claimId)),
                        );
                    } catch (Throwable) {
                        // The review queue remains authoritative.
                    }
                }
            }
        }
    }

    public function list(Nutgram $bot, string $page = '1'): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }

        $number = $this->parseId($page);
        if ($number === null || $number > 10000) {
            $this->notice($bot, 'صفحه معتبر نیست.');

            return;
        }
        Cache::forget($this->referenceKey($actor->userId));

        // Acknowledge before querying financial rows: Telegram expires callback
        // IDs after a short deadline even if the requested list still works.
        $acknowledged = $bot->isCallbackQuery();
        if ($acknowledged) {
            TelegramUi::answerCallback($bot);
        }

        try {
            $rows = $this->payments->pending($actor->userId, 9, ($number - 1) * 8);
        } catch (AuthorizationException) {
            if (! $acknowledged) {
                $this->menus->denyAccess($bot);
            }

            return;
        }

        $text = "🧾 رسیدهای در انتظار بررسی · صفحه {$number}\n";
        $keyboard = InlineKeyboardMarkup::make();

        foreach (array_slice($rows, 0, 8) as $row) {
            $text .= "\n#{$row->id} · سفارش #{$row->sales_order_id} · ".number_format(intdiv((int) $row->total_price_rials + 9, 10)).' تومان';
            $keyboard->addRow(InlineKeyboardButton::make(
                '🖼️ مشاهده رسید #'.$row->id,
                callback_data: 'bank:show:'.$row->id,
            ));
        }

        if ($rows === []) {
            $text .= "\n\nدرخواستی در انتظار نیست.";
        }

        $text .= "\n\n⚠️ عکس رسید، اثبات واریز نیست؛ گردش بانک را بررسی کنید.";
        $arrows = [];
        if ($number > 1) {
            $arrows[] = InlineKeyboardButton::make('◀️ قبلی', callback_data: 'bank:list:'.($number - 1));
        }
        if (count($rows) > 8) {
            $arrows[] = InlineKeyboardButton::make('بعدی ▶️', callback_data: 'bank:list:'.($number + 1));
        }
        if ($arrows !== []) {
            $keyboard->addRow(...$arrows);
        }
        $keyboard->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'bank:list:'.$number))
            ->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'));
        $this->render($bot, $text, $keyboard, $acknowledged);
    }

    public function show(Nutgram $bot, string $claim): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }

        $id = $this->parseId($claim);

        if ($id === null) {
            $this->notice($bot, 'شناسه رسید نامعتبر است.');

            return;
        }

        try {
            $row = $this->payments->detail($actor->userId, $id);
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'رسید در صف بررسی موجود نیست.');

            return;
        }

        TelegramUi::answerCallback($bot);

        if ($row->sourceBotKey === TelegramBotContext::ADMIN) {
            $bot->sendPhoto(
                photo: $row->photoFileId,
                caption: $this->caption($row),
                reply_markup: $this->reviewKeyboard($id),
                protect_content: true,
            );

            return;
        }

        try {
            $this->sender->showReceiptToRoot($id, $actor->userId, (int) $this->private->id($bot), $this->caption($row));
        } catch (Throwable) {
            $this->notice($bot, 'تصویر رسید فعلاً قابل نمایش نیست؛ درخواست در صف بررسی محفوظ است. بدون تطبیق گردش بانک آن را تأیید نکنید.');
        }
    }

    /** The approval button starts review; money changes only after a final reference-bound confirmation. */
    public function decide(Nutgram $bot, string $claim, string $choice): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        $id = $this->parseId($claim);
        if ($id === null || ! in_array($choice, ['a', 'r'], true)) {
            $this->notice($bot, 'درخواست معتبر نیست. رسیدها را دوباره بررسی کنید.');

            return;
        }
        if (config('telegram_notifications.enabled', false) !== true
            || config('telegram.sales.enabled', false) !== true) {
            $this->notice($bot, 'ارسال نتیجه بررسی به مشتری هنوز آماده نیست. رسید در انتظار بررسی باقی مانده است.');

            return;
        }

        // Never commit a bank settlement into a paid-but-stuck order: the
        // one-decision path is enabled only when queued activation can start.
        if ($choice === 'a' && ! $this->automaticActivationReady()) {
            $this->notice($bot, 'تأیید پرداخت انجام نشد؛ فعال‌سازی خودکار هنوز آماده نیست. رسید در انتظار بررسی باقی مانده است.');

            return;
        }

        Cache::forget($this->referenceKey($actor->userId));
        if ($choice === 'a') {
            try {
                $row = $this->payments->detail($actor->userId, $id);
            } catch (AuthorizationException|ValidationException) {
                $this->notice($bot, 'رسید دیگر در انتظار بررسی نیست.');

                return;
            }
            Cache::put($this->referenceKey($actor->userId), ['claim' => $row->id], now()->addMinutes(10));
            $this->render($bot,
                "🔎 بررسی پرداخت · مرحله ۱ از ۲\n\nدرخواست #{$row->id} · سفارش #{$row->orderId}"
                ."\nمبلغ: ".TelegramUi::toman($row->amountRials)." تومان"
                ."\n\nپس از مشاهده واریز واقعی در بانک، شناسه یکتای تراکنش را ارسال کنید."
                ."\nهنوز هیچ پرداختی تأیید نشده است.",
                $this->backKeyboard());

            return;
        }

        try {
            $result = $this->payments->review($actor->userId, $id, false);
        } catch (AuthorizationException|ValidationException|UniqueConstraintViolationException $error) {
            $this->notice($bot, $error instanceof ValidationException && isset($error->errors()['plan'])
                ? 'تأیید انجام نشد؛ مشخصات پلن تغییر کرده یا خرید آن متوقف شده است. رسید در انتظار بررسی محفوظ است؛ واریز مشتری را بررسی کنید.'
                : 'این رسید قبلاً بررسی شده یا دیگر معتبر نیست.');

            return;
        }
        $message = $result->approved
            ? "✅ پرداخت سفارش #{$result->orderId} تأیید شد.\nاشتراک در حال آماده‌سازی است."
            : "❌ رسید سفارش #{$result->orderId} رد شد.\nمشتری می‌تواند رسید جدیدی ارسال کند.";
        try {
            if (($bot->message()->photo ?? []) !== []) {
                TelegramUi::editCaption($bot, $message, InlineKeyboardMarkup::make());
            } else {
                TelegramUi::editText($bot, $message, InlineKeyboardMarkup::make());
            }
        } catch (Throwable) {
            // The committed settlement/rejection must not be undone by Telegram.
        }
    }


    /** Consume Admin-only reference input; this never settles an order. */
    public function text(Nutgram $bot, string $value): bool
    {
        $chat = $this->private->id($bot);
        if ($chat === null || TelegramBotContext::current()->key !== TelegramBotContext::ADMIN) {
            return false;
        }
        $actor = $this->actors->handle($chat);
        if ($actor === null) {
            return false;
        }
        $key = $this->referenceKey($actor->userId);
        $draft = Cache::get($key);
        if (! is_array($draft) || ! is_int($draft['claim'] ?? null)) {
            return false;
        }
        if ($this->root($bot) === null) {
            return true;
        }
        if (! $this->automaticActivationReady()) {
            Cache::forget($key);
            $this->notice($bot, 'بررسی پرداخت فعلاً آماده نیست. رسید در صف محفوظ است.');

            return true;
        }
        $reference = strtoupper(trim($value));
        if (preg_match('/\A[A-Z0-9_-]{6,64}\z/D', $reference) !== 1
            || str_starts_with($reference, 'MANUAL-CLAIM-')) {
            $this->notice($bot, 'شناسه بانکی معتبر (۶ تا ۶۴ نویسه انگلیسی یا عدد) وارد کنید.');

            return true;
        }
        try {
            $row = $this->payments->detail($actor->userId, $draft['claim']);
        } catch (AuthorizationException|ValidationException) {
            Cache::forget($key);
            $this->notice($bot, 'رسید دیگر در انتظار بررسی نیست.');

            return true;
        }
        $token = $this->decisionTokens->issue($actor->userId, $row->id, true, $reference, null);
        Cache::forget($key);
        $bot->sendMessage(
            text: "<b>✅ تأیید نهایی پرداخت · مرحله ۲ از ۲</b>"
                ."\n\nسفارش #{$row->orderId} · درخواست #{$row->id}"
                ."\nمبلغ: <b>".TelegramUi::toman($row->amountRials)." تومان</b>"
                ."\nشناسه بانک: ".TelegramUi::code($reference)
                ."\n\nتا زدن دکمه تأیید نهایی، هیچ مبلغی ثبت نمی‌شود."
                ."\nآیا واریز واقعی را در بانک بررسی کرده‌اید؟",
            parse_mode: 'HTML',
            reply_markup: InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ تأیید نهایی پرداخت', callback_data: 'bank:apply:'.$token))
                ->addRow(InlineKeyboardButton::make('◀️ انصراف', callback_data: 'bank:list:1')),
        );

        return true;
    }

    /** Single-use, root-bound final bank settlement. */
    public function apply(Nutgram $bot, string $token): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        $data = $this->decisionTokens->consume($actor->userId, $token);
        if ($data === null || $data['approve'] !== true || ! is_string($data['reference'])) {
            $this->notice($bot, 'این تأیید منقضی، نامعتبر یا قبلاً استفاده شده است.');

            return;
        }
        if (! $this->automaticActivationReady()) {
            $this->notice($bot, 'فعال‌سازی خودکار آماده نیست؛ پرداخت تأیید نشد.');

            return;
        }
        try {
            $result = $this->payments->review($actor->userId, $data['claim'], true, $data['reference']);
        } catch (AuthorizationException|ValidationException|UniqueConstraintViolationException|\Illuminate\Database\QueryException $error) {
            $this->notice($bot, $error instanceof ValidationException && isset($error->errors()['plan'])
                ? 'مشخصات پلن تغییر کرده است؛ رسید برای بررسی در صف باقی می‌ماند.'
                : 'تأیید انجام نشد؛ رسید تغییر کرده یا شناسه بانکی قبلاً ثبت شده است.');

            return;
        }

        $this->render($bot,
            "✅ پرداخت سفارش #{$result->orderId} تأیید شد.\nاشتراک در حال آماده‌سازی است.",
            $this->backKeyboard());
    }

    private function referenceKey(int $operatorId): string
    {
        return TelegramBotContext::current()->cacheKey('telegram:bankreview:reference:'.$operatorId);
    }

    private function alertRoots(Nutgram $bot, int $claimId): void
    {
        foreach ($this->trust->allowedTelegramIds() as $id) {
            $root = $this->actors->handle((int) $id);

            if ($root === null || $root->canManageRoles() === false) {
                continue;
            }

            try {
                $row = $this->payments->detail($root->userId, $claimId);
                $bot->sendPhoto(
                    photo: $row->photoFileId,
                    chat_id: (int) $id,
                    caption: $this->caption($row),
                    reply_markup: $this->reviewKeyboard($claimId),
                    protect_content: true,
                );
            } catch (Throwable) {
                // The claim is durable; roots can recover it from /payments.
            }
        }
    }

    private function caption(object $row): string
    {
        return "🧾 رسید در انتظار بررسی\n\nدرخواست #{$row->id} · سفارش #{$row->orderId}\nکاربر داخلی #{$row->buyerUserId}\nمبلغ: ".number_format(intdiv($row->amountRials + 9, 10))." تومان\n\n⚠️ قبل از تأیید، تراکنش واقعی بانک را مستقل بررسی کنید.";
    }

    private function reviewKeyboard(int $id): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make('✅ تأیید پرداخت', callback_data: 'bank:decide:'.$id.':a'),
            InlineKeyboardButton::make('❌ رد رسید', callback_data: 'bank:decide:'.$id.':r'),
        );
    }

    /** A single-click approval cannot leave paid orders awaiting a manual CREATE. */
    private function automaticActivationReady(): bool
    {
        return config('sales.one_click_bank_review_enabled', false) === true
            && config('sales.auto_fulfillment_enabled', false) === true
            && config('services.remnawave.writes_enabled') === true
            && config('services.remnawave.squad_bindings_live_verified') === true
            && config('telegram_notifications.enabled', false) === true
            && config('telegram.sales.enabled', false) === true;
    }

    private function backKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make('◀️ رسیدهای در انتظار', callback_data: 'bank:list'),
        );
    }

    private function root(Nutgram $bot): ?TelegramActor
    {
        $actor = $this->actor($bot);

        if ($actor !== null && $actor->canManageRoles() === false) {
            $this->menus->denyAccess($bot);

            return null;
        }

        return $actor;
    }

    private function actor(Nutgram $bot): ?TelegramActor
    {
        $id = $this->private->id($bot);

        if ($id === null) {
            return null;
        }

        $actor = $this->actors->handle($id);

        if ($actor === null) {
            $this->menus->denyAccess($bot);
        }

        return $actor;
    }

    /** Keep receipt drafts compatible with cache stores returning numeric strings. */
    private function cachedOrderId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/\A[1-9][0-9]{0,14}\z/D', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    private function parseId(string $id): ?int
    {
        return preg_match('/\A[1-9][0-9]{0,14}\z/D', $id) === 1 ? (int) $id : null;
    }

    private function notice(Nutgram $bot, string $text): void
    {
        $keyboard = TelegramBotContext::current()->key === TelegramBotContext::SALES
            ? InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('📋 سفارش‌های من', callback_data: 'sales:checkout:orders'))
                ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'menu:home'))
            : $this->backKeyboard();
        $this->render($bot, $text, $keyboard);
    }

    private function render(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard, bool $callbackAcknowledged = false): void
    {
        if ($bot->isCallbackQuery()) {
            if (! $callbackAcknowledged) {
                TelegramUi::answerCallback($bot);
            }

            if (($bot->message()->photo ?? []) !== []) {
                TelegramUi::editCaption($bot, $text, $keyboard);

                return;
            }

            TelegramUi::editText($bot, $text, $keyboard);

            return;
        }

        $bot->sendMessage(text: $text, reply_markup: $keyboard);
    }
}

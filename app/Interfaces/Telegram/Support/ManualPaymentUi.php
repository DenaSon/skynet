<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Notifications\TelegramNotificationSender;
use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Identity\Application\SuperAdminTrust;
use App\Modules\Identity\Application\TelegramActor;
use App\Modules\Identity\Application\TelegramRecipient;
use App\Modules\Sales\Application\ManualBankPayments;
use App\Modules\Sales\Application\OrderReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
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
        private TelegramRecipient $recipients,
        private TelegramNotificationSender $sender,
        private ManualBankPayments $payments,
        private OrderReader $orders,
        private PaymentDecisionTokens $tokens,
        private MenuRenderer $menus,
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
            $card = $this->cardNumber();
            if ($card === null) {
                $this->notice($bot, 'اطلاعات پرداخت هنوز تنظیم نشده است. با پشتیبانی تماس بگیرید.');

                return;
            }
        }

        Cache::put(TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$actor->userId), $order->id, now()->addMinutes(10));
        if ($sales) {
            Cache::put(TelegramBotContext::current()->cacheKey('telegram:payphoto:recent:'.$actor->userId), $order->id, now()->addHour());
        }
        $instructions = trim((string) config('billing.payment_instructions', ''));
        $instructions = mb_substr($instructions, 0, 350);

        $back = $sales ? 'menu:plan-catalog' : 'shop:order:view:'.$order->id;
        $keyboard = InlineKeyboardMarkup::make();
        if ($sales) {
            $amount = (string) intdiv($order->totalPriceRials + 9, 10);
            $keyboard->addRow(TelegramUi::copy($card, '📋 کپی شماره کارت'), TelegramUi::copy($amount, '📋 کپی مبلغ'));
            $keyboard->addRow(InlineKeyboardButton::make('🛒 خرید جدید همین پلن',
                callback_data: 'sales:checkout:new:'.$order->id));
        }
        $keyboard->addRow(InlineKeyboardButton::make('◀️ پلن‌ها', callback_data: $back))
            ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'menu:home'));

        if ($sales) {
            $details = '<b>💳 پرداخت سفارش #'.$order->id.'</b>'
                ."\n\n📦 پلن: <b>".TelegramUi::escape($order->productName).'</b>'
                ."\nحجم: ".($order->trafficGb === null ? 'نامحدود' : $order->trafficGb.' گیگابایت')
                ." · مدت: {$order->durationDays} روز"
                ."\n\nمبلغ دقیق: <b>".TelegramUi::toman($order->totalPriceRials).' تومان</b>'
                ."\nشماره کارت: ".TelegramUi::code($card)
                .($instructions === '' ? '' : "\n".TelegramUi::escape($instructions))
                ."\n\n<blockquote>بعد از واریز، عکس رسید را همین‌جا بفرستید. ثبت رسید به معنای تأیید پرداخت نیست.</blockquote>";
            $this->renderPayment($bot, $details, $keyboard);
            // ForceReply is a lightweight prompt, not another required step.
            // Ownership and order state are rechecked on receipt submission.
            $bot->sendMessage(
                text: '📷 عکس رسید را برای همین سفارش ارسال کنید (۱۰ دقیقه فرصت دارید).',
                reply_markup: ForceReply::make(
                    force_reply: true,
                    input_field_placeholder: 'عکس رسید پرداخت',
                    selective: true,
                ),
            );

            return;
        }

        $this->render($bot, "🧾 ثبت رسید سفارش #{$order->id}\n\nمبلغ دقیق: ".TelegramUi::toman($order->totalPriceRials)." تومان\n\n{$instructions}\n\nپس از انتقال وجه، تصویر رسید را در همین گفت‌وگوی خصوصی ارسال کنید.\nاین درخواست ۱۰ دقیقه اعتبار دارد؛ تصویر به‌تنهایی پرداخت را اثبات نمی‌کند.", $keyboard);
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
        $orderId = Cache::get(TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$actor->userId));
        if (! is_int($orderId)) {
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

        $orderId = Cache::get(TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$actor->userId));

        if (is_int($orderId) === false) {
            $recent = TelegramBotContext::current()->key === TelegramBotContext::SALES
                ? Cache::get(TelegramBotContext::current()->cacheKey('telegram:payphoto:recent:'.$actor->userId))
                : null;
            if (is_int($recent)) {
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
        } catch (ValidationException|AuthorizationException|UniqueConstraintViolationException) {
            $this->notice($bot, 'این رسید تکراری است یا سفارش دیگر قابل پرداخت نیست. از «سفارش‌های من» وضعیت را بررسی کنید.');

            return;
        }

        Cache::forget(TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$actor->userId));
        $this->notice($bot, TelegramBotContext::current()->key === TelegramBotContext::SALES
            ? "✅ رسید دریافت شد؛ منتظر بررسی مدیر باشید.\nسفارش #{$orderId}"
            : "🕘 رسید سفارش #{$orderId} ثبت شد.\nشماره بررسی: #{$claimId}\n\nتنها پس از تطبیق واقعی گردش بانک و تأیید مدیر، سفارش تسویه می‌شود.");
        // Keep the already deployed best-effort alerts until the durable
        // notification worker is explicitly enabled in Production.
        if (config('telegram_notifications.enabled', false) !== true
            && TelegramBotContext::current()->key === TelegramBotContext::ADMIN) {
            // The legacy original-bot best-effort path remains unchanged.
            // Never deliver Admin-only bank buttons using the Sales token.
            $this->alertRoots($bot, $claimId);
        }
    }

    public function list(Nutgram $bot): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }

        // Acknowledge before querying financial rows: Telegram expires callback
        // IDs after a short deadline even if the requested list still works.
        $acknowledged = $bot->isCallbackQuery();
        if ($acknowledged) {
            TelegramUi::answerCallback($bot);
        }

        try {
            $rows = $this->payments->pending($actor->userId);
        } catch (AuthorizationException) {
            if (! $acknowledged) {
                $this->menus->denyAccess($bot);
            }

            return;
        }

        $text = "🧾 رسیدهای در انتظار بررسی\n";
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($rows as $row) {
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
        $keyboard->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'bank:list'))
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

    public function choose(Nutgram $bot, string $claim, string $choice): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }

        $id = $this->parseId($claim);

        if ($id === null || in_array($choice, ['a', 'r'], true) === false) {
            $this->notice($bot, 'درخواست معتبر نیست.');

            return;
        }

        try {
            $row = $this->payments->detail($actor->userId, $id);
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'این درخواست قبلاً بررسی شده یا دیگر معتبر نیست.');

            return;
        }

        if (($bot->message()->photo ?? []) !== []) {
            Cache::put(TelegramBotContext::current()->cacheKey('telegram:bankreview:origin:'.$actor->userId.':'.$id), $bot->message()->message_id, now()->addMinutes(10));
        }

        if ($choice === 'a') {
            $this->render($bot, "✅ بررسی تأیید رسید #{$id}\n\nبرای تأیید، ابتدا مبلغ و شناسه تراکنش را از گردش واقعی بانک بررسی کنید و در همین گفت‌وگو بنویسید:\n/bankconfirm {$id} BANK123456\n\nشناسه تصویر کاربر به‌تنهایی کافی نیست.", $this->backKeyboard());

            return;
        }

        $token = $this->tokens->issue($actor->userId, $id, false, null, $bot->message()?->message_id);
        $this->render($bot, "⚠️ رد رسید #{$id}\n\nسفارش #{$row->orderId}، مبلغ ".number_format(intdiv($row->amountRials + 9, 10))." تومان\n\nرد این رسید بدون تغییر وضعیت پرداخت سفارش ثبت می‌شود.", InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('❌ تأیید نهایی رد', callback_data: 'bank:apply:'.$token))
            ->addRow(InlineKeyboardButton::make('انصراف', callback_data: 'bank:list')));
    }

    public function bankConfirm(Nutgram $bot, string $claim, string $reference): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }

        $id = $this->parseId($claim);

        if ($id === null || preg_match('/\A[A-Za-z0-9_-]{6,64}\z/D', $reference) !== 1) {
            $this->notice($bot, 'شناسه بانکی معتبر نیست. مثال: /bankconfirm 12 BANK123456');

            return;
        }

        try {
            $row = $this->payments->detail($actor->userId, $id);
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'این رسید دیگر قابل بررسی نیست.');

            return;
        }

        $reference = strtoupper($reference);
        $origin = Cache::get(TelegramBotContext::current()->cacheKey('telegram:bankreview:origin:'.$actor->userId.':'.$id));
        $token = $this->tokens->issue($actor->userId, $id, true, $reference, is_int($origin) ? $origin : null);
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('✅ تأیید نهایی پرداخت', callback_data: 'bank:apply:'.$token))
            ->addRow(InlineKeyboardButton::make('انصراف', callback_data: 'bank:list'));

        $this->render($bot, "⚠️ تأیید نهایی تراکنش\n\nسفارش #{$row->orderId} · رسید #{$id}\nمبلغ: ".number_format(intdiv($row->amountRials + 9, 10))." تومان\nشناسه تطبیق‌داده‌شده: {$reference}\n\nاین تأیید یک‌بارمصرف و ۳ دقیقه معتبر است و فقط در صورت واریز واقعی باید انجام شود.", $keyboard);
    }

    public function apply(Nutgram $bot, string $token): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }

        $decision = $this->tokens->consume($actor->userId, $token);

        if ($decision === null) {
            $this->notice($bot, 'تأیید منقضی یا قبلاً مصرف شده است.');

            return;
        }

        try {
            $result = $this->payments->review(
                $actor->userId, $decision['claim'], $decision['approve'], $decision['reference'],
            );
        } catch (AuthorizationException|ValidationException|UniqueConstraintViolationException) {
            $this->notice($bot, 'درخواست تغییر کرده یا شناسه تراکنش قبلاً استفاده شده است. دوباره بررسی کنید.');

            return;
        }

        $message = $result->approved
            ? "✅ پرداخت سفارش #{$result->orderId} تأیید شد.\nدرخواست فعال‌سازی ثبت شد؛ سرویس هنوز ساخته نشده است."
            : "❌ رسید سفارش #{$result->orderId} رد شد.\nدر صورت نیاز می‌توانید رسید صحیح دیگری بفرستید.";

        $this->notice($bot, $message);

        if ($decision['approve'] && is_int($decision['photo_message_id']) && $decision['photo_message_id'] > 0) {
            try {
                TelegramUi::editCaption(
                    $bot,
                    "✅ پرداخت سفارش #{$result->orderId} تأیید شد.\nشناسه رسید #{$decision['claim']}\nدر انتظار فعال‌سازی.",
                    InlineKeyboardMarkup::make(),
                    $this->private->id($bot),
                    $decision['photo_message_id'],
                );
            } catch (Throwable) {
                // A failure to update an old Telegram photo does not undo settlement.
            }
        }

        if (config('telegram_notifications.enabled', false) !== true) {
            $recipient = $this->recipients->forActiveUser($result->buyerUserId);

            if ($recipient !== null) {
                try {
                    $bot->sendMessage(text: $message, chat_id: $recipient, protect_content: true);
                } catch (Throwable) {
                    // Review is committed. Failed delivery cannot roll back a bank decision.
                    // Status remains accessible in the customer's order history.
                }
            }
        }
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
            InlineKeyboardButton::make('✅ بررسی تأیید', callback_data: 'bank:choose:'.$id.':a'),
            InlineKeyboardButton::make('❌ رد رسید', callback_data: 'bank:choose:'.$id.':r'),
        );
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

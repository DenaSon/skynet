<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Identity\Application\SuperAdminTrust;
use App\Modules\Identity\Application\TelegramRecipient;
use App\Modules\Sales\Application\ManualBankPayments;
use App\Modules\Sales\Application\OrderReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
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

        if (! config('sales.manual_bank_review_enabled', false)) {
            $this->notice($bot, 'ارسال رسید در حال حاضر غیرفعال است.');
            return;
        }

        if (preg_match('/\A[1-9][0-9]{0,14}\z/D', $orderId) !== 1) {
            $this->notice($bot, 'شماره سفارش معتبر نیست.');
            return;
        }

        try {
            $order = $this->orders->find($actor->userId, (int) $orderId);

            if ($order->status !== 'pending_payment') {
                throw ValidationException::withMessages(['order' => 'Not payable']);
            }
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'سفارش قابل پرداخت نیست.');
            return;
        }

        Cache::put('telegram:payphoto:'.$actor->userId, $order->id, now()->addMinutes(10));
        $instructions = trim((string) config('billing.payment_instructions', ''));
        $instructions = mb_substr($instructions === '' ? 'برای دریافت اطلاعات پرداخت با پشتیبانی هماهنگ کنید.' : $instructions, 0, 350);

        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make('◀️ سفارش', callback_data: 'shop:order:view:'.$order->id),
        )->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'menu:home'));

        $this->render($bot, "🧾 ثبت رسید سفارش #{$order->id}\n\nمبلغ دقیق: ".number_format(intdiv($order->totalPriceRials + 9, 10))." تومان\n\n{$instructions}\n\nپس از انتقال وجه، تصویر رسید را در همین گفت‌وگوی خصوصی ارسال کنید.\nاین درخواست ۱۰ دقیقه اعتبار دارد؛ تصویر به‌تنهایی پرداخت را اثبات نمی‌کند.", $keyboard);
    }

    public function photo(Nutgram $bot): void
    {
        $actor = $this->actor($bot);

        if ($actor === null || ! config('sales.manual_bank_review_enabled', false)) {
            return;
        }

        $orderId = Cache::get('telegram:payphoto:'.$actor->userId);

        if (! is_int($orderId)) {
            return; // Never intercept unrelated images.
        }

        $photos = $bot->message()->photo ?? [];
        $photo = $photos === [] ? null : $photos[array_key_last($photos)];
        $messageId = $bot->message()?->message_id;

        if ($photo === null || ! is_int($messageId)) {
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
            );
        } catch (ValidationException|AuthorizationException|UniqueConstraintViolationException) {
            $this->notice($bot, 'رسید پذیرفته نشد؛ وضعیت سفارش یا رسید قبلی را بررسی کنید.');
            return;
        }

        Cache::forget('telegram:payphoto:'.$actor->userId);
        $this->notice($bot, "🕘 رسید سفارش #{$orderId} ثبت شد.\nشماره بررسی: #{$claimId}\n\nتنها پس از تطبیق واقعی گردش بانک و تأیید Super Admin، سفارش تسویه می‌شود.");
        $this->alertRoots($bot, $claimId);
    }

    public function list(Nutgram $bot): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }

        try {
            $rows = $this->payments->pending($actor->userId);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);
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
        $this->render($bot, $text, $keyboard);
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

        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        }

        $bot->sendPhoto(
            photo: $row->photoFileId,
            caption: $this->caption($row),
            reply_markup: $this->reviewKeyboard($id),
            protect_content: true,
        );
    }

    public function choose(Nutgram $bot, string $claim, string $choice): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }

        $id = $this->parseId($claim);

        if ($id === null || ! in_array($choice, ['a', 'r'], true)) {
            $this->notice($bot, 'درخواست معتبر نیست.');
            return;
        }

        try {
            $row = $this->payments->detail($actor->userId, $id);
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'این درخواست قبلاً بررسی شده یا دیگر معتبر نیست.');
            return;
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
        $token = $this->tokens->issue($actor->userId, $id, true, $reference, null);
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

    private function alertRoots(Nutgram $bot, int $claimId): void
    {
        foreach ($this->trust->allowedTelegramIds() as $id) {
            $root = $this->actors->handle((int) $id);

            if ($root === null || ! $root->canManageRoles()) {
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

    private function root(Nutgram $bot): ?\App\Modules\Identity\Application\TelegramActor
    {
        $actor = $this->actor($bot);

        if ($actor !== null && ! $actor->canManageRoles()) {
            $this->menus->denyAccess($bot);
            return null;
        }

        return $actor;
    }

    private function actor(Nutgram $bot): ?\App\Modules\Identity\Application\TelegramActor
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
        $this->render($bot, $text, $this->backKeyboard());
    }

    private function render(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard): void
    {
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();

            if (($bot->message()->photo ?? []) !== []) {
                $bot->editMessageCaption(caption: $text, reply_markup: $keyboard);
                return;
            }

            $bot->editMessageText(text: $text, reply_markup: $keyboard);
            return;
        }

        $bot->sendMessage(text: $text, reply_markup: $keyboard);
    }
}

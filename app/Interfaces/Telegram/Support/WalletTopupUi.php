<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Billing\Application\RequestTopup;
use App\Modules\Billing\Application\SubmitTopupPhoto;
use App\Modules\Identity\Application\ResolveTelegramActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final readonly class WalletTopupUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private RequestTopup $requests,
        private SubmitTopupPhoto $photos,
    ) {}

    public function start(Nutgram $bot): void
    {
        $userId = $this->buyer($bot);
        if ($userId === null) {
            return;
        }

        if (! $this->ready()) {
            $this->notice($bot, 'افزایش موجودی فعلاً در دسترس نیست. لطفاً بعداً تلاش کنید.');

            return;
        }

        Cache::forget(TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$userId));
        Cache::put($this->key($userId), ['step' => 'amount'], now()->addMinutes(10));
        $this->notice($bot, "<b>➕ افزایش موجودی</b>\n\nمبلغ شارژ را به تومان ارسال کنید.\nمثلاً: <code>500000</code>\n\n<blockquote>اعتبار فقط پس از دریافت و بررسی واقعی وجه توسط مدیر اضافه می‌شود.</blockquote>");
    }

    /** Return true only when the incoming text belongs to this Sales top-up form. */
    public function text(Nutgram $bot, string $value): bool
    {
        $userId = $this->buyer($bot);
        if ($userId === null) {
            return false;
        }

        $state = Cache::get($this->key($userId));
        if (! is_array($state) || ($state['step'] ?? null) !== 'amount') {
            return false;
        }

        if (! $this->ready()) {
            Cache::forget($this->key($userId));
            $this->notice($bot, 'افزایش موجودی فعلاً در دسترس نیست.');

            return true;
        }

        $amountText = strtr(trim($value), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        if (preg_match('/\A[1-9][0-9]{0,11}\z/D', $amountText) !== 1
            || (int) $amountText < 10000 || (int) $amountText > 100000000000) {
            $this->notice($bot, 'مبلغ را به تومان، بین ۱۰ هزار تا ۱۰۰ میلیارد تومان وارد کنید.');

            return true;
        }

        $messageId = $bot->message()?->message_id;
        if (! is_int($messageId) || $messageId < 1) {
            $this->notice($bot, 'درخواست معتبر نیست. دوباره تلاش کنید.');

            return true;
        }

        try {
            $id = $this->requests->handle(
                $userId, (int) $amountText * 10,
                'sales:topup:'.(int) $this->private->id($bot).':'.$messageId,
            );
        } catch (AuthorizationException|ValidationException|UniqueConstraintViolationException) {
            $this->notice($bot, 'درخواست قابل ثبت نیست. از کیف پول دوباره شروع کنید.');

            return true;
        }

        Cache::put($this->key($userId), ['step' => 'photo', 'topup_id' => $id], now()->addMinutes(10));
        $card = app(ManualPaymentUi::class)->cardNumber();
        if ($card === null) {
            Cache::forget($this->key($userId));
            $this->notice($bot, 'مشخصات پرداخت در دسترس نیست. لطفاً با پشتیبانی تماس بگیرید.');

            return true;
        }

        $instructions = trim((string) config('billing.payment_instructions', ''));
        $message = "<b>💳 شارژ کیف پول</b>\n\nدرخواست #{$id}"
            ."\nمبلغ: <b>".TelegramUi::toman((int) $amountText * 10)." تومان</b>"
            ."\nشماره کارت: ".TelegramUi::code($card);
        if ($instructions !== '') {
            $message .= "\n".TelegramUi::escape(mb_substr($instructions, 0, 350));
        }
        $message .= "\n\nپس از واریز، <b>عکس رسید</b> را در همین گفت‌وگو بفرستید."
            ."\n<blockquote>ارسال عکس به‌معنای تأیید پرداخت نیست.</blockquote>";

        $this->render($bot, $message, InlineKeyboardMarkup::make()
            ->addRow(TelegramUi::copy($card, '📋 کپی شماره کارت'))
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'sales:wallet')));
        return true;
    }

    /** Only consume the photo if this authenticated Sales user awaits a top-up receipt. */
    public function photo(Nutgram $bot): bool
    {
        $userId = $this->buyer($bot);
        if ($userId === null) {
            return false;
        }

        $state = Cache::get($this->key($userId));
        if (! is_array($state) || ($state['step'] ?? null) !== 'photo' || ! is_int($state['topup_id'] ?? null)) {
            return false;
        }

        $photos = $bot->message()?->photo ?? [];
        $photo = $photos === [] ? null : $photos[array_key_last($photos)];
        $messageId = $bot->message()?->message_id;
        if ($photo === null || ! is_int($messageId)) {
            $this->notice($bot, 'لطفاً تصویر معمولی رسید را بفرستید.');
            return true;
        }

        try {
            $this->photos->handle(
                $userId, $state['topup_id'], (int) $this->private->id($bot),
                $photo->file_id, $photo->file_unique_id, $messageId,
            );
        } catch (AuthorizationException|ValidationException|UniqueConstraintViolationException) {
            $this->notice($bot, 'رسید قابل ثبت نیست یا قبلاً استفاده شده است. وضعیت کیف پول را بررسی کنید.');
            return true;
        }

        Cache::forget($this->key($userId));
        $this->notice($bot, "🕘 عکس رسید شارژ #{$state['topup_id']} دریافت شد.\n\nدر انتظار بررسی پرداخت توسط مدیر است. موجودی هنوز افزایش نیافته است.");
        return true;
    }

    public function invalidInput(Nutgram $bot): bool
    {
        $userId = $this->buyer($bot);
        if ($userId === null) {
            return false;
        }

        $state = Cache::get($this->key($userId));
        if (! is_array($state)) {
            return false;
        }

        $this->notice($bot, ($state['step'] ?? null) === 'amount'
            ? 'لطفاً مبلغ شارژ را به تومان وارد کنید یا از کیف پول خارج شوید.'
            : 'لطفاً عکس رسید شارژ را بفرستید یا از کیف پول خارج شوید.');
        return true;
    }

    private function buyer(Nutgram $bot): ?int
    {
        if (TelegramBotContext::current()->key !== TelegramBotContext::SALES) {
            return null;
        }
        $id = $this->private->id($bot);
        if ($id === null) {
            return null;
        }
        $actor = $this->actors->handle($id);
        if ($actor === null || (! in_array('member', $actor->roles, true) && ! in_array('reseller', $actor->roles, true))) {
            return null;
        }

        return $actor->userId;
    }

    private function ready(): bool
    {
        return config('billing.topups_enabled', false) === true
            && Schema::hasTable('billing_topup_photos')
            && app(ManualPaymentUi::class)->cardNumber() !== null;
    }

    private function key(int $userId): string
    {
        return TelegramBotContext::current()->cacheKey('telegram:wallet:topup:'.$userId);
    }

    private function notice(Nutgram $bot, string $message): void
    {
        $this->render($bot, $message, InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ کیف پول', callback_data: 'sales:wallet'))
            ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home')));
    }

    private function render(Nutgram $bot, string $message, InlineKeyboardMarkup $keyboard): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $message, $keyboard, 'HTML');
            return;
        }

        $bot->sendMessage(text: $message, reply_markup: $keyboard, parse_mode: 'HTML');
    }
}

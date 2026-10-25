<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Notifications\WalletTopupNotificationOutbox;
use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Billing\Application\RequestTopup;
use App\Modules\Billing\Application\SubmitTopupPhoto;
use App\Modules\Identity\Application\ResolveTelegramActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final readonly class WalletTopupUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private RequestTopup $requests,
        private SubmitTopupPhoto $photos,
        private WalletTopupNotificationOutbox $notifications,
    ) {}

    public function start(Nutgram $bot): void
    {
        $userId = $this->buyer($bot);
        if ($userId === null) {
            return;
        }
        if (! $this->ready()) {
            $this->notice($bot, 'افزایش اعتبار فعلاً در دسترس نیست. لطفاً بعداً تلاش کنید.');

            return;
        }
        // The next photo must never be associated with an older purchase.
        Cache::forget(TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$userId));
        Cache::forget(TelegramBotContext::current()->cacheKey('telegram:payphoto:recent:'.$userId));
        $pending = $this->pendingTopup($userId);
        if ($pending !== null) {
            Cache::put($this->key($userId), ['step' => 'resume'], now()->addMinutes(10));
            $this->render($bot,
                '<b>🕘 درخواست شارژ نیمه‌تمام</b>'
                ."\n\nدرخواست #{$pending->id}\nمبلغ: <b>".TelegramUi::toman((int) $pending->amount_rials).' تومان</b>'
                ."\nوضعیت: در انتظار ارسال رسید\n\nمی‌توانید همین درخواست را ادامه دهید؛ شارژ جدید جداگانه ثبت می‌شود.",
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('▶️ ادامه پرداخت', callback_data: 'sales:wallet:topup:resume:'.$pending->id, style: ButtonStyle::SUCCESS))
                    ->addRow(InlineKeyboardButton::make('➕ درخواست شارژ جدید', callback_data: 'sales:wallet:topup:new', style: ButtonStyle::PRIMARY))
                    ->addRow(InlineKeyboardButton::make('◀️ اعتبار من', callback_data: 'sales:wallet:topup:cancel')),
            );

            return;
        }
        $this->chooseAmount($bot);
    }

    /** A second pending top-up must be explicitly requested. */
    public function newRequest(Nutgram $bot): void
    {
        $userId = $this->buyer($bot);
        if ($userId === null) {
            return;
        }
        if (! $this->ready()) {
            $this->notice($bot, 'افزایش اعتبار فعلاً در دسترس نیست.');

            return;
        }
        Cache::forget(TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$userId));
        Cache::forget(TelegramBotContext::current()->cacheKey('telegram:payphoto:recent:'.$userId));
        $this->chooseAmount($bot);
    }

    private function chooseAmount(Nutgram $bot): void
    {
        $userId = $this->buyer($bot);
        if ($userId === null) {
            return;
        }
        Cache::put($this->key($userId), ['step' => 'choice'], now()->addMinutes(10));
        $this->render($bot,
            "<b>➕ افزایش اعتبار</b>\n\nمبلغ شارژ را انتخاب کنید.\n<blockquote>حداکثر هر واریز: ۱۰ میلیون تومان\nاعتبار تنها پس از تأیید واریز توسط مدیر اضافه می‌شود.</blockquote>",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('💰 ۱,۰۰۰,۰۰۰ تومان', callback_data: 'sales:wallet:topup:quick:1000000', style: ButtonStyle::SUCCESS))
                ->addRow(InlineKeyboardButton::make('💰 ۷۵۰,۰۰۰ تومان', callback_data: 'sales:wallet:topup:quick:750000', style: ButtonStyle::PRIMARY))
                ->addRow(InlineKeyboardButton::make('✏️ مبلغ دلخواه', callback_data: 'sales:wallet:topup:custom'))
                ->addRow(InlineKeyboardButton::make('◀️ اعتبار من', callback_data: 'sales:wallet:topup:cancel')),
        );
    }

    /** Restore an owned pending request from SQL even when UI cache expired. */
    public function resume(Nutgram $bot, string $topupId): void
    {
        $userId = $this->buyer($bot);
        if ($userId === null) {
            return;
        }
        if (! $this->ready() || preg_match('/\\A[1-9][0-9]{0,14}\\z/D', $topupId) !== 1) {
            $this->notice($bot, 'ادامه این درخواست در دسترس نیست. وضعیت را در اعتبار من بررسی کنید.');

            return;
        }
        $pending = $this->pendingTopup($userId, (int) $topupId);
        if ($pending === null) {
            $this->notice($bot, 'این درخواست دیگر در انتظار رسید نیست یا متعلق به شما نیست. وضعیت را در اعتبار من بررسی کنید.');

            return;
        }

        Cache::forget(TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$userId));
        Cache::forget(TelegramBotContext::current()->cacheKey('telegram:payphoto:recent:'.$userId));
        $toman = intdiv((int) $pending->amount_rials, 10);
        Cache::put($this->key($userId), [
            'step' => 'payment', 'topup_id' => (int) $pending->id, 'amount_toman' => $toman,
        ], now()->addMinutes(10));
        $this->showPayment($bot, (int) $pending->id, $toman);
    }

    private function pendingTopup(int $userId, ?int $topupId = null): ?object
    {
        $query = DB::table('billing_topups as topups')
            ->join('billing_wallets as wallets', 'wallets.id', '=', 'topups.wallet_id')
            ->where('wallets.user_id', $userId)->where('topups.status', 'pending')
            ->select(['topups.id', 'topups.amount_rials']);
        if ($topupId !== null) {
            $query->where('topups.id', $topupId);
        }

        return $query->orderByDesc('topups.id')->first();
    }

    public function custom(Nutgram $bot): void
    {
        $userId = $this->buyer($bot);
        if ($userId === null) {
            return;
        }
        $state = Cache::get($this->key($userId));
        if (! $this->ready() || ! is_array($state) || ! in_array($state['step'] ?? null, ['choice', 'amount'], true)) {
            $this->notice($bot, 'این انتخاب منقضی شده است. افزایش اعتبار را دوباره باز کنید.');

            return;
        }
        Cache::put($this->key($userId), ['step' => 'amount'], now()->addMinutes(10));
        $this->render($bot,
            "<b>✏️ مبلغ دلخواه</b>\n\nمبلغ شارژ را به تومان بفرستید.\nمثلاً: <code>500000</code>\n\nحداقل ۱۰ هزار و حداکثر ۱۰ میلیون تومان.",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('◀️ انتخاب مبلغ', callback_data: 'sales:wallet:topup'))
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'sales:wallet:topup:cancel')),
        );
    }

    public function quick(Nutgram $bot, string $toman): void
    {
        $userId = $this->buyer($bot);
        if ($userId === null) {
            return;
        }
        $state = Cache::get($this->key($userId));
        if (! $this->ready() || ! is_array($state)) {
            $this->notice($bot, 'این انتخاب منقضی شده است. افزایش اعتبار را دوباره باز کنید.');

            return;
        }
        if (in_array($state['step'] ?? null, ['payment', 'photo'], true)) {
            // Old buttons may render payment again only after checking live SQL.
            $this->resume($bot, (string) $state['topup_id']);

            return;
        }
        if (($state['step'] ?? null) !== 'choice' || ! in_array($toman, ['750000', '1000000'], true)) {
            $this->notice($bot, 'برای انتخاب مبلغ، افزایش اعتبار را دوباره باز کنید.');

            return;
        }
        $callbackId = $bot->callbackQuery()?->id;
        if (! is_string($callbackId) || $callbackId === '') {
            $this->notice($bot, 'درخواست معتبر نیست. دوباره تلاش کنید.');

            return;
        }
        $this->createTopup($bot, $userId, (int) $toman, 'cb:'.substr(hash('sha256', $callbackId), 0, 32));
    }

    public function requestReceipt(Nutgram $bot, string $topupId): void
    {
        $userId = $this->buyer($bot);
        if ($userId === null) {
            return;
        }
        $state = Cache::get($this->key($userId));
        if (! is_array($state) || ! in_array($state['step'] ?? null, ['payment', 'photo'], true)
            || ! ctype_digit($topupId) || (string) ($state['topup_id'] ?? '') !== $topupId) {
            $this->notice($bot, 'درخواست فعال پیدا نشد. وضعیت شارژ را از اعتبار من بررسی کنید.');

            return;
        }
        $pending = DB::table('billing_topups as topups')
            ->join('billing_wallets as wallets', 'wallets.id', '=', 'topups.wallet_id')
            ->where('topups.id', (int) $topupId)->where('wallets.user_id', $userId)
            ->where('topups.status', 'pending')->exists();
        if (! $pending) {
            Cache::forget($this->key($userId));
            $this->notice($bot, 'این درخواست دیگر منتظر رسید نیست. وضعیت آن را در اعتبار من بررسی کنید.');

            return;
        }
        Cache::put($this->key($userId), [
            'step' => 'photo', 'topup_id' => (int) $topupId, 'amount_toman' => (int) $state['amount_toman'],
        ], now()->addMinutes(10));
        $this->render($bot,
            "<b>📷 ارسال رسید شارژ #{$topupId}</b>\n\nلطفاً یک عکس معمولی از رسید واریز را همین‌جا بفرستید.\n<blockquote>رسید برای بررسی مدیر ارسال می‌شود؛ تا زمان تأیید، اعتبار تغییری نمی‌کند.</blockquote>",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('◀️ بازگشت به پرداخت', callback_data: 'sales:wallet:topup:back:'.$topupId))
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'sales:wallet:topup:cancel')),
        );
    }

    public function back(Nutgram $bot, string $topupId): void
    {
        $userId = $this->buyer($bot);
        if ($userId === null) {
            return;
        }
        $state = Cache::get($this->key($userId));
        if (! is_array($state) || ($state['step'] ?? null) !== 'photo'
            || (string) ($state['topup_id'] ?? '') !== $topupId) {
            $this->notice($bot, 'صفحه پرداخت منقضی شده است. از اعتبار من دوباره شروع کنید.');

            return;
        }
        $this->resume($bot, $topupId);
    }

    public function cancel(Nutgram $bot): void
    {
        $userId = $this->buyer($bot);
        if ($userId === null) {
            return;
        }
        Cache::forget($this->key($userId));
        app(SalesHomeUi::class)->wallet($bot);
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
            $this->notice($bot, 'افزایش اعتبار فعلاً در دسترس نیست.');

            return true;
        }
        $amountText = strtr(trim($value), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        if (preg_match('/\A[1-9][0-9]{0,7}\z/D', $amountText) !== 1
            || (int) $amountText < 10000 || (int) $amountText > 10000000) {
            $this->notice($bot, 'مبلغ شارژ باید بین ۱۰ هزار تا ۱۰ میلیون تومان باشد.');

            return true;
        }
        $messageId = $bot->message()?->message_id;
        if (! is_int($messageId) || $messageId < 1) {
            $this->notice($bot, 'درخواست معتبر نیست. دوباره تلاش کنید.');

            return true;
        }
        $this->createTopup($bot, $userId, (int) $amountText, 'msg:'.$messageId);

        return true;
    }

    private function createTopup(Nutgram $bot, int $userId, int $toman, string $intent): void
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null || $toman < 10000 || $toman > 10000000) {
            $this->notice($bot, 'مبلغ شارژ معتبر نیست.');

            return;
        }
        try {
            $id = $this->requests->handle($userId, $toman * 10, 'sales:topup:'.$telegramId.':'.$intent);
        } catch (AuthorizationException|ValidationException|UniqueConstraintViolationException) {
            $this->notice($bot, 'درخواست قابل ثبت نیست. از اعتبار من دوباره شروع کنید.');

            return;
        }
        Cache::put($this->key($userId), [
            'step' => 'payment', 'topup_id' => $id, 'amount_toman' => $toman,
        ], now()->addMinutes(10));
        $this->showPayment($bot, $id, $toman);
    }

    private function showPayment(Nutgram $bot, int $id, int $toman): void
    {
        $card = app(ManualPaymentUi::class)->cardNumber();
        if ($card === null) {
            $this->notice($bot, 'مشخصات پرداخت در دسترس نیست. لطفاً با پشتیبانی تماس بگیرید.');

            return;
        }
        $instructions = trim((string) config('billing.payment_instructions', ''));
        $message = "<b>💳 شارژ اعتبار من</b>\n\nدرخواست #{$id}"
            ."\nمبلغ: <b>".TelegramUi::toman($toman * 10).' تومان</b>'
            ."\nشماره کارت: ".TelegramUi::code($card);
        if ($instructions !== '') {
            $message .= "\n".TelegramUi::escape(mb_substr($instructions, 0, 350));
        }
        $message .= "\n\nپس از واریز، دکمه <b>ارسال رسید شارژ</b> را بزنید."
            ."\n<blockquote>حداکثر مبلغ هر واریز ۱۰ میلیون تومان است؛ ارسال رسید به‌معنای تأیید پرداخت نیست.</blockquote>";
        $this->render($bot, $message, InlineKeyboardMarkup::make()
            ->addRow(TelegramUi::copy($card, '📋 کپی شماره کارت'), TelegramUi::copy((string) $toman, '📋 کپی مبلغ'))
            ->addRow(InlineKeyboardButton::make('📷 ارسال رسید شارژ', callback_data: 'sales:wallet:topup:receipt:'.$id, style: ButtonStyle::SUCCESS))
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'sales:wallet:topup:cancel')));
    }

    /** Only consume the photo if this authenticated Sales user awaits a top-up receipt. */
    public function photo(Nutgram $bot): bool
    {
        $userId = $this->buyer($bot);
        if ($userId === null) {
            return false;
        }

        $state = Cache::get($this->key($userId));
        if (! is_array($state)) {
            return false;
        }
        if (($state['step'] ?? null) === 'payment') {
            $this->notice($bot, 'برای ارسال عکس ابتدا دکمه «📷 ارسال رسید شارژ» را بزنید.');

            return true;
        }
        if (($state['step'] ?? null) !== 'photo' || ! is_int($state['topup_id'] ?? null)) {
            return false;
        }

        $photos = $bot->message()->photo ?? [];
        $photo = $photos === [] ? null : $photos[array_key_last($photos)];
        $messageId = $bot->message()?->message_id;
        if ($photo === null || ! is_int($messageId)) {
            $this->notice($bot, 'لطفاً تصویر معمولی رسید را بفرستید.');

            return true;
        }

        try {
            DB::transaction(function () use ($userId, $state, $bot, $photo, $messageId): void {
                $this->photos->handle(
                    $userId, $state['topup_id'], (int) $this->private->id($bot),
                    $photo->file_id, $photo->file_unique_id, $messageId,
                );
                $this->notifications->submitted($state['topup_id']);
            });
        } catch (AuthorizationException|ValidationException|UniqueConstraintViolationException) {
            if ($this->pendingTopup($userId, $state['topup_id']) === null) {
                Cache::forget($this->key($userId));
                $this->notice($bot, 'رسید این شارژ قبلاً ثبت شده یا درخواست بسته شده است. وضعیت را در اعتبار من بررسی کنید.');
            } else {
                $this->notice($bot, 'این تصویر قبلاً استفاده شده یا معتبر نیست. عکس رسید دیگری بفرستید.');
            }

            return true;
        }

        Cache::forget($this->key($userId));
        $this->notice($bot, "<b>🕘 رسید شارژ #{$state['topup_id']} ثبت شد</b>\n\nوضعیت: <b>در انتظار بررسی</b>\nاعتبار پس از تأیید واریز توسط مدیر اضافه می‌شود. تا آن زمان موجودی تغییری نمی‌کند.");

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

        $message = match ($state['step'] ?? null) {
            'choice' => 'برای ادامه یکی از مبلغ‌ها یا «مبلغ دلخواه» را انتخاب کنید.',
            'amount' => 'مبلغ شارژ را بین ۱۰ هزار تا ۱۰ میلیون تومان وارد کنید.',
            'payment' => 'پس از واریز، دکمه «ارسال رسید شارژ» را بزنید.',
            'photo' => 'لطفاً عکس رسید شارژ را بفرستید یا انصراف دهید.',
            default => 'از اعتبار من دوباره شروع کنید.',
        };
        $this->notice($bot, $message);

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
            ->addRow(InlineKeyboardButton::make('◀️ اعتبار من', callback_data: 'sales:wallet:topup:cancel'))
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

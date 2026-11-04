<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Notifications\SalesPanelNotificationOutbox;
use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Sales\Application\SalesPanelRequests;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

final readonly class SalesPanelRequestUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private SalesPanelRequests $requests,
        private SalesPanelNotificationOutbox $notifications,
    ) {}

    public function start(Nutgram $bot): void
    {
        $userId = $this->buyer($bot);
        if ($userId === null) {
            return;
        }
        // Switching to this form cancels old checkout/top-up drafts first.
        app(SalesHomeUi::class)->navigationActor($bot);
        $current = $this->requests->find($userId);
        $actor = $this->actors->handle((int) $this->private->id($bot));
        if (in_array('reseller', $actor->roles, true)) {
            $this->clear($userId);
            $this->notice($bot, '<b>🧩 پنل فروش</b>'."\n\n✅ پنل فروش برای حساب شما فعال است.");

            return;
        }
        if ($current !== null) {
            $this->clear($userId);
            $this->notice($bot, $this->status($current), in_array($current['status'], ['approved', 'rejected'], true));

            return;
        }
        if (! in_array('member', $actor->roles, true)) {
            $this->notice($bot, 'امکان درخواست پنل فروش برای این حساب وجود ندارد.');

            return;
        }

        try {
            if (! $this->requests->hasMinimumWalletBalance($userId)) {
                $this->clear($userId);
                $this->insufficientWallet($bot);

                return;
            }
        } catch (Throwable) {
            $this->clear($userId);
            $this->notice($bot, 'اعتبار فعلاً قابل بررسی نیست. لطفاً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.');

            return;
        }

        Cache::put($this->key($userId), ['step' => 'terms'], now()->addMinutes(10));
        $this->render($bot,
            '<b>🧩 قوانین پنل فروش</b>'
            ."\n\n<blockquote>۱. مدیریت مشتریان و پاسخ‌گویی اولیه بر عهده صاحب پنل است."
            ."\n۲. خرید سرویس‌ها مطابق تعرفه و تخفیف پنل فروش انجام می‌شود."
            ."\n۳. سوءاستفاده، فعالیت متقلبانه یا فروش غیرمجاز ممنوع است."
            ."\n۴. پایداری اتصال ممکن است تحت تأثیر اختلال اینترنت و محدودیت اپراتورها باشد."
            ."\n۵. فعال‌سازی و ادامه فعالیت پنل منوط به تأیید مدیریت و رعایت مقررات است."
            ."\n۶. برای ثبت درخواست، حداقل <b>".TelegramUi::tomanPersian(SalesPanelRequests::minimumWalletBalanceRials()).' تومان</b> اعتبار لازم است. این مبلغ بابت درخواست کسر نمی‌شود.</blockquote>'
            ."\n\nبا پذیرش قوانین می‌توانید درخواست خود را ثبت کنید.",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ می‌پذیرم', callback_data: 'sales:panel:accept', style: ButtonStyle::SUCCESS))
                ->addRow(InlineKeyboardButton::make('↩️ بازگشت', callback_data: 'sales:panel:cancel')));
    }

    public function accept(Nutgram $bot): void
    {
        $userId = $this->buyer($bot);
        if ($userId === null) {
            return;
        }
        $draft = Cache::get($this->key($userId));
        $actor = $this->actors->handle((int) $this->private->id($bot));
        if (! is_array($draft) || ($draft['step'] ?? null) !== 'terms'
            || $actor === null || ! in_array('member', $actor->roles, true)
            || in_array('reseller', $actor->roles, true)
            || $this->requests->find($userId) !== null) {
            $this->clear($userId);
            $this->notice($bot, 'برای ادامه، وضعیت درخواست پنل فروش را دوباره بررسی کنید.');

            return;
        }
        Cache::put($this->key($userId), ['step' => 'first'], now()->addMinutes(10));
        $this->notice($bot,
            '<b>🧩 درخواست پنل فروش</b>'
            ."\n\n<b>مرحله ۱ از ۳</b> · نام خود را ارسال کنید."
            ."\n\n<blockquote>برای بررسی درخواست، نام، نام خانوادگی و موبایل دریافت می‌شود. حساب شما تا تأیید مدیر عادی باقی می‌ماند.</blockquote>");
    }

    public function text(Nutgram $bot, string $value): bool
    {
        $userId = $this->buyer($bot);
        if ($userId === null) {
            return false;
        }
        $draft = Cache::get($this->key($userId));
        if (! is_array($draft) || ! in_array($draft['step'] ?? null, ['first', 'last', 'mobile'], true)) {
            return false;
        }

        if ($this->requests->find($userId) !== null) {
            $this->clear($userId);
            $this->notice($bot, 'درخواست شما قبلاً ثبت شده است. برای مشاهده وضعیت، دوباره وارد پنل فروش شوید.');

            return true;
        }
        $value = trim($value);
        if ($draft['step'] === 'first' || $draft['step'] === 'last') {
            if (! SalesPanelRequests::validName($value)) {
                $this->notice($bot, 'لطفاً نام را با حروف فارسی یا انگلیسی و بین ۲ تا ۶۰ نویسه وارد کنید.');

                return true;
            }
            if ($draft['step'] === 'first') {
                $draft['first'] = $value;
                $draft['step'] = 'last';
                Cache::put($this->key($userId), $draft, now()->addMinutes(10));
                $this->notice($bot, '<b>🧩 درخواست پنل فروش</b>'."\n\n<b>مرحله ۲ از ۳</b> · نام خانوادگی را ارسال کنید.");

                return true;
            }
            $draft['last'] = $value;
            $draft['step'] = 'mobile';
            Cache::put($this->key($userId), $draft, now()->addMinutes(10));
            $this->sendContactPrompt($bot,
                '<b>🧩 درخواست پنل فروش</b>'."\n\n<b>مرحله ۳ از ۳</b> · شماره همین حساب تلگرام را با دکمه زیر ارسال کنید.",
                true);

            return true;
        }

        $this->sendContactPrompt($bot, 'شماره متنی پذیرفته نمی‌شود. از دکمه «📱 اشتراک‌گذاری شماره من» استفاده کنید.');

        return true;
    }

    /** Reopen only an existing, owner-bound phone step; never bypass the form gates. */
    public function showContactKeyboard(Nutgram $bot): void
    {
        $userId = $this->buyer($bot);
        if ($userId === null) {
            return;
        }
        $draft = Cache::get($this->key($userId));
        $actor = $this->actors->handle((int) $this->private->id($bot));
        if (! is_array($draft) || ($draft['step'] ?? null) !== 'mobile'
            || $actor === null || ! in_array('member', $actor->roles, true)
            || in_array('reseller', $actor->roles, true)
            || $this->requests->find($userId) !== null) {
            $this->clear($userId);
            TelegramUi::answerCallback($bot);
            $bot->sendMessage(text: 'مرحله ارسال شماره منقضی شده یا دیگر در دسترس نیست.',
                reply_markup: app(ReplyMenuRenderer::class)->salesKeyboard($actor));
            $this->notice($bot, 'برای ادامه، درخواست نمایندگی را دوباره شروع کنید.');

            return;
        }

        TelegramUi::answerCallback($bot);
        $this->sendContactPrompt($bot, '📱 برای ارسال شماره، دکمه «اشتراک‌گذاری شماره من» را انتخاب کنید.');
    }

    private function sendContactPrompt(Nutgram $bot, string $message, bool $withRecovery = false): void
    {
        $bot->sendMessage(
            text: $message."\n\nاگر دکمه را نمی‌بینید، آیکون کیبورد کنار کادر پیام را بزنید.",
            parse_mode: 'HTML',
            reply_markup: app(ReplyMenuRenderer::class)->salesSelfContactKeyboard(),
        );

        if ($withRecovery) {
            $bot->sendMessage(text: 'دکمه اشتراک‌گذاری نمایش داده نمی‌شود؟',
                reply_markup: InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('🔄 نمایش دوباره دکمه',
                        callback_data: 'sales:panel:phone:show'))
                    ->addRow(InlineKeyboardButton::make('❌ انصراف',
                        callback_data: 'sales:panel:cancel')));
        }
    }

    /** Handle only the active panel phone step; other Contacts belong to the trial flow. */
    public function contact(Nutgram $bot): bool
    {
        $userId = $this->buyer($bot);
        if ($userId === null) {
            return false;
        }

        $draft = Cache::get($this->key($userId));
        if (! is_array($draft) || ($draft['step'] ?? null) !== 'mobile') {
            return false;
        }

        $telegramId = $this->private->id($bot);
        $message = $bot->message();
        $contact = $message?->contact;
        $actor = $telegramId === null ? null : $this->actors->handle($telegramId);

        if ($actor === null || ! in_array('member', $actor->roles, true)
            || in_array('reseller', $actor->roles, true)
            || $this->requests->find($userId) !== null) {
            // Return to the reseller entry and restore the persistent keyboard.
            app(SalesHomeUi::class)->panel($bot);

            return true;
        }

        $mobile = $contact === null ? null : SalesPanelRequests::normalizeMobile((string) $contact->phone_number);
        if ($contact === null || $contact->user_id !== $telegramId
            || $message->forward_origin !== null || $message->via_bot !== null
            || $mobile === null) {
            $error = $contact !== null && $contact->user_id === $telegramId && $mobile === null
                ? 'شماره تأیید نشد؛ برای درخواست نمایندگی فقط موبایل ایران پذیرفته می‌شود.'
                : 'شماره تأیید نشد؛ فقط شماره متعلق به همین حساب تلگرام را با دکمه زیر ارسال کنید.';
            $this->sendContactPrompt($bot, $error);

            return true;
        }

        $draft['mobile'] = $mobile;
        $draft['step'] = 'confirm';
        Cache::put($this->key($userId), $draft, now()->addMinutes(10));

        // Restore the persistent Sales menu before sending the final inline confirmation.
        $bot->sendMessage(
            text: '✅ شماره دریافت شد.',
            reply_markup: app(ReplyMenuRenderer::class)->salesKeyboard(
                $actor,
                app(SalesTrialUi::class)->visible($actor, $telegramId),
            ),
        );
        $this->render($bot,
            '<b>📝 بررسی درخواست پنل فروش</b>'
            ."\n\nنام: <b>".TelegramUi::escape((string) $draft['first']).'</b>'
            ."\nنام خانوادگی: <b>".TelegramUi::escape((string) $draft['last']).'</b>'
            ."\nموبایل: ".TelegramUi::code($mobile)
            ."\n\n<blockquote>پس از ثبت، درخواست برای بررسی مدیر آماده خواهد بود و دسترسی جدیدی تا تأیید ایجاد نمی‌شود.</blockquote>",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ ثبت درخواست', callback_data: 'sales:panel:confirm', style: ButtonStyle::SUCCESS))
                ->addRow(InlineKeyboardButton::make('✏️ اصلاح اطلاعات', callback_data: 'sales:panel:request'))
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'sales:panel:cancel')));

        return true;
    }

    public function confirm(Nutgram $bot): void
    {
        $userId = $this->buyer($bot);
        if ($userId === null) {
            return;
        }
        // A stale confirmation must not consume the terms or input draft.
        $pending = Cache::get($this->key($userId));
        if (! is_array($pending) || ($pending['step'] ?? null) !== 'confirm') {
            $this->notice($bot, 'برای ثبت درخواست، ابتدا مراحل و تأیید نهایی را کامل کنید.');

            return;
        }
        $draft = Cache::pull($this->key($userId));
        if (! is_array($draft) || ($draft['step'] ?? null) !== 'confirm'
            || ! is_string($draft['first'] ?? null) || ! is_string($draft['last'] ?? null)
            || ! is_string($draft['mobile'] ?? null)) {
            $this->notice($bot, 'زمان ثبت درخواست به پایان رسیده یا قبلاً ثبت شده است. وضعیت را دوباره بررسی کنید.');

            return;
        }
        try {
            $row = DB::transaction(function () use ($userId, $draft): array {
                $result = $this->requests->submit($userId, $draft['first'], $draft['last'], $draft['mobile']);
                $this->notifications->submitted($result['id']);

                return $result;
            });
        } catch (ValidationException $exception) {
            if (isset($exception->errors()['wallet_balance'])) {
                $this->insufficientWallet($bot);
            } else {
                $this->notice($bot, 'ثبت درخواست ممکن نیست. لطفاً وضعیت حساب خود را بررسی کنید.');
            }

            return;
        } catch (AuthorizationException) {
            $this->notice($bot, 'ثبت درخواست ممکن نیست. لطفاً وضعیت حساب خود را بررسی کنید.');

            return;
        }
        $this->notice($bot, $this->status($row), in_array($row['status'], ['approved', 'rejected'], true));
    }

    public function cancel(Nutgram $bot): void
    {
        $userId = $this->buyer($bot);
        if ($userId === null) {
            return;
        }
        // Return to the reseller entry and restore any temporary Contact keyboard.
        app(SalesHomeUi::class)->panel($bot);
    }

    public function invalidInput(Nutgram $bot): bool
    {
        $userId = $this->buyer($bot);
        if ($userId === null) {
            return false;
        }
        $draft = Cache::get($this->key($userId));
        if (! is_array($draft)) {
            return false;
        }

        if (($draft['step'] ?? null) === 'mobile') {
            $this->sendContactPrompt($bot, 'برای ادامه، شماره خود را با دکمه اشتراک‌گذاری ارسال کنید.');

            return true;
        }

        $this->notice($bot, match ($draft['step'] ?? null) {
            'terms' => 'برای ادامه، ابتدا قوانین پنل فروش را بپذیرید یا بازگردید.',
            'confirm' => 'برای ثبت درخواست، دکمه تأیید را بزنید یا اطلاعات را اصلاح کنید.',
            default => 'برای ادامه، اطلاعات خواسته‌شده را ارسال کنید یا انصراف بزنید.',
        });

        return true;
    }

    public function clear(int $userId): bool
    {
        $draft = Cache::pull($this->key($userId));

        return is_array($draft) && ($draft['step'] ?? null) === 'mobile';
    }

    private function key(int $userId): string
    {
        return TelegramBotContext::current()->cacheKey('telegram:panel:request:'.$userId);
    }

    private function buyer(Nutgram $bot): ?int
    {
        if (TelegramBotContext::current()->key !== TelegramBotContext::SALES) {
            return null;
        }
        $telegramId = $this->private->id($bot);
        if ($telegramId === null) {
            return null;
        }
        $actor = $this->actors->handle($telegramId);

        return $actor?->userId;
    }

    /** @param array{id:int,status:string,first_name:string,last_name:string,mobile:string} $row */
    private function status(array $row): string
    {
        $text = match ($row['status']) {
            'approved' => 'ℹ️ درخواست شما قبلاً تأیید شده است، اما دسترسی پنل فروش در حال حاضر فعال نیست. برای پیگیری با پشتیبانی تماس بگیرید.',
            'rejected' => '❌ درخواست شما تأیید نشده است. برای پیگیری با پشتیبانی تماس بگیرید.',
            default => '🕘 درخواست شما ثبت شد و در انتظار بررسی است.',
        };

        return '<b>🧩 وضعیت پنل فروش</b>'
            ."\n\nشناسه درخواست: ".TelegramUi::code((string) $row['id'])
            ."\n{$text}";
    }

    private function insufficientWallet(Nutgram $bot): void
    {
        $this->render($bot,
            '<b>💰 اعتبار کافی نیست</b>'
            ."\n\nبرای ارسال درخواست پنل فروش، باید حداقل <b>".TelegramUi::tomanPersian(SalesPanelRequests::minimumWalletBalanceRials()).' تومان</b> اعتبار خود را افزایش دهید.'
            ."\n\n<blockquote>این شارژ پس از تأیید پنل فروش برای خرید سرویس قابل استفاده است و بابت درخواست از اعتبار شما کسر نمی‌شود.</blockquote>",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('💰 اعتبار من', callback_data: 'sales:wallet'))
                ->addRow(InlineKeyboardButton::make('💬 پشتیبانی', callback_data: 'sales:support'))
                ->addRow(InlineKeyboardButton::make('↩️ بازگشت', callback_data: 'sales:panel:hub')));
    }

    private function notice(Nutgram $bot, string $message, bool $withSupport = false): void
    {
        $keyboard = InlineKeyboardMarkup::make();
        if ($withSupport) {
            // The existing Sales support flow owns the validated operator link.
            $keyboard->addRow(InlineKeyboardButton::make(
                '💬 ارتباط با پشتیبانی',
                callback_data: 'sales:support',
                style: ButtonStyle::PRIMARY,
            ));
        }
        $this->render($bot, $message, $keyboard
            ->addRow(InlineKeyboardButton::make('◀️ نمایندگی', callback_data: 'sales:panel:hub'))
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

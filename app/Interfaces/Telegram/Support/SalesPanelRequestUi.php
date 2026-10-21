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
            $this->notice($bot, $this->status($current));

            return;
        }
        if (! in_array('member', $actor->roles, true)) {
            $this->notice($bot, 'امکان درخواست پنل فروش برای این حساب وجود ندارد.');

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
            $this->notice($bot, '<b>🧩 درخواست پنل فروش</b>'."\n\n<b>مرحله ۳ از ۳</b> · شماره موبایل را ارسال کنید."
                ."\nنمونه: <code>09123456789</code>");

            return true;
        }

        $mobile = SalesPanelRequests::normalizeMobile($value);
        if ($mobile === null) {
            $this->notice($bot, 'شماره موبایل معتبر نیست. نمونه: <code>09123456789</code>');

            return true;
        }
        $draft['mobile'] = $mobile;
        $draft['step'] = 'confirm';
        Cache::put($this->key($userId), $draft, now()->addMinutes(10));
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
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'ثبت درخواست ممکن نیست. لطفاً وضعیت حساب خود را بررسی کنید.');

            return;
        }
        $this->notice($bot, $this->status($row));
    }

    public function cancel(Nutgram $bot): void
    {
        $userId = $this->buyer($bot);
        if ($userId === null) {
            return;
        }
        $this->clear($userId);
        app(SalesHomeUi::class)->account($bot);
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

        $this->notice($bot, ($draft['step'] ?? null) === 'confirm'
            ? 'برای ثبت درخواست، دکمه تأیید را بزنید یا اطلاعات را اصلاح کنید.'
            : 'برای ادامه، اطلاعات خواسته‌شده را ارسال کنید یا انصراف بزنید.');

        return true;
    }

    public function clear(int $userId): void
    {
        Cache::forget($this->key($userId));
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
            'approved' => '✅ درخواست شما تأیید شده است.',
            'rejected' => '❌ درخواست شما تأیید نشده است. برای پیگیری با پشتیبانی تماس بگیرید.',
            default => '🕘 درخواست شما ثبت شد و در انتظار بررسی است.',
        };

        return '<b>🧩 وضعیت پنل فروش</b>'
            ."\n\nشناسه درخواست: ".TelegramUi::code((string) $row['id'])
            ."\n{$text}"
            ."\n\nنام: ".TelegramUi::escape($row['first_name'].' '.$row['last_name'])
            ."\nموبایل: ".TelegramUi::code(substr($row['mobile'], 0, 4).'***'.substr($row['mobile'], -4));
    }

    private function notice(Nutgram $bot, string $message): void
    {
        $this->render($bot, $message, InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ حساب کاربری', callback_data: 'sales:account'))
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

<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\LinkTelegramAccount;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\TelegramActor;
use App\Modules\Onboarding\Application\JoinFromTelegram;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * SB-01: Sales-only entry and identity UI. No checkout, payment or provider calls.
 * Identity/registration actions are shared with the legacy bot.
 */
final readonly class SalesHomeUi
{
    public function __construct(
        private PrivateTelegramUser $privateUser,
        private ResolveTelegramActor $resolve,
        private PendingReferral $referrals,
        private JoinFromTelegram $onboard,
        private LinkTelegramAccount $linkAccount,
    ) {}

    public function start(Nutgram $bot, ?string $payload = null): void
    {
        $telegramId = $this->privateUser->id($bot);
        if ($telegramId === null) {
            return;
        }

        $actor = $this->resolve->handle($telegramId);
        if ($actor === null) {
            if (! $bot->isCallbackQuery()) {
                // A new /start replaces an older pending referral.
                $this->referrals->remember($telegramId, $payload ?? '');
            }

            $this->guest($bot);

            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🛒 انتخاب پلن', callback_data: 'menu:plan-catalog'));
        if (in_array('reseller', $actor->roles, true)) {
            $keyboard->addRow(InlineKeyboardButton::make('💼 پلن‌ساز نماینده', callback_data: 'menu:builder'));
        }
        $keyboard->addRow(InlineKeyboardButton::make('📋 سفارش‌های من', callback_data: 'sales:checkout:orders'));
        $keyboard->addRow(InlineKeyboardButton::make('📦 اشتراک‌های من', callback_data: 'sales:subs:list:0'));
        $keyboard->addRow(InlineKeyboardButton::make('👤 حساب من', callback_data: 'sales:account'));

        $this->render(
            $bot,
            "🏠 ParsaGate\n\nخوش آمدید، ".$this->name($actor->name)
                .".\nنوع حساب: ".$this->salesRole($actor)
                ."\n\nبرای مشاهده پلن‌ها و قیمت‌ها، گزینه زیر را انتخاب کنید.\nثبت سفارش فقط برای پلن‌های آماده امکان‌پذیر است."
                .(config('sales.manual_bank_review_enabled', false) === true
                    ? "\nپس از سفارش، می‌توانید رسید پرداخت را ارسال کنید."
                    : "\nپرداخت فعلاً فعال نیست."),
            $keyboard,
        );
    }

    public function account(Nutgram $bot): void
    {
        $telegramId = $this->privateUser->id($bot);
        if ($telegramId === null) {
            return;
        }

        $actor = $this->resolve->handle($telegramId);
        if ($actor === null) {
            $this->guest($bot);

            return;
        }

        $this->render(
            $bot,
            "👤 حساب من\n\nنام: ".$this->name($actor->name)
                ."\nشناسه ParsaGate: {$actor->userId}"
                ."\nنوع حساب: ".$this->salesRole($actor)
                ."\nوضعیت: 🟢 فعال",
            $this->back(),
        );
    }

    public function join(Nutgram $bot): void
    {
        if (! $bot->isCallbackQuery()) {
            return;
        }

        $telegramId = $this->privateUser->id($bot);
        if ($telegramId === null) {
            return;
        }

        if (config('telegram.public_signup_enabled', false) !== true) {
            $this->render($bot, '🔒 ثبت‌نام در حال حاضر غیرفعال است.', $this->back());

            return;
        }

        $actor = $this->resolve->handle($telegramId);
        if ($actor !== null) {
            $this->start($bot);

            return;
        }

        $this->onboard->handle(
            $telegramId,
            (string) ($bot->user()->first_name ?? 'کاربر'),
            $this->referrals->take($telegramId),
        );

        $actor = $this->resolve->handle($telegramId);
        if ($actor === null) {
            $this->render($bot, 'حساب شما غیرفعال است. برای پیگیری با پشتیبانی تماس بگیرید.', $this->back());

            return;
        }

        $this->start($bot);
    }

    public function link(Nutgram $bot, string $code): void
    {
        $telegramId = $this->privateUser->id($bot);
        if ($telegramId === null) {
            return;
        }

        if (! $this->linkAccount->handle($code, $telegramId)) {
            $this->render($bot, 'کد اتصال نامعتبر، منقضی یا قبلاً مصرف شده است.', $this->back());

            return;
        }

        $this->start($bot);
    }

    private function guest(Nutgram $bot): void
    {
        if (config('telegram.public_signup_enabled', false) === true) {
            $this->render(
                $bot,
                "👋 به ParsaGate خوش آمدید.\n\nبرای ساخت حساب کاربری، دکمه زیر را انتخاب کنید.\nاگر از قبل حساب دارید، کد اتصال دریافتی را با دستور /link وارد کنید.",
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('✅ ایجاد حساب', callback_data: 'sales:join')),
            );

            return;
        }

        $this->render(
            $bot,
            "🔒 برای استفاده از ParsaGate ابتدا حساب خود را متصل کنید.\n\nکد یک‌بارمصرف دریافتی از مدیر را با دستور /link وارد کنید.",
            $this->back(),
        );
    }

    private function salesRole(TelegramActor $actor): string
    {
        return in_array('reseller', $actor->roles, true) ? 'نماینده فروش' : 'کاربر';
    }

    private function name(string $name): string
    {
        return trim((string) preg_replace('/[\r\n\x00-\x1F\x7F]+/u', ' ', $name));
    }

    private function back(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🏠 صفحه اصلی', callback_data: 'sales:home'));
    }

    private function render(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $text, $keyboard);

            return;
        }

        $bot->sendMessage(text: $text, reply_markup: $keyboard);
    }
}

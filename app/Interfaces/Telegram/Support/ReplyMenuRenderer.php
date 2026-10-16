<?php

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\TelegramActor;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardRemove;

final class ReplyMenuRenderer
{
    public function __construct(private readonly MenuRenderer $inlineMenus) {}

    public function showHome(Nutgram $bot, TelegramActor $actor): void
    {
        if ($actor->isAdmin()) {
            $this->showAdmin($bot, $actor);

            return;
        }

        $name = trim((string) preg_replace('/[\r\n\x00-\x1F\x7F]+/u', ' ', $actor->name));

        $bot->sendMessage(
            text: "🏠 ParsaGate\n\nخوش آمدید، {$name}.",
            reply_markup: $this->homeKeyboard($actor),
        );
    }

    public function showAdmin(Nutgram $bot, TelegramActor $actor): void
    {
        $bot->sendMessage(
            text: "🛡️ مدیریت ParsaGate\n\nبخش موردنظر را از منوی پایین انتخاب کنید.",
            reply_markup: $this->adminKeyboard($actor),
        );
    }

    public function showUnauthenticated(Nutgram $bot): void
    {
        if (config('telegram.public_signup_enabled', false)) {
            $this->inlineMenus->showHome($bot, null);

            return;
        }

        $bot->sendMessage(
            text: '🔒 حساب شما به ParsaGate متصل نیست. برای اتصال، کد یک‌بارمصرف صادرشده توسط مدیر را در گفت‌وگوی خصوصی با دستور /link وارد کنید.',
            reply_markup: ReplyKeyboardRemove::make(true),
        );
    }

    public function homeKeyboard(TelegramActor $actor): ReplyKeyboardMarkup
    {
        if ($actor->isAdmin()) {
            return $this->adminKeyboard($actor);
        }

        $labels = [
            ReplyMenuLabels::BUY,
            ReplyMenuLabels::SUBSCRIPTIONS,
            ReplyMenuLabels::CATALOG,
            ReplyMenuLabels::ACCOUNT,
            ReplyMenuLabels::INVITE,
            ReplyMenuLabels::SETTINGS,
        ];

        if (in_array('reseller', $actor->roles, true)) {
            $labels[] = ReplyMenuLabels::WALLET;
            $labels[] = ReplyMenuLabels::BUILDER;
        }

        return $this->keyboard($labels);
    }

    public function adminKeyboard(TelegramActor $actor): ReplyKeyboardMarkup
    {
        $labels = [];

        if ($actor->isAdmin()) {
            $labels[] = ReplyMenuLabels::USERS;
        }

        if ($actor->canManageRoles()) {
            $labels[] = ReplyMenuLabels::ROLES;
            $labels[] = ReplyMenuLabels::SALES_SECTION;

            if (config('billing.topups_enabled', false) === true
                || config('sales.manual_bank_review_enabled', false) === true) {
                $labels[] = ReplyMenuLabels::FINANCE_SECTION;
            }
        }

        // A primary-menu HOME button would only reopen this same Admin screen.
        // The original HOME label remains accepted by the dispatcher for stale keyboards.
        $labels[] = ReplyMenuLabels::ACCOUNT;

        return $this->keyboard($labels);
    }

    /**
     * @param  list<string>  $labels
     */
    private function keyboard(array $labels): ReplyKeyboardMarkup
    {
        $keyboard = ReplyKeyboardMarkup::make(
            resize_keyboard: true,
            one_time_keyboard: false,
            input_field_placeholder: 'بخش موردنظر را انتخاب کنید',
            is_persistent: true,
        );

        foreach (array_chunk($labels, 2) as $row) {
            $keyboard->addRow(...array_map(
                static fn (string $label): KeyboardButton => KeyboardButton::make($label),
                $row,
            ));
        }

        return $keyboard;
    }
}

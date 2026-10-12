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
        $name = trim((string) preg_replace('/[\r\n\x00-\x1F\x7F]+/u', ' ', $actor->name));

        $bot->sendMessage(
            text: "🏠 SkyNet\n\nخوش آمدید، {$name}.",
            reply_markup: $this->homeKeyboard($actor),
        );
    }

    public function showAdmin(Nutgram $bot, TelegramActor $actor): void
    {
        $bot->sendMessage(
            text: "🛡️ مدیریت SkyNet\n\nبخش موردنظر را از منوی پایین انتخاب کنید.",
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
            text: '🔒 حساب شما به SkyNet متصل نیست. برای اتصال، کد یک‌بارمصرف صادرشده توسط مدیر را در گفت‌وگوی خصوصی با دستور /link وارد کنید.',
            reply_markup: ReplyKeyboardRemove::make(true),
        );
    }

    public function homeKeyboard(TelegramActor $actor): ReplyKeyboardMarkup
    {
        $labels = [
            ReplyMenuLabels::BUY,
            ReplyMenuLabels::SUBSCRIPTIONS,
            ReplyMenuLabels::ACCOUNT,
            ReplyMenuLabels::INVITE,
            ReplyMenuLabels::SETTINGS,
        ];

        if (in_array('reseller', $actor->roles, true)) {
            $labels[] = ReplyMenuLabels::WALLET;
        }

        if ($actor->isAdmin()) {
            $labels[] = ReplyMenuLabels::ADMIN;
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

            if (config('billing.topups_enabled', false) === true) {
                $labels[] = ReplyMenuLabels::TOPUPS;
            }

            if (config('sales.manual_bank_review_enabled', false) === true) {
                $labels[] = ReplyMenuLabels::RECEIPTS;
            }
        }

        $labels[] = ReplyMenuLabels::HOME;

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
            input_field_placeholder: 'از منو انتخاب کنید یا دستور بنویسید',
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

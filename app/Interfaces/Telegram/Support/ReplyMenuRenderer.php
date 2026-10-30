<?php

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\TelegramActor;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardRemove;

final class ReplyMenuRenderer
{
    public function __construct(private readonly MenuRenderer $inlineMenus) {}

    public function showHome(Nutgram $bot, TelegramActor $actor): void
    {
        if ($actor->canEnterAdmin()) {
            $this->showAdmin($bot, $actor);

            return;
        }

        $name = trim((string) preg_replace('/[\r\n\x00-\x1F\x7F]+/u', ' ', $actor->name));

        $bot->sendMessage(
            text: "🏠 DenaGate\n\nخوش آمدید، {$name}.",
            reply_markup: $this->homeKeyboard($actor),
        );
    }

    public function showAdmin(Nutgram $bot, TelegramActor $actor): void
    {
        $bot->sendMessage(
            text: "🛡️ مدیریت DenaGate\n\nبخش موردنظر را از منوی پایین انتخاب کنید.",
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
            text: '🔒 حساب شما به DenaGate متصل نیست. برای اتصال، کد یک‌بارمصرف صادرشده توسط مدیر را در گفت‌وگوی خصوصی با دستور /link وارد کنید.',
            reply_markup: ReplyKeyboardRemove::make(true),
        );
    }

    public function homeKeyboard(TelegramActor $actor): ReplyKeyboardMarkup
    {
        if ($actor->canEnterAdmin()) {
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
            $labels[] = ReplyMenuLabels::SALES_WALLET;
            $labels[] = ReplyMenuLabels::BUILDER;
        }

        return $this->keyboard($labels);
    }

    public function adminKeyboard(TelegramActor $actor): ReplyKeyboardMarkup
    {
        $labels = $actor->canManageRoles() ? [ReplyMenuLabels::DENABAN] : [];

        if ($actor->allows('users.view') || $actor->allows('support.view')) {
            $labels[] = ReplyMenuLabels::USERS;
        }

        if ($actor->canManageRoles()) {
            $labels[] = ReplyMenuLabels::ROLES;
            $labels[] = ReplyMenuLabels::SALES_SECTION;

            $labels[] = ReplyMenuLabels::SERVERS;

            $labels[] = ReplyMenuLabels::FINANCE_SECTION;
            $labels[] = ReplyMenuLabels::STATS;
            $labels[] = ReplyMenuLabels::SETTINGS;
        }

        if (! $actor->canManageRoles()) {
            if ($actor->allows('plans.manage') || $actor->allows('resellers.review')) {
                $labels[] = ReplyMenuLabels::SALES_SECTION;
            }
            if ($actor->allows('reports.view')) {
                $labels[] = ReplyMenuLabels::STATS;
            }
            if ($actor->allows('finance.view')) {
                $labels[] = ReplyMenuLabels::FINANCE_SECTION;
            }
            if ($actor->allows('servers.view')) {
                $labels[] = ReplyMenuLabels::SERVERS;
            }
            if ($actor->allows('purchases.view')) {
                $labels[] = ReplyMenuLabels::RECEIPTS;
            }
            if ($actor->allows('topups.view')) {
                $labels[] = ReplyMenuLabels::TOPUPS;
            }
        }

        // Keep account and home routes available for previously delivered buttons.
        // Neither belongs in the compact Admin home keyboard.
        return $this->keyboard($labels);
    }

    /** Sales has its own registry; never expose the legacy/Admin keyboard here. */
    public function salesKeyboard(?TelegramActor $actor = null, bool $trialVisible = false, bool $panelRequestExists = false): ReplyKeyboardMarkup
    {
        $labels = [
            ReplyMenuLabels::SALES_BUY,
            ReplyMenuLabels::SALES_SUBSCRIPTIONS,
        ];
        $isReseller = $actor !== null && in_array('reseller', $actor->roles, true);
        $hasWallet = $actor !== null && (in_array('member', $actor->roles, true) || $isReseller);

        // Keep the buy/services row first. Prioritize credit for both audiences,
        // and the existing sales report for resellers; no routes or roles change.
        if ($hasWallet) {
            $labels[] = ReplyMenuLabels::SALES_WALLET;
        }
        if ($isReseller) {
            $labels[] = ReplyMenuLabels::SALES_REPORTS;
            $labels[] = ReplyMenuLabels::BUILDER;
            $labels[] = ReplyMenuLabels::SALES_SUPPORT;
            $labels[] = ReplyMenuLabels::SALES_ACCOUNT;
            $labels[] = ReplyMenuLabels::SALES_TUTORIAL;
        } else {
            $labels[] = ReplyMenuLabels::SALES_ACCOUNT;
            $labels[] = ReplyMenuLabels::SALES_SUPPORT;
            $labels[] = ReplyMenuLabels::SALES_TUTORIAL;
        }
        if ($actor !== null
            && in_array('member', $actor->roles, true)
            && ! in_array('reseller', $actor->roles, true)
            && ! in_array('admin', $actor->roles, true)
            && ! in_array('super-admin', $actor->roles, true)) {
            $labels[] = $panelRequestExists
                ? ReplyMenuLabels::SALES_PANEL_STATUS
                : ReplyMenuLabels::SALES_PANEL_REQUEST;
        }
        if ($trialVisible) {
            $labels[] = ReplyMenuLabels::SALES_TRIAL;
        }
        $keyboard = $this->keyboard($labels);

        // Native Telegram styles apply only to Sales, not legacy/Admin menus.
        $keyboard->keyboard[0][0]->style = ButtonStyle::SUCCESS;
        $keyboard->keyboard[0][1]->style = ButtonStyle::PRIMARY;

        return $keyboard;
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

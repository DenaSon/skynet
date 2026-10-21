<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use RuntimeException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Command\BotCommand;
use SergiX44\Nutgram\Telegram\Types\Command\MenuButtonCommands;

/** Sales-only native Telegram hamburger menu; register once per deploy, never per webhook. */
final class SalesNativeMenu
{
    /** @return list<BotCommand> */
    public static function commands(): array
    {
        return [
            BotCommand::make('buy', '🛒 خرید سرویس'),
            BotCommand::make('subscriptions', '📋 سرویس‌های من'),
            BotCommand::make('account', '👤 حساب کاربری'),
            BotCommand::make('help', '💬 پشتیبانی'),
        ];
    }

    public static function register(Nutgram $bot): void
    {
        if ($bot->setMyCommands(self::commands()) !== true) {
            throw new RuntimeException('Sales command registration was not acknowledged.');
        }

        if ($bot->setChatMenuButton(menu_button: new MenuButtonCommands()) !== true) {
            throw new RuntimeException('Sales native menu registration was not acknowledged.');
        }
    }
}

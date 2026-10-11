<?php

use App\Interfaces\Telegram\Handlers\AccountHandler;
use App\Interfaces\Telegram\Handlers\AdminHandler;
use App\Interfaces\Telegram\Handlers\AdminRolesHandler;
use App\Interfaces\Telegram\Handlers\AdminUsersHandler;
use App\Interfaces\Telegram\Handlers\LinkHandler;
use App\Interfaces\Telegram\Handlers\SettingsHandler;
use App\Interfaces\Telegram\Handlers\StartHandler;
use SergiX44\Nutgram\Nutgram;

/** @var Nutgram $bot */
$bot->onCommand('start', StartHandler::class);
$bot->onCommand('menu', StartHandler::class);
$bot->onCommand('account', AccountHandler::class);
$bot->onCommand('settings', SettingsHandler::class);
$bot->onCommand('admin', AdminHandler::class);
$bot->onCommand('link {code}', LinkHandler::class);

$bot->onCallbackQueryData('menu:home', StartHandler::class);
$bot->onCallbackQueryData('menu:account', AccountHandler::class);
$bot->onCallbackQueryData('menu:settings', SettingsHandler::class);
$bot->onCallbackQueryData('menu:admin', AdminHandler::class);
$bot->onCallbackQueryData('menu:admin:users', AdminUsersHandler::class);
$bot->onCallbackQueryData('menu:admin:roles', AdminRolesHandler::class);

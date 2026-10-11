<?php

use App\Interfaces\Telegram\Handlers\AccountHandler;
use App\Interfaces\Telegram\Handlers\AdminHandler;
use App\Interfaces\Telegram\Handlers\AdminRolesHandler;
use App\Interfaces\Telegram\Handlers\AdminUsersHandler;
use App\Interfaces\Telegram\Handlers\LinkHandler;
use App\Interfaces\Telegram\Handlers\SettingsHandler;
use App\Interfaces\Telegram\Handlers\StartHandler;
use App\Interfaces\Telegram\Support\UserManagementUi;
use SergiX44\Nutgram\Nutgram;

/** @var Nutgram $bot */
$bot->onCommand('start', StartHandler::class);
$bot->onCommand('menu', StartHandler::class);
$bot->onCommand('account', AccountHandler::class);
$bot->onCommand('settings', SettingsHandler::class);
$bot->onCommand('admin', AdminHandler::class);
$bot->onCommand('link {code}', LinkHandler::class);
$bot->onCommand('users', AdminUsersHandler::class);
$bot->onCommand('finduser {query}', function (Nutgram $bot, string $query): void {
    app(UserManagementUi::class)->search($bot, $query);
});

$bot->onCallbackQueryData('menu:home', StartHandler::class);
$bot->onCallbackQueryData('menu:account', AccountHandler::class);
$bot->onCallbackQueryData('menu:settings', SettingsHandler::class);
$bot->onCallbackQueryData('menu:admin', AdminHandler::class);
$bot->onCallbackQueryData('menu:admin:users', AdminUsersHandler::class);
$bot->onCallbackQueryData('menu:admin:roles', AdminRolesHandler::class);

$bot->onCallbackQueryData('users:page:{page}', function (Nutgram $bot, string $page): void {
    app(UserManagementUi::class)->browse($bot, (int) $page);
});
$bot->onCallbackQueryData('users:findpage:{page}', function (Nutgram $bot, string $page): void {
    app(UserManagementUi::class)->browse($bot, (int) $page, true);
});
$bot->onCallbackQueryData('users:search', function (Nutgram $bot): void {
    app(UserManagementUi::class)->searchHelp($bot);
});
$bot->onCallbackQueryData('users:view:{id}:{page}:{mode}', function (Nutgram $bot, string $id, string $page, string $mode): void {
    app(UserManagementUi::class)->details($bot, $id, $page, $mode);
});
$bot->onCallbackQueryData('users:confirm:{id}:{page}:{mode}', function (Nutgram $bot, string $id, string $page, string $mode): void {
    app(UserManagementUi::class)->confirm($bot, $id, $page, $mode);
});
$bot->onCallbackQueryData('users:apply:{token}', function (Nutgram $bot, string $token): void {
    app(UserManagementUi::class)->apply($bot, $token);
});

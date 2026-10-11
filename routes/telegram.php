<?php

use App\Interfaces\Telegram\Handlers\AccountHandler;
use App\Interfaces\Telegram\Handlers\AdminHandler;
use App\Interfaces\Telegram\Handlers\AdminRolesHandler;
use App\Interfaces\Telegram\Handlers\AdminUsersHandler;
use App\Interfaces\Telegram\Handlers\LinkHandler;
use App\Interfaces\Telegram\Handlers\SettingsHandler;
use App\Interfaces\Telegram\Handlers\StartHandler;
use App\Interfaces\Telegram\Support\RbacUi;
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
$bot->onCommand('roles', AdminRolesHandler::class);
$bot->onCommand('newrole {name}', function (Nutgram $bot, string $name): void {
    app(RbacUi::class)->createRequest($bot, $name);
});
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

$bot->onCallbackQueryData('rbac:page:{page}', function (Nutgram $bot, string $page): void {
    app(RbacUi::class)->browse($bot, (int) $page);
});
$bot->onCallbackQueryData('rbac:role:{id}:{page}', function (Nutgram $bot, string $id, string $page): void {
    app(RbacUi::class)->detail($bot, $id, $page);
});
$bot->onCallbackQueryData('rbac:new', function (Nutgram $bot): void {
    app(RbacUi::class)->createHelp($bot);
});
$bot->onCallbackQueryData('rbac:perm:{role}:{permission}', function (Nutgram $bot, string $role, string $permission): void {
    app(RbacUi::class)->permissionRequest($bot, $role, $permission);
});
$bot->onCallbackQueryData('rbac:user:{id}:{page}', function (Nutgram $bot, string $id, string $page): void {
    app(RbacUi::class)->userRoles($bot, $id, $page);
});
$bot->onCallbackQueryData('rbac:member:{user}:{role}:{page}', function (Nutgram $bot, string $user, string $role, string $page): void {
    app(RbacUi::class)->userRoleRequest($bot, $user, $role, $page);
});
$bot->onCallbackQueryData('rbac:apply:{token}', function (Nutgram $bot, string $token): void {
    app(RbacUi::class)->apply($bot, $token);
});

$bot->onCallbackQueryData('rbac:p:{id}:{page}:{permissionPage}', function (Nutgram $bot, string $id, string $page, string $permissionPage): void {
    app(RbacUi::class)->detail($bot, $id, $page, $permissionPage);
});

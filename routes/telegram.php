<?php

use App\Interfaces\Telegram\Handlers\AccountHandler;
use App\Interfaces\Telegram\Handlers\AdminHandler;
use App\Interfaces\Telegram\Handlers\AdminRolesHandler;
use App\Interfaces\Telegram\Handlers\AdminUsersHandler;
use App\Interfaces\Telegram\Handlers\InviteHandler;
use App\Interfaces\Telegram\Handlers\JoinHandler;
use App\Interfaces\Telegram\Handlers\LinkHandler;
use App\Interfaces\Telegram\Handlers\SettingsHandler;
use App\Interfaces\Telegram\Handlers\StartHandler;
use App\Interfaces\Telegram\Support\BillingUi;
use App\Interfaces\Telegram\Support\ManualPaymentUi;
use App\Interfaces\Telegram\Support\RbacUi;
use App\Interfaces\Telegram\Support\ShopUi;
use App\Interfaces\Telegram\Support\SubscriptionUi;
use App\Interfaces\Telegram\Support\UserManagementUi;
use SergiX44\Nutgram\Nutgram;

/** @var Nutgram $bot */
$bot->onCommand('start {payload}', StartHandler::class);
$bot->onCommand('start', StartHandler::class);
$bot->onCommand('menu', StartHandler::class);
$bot->onCommand('account', AccountHandler::class);
$bot->onCommand('invite', InviteHandler::class);
$bot->onCommand('buy', function (Nutgram $bot): void {
    app(ShopUi::class)->open($bot);
});
$bot->onCommand('subscriptions', function (Nutgram $bot): void {
    app(SubscriptionUi::class)->browse($bot);
});
$bot->onCommand('orders', function (Nutgram $bot): void {
    app(ShopUi::class)->listOrders($bot);
});
$bot->onCommand('wallet', function (Nutgram $bot): void {
    app(BillingUi::class)->wallet($bot);
});
$bot->onCommand('topup {toman}', function (Nutgram $bot, string $toman): void {
    app(BillingUi::class)->topup($bot, $toman);
});
$bot->onCommand('paid {id} {reference}', function (Nutgram $bot, string $id, string $reference): void {
    app(BillingUi::class)->paid($bot, $id, $reference);
});
$bot->onCommand('payments', function (Nutgram $bot): void {
    app(ManualPaymentUi::class)->list($bot);
});
$bot->onCommand('bankconfirm {claim} {reference}', function (Nutgram $bot, string $claim, string $reference): void {
    app(ManualPaymentUi::class)->bankConfirm($bot, $claim, $reference);
});
$bot->onPhoto(function (Nutgram $bot): void {
    app(ManualPaymentUi::class)->photo($bot);
});
$bot->onCommand('topups', function (Nutgram $bot): void {
    app(BillingUi::class)->reviews($bot);
});
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
$bot->onCallbackQueryData('menu:invite', InviteHandler::class);
$bot->onCallbackQueryData('menu:wallet', function (Nutgram $bot): void {
    app(BillingUi::class)->wallet($bot);
});
$bot->onCallbackQueryData('bank:list', function (Nutgram $bot): void {
    app(ManualPaymentUi::class)->list($bot);
});
$bot->onCallbackQueryData('bank:show:{claim}', function (Nutgram $bot, string $claim): void {
    app(ManualPaymentUi::class)->show($bot, $claim);
});
$bot->onCallbackQueryData('bank:choose:{claim}:{decision}', function (Nutgram $bot, string $claim, string $decision): void {
    app(ManualPaymentUi::class)->choose($bot, $claim, $decision);
});
$bot->onCallbackQueryData('bank:apply:{token}', function (Nutgram $bot, string $token): void {
    app(ManualPaymentUi::class)->apply($bot, $token);
});
$bot->onCallbackQueryData('shop:pay:{id}', function (Nutgram $bot, string $id): void {
    app(ManualPaymentUi::class)->start($bot, $id);
});
$bot->onCallbackQueryData('billing:help', function (Nutgram $bot): void {
    app(BillingUi::class)->help($bot);
});
$bot->onCallbackQueryData('menu:billing-reviews', function (Nutgram $bot): void {
    app(BillingUi::class)->reviews($bot);
});
$bot->onCallbackQueryData('billing:review:{id}:{decision}', function (Nutgram $bot, string $id, string $decision): void {
    app(BillingUi::class)->reviewRequest($bot, $id, $decision);
});
$bot->onCallbackQueryData('billing:apply:{token}', function (Nutgram $bot, string $token): void {
    app(BillingUi::class)->applyReview($bot, $token);
});
$bot->onCallbackQueryData('onboarding:join', JoinHandler::class);
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

$bot->onCallbackQueryData('menu:subscriptions', function (Nutgram $bot): void {
    app(SubscriptionUi::class)->browse($bot);
});
$bot->onCallbackQueryData('subs:list:{cursor}', function (Nutgram $bot, string $cursor): void {
    app(SubscriptionUi::class)->browse($bot, $cursor);
});
$bot->onCallbackQueryData('subs:view:{id}:{cursor}', function (Nutgram $bot, string $id, string $cursor): void {
    app(SubscriptionUi::class)->detail($bot, $id, $cursor);
});
$bot->onCallbackQueryData('subs:link:{id}:{cursor}', function (Nutgram $bot, string $id, string $cursor): void {
    app(SubscriptionUi::class)->link($bot, $id, $cursor);
});
$bot->onCallbackQueryData('subs:nodes:{id}:{cursor}', function (Nutgram $bot, string $id, string $cursor): void {
    app(SubscriptionUi::class)->nodes($bot, $id, $cursor);
});

$bot->onCallbackQueryData('menu:shop', function (Nutgram $bot): void {
    app(ShopUi::class)->open($bot);
});
$bot->onCallbackQueryData('shop:catalog:{page}', function (Nutgram $bot, string $page): void {
    app(ShopUi::class)->open($bot, (int) $page);
});
$bot->onCallbackQueryData('shop:product:{id}', function (Nutgram $bot, string $id): void {
    app(ShopUi::class)->duration($bot, $id);
});
$bot->onCallbackQueryData('shop:volume:{id}:{days}', function (Nutgram $bot, string $id, string $days): void {
    app(ShopUi::class)->volume($bot, $id, $days);
});
$bot->onCallbackQueryData('shop:custom:{id}:{days}', function (Nutgram $bot, string $id, string $days): void {
    app(ShopUi::class)->custom($bot, $id, $days);
});
$bot->onCallbackQueryData('shop:quote:{id}:{days}:{gb}', function (Nutgram $bot, string $id, string $days, string $gb): void {
    app(ShopUi::class)->quote($bot, $id, $days, $gb);
});

// Last-resort text matcher: it only responds if the active user previously
// selected "custom volume" in a private chat. Commands remain higher priority.
$bot->onText('{volume}', function (Nutgram $bot, string $volume): void {
    app(ShopUi::class)->customText($bot, $volume);
});

$bot->onCallbackQueryData('shop:order:{token}', function (Nutgram $bot, string $token): void {
    app(ShopUi::class)->createOrder($bot, $token);
});
$bot->onCallbackQueryData('shop:orders', function (Nutgram $bot): void {
    app(ShopUi::class)->listOrders($bot);
});
$bot->onCallbackQueryData('shop:order:view:{id}', function (Nutgram $bot, string $id): void {
    app(ShopUi::class)->viewOrder($bot, $id);
});

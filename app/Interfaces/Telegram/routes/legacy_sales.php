<?php

use App\Interfaces\Telegram\Handlers\InviteHandler;
use App\Interfaces\Telegram\Handlers\JoinHandler;
use App\Interfaces\Telegram\Support\BillingUi;
use App\Interfaces\Telegram\Support\ManualPaymentUi;
use App\Interfaces\Telegram\Support\PlanCatalogUi;
use App\Interfaces\Telegram\Support\ResellerBuilderUi;
use App\Interfaces\Telegram\Support\ShopUi;
use App\Interfaces\Telegram\Support\SubscriptionUi;
use SergiX44\Nutgram\Nutgram;

/** @var Nutgram $bot */
// Original-bot Sales compatibility registrations; not loaded into the isolated Sales Bot.

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

$bot->onPhoto(function (Nutgram $bot): void {
    app(ManualPaymentUi::class)->photo($bot);
});

$bot->onCommand('builder', function (Nutgram $bot): void {
    app(ResellerBuilderUi::class)->browse($bot);
});

$bot->onCommand('catalog', function (Nutgram $bot): void {
    app(PlanCatalogUi::class)->browse($bot);
});

$bot->onCallbackQueryData('menu:invite', InviteHandler::class);

$bot->onCallbackQueryData('menu:wallet', function (Nutgram $bot): void {
    app(BillingUi::class)->wallet($bot);
});

$bot->onCallbackQueryData('shop:pay:{id}', function (Nutgram $bot, string $id): void {
    app(ManualPaymentUi::class)->start($bot, $id);
});

$bot->onCallbackQueryData('billing:help', function (Nutgram $bot): void {
    app(BillingUi::class)->help($bot);
});

$bot->onCallbackQueryData('onboarding:join', JoinHandler::class);

$bot->onCallbackQueryData('menu:builder', function (Nutgram $bot): void {
    app(ResellerBuilderUi::class)->browse($bot);
});

$bot->onCallbackQueryData('builder:list:{page}', function (Nutgram $bot, string $page): void {
    app(ResellerBuilderUi::class)->browse($bot, ctype_digit($page) ? min(10000, (int) $page) : 1);
});

$bot->onCallbackQueryData('builder:detail:{id}:{page}', function (Nutgram $bot, string $id, string $page): void {
    app(ResellerBuilderUi::class)->detail($bot, $id, $page);
});

$bot->onCallbackQueryData('builder:days:{id}:{gb}:{page}', function (Nutgram $bot, string $id, string $gb, string $page): void {
    app(ResellerBuilderUi::class)->days($bot, $id, $gb, $page);
});

$bot->onCallbackQueryData('builder:quote:{id}:{gb}:{days}:{page}', function (Nutgram $bot, string $id, string $gb, string $days, string $page): void {
    app(ResellerBuilderUi::class)->quote($bot, $id, $gb, $days, $page);
});

$bot->onCallbackQueryData('builder:custom:{id}:{page}', function (Nutgram $bot, string $id, string $page): void {
    app(ResellerBuilderUi::class)->custom($bot, $id, $page);
});

$bot->onCallbackQueryData('builder:cancel', function (Nutgram $bot): void {
    app(ResellerBuilderUi::class)->cancel($bot);
});

$bot->onCallbackQueryData('menu:plan-catalog', function (Nutgram $bot): void {
    app(PlanCatalogUi::class)->browse($bot);
});

$bot->onCallbackQueryData('catalog:list:{page}', function (Nutgram $bot, string $page): void {
    app(PlanCatalogUi::class)->browse($bot, ctype_digit($page) ? (int) $page : 1);
});

$bot->onCallbackQueryData('catalog:detail:{id}:{page}', function (Nutgram $bot, string $id, string $page): void {
    app(PlanCatalogUi::class)->detail($bot, $id, $page);
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

// The existing final text handler now dispatches exact Reply Keyboard labels first.
// Unknown text still reaches the pending custom-volume flow. Commands keep priority.

$bot->onCallbackQueryData('shop:order:{token}', function (Nutgram $bot, string $token): void {
    app(ShopUi::class)->createOrder($bot, $token);
});

$bot->onCallbackQueryData('shop:orders', function (Nutgram $bot): void {
    app(ShopUi::class)->listOrders($bot);
});

$bot->onCallbackQueryData('shop:order:view:{id}', function (Nutgram $bot, string $id): void {
    app(ShopUi::class)->viewOrder($bot, $id);
});

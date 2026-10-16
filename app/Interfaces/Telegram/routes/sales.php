<?php

use App\Interfaces\Telegram\Support\PlanCatalogUi;
use App\Interfaces\Telegram\Support\ResellerBuilderUi;
use App\Interfaces\Telegram\Support\SalesHomeUi;
use SergiX44\Nutgram\Nutgram;

/** @var Nutgram $bot */
// SB-01: isolated Sales entry/identity only. Admin and legacy purchasing
// routers must not be loaded here. Checkout/payment belong to later phases.
$bot->onCommand('start {payload}', function (Nutgram $bot, string $payload): void {
    app(SalesHomeUi::class)->start($bot, $payload);
});

$bot->onCommand('start', function (Nutgram $bot): void {
    app(SalesHomeUi::class)->start($bot);
});

$bot->onCommand('menu', function (Nutgram $bot): void {
    app(SalesHomeUi::class)->start($bot);
});

$bot->onCommand('account', function (Nutgram $bot): void {
    app(SalesHomeUi::class)->account($bot);
});

$bot->onCommand('link {code}', function (Nutgram $bot, string $code): void {
    app(SalesHomeUi::class)->link($bot, $code);
});

$bot->onCallbackQueryData('sales:home', function (Nutgram $bot): void {
    app(SalesHomeUi::class)->start($bot);
});

$bot->onCallbackQueryData('sales:account', function (Nutgram $bot): void {
    app(SalesHomeUi::class)->account($bot);
});

$bot->onCallbackQueryData('sales:join', function (Nutgram $bot): void {
    app(SalesHomeUi::class)->join($bot);
});


// SB-02: isolated routes for the existing read-only Sales presenters. Never
// register legacy Shop, Admin, payment or provider actions on this token.
$bot->onCommand('catalog', function (Nutgram $bot): void {
    app(PlanCatalogUi::class)->browse($bot);
});

$bot->onCommand('builder', function (Nutgram $bot): void {
    app(ResellerBuilderUi::class)->browse($bot);
});

// Reuse only the safe callback identifiers already emitted by these presenters.
$bot->onCallbackQueryData('menu:home', function (Nutgram $bot): void {
    app(SalesHomeUi::class)->start($bot);
});

$bot->onCallbackQueryData('menu:plan-catalog', function (Nutgram $bot): void {
    app(PlanCatalogUi::class)->browse($bot);
});

$bot->onCallbackQueryData('catalog:list:{page}', function (Nutgram $bot, string $page): void {
    app(PlanCatalogUi::class)->browse($bot, ctype_digit($page) ? min(10000, (int) $page) : 1);
});

$bot->onCallbackQueryData('catalog:detail:{id}:{page}', function (Nutgram $bot, string $id, string $page): void {
    app(PlanCatalogUi::class)->detail($bot, $id, $page);
});

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

// Only a pending reseller builder may consume uncommanded text; no fallback
// into legacy admin forms, sales checkout or payment submission.
$bot->onText('{volume}', function (Nutgram $bot, string $volume): void {
    app(ResellerBuilderUi::class)->customText($bot, $volume);
});

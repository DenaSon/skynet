<?php

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

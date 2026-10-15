<?php

use App\Interfaces\Telegram\Handlers\ReplyMenuHandler;
use App\Interfaces\Telegram\Support\PlanAdminUi;
use App\Interfaces\Telegram\Support\ProductAdminUi;
use App\Interfaces\Telegram\Support\ResellerBuilderUi;
use App\Interfaces\Telegram\Support\ShopUi;
use SergiX44\Nutgram\Nutgram;

/** @var Nutgram $bot */
// Keep the exact original Reply -> Product -> Plan -> Builder -> Shop precedence until a safe Sales cutover.

$bot->onText('{volume}', function (Nutgram $bot, string $volume): void {
    if (app(ReplyMenuHandler::class)->handle($bot, $volume)) {
        return;
    }

    if (app(ProductAdminUi::class)->text($bot, $volume)) {
        return;
    }

    if (app(PlanAdminUi::class)->text($bot, $volume)) {
        return;
    }

    if (app(ResellerBuilderUi::class)->customText($bot, $volume)) {
        return;
    }

    app(ShopUi::class)->customText($bot, $volume);
});

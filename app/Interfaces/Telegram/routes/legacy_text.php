<?php

use App\Interfaces\Telegram\Handlers\ReplyMenuHandler;
use App\Interfaces\Telegram\Handlers\SettingsHandler;
use App\Interfaces\Telegram\Support\AdminAccountingUi;
use App\Interfaces\Telegram\Support\AdminCloudflareDnsUi;
use App\Interfaces\Telegram\Support\AdminConfigProfileOperationsUi;
use App\Interfaces\Telegram\Support\AdminFinanceWalletUi;
use App\Interfaces\Telegram\Support\AdminHostOperationsUi;
use App\Interfaces\Telegram\Support\AdminHostsUi;
use App\Interfaces\Telegram\Support\AdminNodeBatchUi;
use App\Interfaces\Telegram\Support\AdminNodeOperationsUi;
use App\Interfaces\Telegram\Support\AdminNodeSetupUi;
use App\Interfaces\Telegram\Support\AdminNodesUi;
use App\Interfaces\Telegram\Support\ManualPaymentUi;
use App\Interfaces\Telegram\Support\PlanAdminUi;
use App\Interfaces\Telegram\Support\ProductAdminUi;
use App\Interfaces\Telegram\Support\ResellerBuilderUi;
use App\Interfaces\Telegram\Support\ShopUi;
use App\Interfaces\Telegram\Support\UserManagementUi;
use SergiX44\Nutgram\Nutgram;

/** @var Nutgram $bot */
// Keep the exact original Reply -> Product -> Plan -> Builder -> Shop precedence until a safe Sales cutover.

$bot->onText('{volume}', function (Nutgram $bot, string $volume): void {
    // Consume a pending private SSH secret before generic reply-menu matching.
    if (app(AdminNodeSetupUi::class)->text($bot, $volume)) {
        return;
    }

    if (app(ManualPaymentUi::class)->text($bot, $volume)) {
        return;
    }

    if (app(ReplyMenuHandler::class)->handle($bot, $volume)) {
        return;
    }

    if (app(AdminAccountingUi::class)->text($bot, $volume)) {
        return;
    }

    if (app(AdminFinanceWalletUi::class)->text($bot, $volume)) {
        return;
    }

    if (app(AdminConfigProfileOperationsUi::class)->text($bot, $volume)) {
        return;
    }

    if (app(AdminHostOperationsUi::class)->text($bot, $volume)) {
        return;
    }

    if (app(AdminHostsUi::class)->text($bot, $volume)) {
        return;
    }

    if (app(AdminNodesUi::class)->text($bot, $volume)) {
        return;
    }

    if (app(AdminNodeOperationsUi::class)->text($bot, $volume)) {
        return;
    }

    if (app(AdminNodeBatchUi::class)->text($bot, $volume)) {
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

    if (app(AdminCloudflareDnsUi::class)->text($bot, $volume)) {
        return;
    }

    if (app(UserManagementUi::class)->text($bot, $volume)) {
        return;
    }

    if (app(SettingsHandler::class)->text($bot, $volume)) {
        return;
    }

    app(ShopUi::class)->customText($bot, $volume);
});

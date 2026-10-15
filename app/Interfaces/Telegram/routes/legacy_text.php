<?php

use App\Interfaces\Telegram\Handlers\AccountHandler;
use App\Interfaces\Telegram\Handlers\AdminHandler;
use App\Interfaces\Telegram\Handlers\AdminRolesHandler;
use App\Interfaces\Telegram\Handlers\AdminUsersHandler;
use App\Interfaces\Telegram\Handlers\InviteHandler;
use App\Interfaces\Telegram\Handlers\JoinHandler;
use App\Interfaces\Telegram\Handlers\LinkHandler;
use App\Interfaces\Telegram\Handlers\ReplyMenuHandler;
use App\Interfaces\Telegram\Handlers\SettingsHandler;
use App\Interfaces\Telegram\Handlers\StartHandler;
use App\Interfaces\Telegram\Support\AdminSectionsUi;
use App\Interfaces\Telegram\Support\BillingUi;
use App\Interfaces\Telegram\Support\ManualPaymentUi;
use App\Interfaces\Telegram\Support\PlanAdminUi;
use App\Interfaces\Telegram\Support\PlanCatalogUi;
use App\Interfaces\Telegram\Support\PrivateTelegramUser;
use App\Interfaces\Telegram\Support\ProductAdminUi;
use App\Interfaces\Telegram\Support\RbacUi;
use App\Interfaces\Telegram\Support\ResellerBuilderUi;
use App\Interfaces\Telegram\Support\ShopUi;
use App\Interfaces\Telegram\Support\SubscriptionUi;
use App\Interfaces\Telegram\Support\UserManagementUi;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardRemove;


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

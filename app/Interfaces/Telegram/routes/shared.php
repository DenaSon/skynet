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
// Original-bot shared navigation/identity handlers; Sales Bot has an independent, closed router.

$bot->onCommand('start {payload}', StartHandler::class);

$bot->onCommand('start', StartHandler::class);

$bot->onCommand('menu', StartHandler::class);

$bot->onCommand('resetmenu', function (Nutgram $bot): void {
    if (app(PrivateTelegramUser::class)->id($bot) === null) {
        return;
    }

    $bot->sendMessage(
        text: '✅ کیبورد سفارشی حذف شد. برای دریافت منوی فعلی /start را بزنید.',
        reply_markup: ReplyKeyboardRemove::make(true),
    );
});

$bot->onCommand('account', AccountHandler::class);

$bot->onCommand('settings', SettingsHandler::class);

$bot->onCommand('link {code}', LinkHandler::class);

$bot->onCallbackQueryData('menu:home', StartHandler::class);

$bot->onCallbackQueryData('menu:account', AccountHandler::class);

$bot->onCallbackQueryData('menu:settings', SettingsHandler::class);

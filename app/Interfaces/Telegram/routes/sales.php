<?php

use App\Interfaces\Telegram\Support\ManualPaymentUi;
use App\Interfaces\Telegram\Support\PlanCatalogUi;
use App\Interfaces\Telegram\Support\ResellerBuilderUi;
use App\Interfaces\Telegram\Support\ResellerSalesReportUi;
use App\Interfaces\Telegram\Support\ResellerServiceHealthUi;
use App\Interfaces\Telegram\Support\SalesCheckoutUi;
use App\Interfaces\Telegram\Support\SalesHomeUi;
use App\Interfaces\Telegram\Support\SalesPanelRequestUi;
use App\Interfaces\Telegram\Support\SalesSubscriptionsUi;
use App\Interfaces\Telegram\Support\SalesSupportUi;
use App\Interfaces\Telegram\Support\SalesTrialUi;
use App\Interfaces\Telegram\Support\WalletTopupUi;
use SergiX44\Nutgram\Nutgram;

/** @var Nutgram $bot */
$bot->middleware(function (Nutgram $bot, $next): void {
    app(SalesTrialUi::class)->retireOnNavigation($bot);
    $next($bot);
});
// Sales-only identity, customer navigation and existing checkout handlers.
// Admin and legacy purchasing routers must never be loaded here.
$bot->onCommand('start {payload}', function (Nutgram $bot, string $payload): void {
    app(SalesHomeUi::class)->start($bot, $payload);
});

$bot->onCommand('start', function (Nutgram $bot): void {
    app(SalesHomeUi::class)->start($bot);
});

$bot->onCommand('menu', function (Nutgram $bot): void {
    app(SalesHomeUi::class)->start($bot);
});

// MENU-01: native Telegram commands use the original authorized Sales presenter.
$bot->onCommand('buy', function (Nutgram $bot): void {
    app(SalesHomeUi::class)->buy($bot);
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

// Explicit native HTML fallback for Rich ready-buy lists.
$bot->onCallbackQueryData('sales:buy:simple:{page}', function (Nutgram $bot, string $page): void {
    app(SalesHomeUi::class)->buy($bot, ctype_digit($page) && strlen($page) <= 4 ? (int) $page : 1, false);
});

$bot->onCallbackQueryData('sales:buy', function (Nutgram $bot): void {
    app(SalesHomeUi::class)->buy($bot);
});

// Preserve pagination callbacks already present on older buying messages.
$bot->onCallbackQueryData('sales:home:{page}', function (Nutgram $bot, string $page): void {
    app(SalesHomeUi::class)->buy($bot, ctype_digit($page) && strlen($page) <= 4 ? (int) $page : 1);
});

// Backward-compatible plain HTML account/referral views for Rich-incompatible clients.
$bot->onCallbackQueryData('sales:invite:simple', function (Nutgram $bot): void {
    app(SalesHomeUi::class)->invite($bot, false);
});

$bot->onCallbackQueryData('sales:invite', function (Nutgram $bot): void {
    app(SalesHomeUi::class)->invite($bot);
});

$bot->onCommand('invite', function (Nutgram $bot): void {
    app(SalesHomeUi::class)->invite($bot);
});

$bot->onCallbackQueryData('sales:support', function (Nutgram $bot): void {
    app(SalesSupportUi::class)->show($bot);
});

$bot->onCommand('help', function (Nutgram $bot): void {
    app(SalesSupportUi::class)->show($bot);
});

$bot->onCommand('cancel', function (Nutgram $bot): void {
    app(SalesHomeUi::class)->start($bot);
});

// Preserve the old HTML guide for unsupported Rich clients and old devices.
$bot->onCallbackQueryData('sales:help:simple:{page}', function (Nutgram $bot, string $page): void {
    app(SalesSupportUi::class)->show($bot, $page, false);
});

$bot->onCallbackQueryData('sales:help:{page}', function (Nutgram $bot, string $page): void {
    app(SalesSupportUi::class)->show($bot, $page);
});

$bot->onCallbackQueryData('sales:health:{cursor}', function (Nutgram $bot, string $cursor): void {
    app(ResellerServiceHealthUi::class)->show($bot, $cursor);
});

$bot->onCallbackQueryData('sales:report:{days}', function (Nutgram $bot, string $days): void {
    app(ResellerSalesReportUi::class)->show($bot, $days);
});

$bot->onCallbackQueryData('sales:wallet', function (Nutgram $bot): void {
    app(SalesHomeUi::class)->wallet($bot);
});

$bot->onCallbackQueryData('sales:wallet:topup', function (Nutgram $bot): void {
    app(WalletTopupUi::class)->start($bot);
});

$bot->onCallbackQueryData('sales:wallet:topup:quick:{toman}', function (Nutgram $bot, string $toman): void {
    app(WalletTopupUi::class)->quick($bot, $toman);
});

$bot->onCallbackQueryData('sales:wallet:topup:custom', function (Nutgram $bot): void {
    app(WalletTopupUi::class)->custom($bot);
});

$bot->onCallbackQueryData('sales:wallet:topup:new', function (Nutgram $bot): void {
    app(WalletTopupUi::class)->newRequest($bot);
});

$bot->onCallbackQueryData('sales:wallet:topup:resume:{id}', function (Nutgram $bot, string $id): void {
    app(WalletTopupUi::class)->resume($bot, $id);
});

$bot->onCallbackQueryData('sales:wallet:topup:receipt:{id}', function (Nutgram $bot, string $id): void {
    app(WalletTopupUi::class)->requestReceipt($bot, $id);
});

$bot->onCallbackQueryData('sales:wallet:topup:back:{id}', function (Nutgram $bot, string $id): void {
    app(WalletTopupUi::class)->back($bot, $id);
});

$bot->onCallbackQueryData('sales:wallet:topup:cancel', function (Nutgram $bot): void {
    app(WalletTopupUi::class)->cancel($bot);
});

$bot->onCallbackQueryData('sales:panel:request', function (Nutgram $bot): void {
    app(SalesPanelRequestUi::class)->start($bot);
});

$bot->onCallbackQueryData('sales:panel:accept', function (Nutgram $bot): void {
    app(SalesPanelRequestUi::class)->accept($bot);
});

$bot->onCallbackQueryData('sales:panel:confirm', function (Nutgram $bot): void {
    app(SalesPanelRequestUi::class)->confirm($bot);
});

$bot->onCallbackQueryData('sales:panel:cancel', function (Nutgram $bot): void {
    app(SalesPanelRequestUi::class)->cancel($bot);
});

$bot->onCallbackQueryData('sales:account:simple', function (Nutgram $bot): void {
    app(SalesHomeUi::class)->account($bot, false);
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

$bot->onCommand('reports', function (Nutgram $bot): void {
    app(ResellerSalesReportUi::class)->show($bot);
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

// Sales-only read presentation and compatibility callbacks. They never create
// an order; existing catalog:detail continues directly to validated checkout.
$bot->onCallbackQueryData('sales:catalog:simple:{page}', function (Nutgram $bot, string $page): void {
    app(PlanCatalogUi::class)->browse($bot, ctype_digit($page) ? min(10000, (int) $page) : 1, false);
});

$bot->onCallbackQueryData('sales:catalog:preview:simple:{id}:{page}', function (Nutgram $bot, string $id, string $page): void {
    app(PlanCatalogUi::class)->detail($bot, $id, $page, false);
});

$bot->onCallbackQueryData('sales:catalog:preview:{id}:{page}', function (Nutgram $bot, string $id, string $page): void {
    app(PlanCatalogUi::class)->detail($bot, $id, $page);
});

$bot->onCallbackQueryData('catalog:list:{page}', function (Nutgram $bot, string $page): void {
    app(PlanCatalogUi::class)->browse($bot, ctype_digit($page) ? min(10000, (int) $page) : 1);
});

$bot->onCallbackQueryData('catalog:detail:{id}:{page}', function (Nutgram $bot, string $id, string $page): void {
    app(SalesCheckoutUi::class)->choosePreset($bot, $id);
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

$bot->onCommand('trial', function (Nutgram $bot): void {
    app(SalesTrialUi::class)->show($bot);
});
$bot->onCallbackQueryData('sales:trial', function (Nutgram $bot): void {
    app(SalesTrialUi::class)->show($bot);
});
$bot->onCallbackQueryData('sales:trial:contact', function (Nutgram $bot): void {
    app(SalesTrialUi::class)->requestContact($bot);
});
$bot->onCallbackQueryData('sales:trial:confirm:{token}', function (Nutgram $bot, string $token): void {
    app(SalesTrialUi::class)->confirm($bot, $token);
});
$bot->onCallbackQueryData('sales:trial:cancel', function (Nutgram $bot): void {
    app(SalesTrialUi::class)->cancel($bot);
});
$bot->onCallbackQueryData('sales:trial:link', function (Nutgram $bot): void {
    app(SalesTrialUi::class)->link($bot);
});
$bot->onContact(function (Nutgram $bot): void {
    app(SalesTrialUi::class)->contact($bot);
});

// Primary menu labels always win over pending free-text forms. Unmatched
// input gets guidance, never a fallback into Admin or legacy purchase routes.
// Exclude commands: Nutgram resolves every matching handler, not just the first.
$bot->onText('(?!/){volume}', function (Nutgram $bot, string $volume): void {
    if (app(SalesHomeUi::class)->navigate($bot, $volume)) {
        return;
    }
    if (app(SalesTrialUi::class)->invalidInput($bot)) {
        return;
    }
    if (app(SalesCheckoutUi::class)->customerNameText($bot, $volume)) {
        return;
    }
    if (app(SalesSubscriptionsUi::class)->customerPhoneText($bot, $volume)) {
        return;
    }
    if (app(SalesSubscriptionsUi::class)->customerLabelText($bot, $volume)) {
        return;
    }
    if (app(SalesSubscriptionsUi::class)->customerSearchText($bot, $volume)) {
        return;
    }
    if (app(SalesPanelRequestUi::class)->text($bot, $volume)) {
        return;
    }
    if (app(WalletTopupUi::class)->text($bot, $volume)) {
        return;
    }
    if (app(ResellerBuilderUi::class)->customText($bot, $volume)) {
        return;
    }
    if (app(SalesPanelRequestUi::class)->invalidInput($bot)) {
        return;
    }
    if (app(WalletTopupUi::class)->invalidInput($bot)) {
        return;
    }
    if (app(ManualPaymentUi::class)->invalidInput($bot)) {
        return;
    }
    app(SalesHomeUi::class)->fallback($bot);
});

$bot->fallback(function (Nutgram $bot): void {
    if (app(SalesTrialUi::class)->invalidInput($bot)) {
        return;
    }
    if (app(SalesPanelRequestUi::class)->invalidInput($bot)) {
        return;
    }
    if (app(WalletTopupUi::class)->invalidInput($bot)) {
        return;
    }
    if (app(ManualPaymentUi::class)->invalidInput($bot)) {
        return;
    }
    app(SalesHomeUi::class)->fallback($bot);
});

// SB-03: buyer-owned managed-plan pending orders. No payment, bank review,
// legacy Shop mutation or provider operations are registered on Sales.
$bot->onCommand('orders', function (Nutgram $bot): void {
    app(SalesCheckoutUi::class)->listOrders($bot);
});

$bot->onCallbackQueryData('sales:checkout:preview:{id}:{days}:{gb}', function (Nutgram $bot, string $id, string $days, string $gb): void {
    app(SalesCheckoutUi::class)->preview($bot, $id, $days, $gb);
});

$bot->onCallbackQueryData('sales:checkout:new:{source}', function (Nutgram $bot, string $source): void {
    app(SalesCheckoutUi::class)->newPurchase($bot, $source);
});

$bot->onCallbackQueryData('sales:checkout:commit:{token}', function (Nutgram $bot, string $token): void {
    app(SalesCheckoutUi::class)->commit($bot, $token);
});

$bot->onCallbackQueryData('sales:walletpay:confirm:{id}:{token}', function (Nutgram $bot, string $id, string $token): void {
    app(SalesCheckoutUi::class)->walletConfirm($bot, $id, $token);
});

$bot->onCallbackQueryData('sales:walletpay:cancel:{id}', function (Nutgram $bot, string $id): void {
    app(SalesCheckoutUi::class)->walletCancel($bot, $id);
});

$bot->onCallbackQueryData('sales:checkout:wallet:{id}', function (Nutgram $bot, string $id): void {
    app(SalesCheckoutUi::class)->walletPreview($bot, $id);
});

$bot->onCallbackQueryData('sales:checkout:name:cancel', function (Nutgram $bot): void {
    app(SalesCheckoutUi::class)->cancelCustomerName($bot);
});

$bot->onCallbackQueryData('sales:checkout:phone:cancel', function (Nutgram $bot): void {
    app(SalesCheckoutUi::class)->cancelCustomerPhone($bot);
});

$bot->onCallbackQueryData('sales:checkout:orders', function (Nutgram $bot): void {
    app(SalesCheckoutUi::class)->listOrders($bot);
});

$bot->onCallbackQueryData('sales:checkout:view:{id}', function (Nutgram $bot, string $id): void {
    app(SalesCheckoutUi::class)->viewOrder($bot, $id);
});

// SB-04: customer receipt intake only. Admin decision callbacks remain
// exclusively in the Admin router and are never bound to this Sales token.
$bot->onCallbackQueryData('sales:payment:start:{id}', function (Nutgram $bot, string $id): void {
    app(ManualPaymentUi::class)->start($bot, $id);
});

$bot->onCallbackQueryData('sales:payment:receipt:{id}', function (Nutgram $bot, string $id): void {
    app(ManualPaymentUi::class)->requestReceipt($bot, $id);
});

$bot->onPhoto(function (Nutgram $bot): void {
    if (app(WalletTopupUi::class)->photo($bot)) {
        return;
    }
    app(ManualPaymentUi::class)->photo($bot);
});

// SB-06: buyer-owned subscriptions. Reuse existing Provisioning read Actions.
// Callback IDs are Sales-only; Admin subscription router stays unchanged.
$bot->onCommand('subscriptions', function (Nutgram $bot): void {
    app(SalesSubscriptionsUi::class)->browse($bot);
});

$bot->onCallbackQueryData('sales:subs:list:{cursor}', function (Nutgram $bot, string $cursor): void {
    app(SalesSubscriptionsUi::class)->browse($bot, $cursor);
});

$bot->onCallbackQueryData('sales:subs:view:{id}:{cursor}', function (Nutgram $bot, string $id, string $cursor): void {
    app(SalesSubscriptionsUi::class)->detail($bot, $id, $cursor);
});

// Keep the pilot callback routable for already-delivered messages.
$bot->onCallbackQueryData('sales:subs:rich:{id}:{cursor}', function (Nutgram $bot, string $id, string $cursor): void {
    app(SalesSubscriptionsUi::class)->detail($bot, $id, $cursor, true);
});

// Explicit plain HTML fallback for clients that cannot show Rich Messages.
$bot->onCallbackQueryData('sales:subs:simple:{id}:{cursor}', function (Nutgram $bot, string $id, string $cursor): void {
    app(SalesSubscriptionsUi::class)->detail($bot, $id, $cursor, false);
});

$bot->onCallbackQueryData('sales:subs:page:{id}:{cursor}', function (Nutgram $bot, string $id, string $cursor): void {
    app(SalesSubscriptionsUi::class)->page($bot, $id, $cursor);
});

$bot->onCallbackQueryData('sales:subs:link:{id}:{cursor}', function (Nutgram $bot, string $id, string $cursor): void {
    app(SalesSubscriptionsUi::class)->link($bot, $id, $cursor);
});

$bot->onCallbackQueryData('sales:subs:search:start', function (Nutgram $bot): void {
    app(SalesSubscriptionsUi::class)->beginCustomerSearch($bot);
});

$bot->onCallbackQueryData('sales:subs:search:page:{cursor}', function (Nutgram $bot, string $cursor): void {
    app(SalesSubscriptionsUi::class)->customerSearchResults($bot, $cursor);
});

$bot->onCallbackQueryData('sales:subs:search:cancel', function (Nutgram $bot): void {
    app(SalesSubscriptionsUi::class)->cancelCustomerSearch($bot);
});

$bot->onCallbackQueryData('sales:subs:phone:edit:{id}:{cursor}', function (Nutgram $bot, string $id, string $cursor): void {
    app(SalesSubscriptionsUi::class)->beginCustomerPhone($bot, $id, $cursor);
});

$bot->onCallbackQueryData('sales:subs:phone:remove:{id}:{cursor}', function (Nutgram $bot, string $id, string $cursor): void {
    app(SalesSubscriptionsUi::class)->confirmCustomerPhoneRemoval($bot, $id, $cursor);
});

$bot->onCallbackQueryData('sales:subs:phone:clear:{id}:{cursor}', function (Nutgram $bot, string $id, string $cursor): void {
    app(SalesSubscriptionsUi::class)->clearCustomerPhone($bot, $id, $cursor);
});

$bot->onCallbackQueryData('sales:subs:phone:cancel', function (Nutgram $bot): void {
    app(SalesSubscriptionsUi::class)->cancelCustomerPhone($bot);
});

$bot->onCallbackQueryData('sales:subs:label:edit:{id}:{cursor}', function (Nutgram $bot, string $id, string $cursor): void {
    app(SalesSubscriptionsUi::class)->beginCustomerLabel($bot, $id, $cursor);
});

$bot->onCallbackQueryData('sales:subs:label:remove:{id}:{cursor}', function (Nutgram $bot, string $id, string $cursor): void {
    app(SalesSubscriptionsUi::class)->confirmCustomerLabelRemoval($bot, $id, $cursor);
});

$bot->onCallbackQueryData('sales:subs:label:clear:{id}:{cursor}', function (Nutgram $bot, string $id, string $cursor): void {
    app(SalesSubscriptionsUi::class)->clearCustomerLabel($bot, $id, $cursor);
});

$bot->onCallbackQueryData('sales:subs:label:cancel', function (Nutgram $bot): void {
    app(SalesSubscriptionsUi::class)->cancelCustomerLabel($bot);
});

$bot->onCallbackQueryData('sales:subs:renew:{id}:{cursor}', function (Nutgram $bot, string $id, string $cursor): void {
    app(SalesSubscriptionsUi::class)->renewal($bot, $id, $cursor);
});

$bot->onCallbackQueryData('sales:subs:renew:checkout:{id}:{cursor}', function (Nutgram $bot, string $id, string $cursor): void {
    app(SalesSubscriptionsUi::class)->renewalCheckout($bot, $id, $cursor);
});

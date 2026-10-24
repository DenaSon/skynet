<?php

use App\Interfaces\Telegram\Handlers\AdminHandler;
use App\Interfaces\Telegram\Handlers\AdminRolesHandler;
use App\Interfaces\Telegram\Handlers\AdminUsersHandler;
use App\Interfaces\Telegram\Support\AdminAccountingUi;
use App\Interfaces\Telegram\Support\AdminCommandCenterUi;
use App\Interfaces\Telegram\Support\AdminBusinessReportUi;
use App\Interfaces\Telegram\Support\AdminCloudflareDnsUi;
use App\Interfaces\Telegram\Support\AdminConfigProfileOperationsUi;
use App\Interfaces\Telegram\Support\AdminConfigProfilesUi;
use App\Interfaces\Telegram\Support\AdminDashboardUi;
use App\Interfaces\Telegram\Support\AdminFinanceWalletUi;
use App\Interfaces\Telegram\Support\AdminFulfillmentUi;
use App\Interfaces\Telegram\Support\AdminHostBatchUi;
use App\Interfaces\Telegram\Support\AdminHostOperationsUi;
use App\Interfaces\Telegram\Support\AdminHostsUi;
use App\Interfaces\Telegram\Support\AdminNodeBatchUi;
use App\Interfaces\Telegram\Support\AdminNodeMetricsUi;
use App\Interfaces\Telegram\Support\AdminNodeOperationsUi;
use App\Interfaces\Telegram\Support\AdminNodeSetupUi;
use App\Interfaces\Telegram\Support\AdminNodesUi;
use App\Interfaces\Telegram\Support\AdminPanelUsersUi;
use App\Interfaces\Telegram\Support\AdminProviderOverviewUi;
use App\Interfaces\Telegram\Support\AdminSalesPanelRequestsUi;
use App\Interfaces\Telegram\Support\AdminSectionsUi;
use App\Interfaces\Telegram\Support\AdminSubscriptionStatusUi;
use App\Interfaces\Telegram\Support\AdminTrafficReportUi;
use App\Interfaces\Telegram\Support\BillingUi;
use App\Interfaces\Telegram\Support\ManualPaymentUi;
use App\Interfaces\Telegram\Support\PlanAdminUi;
use App\Interfaces\Telegram\Support\PlanSquadUi;
use App\Interfaces\Telegram\Support\ProductAdminUi;
use App\Interfaces\Telegram\Support\RbacUi;
use App\Interfaces\Telegram\Support\UserManagementUi;
use SergiX44\Nutgram\Nutgram;

/** @var Nutgram $bot */
// Admin-only registrations. Existing authorization and confirmation checks remain inside the handlers.

// Operator-only provider reads. Status writes are separate, confirmed and default-disabled.
// SP-02: private Super Admin review of Sales Panel applications.
$bot->onCommand('panelrequests', function (Nutgram $bot): void {
    app(AdminSalesPanelRequestsUi::class)->browse($bot);
});
$bot->onCallbackQueryData('sp:list:{page}', function (Nutgram $bot, string $page): void {
    app(AdminSalesPanelRequestsUi::class)->browse($bot, $page);
});
$bot->onCallbackQueryData('sp:detail:{id}', function (Nutgram $bot, string $id): void {
    app(AdminSalesPanelRequestsUi::class)->detail($bot, $id);
});
$bot->onCallbackQueryData('sp:prepare:{id}:{decision}', function (Nutgram $bot, string $id, string $decision): void {
    app(AdminSalesPanelRequestsUi::class)->prepare($bot, $id, $decision);
});
$bot->onCallbackQueryData('sp:apply:{token}', function (Nutgram $bot, string $token): void {
    app(AdminSalesPanelRequestsUi::class)->apply($bot, $token);
});

$bot->onCommand('panelusers', function (Nutgram $bot): void {
    app(AdminPanelUsersUi::class)->browse($bot);
});

$bot->onCallbackQueryData('menu:admin:panelusers', function (Nutgram $bot): void {
    app(AdminPanelUsersUi::class)->browse($bot);
});

$bot->onCallbackQueryData('rw:users:page:{page}', function (Nutgram $bot, string $page): void {
    app(AdminPanelUsersUi::class)->browse($bot, $page);
});

$bot->onCallbackQueryData('rw:users:view:{id}:{page}', function (Nutgram $bot, string $id, string $page): void {
    app(AdminPanelUsersUi::class)->detail($bot, $id, $page);
});

// CF-02: small Super Admin DNS directory and one-use A mutations.
$bot->onCommand('dns', function (Nutgram $bot): void {
    app(AdminCloudflareDnsUi::class)->browse($bot);
});
$bot->onCallbackQueryData('cf2:zones', function (Nutgram $bot): void {
    app(AdminCloudflareDnsUi::class)->browse($bot);
});
$bot->onCallbackQueryData('cf2:zone:{index}', function (Nutgram $bot, string $index): void {
    app(AdminCloudflareDnsUi::class)->selectZone($bot, $index);
});
$bot->onCallbackQueryData('cf2:list:{page}', function (Nutgram $bot, string $page): void {
    app(AdminCloudflareDnsUi::class)->records($bot, $page);
});
$bot->onCallbackQueryData('cf2:view:{index}:{token}', function (Nutgram $bot, string $index, string $token): void {
    app(AdminCloudflareDnsUi::class)->detail($bot, $index, $token);
});
$bot->onCallbackQueryData('cf2:create', function (Nutgram $bot): void {
    app(AdminCloudflareDnsUi::class)->create($bot);
});
$bot->onCallbackQueryData('cf2:edit:{mode}:{token}', function (Nutgram $bot, string $mode, string $token): void {
    app(AdminCloudflareDnsUi::class)->edit($bot, $mode, $token);
});
$bot->onCallbackQueryData('cf2:apply:{token}', function (Nutgram $bot, string $token): void {
    app(AdminCloudflareDnsUi::class)->apply($bot, $token);
});
$bot->onCallbackQueryData('cf2:cancel', function (Nutgram $bot): void {
    app(AdminCloudflareDnsUi::class)->cancel($bot);
});

// NODE-01: private Super Admin Node browsing and guarded, one-use CREATE.
// CFG-01: read-only existing Config Profiles and their Inbounds.
$bot->onCommand('profiles', function (Nutgram $bot): void {
    app(AdminConfigProfilesUi::class)->browse($bot);
});
$bot->onCallbackQueryData('cfg1:list:{page}', function (Nutgram $bot, string $page): void {
    app(AdminConfigProfilesUi::class)->browse($bot, $page);
});
$bot->onCallbackQueryData('cfg1:view:{uuid}:{page}', function (Nutgram $bot, string $uuid, string $page): void {
    app(AdminConfigProfilesUi::class)->detail($bot, $uuid, $page);
});

// CFG-02: confirmed root-only create/rename/delete. No raw Xray editor.
$bot->onCallbackQueryData('cfg2:begin', function (Nutgram $bot): void {
    app(AdminConfigProfileOperationsUi::class)->begin($bot);
});
$bot->onCallbackQueryData('cfg2:prepare:{uuid}:{mode}', function (Nutgram $bot, string $uuid, string $mode): void {
    app(AdminConfigProfileOperationsUi::class)->prepare($bot, $uuid, $mode);
});
$bot->onCallbackQueryData('cfg2:apply:{token}', function (Nutgram $bot, string $token): void {
    app(AdminConfigProfileOperationsUi::class)->apply($bot, $token);
});
$bot->onCallbackQueryData('cfg2:cancel', function (Nutgram $bot): void {
    app(AdminConfigProfileOperationsUi::class)->cancel($bot);
});

// HOST-01: private root-only Host list/detail and guarded create wizard.
$bot->onCommand('hosts', function (Nutgram $bot): void {
    app(AdminHostsUi::class)->browse($bot);
});
$bot->onCallbackQueryData('host1:list:{page}', function (Nutgram $bot, string $page): void {
    app(AdminHostsUi::class)->browse($bot, $page);
});
$bot->onCallbackQueryData('host1:view:{uuid}', function (Nutgram $bot, string $uuid): void {
    app(AdminHostsUi::class)->detail($bot, $uuid);
});
$bot->onCallbackQueryData('host1:begin', function (Nutgram $bot): void {
    app(AdminHostsUi::class)->begin($bot);
});
$bot->onCallbackQueryData('host1:profile:{index}', function (Nutgram $bot, string $index): void {
    app(AdminHostsUi::class)->profile($bot, $index);
});
$bot->onCallbackQueryData('host1:inbound:{index}', function (Nutgram $bot, string $index): void {
    app(AdminHostsUi::class)->inbound($bot, $index);
});
$bot->onCallbackQueryData('host1:apply:{token}', function (Nutgram $bot, string $token): void {
    app(AdminHostsUi::class)->apply($bot, $token);
});
$bot->onCallbackQueryData('host1:cancel', function (Nutgram $bot): void {
    app(AdminHostsUi::class)->cancel($bot);
});

// HOST-02: guarded single-Host operations and bounded group enable/disable.
$bot->onCallbackQueryData('host2:prepare:{uuid}:{mode}', function (Nutgram $bot, string $uuid, string $mode): void {
    app(AdminHostOperationsUi::class)->prepare($bot, $uuid, $mode);
});
$bot->onCallbackQueryData('host2:apply:{token}', function (Nutgram $bot, string $token): void {
    app(AdminHostOperationsUi::class)->apply($bot, $token);
});
$bot->onCallbackQueryData('host2:cancel', function (Nutgram $bot): void {
    app(AdminHostOperationsUi::class)->cancel($bot);
});
$bot->onCallbackQueryData('host2:batch:begin', function (Nutgram $bot): void {
    app(AdminHostBatchUi::class)->begin($bot);
});
$bot->onCallbackQueryData('host2:batch:page:{page}', function (Nutgram $bot, string $page): void {
    app(AdminHostBatchUi::class)->page($bot, $page);
});
$bot->onCallbackQueryData('host2:batch:toggle:{index}', function (Nutgram $bot, string $index): void {
    app(AdminHostBatchUi::class)->toggle($bot, $index);
});
$bot->onCallbackQueryData('host2:batch:choose:{mode}', function (Nutgram $bot, string $mode): void {
    app(AdminHostBatchUi::class)->choose($bot, $mode);
});
$bot->onCallbackQueryData('host2:batch:apply:{token}', function (Nutgram $bot, string $token): void {
    app(AdminHostBatchUi::class)->apply($bot, $token);
});
$bot->onCallbackQueryData('host2:batch:cancel', function (Nutgram $bot): void {
    app(AdminHostBatchUi::class)->cancel($bot);
});

$bot->onCommand('nodes', function (Nutgram $bot): void {
    app(AdminNodesUi::class)->browse($bot);
});

$bot->onCallbackQueryData('node1:list:{page}', function (Nutgram $bot, string $page): void {
    app(AdminNodesUi::class)->browse($bot, $page);
});

$bot->onCallbackQueryData('node1:view:{uuid}', function (Nutgram $bot, string $uuid): void {
    app(AdminNodesUi::class)->detail($bot, $uuid);
});

$bot->onCallbackQueryData('node1:tags', function (Nutgram $bot): void {
    app(AdminNodesUi::class)->tags($bot);
});

$bot->onCallbackQueryData('nw1:begin', function (Nutgram $bot): void {
    app(AdminNodeSetupUi::class)->begin($bot);
});
$bot->onCallbackQueryData('nw1:dns:zones', function (Nutgram $bot): void {
    app(AdminNodeSetupUi::class)->dnsZones($bot);
});
$bot->onCallbackQueryData('nw1:dns:zone:{index}', function (Nutgram $bot, string $index): void {
    app(AdminNodeSetupUi::class)->dnsZone($bot, $index);
});
$bot->onCallbackQueryData('nw1:dns:skip', function (Nutgram $bot): void {
    app(AdminNodeSetupUi::class)->dnsSkip($bot);
});
$bot->onCallbackQueryData('nw1:profile:{index}', function (Nutgram $bot, string $index): void {
    app(AdminNodeSetupUi::class)->profile($bot, $index);
});
$bot->onCallbackQueryData('nw1:inbound:{index}', function (Nutgram $bot, string $index): void {
    app(AdminNodeSetupUi::class)->inbound($bot, $index);
});
$bot->onCallbackQueryData('nw1:scan:{token}', function (Nutgram $bot, string $token): void {
    app(AdminNodeSetupUi::class)->scan($bot, $token);
});
$bot->onCallbackQueryData('nw1:trust:{token}', function (Nutgram $bot, string $token): void {
    app(AdminNodeSetupUi::class)->trust($bot, $token);
});
$bot->onCallbackQueryData('nw4:recent', function (Nutgram $bot): void {
    app(AdminNodeSetupUi::class)->resumeRecent($bot);
});

$bot->onCallbackQueryData('nw3:resume:{identifier}', function (Nutgram $bot, string $identifier): void {
    app(AdminNodeSetupUi::class)->completionOverview($bot, $identifier);
});
$bot->onCallbackQueryData('nw3:node:prepare:{identifier}', function (Nutgram $bot, string $identifier): void {
    app(AdminNodeSetupUi::class)->prepareNodeRegistration($bot, $identifier);
});
$bot->onCallbackQueryData('nw3:node:apply:{token}', function (Nutgram $bot, string $token): void {
    app(AdminNodeSetupUi::class)->applyNodeRegistration($bot, $token);
});
$bot->onCallbackQueryData('nw3:dns:prepare:{identifier}', function (Nutgram $bot, string $identifier): void {
    app(AdminNodeSetupUi::class)->prepareDns($bot, $identifier);
});
$bot->onCallbackQueryData('nw3:dns:apply:{token}', function (Nutgram $bot, string $token): void {
    app(AdminNodeSetupUi::class)->applyDns($bot, $token);
});
$bot->onCallbackQueryData('nw3:dns:reconcile:{identifier}', function (Nutgram $bot, string $identifier): void {
    app(AdminNodeSetupUi::class)->reconcileDns($bot, $identifier);
});
$bot->onCallbackQueryData('nw3:host:begin:{identifier}', function (Nutgram $bot, string $identifier): void {
    app(AdminNodeSetupUi::class)->beginHostCreation($bot, $identifier);
});
$bot->onCallbackQueryData('nw3:host:apply:{token}', function (Nutgram $bot, string $token): void {
    app(AdminNodeSetupUi::class)->applyHostCreation($bot, $token);
});

$bot->onCallbackQueryData('nw2:start:{token}', function (Nutgram $bot, string $token): void {
    app(AdminNodeSetupUi::class)->install($bot, $token);
});
$bot->onCallbackQueryData('nw2:status:{identifier}', function (Nutgram $bot, string $identifier): void {
    app(AdminNodeSetupUi::class)->installationStatus($bot, $identifier);
});

$bot->onCallbackQueryData('nw1:cancel', function (Nutgram $bot): void {
    app(AdminNodeSetupUi::class)->cancel($bot);
});

$bot->onCallbackQueryData('node1:begin', function (Nutgram $bot): void {
    app(AdminNodesUi::class)->begin($bot);
});

$bot->onCallbackQueryData('node1:profile:{index}', function (Nutgram $bot, string $index): void {
    app(AdminNodesUi::class)->profile($bot, $index);
});

$bot->onCallbackQueryData('node1:inbound:{index}', function (Nutgram $bot, string $index): void {
    app(AdminNodesUi::class)->toggle($bot, $index);
});

$bot->onCallbackQueryData('node1:preview', function (Nutgram $bot): void {
    app(AdminNodesUi::class)->preview($bot);
});

$bot->onCallbackQueryData('node1:apply:{token}', function (Nutgram $bot, string $token): void {
    app(AdminNodesUi::class)->apply($bot, $token);
});

$bot->onCallbackQueryData('node1:cancel', function (Nutgram $bot): void {
    app(AdminNodesUi::class)->cancel($bot);
});

// NODE-02: trusted single-node mutations; no bulk operations.
$bot->onCallbackQueryData('node2:prepare:{uuid}:{mode}', function (Nutgram $bot, string $uuid, string $mode): void {
    app(AdminNodeOperationsUi::class)->prepare($bot, $uuid, $mode);
});

$bot->onCallbackQueryData('node2:apply:{token}', function (Nutgram $bot, string $token): void {
    app(AdminNodeOperationsUi::class)->apply($bot, $token);
});

$bot->onCallbackQueryData('node2:cancel', function (Nutgram $bot): void {
    app(AdminNodeOperationsUi::class)->cancel($bot);
});

// NODE-03: private Super Admin batch controls.
$bot->onCallbackQueryData('node3:list', function (Nutgram $bot): void {
    app(AdminNodeBatchUi::class)->browse($bot);
});
$bot->onCallbackQueryData('node3:toggle:{index}', function (Nutgram $bot, string $index): void {
    app(AdminNodeBatchUi::class)->toggle($bot, $index);
});
$bot->onCallbackQueryData('node3:choose:{mode}', function (Nutgram $bot, string $mode): void {
    app(AdminNodeBatchUi::class)->choose($bot, $mode);
});
$bot->onCallbackQueryData('node3:move:{index}:{direction}', function (Nutgram $bot, string $index, string $direction): void {
    app(AdminNodeBatchUi::class)->move($bot, $index, $direction);
});
$bot->onCallbackQueryData('node3:profile:{index}', function (Nutgram $bot, string $index): void {
    app(AdminNodeBatchUi::class)->profile($bot, $index);
});
$bot->onCallbackQueryData('node3:inbound:{index}', function (Nutgram $bot, string $index): void {
    app(AdminNodeBatchUi::class)->inbound($bot, $index);
});
$bot->onCallbackQueryData('node3:field:{index}', function (Nutgram $bot, string $index): void {
    app(AdminNodeBatchUi::class)->field($bot, $index);
});
$bot->onCallbackQueryData('node3:preview', function (Nutgram $bot): void {
    app(AdminNodeBatchUi::class)->preview($bot);
});
$bot->onCallbackQueryData('node3:apply:{token}', function (Nutgram $bot, string $token): void {
    app(AdminNodeBatchUi::class)->apply($bot, $token);
});
$bot->onCallbackQueryData('node3:cancel', function (Nutgram $bot): void {
    app(AdminNodeBatchUi::class)->cancel($bot);
});

// OPS-CMD-01: private Super Admin Denaban snapshot and refresh.
$bot->onCallbackQueryData('denaban:show', function (Nutgram $bot): void {
    app(AdminCommandCenterUi::class)->show($bot);
});

// STAT-01: read-only, private Super Admin dashboard with bounded provider GETs.
$bot->onCommand('stats', function (Nutgram $bot): void {
    app(AdminDashboardUi::class)->show($bot);
});
$bot->onCallbackQueryData('stats:overview', function (Nutgram $bot): void {
    app(AdminDashboardUi::class)->show($bot);
});
$bot->onCallbackQueryData('stats:refresh', function (Nutgram $bot): void {
    app(AdminDashboardUi::class)->show($bot);
});

// STAT-02: Admin-only, read-only 7/30 day Sales and Telegram summaries.
$bot->onCallbackQueryData('stats:detail:{section}:{days}', function (Nutgram $bot, string $section, string $days): void {
    app(AdminBusinessReportUi::class)->show($bot, $section, $days);
});

// MET-01: root-only, read-only Node and traffic snapshot.
$bot->onCommand('metrics', function (Nutgram $bot): void {
    app(AdminNodeMetricsUi::class)->show($bot);
});
$bot->onCallbackQueryData('metrics:refresh', function (Nutgram $bot): void {
    app(AdminNodeMetricsUi::class)->show($bot);
});

// MET-02: bounded on-demand historical reads, never provider mutations.
$bot->onCommand('traffic', function (Nutgram $bot): void {
    app(AdminTrafficReportUi::class)->summary($bot);
});
$bot->onCallbackQueryData('metrics:range:{days}', function (Nutgram $bot, string $days): void {
    app(AdminTrafficReportUi::class)->summary($bot, $days);
});
$bot->onCallbackQueryData('metrics:node:{days}:{uuid}', function (Nutgram $bot, string $days, string $uuid): void {
    app(AdminTrafficReportUi::class)->node($bot, $days, $uuid);
});
$bot->onCallbackQueryData('metrics:page:{days}:{page}', function (Nutgram $bot, string $days, string $page): void {
    app(AdminTrafficReportUi::class)->summary($bot, $days, $page);
});

$bot->onCommand('servers', function (Nutgram $bot): void {
    app(AdminProviderOverviewUi::class)->show($bot);
});

$bot->onCallbackQueryData('menu:admin:servers', function (Nutgram $bot): void {
    app(AdminProviderOverviewUi::class)->show($bot);
});

$bot->onCallbackQueryData('servers:refresh', function (Nutgram $bot): void {
    app(AdminProviderOverviewUi::class)->show($bot);
});

$bot->onCommand('fulfillment', function (Nutgram $bot): void {
    app(AdminFulfillmentUi::class)->browse($bot);
});

$bot->onCommand('payments', function (Nutgram $bot): void {
    app(ManualPaymentUi::class)->list($bot);
});

$bot->onCommand('topups', function (Nutgram $bot): void {
    app(BillingUi::class)->reviews($bot);
});

$bot->onCommand('admin', AdminHandler::class);

$bot->onCommand('users', AdminUsersHandler::class);

$bot->onCommand('roles', AdminRolesHandler::class);

$bot->onCommand('products', function (Nutgram $bot): void {
    app(ProductAdminUi::class)->browse($bot);
});

$bot->onCommand('plans', function (Nutgram $bot): void {
    app(PlanAdminUi::class)->browse($bot);
});

$bot->onCommand('plancancel', function (Nutgram $bot): void {
    app(PlanAdminUi::class)->cancel($bot);
});

$bot->onCommand('newrole {name}', function (Nutgram $bot, string $name): void {
    app(RbacUi::class)->createRequest($bot, $name);
});

$bot->onCommand('finduser {query}', function (Nutgram $bot, string $query): void {
    app(UserManagementUi::class)->search($bot, $query);
});

$bot->onCallbackQueryData('menu:admin:fulfillment', function (Nutgram $bot): void {
    app(AdminFulfillmentUi::class)->browse($bot);
});

$bot->onCallbackQueryData('full:list', function (Nutgram $bot): void {
    app(AdminFulfillmentUi::class)->browse($bot);
});

$bot->onCallbackQueryData('full:completed', function (Nutgram $bot): void {
    app(AdminFulfillmentUi::class)->browse($bot, true);
});

$bot->onCallbackQueryData('full:view:{id}', function (Nutgram $bot, string $id): void {
    app(AdminFulfillmentUi::class)->detail($bot, $id);
});

$bot->onCallbackQueryData('full:prepare:{id}:{mode}', function (Nutgram $bot, string $id, string $mode): void {
    app(AdminFulfillmentUi::class)->prepare($bot, $id, $mode);
});

$bot->onCallbackQueryData('full:apply:{token}', function (Nutgram $bot, string $token): void {
    app(AdminFulfillmentUi::class)->apply($bot, $token);
});

$bot->onCallbackQueryData('bank:list', function (Nutgram $bot): void {
    app(ManualPaymentUi::class)->list($bot);
});

$bot->onCallbackQueryData('bank:list:{page}', function (Nutgram $bot, string $page): void {
    app(ManualPaymentUi::class)->list($bot, $page);
});

$bot->onCallbackQueryData('bank:show:{claim}', function (Nutgram $bot, string $claim): void {
    app(ManualPaymentUi::class)->show($bot, $claim);
});

$bot->onCallbackQueryData('bank:decide:{claim}:{decision}', function (Nutgram $bot, string $claim, string $decision): void {
    app(ManualPaymentUi::class)->decide($bot, $claim, $decision);
});

$bot->onCallbackQueryData('bank:apply:{token}', function (Nutgram $bot, string $token): void {
    app(ManualPaymentUi::class)->apply($bot, $token);
});

// FIN-01: protected wallet reads and top-up photo review, separate from purchase receipts.
// ACC-01: Super Admin-only, private accounting notes; never moves actual money.
$bot->onCallbackQueryData('finance:cash:{period}', function (Nutgram $bot, string $period): void {
    app(AdminAccountingUi::class)->cashReport($bot, $period);
});

$bot->onCallbackQueryData('acc:report:{period}', function (Nutgram $bot, string $period): void {
    app(AdminAccountingUi::class)->report($bot, $period);
});

$bot->onCallbackQueryData('acc:home', function (Nutgram $bot): void {
    app(AdminAccountingUi::class)->home($bot);
});
$bot->onCallbackQueryData('acc:begin:{kind}', function (Nutgram $bot, string $kind): void {
    app(AdminAccountingUi::class)->begin($bot, $kind);
});
$bot->onCallbackQueryData('acc:category:{category}', function (Nutgram $bot, string $category): void {
    app(AdminAccountingUi::class)->category($bot, $category);
});
$bot->onCallbackQueryData('acc:apply:{token}', function (Nutgram $bot, string $token): void {
    app(AdminAccountingUi::class)->apply($bot, $token);
});
$bot->onCallbackQueryData('acc:history:{page}', function (Nutgram $bot, string $page): void {
    app(AdminAccountingUi::class)->history($bot, $page);
});
$bot->onCallbackQueryData('acc:reverse:prepare:{id}', function (Nutgram $bot, string $id): void {
    app(AdminAccountingUi::class)->prepareReverse($bot, $id);
});
$bot->onCallbackQueryData('acc:reverse:apply:{id}:{token}', function (Nutgram $bot, string $id, string $token): void {
    app(AdminAccountingUi::class)->applyReverse($bot, $id, $token);
});
$bot->onCallbackQueryData('acc:cancel', function (Nutgram $bot): void {
    app(AdminAccountingUi::class)->cancel($bot);
});

$bot->onCallbackQueryData('finance:wallets:{page}', function (Nutgram $bot, string $page): void {
    app(AdminFinanceWalletUi::class)->wallets($bot, $page);
});
$bot->onCallbackQueryData('finance:wallet:{id}', function (Nutgram $bot, string $id): void {
    app(AdminFinanceWalletUi::class)->detail($bot, $id);
});
$bot->onCallbackQueryData('finance:find-wallet', function (Nutgram $bot): void {
    app(AdminFinanceWalletUi::class)->find($bot);
});
$bot->onCallbackQueryData('finance:topups:{page}', function (Nutgram $bot, string $page): void {
    app(AdminFinanceWalletUi::class)->pending($bot, $page);
});
$bot->onCallbackQueryData('finance:topup:photo:{id}', function (Nutgram $bot, string $id): void {
    app(AdminFinanceWalletUi::class)->showPhoto($bot, $id);
});
$bot->onCallbackQueryData('finance:topup:decision:{id}:{decision}', function (Nutgram $bot, string $id, string $decision): void {
    app(AdminFinanceWalletUi::class)->decision($bot, $id, $decision);
});
$bot->onCallbackQueryData('finance:topup:apply:{token}', function (Nutgram $bot, string $token): void {
    app(AdminFinanceWalletUi::class)->apply($bot, $token);
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

$bot->onCallbackQueryData('menu:admin', AdminHandler::class);

$bot->onCallbackQueryData('menu:admin:users', AdminUsersHandler::class);

$bot->onCallbackQueryData('menu:admin:roles', AdminRolesHandler::class);

$bot->onCallbackQueryData('menu:admin:sales', function (Nutgram $bot): void {
    app(AdminSectionsUi::class)->sales($bot);
});

$bot->onCallbackQueryData('menu:admin:finance', function (Nutgram $bot): void {
    app(AdminSectionsUi::class)->finance($bot);
});

$bot->onCallbackQueryData('menu:admin:plans', function (Nutgram $bot): void {
    app(PlanAdminUi::class)->browse($bot);
});

$bot->onCallbackQueryData('plan:list:{page}', function (Nutgram $bot, string $page): void {
    app(PlanAdminUi::class)->browse($bot, ctype_digit($page) ? (int) $page : 1);
});

$bot->onCallbackQueryData('plan:examples', function (Nutgram $bot): void {
    app(PlanAdminUi::class)->examples($bot);
});

$bot->onCallbackQueryData('menu:admin:products', function (Nutgram $bot): void {
    app(ProductAdminUi::class)->browse($bot);
});

$bot->onCallbackQueryData('product:list:{page}', function (Nutgram $bot, string $page): void {
    app(ProductAdminUi::class)->browse($bot, ctype_digit($page) ? min(10000, (int) $page) : 1);
});

$bot->onCallbackQueryData('product:view:{id}', function (Nutgram $bot, string $id): void {
    app(ProductAdminUi::class)->detail($bot, $id);
});

$bot->onCallbackQueryData('product:form:{operation}:{id}:{days}', function (Nutgram $bot, string $operation, string $id, string $days): void {
    app(ProductAdminUi::class)->form($bot, $operation, $id, $days);
});

$bot->onCallbackQueryData('product:status:{operation}:{id}:{days}', function (Nutgram $bot, string $operation, string $id, string $days): void {
    app(ProductAdminUi::class)->status($bot, $operation, $id, $days);
});

$bot->onCallbackQueryData('product:apply:{token}', function (Nutgram $bot, string $token): void {
    app(ProductAdminUi::class)->apply($bot, $token);
});

$bot->onCallbackQueryData('product:quick', function (Nutgram $bot): void {
    app(ProductAdminUi::class)->quick($bot);
});

$bot->onCallbackQueryData('product:back', function (Nutgram $bot): void {
    app(ProductAdminUi::class)->back($bot);
});

$bot->onCallbackQueryData('product:cancel', function (Nutgram $bot): void {
    app(ProductAdminUi::class)->cancel($bot);
});

$bot->onCallbackQueryData('plan:products', function (Nutgram $bot): void {
    app(PlanAdminUi::class)->products($bot);
});

$bot->onCallbackQueryData('plan:view:{id}', function (Nutgram $bot, string $id): void {
    app(PlanAdminUi::class)->detail($bot, $id);
});

$bot->onCallbackQueryData('plan:begin:{type}:{id}', function (Nutgram $bot, string $type, string $id): void {
    app(PlanAdminUi::class)->begin($bot, $type, $id);
});

$bot->onCallbackQueryData('plan:save:{token}', function (Nutgram $bot, string $token): void {
    app(PlanAdminUi::class)->save($bot, $token);
});

$bot->onCallbackQueryData('plan:status:{id}:{operation}', function (Nutgram $bot, string $id, string $operation): void {
    app(PlanAdminUi::class)->requestStatus($bot, $id, $operation);
});

$bot->onCallbackQueryData('plan:apply:{type}:{token}', function (Nutgram $bot, string $type, string $token): void {
    app(PlanAdminUi::class)->applyStatus($bot, $type, $token);
});

$bot->onCallbackQueryData('plan:editquick', function (Nutgram $bot): void {
    app(PlanAdminUi::class)->editQuick($bot);
});

$bot->onCallbackQueryData('plan:editfields', function (Nutgram $bot): void {
    app(PlanAdminUi::class)->editFields($bot);
});

$bot->onCallbackQueryData('plan:editfield:{index}', function (Nutgram $bot, string $index): void {
    app(PlanAdminUi::class)->editField($bot, $index);
});

$bot->onCallbackQueryData('plan:editchoice:{value}', function (Nutgram $bot, string $value): void {
    app(PlanAdminUi::class)->editChoice($bot, $value);
});

$bot->onCallbackQueryData('plan:choose:{value}', function (Nutgram $bot, string $value): void {
    app(PlanAdminUi::class)->choose($bot, $value);
});

$bot->onCallbackQueryData('plan:quick', function (Nutgram $bot): void {
    app(PlanAdminUi::class)->quick($bot);
});

$bot->onCallbackQueryData('plan:back', function (Nutgram $bot): void {
    app(PlanAdminUi::class)->previous($bot);
});

$bot->onCallbackQueryData('plan:cancel', function (Nutgram $bot): void {
    app(PlanAdminUi::class)->cancel($bot);
});

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

// OP-02: trusted Admin-only Plan–Squad selection. No remote writes.
$bot->onCallbackQueryData('plan:squad:list:{id}', function (Nutgram $bot, string $id): void {
    app(PlanSquadUi::class)->browse($bot, $id);
});

$bot->onCallbackQueryData('plan:squad:select:{id}:{choice}', function (Nutgram $bot, string $id, string $choice): void {
    app(PlanSquadUi::class)->select($bot, $id, $choice);
});

$bot->onCallbackQueryData('plan:squad:apply:{token}', function (Nutgram $bot, string $token): void {
    app(PlanSquadUi::class)->apply($bot, $token);
});

// MVP-04: Admin-only status operations for locally linked, fulfilled subscriptions.
$bot->onCallbackQueryData('full:service:view:{id}', function (Nutgram $bot, string $id): void {
    app(AdminSubscriptionStatusUi::class)->view($bot, $id);
});

$bot->onCallbackQueryData('full:service:prepare:{id}:{mode}', function (Nutgram $bot, string $id, string $mode): void {
    app(AdminSubscriptionStatusUi::class)->prepare($bot, $id, $mode);
});

$bot->onCallbackQueryData('full:service:apply:{token}', function (Nutgram $bot, string $token): void {
    app(AdminSubscriptionStatusUi::class)->apply($bot, $token);
});

$bot->onCallbackQueryData('full:service:check:{id}', function (Nutgram $bot, string $id): void {
    app(AdminSubscriptionStatusUi::class)->check($bot, $id);
});

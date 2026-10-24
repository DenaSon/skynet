<?php

use App\Interfaces\Telegram\Processing\ProcessSalesTelegramUpdate;
use App\Interfaces\Telegram\Processing\SalesTelegramRuntime;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;

uses(RefreshDatabase::class);

/** @return array<int, string> */
function mb03Registrations(string $path): array
{
    $bot = new class
    {
        /** @var array<int, string> */
        public array $routes = [];

        public function onCommand(string $pattern, mixed $handler): void
        {
            $this->routes[] = 'command:'.$pattern;
        }

        public function onCallbackQueryData(string $pattern, mixed $handler): void
        {
            $this->routes[] = 'callback:'.$pattern;
        }

        public function onPhoto(mixed $handler): void
        {
            $this->routes[] = 'photo:*';
        }

        public function fallback(mixed $handler): void
        {
            $this->routes[] = 'fallback:*';
        }

        public function onText(string $pattern, mixed $handler): void
        {
            $this->routes[] = 'text:'.$pattern;
        }
    };

    require base_path($path);

    return $bot->routes;
}

it('registers exactly the audited MB-01 routes in distinct Admin, legacy Sales and shared registries', function (): void {
    $inventory = file_get_contents(base_path('docs/019-MB01-TELEGRAM-ROUTE-INVENTORY.md'));
    expect($inventory)->toBeString();

    preg_match_all(
        '/^\\| \\x60([^\\x60]+)\\x60 \\| (ADMIN|SALES|SHARED) \\| [^\\n]*\\| \\x60routes\\/telegram\\.php:(\\d+)\\x60 \\|$/m',
        $inventory,
        $matches,
        PREG_SET_ORDER,
    );

    $expected = ['ADMIN' => [], 'SALES' => [], 'SHARED' => []];

    foreach ($matches as $match) {
        $kind = (int) $match[3] < 100 ? 'command:' : 'callback:';
        $name = $kind === 'command:' ? ltrim($match[1], '/') : $match[1];
        $expected[$match[2]][] = $kind.$name;
    }

    expect(count($matches))->toBe(111); // Immutable MB-01 baseline

    // SP-02: protected Sales Panel review queue, only on Admin.
    $expected['ADMIN'][] = 'command:panelrequests';
    $expected['ADMIN'][] = 'callback:sp:list:{page}';
    $expected['ADMIN'][] = 'callback:sp:detail:{id}';
    $expected['ADMIN'][] = 'callback:sp:prepare:{id}:{decision}';
    $expected['ADMIN'][] = 'callback:sp:apply:{token}';
    // STAT-01: scoped Admin read-only statistics routes.
    $expected['ADMIN'][] = 'command:stats';
    // STAT-02: bounded Admin-only business reports.
    $expected['ADMIN'][] = 'callback:stats:detail:{section}:{days}';
    $expected['ADMIN'][] = 'callback:stats:overview';
    $expected['ADMIN'][] = 'callback:stats:refresh';
    // MB-05B adds one guarded, unadvertised Admin-only read command.
    $expected['ADMIN'][] = 'command:servers';
    // SRO-02 adds two guarded, read-only Admin callbacks without changing the MB-01 baseline.
    $expected['ADMIN'][] = 'callback:menu:admin:servers';
    $expected['ADMIN'][] = 'callback:servers:refresh';
    // RW-04A adds three root-only read-only routes; immutable MB-01 baseline retained.
    $expected['ADMIN'][] = 'command:panelusers';
    $expected['ADMIN'][] = 'callback:menu:admin:panelusers';
    $expected['ADMIN'][] = 'callback:rw:users:page:{page}';
    $expected['ADMIN'][] = 'callback:rw:users:view:{id}:{page}';
    // OP-02 adds three Admin-only root-protected Squad callbacks.
    $expected['ADMIN'][] = 'callback:plan:squad:list:{id}';
    $expected['ADMIN'][] = 'callback:plan:squad:select:{id}:{choice}';
    $expected['ADMIN'][] = 'callback:plan:squad:apply:{token}';
    // OP-03 only exposes these operator-confirmed fulfillment callbacks on Admin.
    $expected['ADMIN'][] = 'command:fulfillment';
    $expected['ADMIN'][] = 'callback:menu:admin:fulfillment';
    $expected['ADMIN'][] = 'callback:full:list';
    $expected['ADMIN'][] = 'callback:full:completed';
    $expected['ADMIN'][] = 'callback:full:view:{id}';
    $expected['ADMIN'][] = 'callback:full:prepare:{id}:{mode}';
    $expected['ADMIN'][] = 'callback:full:apply:{token}';
    $expected['ADMIN'][] = 'callback:bank:decide:{claim}:{decision}';
    $expected['ADMIN'][] = 'callback:bank:list:{page}';
    $expected['ADMIN'][] = 'callback:full:service:view:{id}';
    $expected['ADMIN'][] = 'callback:full:service:prepare:{id}:{mode}';
    $expected['ADMIN'][] = 'callback:full:service:apply:{token}';
    $expected['ADMIN'][] = 'callback:full:service:check:{id}';
    // FIN-01 keeps photo finance routes private to the Admin Bot.
    $expected['ADMIN'][] = 'callback:finance:wallets:{page}';
    $expected['ADMIN'][] = 'callback:finance:wallet:{id}';
    $expected['ADMIN'][] = 'callback:finance:find-wallet';
    $expected['ADMIN'][] = 'callback:finance:topups:{page}';
    $expected['ADMIN'][] = 'callback:finance:topup:photo:{id}';
    $expected['ADMIN'][] = 'callback:finance:topup:decision:{id}:{decision}';
    $expected['ADMIN'][] = 'callback:finance:topup:apply:{token}';
    // ACC-01 adds private root-only accounting, no Sales routes.
    $expected['ADMIN'][] = 'callback:acc:home';
    $expected['ADMIN'][] = 'callback:acc:report:{period}';
    $expected['ADMIN'][] = 'callback:acc:begin:{kind}';
    $expected['ADMIN'][] = 'callback:acc:category:{category}';
    $expected['ADMIN'][] = 'callback:acc:apply:{token}';
    $expected['ADMIN'][] = 'callback:acc:history:{page}';
    $expected['ADMIN'][] = 'callback:acc:reverse:prepare:{id}';
    $expected['ADMIN'][] = 'callback:acc:reverse:apply:{id}:{token}';
    $expected['ADMIN'][] = 'callback:acc:cancel';
    // MET-01 adds only read-only private Super Admin routes.
    $expected['ADMIN'][] = 'command:metrics';
    $expected['ADMIN'][] = 'callback:metrics:refresh';
    // MET-02 keeps read-only history and node-user drilldown on Admin only.
    $expected['ADMIN'][] = 'command:traffic';
    $expected['ADMIN'][] = 'callback:metrics:range:{days}';
    $expected['ADMIN'][] = 'callback:metrics:node:{days}:{uuid}';
    $expected['ADMIN'][] = 'callback:metrics:page:{days}:{page}';
    // CFG-01 is read-only and private to Admin Bot.
    $expected['ADMIN'][] = 'command:profiles';
    $expected['ADMIN'][] = 'callback:cfg1:list:{page}';
    $expected['ADMIN'][] = 'callback:cfg1:view:{uuid}:{page}';
    // HOST-01 adds private read and guarded Host creation only on Admin.
    $expected['ADMIN'][] = 'command:hosts';
    $expected['ADMIN'][] = 'callback:host1:list:{page}';
    $expected['ADMIN'][] = 'callback:host1:view:{uuid}';
    $expected['ADMIN'][] = 'callback:host1:begin';
    $expected['ADMIN'][] = 'callback:host1:profile:{index}';
    $expected['ADMIN'][] = 'callback:host1:inbound:{index}';
    $expected['ADMIN'][] = 'callback:host1:apply:{token}';
    $expected['ADMIN'][] = 'callback:host1:cancel';
    // HOST-02 adds single-Host mutation and bounded bulk actions on Admin only.
    $expected['ADMIN'][] = 'callback:host2:prepare:{uuid}:{mode}';
    $expected['ADMIN'][] = 'callback:host2:apply:{token}';
    $expected['ADMIN'][] = 'callback:host2:cancel';
    $expected['ADMIN'][] = 'callback:host2:batch:begin';
    $expected['ADMIN'][] = 'callback:host2:batch:page:{page}';
    $expected['ADMIN'][] = 'callback:host2:batch:toggle:{index}';
    $expected['ADMIN'][] = 'callback:host2:batch:choose:{mode}';
    $expected['ADMIN'][] = 'callback:host2:batch:apply:{token}';
    $expected['ADMIN'][] = 'callback:host2:batch:cancel';
    // CFG-02 keeps guarded profile lifecycle isolated to Admin Bot.
    $expected['ADMIN'][] = 'callback:cfg2:begin';
    $expected['ADMIN'][] = 'callback:cfg2:prepare:{uuid}:{mode}';
    $expected['ADMIN'][] = 'callback:cfg2:apply:{token}';
    $expected['ADMIN'][] = 'callback:cfg2:cancel';
    // NW-04: resume one actor-owned installation from existing Admin Node menu.
    $expected['ADMIN'][] = 'callback:nw4:recent';
    // NW-03 retains Admin-only routing for confirmed Node/Host linking.
    $expected['ADMIN'][] = 'callback:nw3:resume:{identifier}';
    $expected['ADMIN'][] = 'callback:nw3:node:prepare:{identifier}';
    $expected['ADMIN'][] = 'callback:nw3:node:apply:{token}';
    $expected['ADMIN'][] = 'callback:nw3:dns:prepare:{identifier}';
    $expected['ADMIN'][] = 'callback:nw3:dns:apply:{token}';
    $expected['ADMIN'][] = 'callback:nw3:dns:reconcile:{identifier}';
    $expected['ADMIN'][] = 'callback:nw3:host:begin:{identifier}';
    $expected['ADMIN'][] = 'callback:nw3:host:apply:{token}';
    // NW-02 adds two separately gated private Admin-only installer callbacks.
    $expected['ADMIN'][] = 'callback:nw2:start:{token}';
    $expected['ADMIN'][] = 'callback:nw2:status:{identifier}';
    // NW-01 adds root-only read-only SSH readiness callbacks, no Sales routes.
    $expected['ADMIN'][] = 'callback:nw1:begin';
    $expected['ADMIN'][] = 'callback:nw1:dns:zones';
    $expected['ADMIN'][] = 'callback:nw1:dns:zone:{index}';
    $expected['ADMIN'][] = 'callback:nw1:dns:skip';
    $expected['ADMIN'][] = 'callback:nw1:profile:{index}';
    $expected['ADMIN'][] = 'callback:nw1:inbound:{index}';
    $expected['ADMIN'][] = 'callback:nw1:scan:{token}';
    $expected['ADMIN'][] = 'callback:nw1:trust:{token}';
    $expected['ADMIN'][] = 'callback:nw1:cancel';
    // CF-02: bounded read and confirmed DNS management callbacks (Admin-only).
    $expected['ADMIN'][] = 'command:dns';
    $expected['ADMIN'][] = 'callback:cf2:zones';
    $expected['ADMIN'][] = 'callback:cf2:zone:{index}';
    $expected['ADMIN'][] = 'callback:cf2:list:{page}';
    $expected['ADMIN'][] = 'callback:cf2:view:{index}:{token}';
    $expected['ADMIN'][] = 'callback:cf2:create';
    $expected['ADMIN'][] = 'callback:cf2:edit:{mode}:{token}';
    $expected['ADMIN'][] = 'callback:cf2:apply:{token}';
    $expected['ADMIN'][] = 'callback:cf2:cancel';
    // NODE-01 adds only guarded Admin-only Node routes.
    $expected['ADMIN'][] = 'command:nodes';
    $expected['ADMIN'][] = 'callback:node1:list:{page}';
    $expected['ADMIN'][] = 'callback:node1:view:{uuid}';
    $expected['ADMIN'][] = 'callback:node1:tags';
    $expected['ADMIN'][] = 'callback:node1:begin';
    $expected['ADMIN'][] = 'callback:node1:profile:{index}';
    $expected['ADMIN'][] = 'callback:node1:inbound:{index}';
    $expected['ADMIN'][] = 'callback:node1:preview';
    $expected['ADMIN'][] = 'callback:node1:apply:{token}';
    $expected['ADMIN'][] = 'callback:node1:cancel';
    // NODE-02 adds private Super Admin mutations, isolated from the Sales bot.
    $expected['ADMIN'][] = 'callback:node2:prepare:{uuid}:{mode}';
    $expected['ADMIN'][] = 'callback:node2:apply:{token}';
    $expected['ADMIN'][] = 'callback:node2:cancel';
    // NODE-03 is an isolated Admin-only group operations wizard.
    $expected['ADMIN'][] = 'callback:node3:list';
    $expected['ADMIN'][] = 'callback:node3:toggle:{index}';
    $expected['ADMIN'][] = 'callback:node3:choose:{mode}';
    $expected['ADMIN'][] = 'callback:node3:move:{index}:{direction}';
    $expected['ADMIN'][] = 'callback:node3:profile:{index}';
    $expected['ADMIN'][] = 'callback:node3:inbound:{index}';
    $expected['ADMIN'][] = 'callback:node3:field:{index}';
    $expected['ADMIN'][] = 'callback:node3:preview';
    $expected['ADMIN'][] = 'callback:node3:apply:{token}';
    $expected['ADMIN'][] = 'callback:node3:cancel';
    $expected['SALES'][] = 'photo:*';
    $files = [
        'ADMIN' => 'app/Interfaces/Telegram/routes/admin.php',
        'SALES' => 'app/Interfaces/Telegram/routes/legacy_sales.php',
        'SHARED' => 'app/Interfaces/Telegram/routes/shared.php',
    ];

    // CLOSE-01 removes the legacy typed bank review routes from the Admin
    // registry while preserving the immutable historical MB-01 inventory.
    $expected['ADMIN'] = array_values(array_diff($expected['ADMIN'], [
        'command:bankconfirm {claim} {reference}',
        'callback:bank:choose:{claim}:{decision}',
    ]));

    foreach ($files as $owner => $path) {
        $actual = mb03Registrations($path);
        sort($expected[$owner]);
        sort($actual);

        expect($actual)->toBe($expected[$owner]);
    }

    expect(mb03Registrations('app/Interfaces/Telegram/routes/legacy_text.php'))
        ->toBe(['text:{volume}']);

    $legacy = mb03Registrations('routes/telegram.php');
    $all = array_merge($expected['ADMIN'], $expected['SALES'], $expected['SHARED'], ['text:{volume}']);
    sort($legacy);
    sort($all);

    expect($legacy)->toBe($all)
        ->and(count($legacy))->toBe(241)
        ->and(count(array_unique($legacy)))->toBe(241);
});

it('keeps Sales identity, navigation and buyer routes isolated from Admin callbacks', function (): void {
    expect(mb03Registrations('app/Interfaces/Telegram/routes/sales.php'))->toBe([
        'command:start {payload}',
        'command:start',
        'command:menu',
        'command:buy',
        'command:account',
        'command:link {code}',
        'callback:sales:home',
        'callback:sales:buy',
        'callback:sales:home:{page}',
        'callback:sales:invite',
        'command:invite',
        'callback:sales:support',
        'command:help',
        'command:cancel',
        'callback:sales:help:{page}',
        'callback:sales:health:{cursor}',
        'callback:sales:report:{days}',
        'callback:sales:wallet',
        'callback:sales:wallet:topup',
        'callback:sales:panel:request',
        'callback:sales:panel:accept',
        'callback:sales:panel:confirm',
        'callback:sales:panel:cancel',
        'callback:sales:account',
        'callback:sales:join',
        'command:catalog',
        'command:reports',
        'command:builder',
        'callback:menu:home',
        'callback:menu:plan-catalog',
        'callback:catalog:list:{page}',
        'callback:catalog:detail:{id}:{page}',
        'callback:menu:builder',
        'callback:builder:list:{page}',
        'callback:builder:detail:{id}:{page}',
        'callback:builder:days:{id}:{gb}:{page}',
        'callback:builder:quote:{id}:{gb}:{days}:{page}',
        'callback:builder:custom:{id}:{page}',
        'callback:builder:cancel',
        'text:(?!/){volume}',
        'fallback:*',
        'command:orders',
        'callback:sales:checkout:preview:{id}:{days}:{gb}',
        'callback:sales:checkout:new:{source}',
        'callback:sales:checkout:commit:{token}',
        'callback:sales:walletpay:confirm:{id}:{token}',
        'callback:sales:walletpay:cancel:{id}',
        'callback:sales:checkout:wallet:{id}',
        'callback:sales:checkout:name:cancel',
        'callback:sales:checkout:phone:cancel',
        'callback:sales:checkout:orders',
        'callback:sales:checkout:view:{id}',
        'callback:sales:payment:start:{id}',
        'photo:*',
        'command:subscriptions',
        'callback:sales:subs:list:{cursor}',
        'callback:sales:subs:view:{id}:{cursor}',
        'callback:sales:subs:page:{id}:{cursor}',
        'callback:sales:subs:link:{id}:{cursor}',
        'callback:sales:subs:search:start',
        'callback:sales:subs:search:page:{cursor}',
        'callback:sales:subs:search:cancel',
        'callback:sales:subs:phone:edit:{id}:{cursor}',
        'callback:sales:subs:phone:remove:{id}:{cursor}',
        'callback:sales:subs:phone:clear:{id}:{cursor}',
        'callback:sales:subs:phone:cancel',
        'callback:sales:subs:label:edit:{id}:{cursor}',
        'callback:sales:subs:label:remove:{id}:{cursor}',
        'callback:sales:subs:label:clear:{id}:{cursor}',
        'callback:sales:subs:label:cancel',
        'callback:sales:subs:renew:{id}:{cursor}',
        'callback:sales:subs:renew:checkout:{id}:{cursor}',
    ]);

    $runtime = file_get_contents(app_path('Interfaces/Telegram/Processing/SalesTelegramRuntime.php'));
    expect($runtime)->toContain("require __DIR__.'/../routes/sales.php'")
        ->not->toContain("require base_path('routes/telegram.php')");

    config()->set('telegram.sales.token', '12345678:sales-secret');
    $sales = app(SalesTelegramRuntime::class)->make();
    expect($sales->getBotId())->toBe(12345678);
});

it('does not execute forged Admin callbacks delivered to an enabled, isolated Sales webhook', function (): void {
    config()->set('telegram.webhook_secret', 'admin_secret_1234');
    config()->set('nutgram.token', '87654321:admin-secret');
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram.sales.token', '12345678:sales-secret');
    config()->set('telegram.sales.webhook_secret', 'sales_secret_5678');

    $user = User::factory()->create();
    TelegramAccount::query()->create([
        'user_id' => $user->getKey(),
        'telegram_user_id' => 42424242,
    ]);

    $beforeRoles = $user->getRoleNames()->all();

    // Unknown callbacks now send safe guidance. Use the real Sales registry
    // with a fake Telegram transport rather than calling Telegram from CI.
    app()->instance(ProcessSalesTelegramUpdate::class, new class implements ProcessSalesTelegramUpdate
    {
        public function handle(): void
        {
            $bot = Nutgram::fake();
            require base_path('app/Interfaces/Telegram/routes/sales.php');
            $id = (int) request()->input('callback_query.from.id');
            $bot->setCommonUser(TelegramUser::make($id, false, 'Test'));
            $bot->setCommonChat(Chat::make($id, ChatType::PRIVATE));
            $bot->hearCallbackQueryData((string) request()->input('callback_query.data'))->reply()
                ->assertCalled('answerCallbackQuery')->assertCalled('editMessageText');
        }
    });

    foreach (['rbac:apply:aaaa', 'users:apply:bbbb', 'product:apply:cccc', 'bank:apply:dddd', 'menu:admin:servers', 'servers:refresh'] as $offset => $forged) {
        $this->postJson('/api/telegram/sales/webhook', [
            'update_id' => 92000 + $offset,
            'callback_query' => [
                'id' => 'forged-'.$offset,
                'from' => ['id' => 42424242, 'first_name' => 'Test', 'is_bot' => false],
                'message' => [
                    'message_id' => 5,
                    'date' => time(),
                    'chat' => ['id' => 42424242, 'type' => 'private'],
                ],
                'chat_instance' => 'test-instance',
                'data' => $forged,
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'sales_secret_5678'])
            ->assertNoContent();
    }

    expect($user->fresh()->getRoleNames()->all())->toBe($beforeRoles)
        ->and(DB::table('telegram_updates')->where('bot_key', 'sales')->where('status', 'processed')->count())->toBe(6);
});

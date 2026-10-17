<?php

use App\Interfaces\Telegram\Processing\SalesTelegramRuntime;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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
    $expected['ADMIN'][] = 'callback:full:service:view:{id}';
    $expected['ADMIN'][] = 'callback:full:service:prepare:{id}:{mode}';
    $expected['ADMIN'][] = 'callback:full:service:apply:{token}';
    $expected['ADMIN'][] = 'callback:full:service:check:{id}';
    // MET-01 adds only read-only private Super Admin routes.
    $expected['ADMIN'][] = 'command:metrics';
    $expected['ADMIN'][] = 'callback:metrics:refresh';
    // MET-02 keeps read-only history and node-user drilldown on Admin only.
    $expected['ADMIN'][] = 'command:traffic';
    $expected['ADMIN'][] = 'callback:metrics:range:{days}';
    $expected['ADMIN'][] = 'callback:metrics:node:{days}:{uuid}';
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
        ->and(count($legacy))->toBe(173)
        ->and(count(array_unique($legacy)))->toBe(173);
});

it('keeps the second Sales Bot router limited to identity and read-only catalog without Admin callbacks', function (): void {
    expect(mb03Registrations('app/Interfaces/Telegram/routes/sales.php'))->toBe([
        'command:start {payload}',
        'command:start',
        'command:menu',
        'command:account',
        'command:link {code}',
        'callback:sales:home',
        'callback:sales:account',
        'callback:sales:join',
        'command:catalog',
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
        'text:{volume}',
        'command:orders',
        'callback:sales:checkout:preview:{id}:{days}:{gb}',
        'callback:sales:checkout:commit:{token}',
        'callback:sales:checkout:orders',
        'callback:sales:checkout:view:{id}',
        'callback:sales:payment:start:{id}',
        'photo:*',
        'command:subscriptions',
        'callback:sales:subs:list:{cursor}',
        'callback:sales:subs:view:{id}:{cursor}',
        'callback:sales:subs:link:{id}:{cursor}',
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

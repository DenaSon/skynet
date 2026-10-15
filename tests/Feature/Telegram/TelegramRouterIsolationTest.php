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
        ->and(count($legacy))->toBe(114)
        ->and(count(array_unique($legacy)))->toBe(114);
});

it('keeps the second Sales Bot router empty, separate and unable to register Admin callbacks', function (): void {
    expect(mb03Registrations('app/Interfaces/Telegram/routes/sales.php'))->toBe([]);

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

    foreach (['rbac:apply:aaaa', 'users:apply:bbbb', 'product:apply:cccc', 'bank:apply:dddd'] as $offset => $forged) {
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
        ->and(DB::table('telegram_updates')->where('bot_key', 'sales')->where('status', 'processed')->count())->toBe(4);
});

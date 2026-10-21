<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ReadAdminDashboard;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['990101', '990102']);
    config()->set('services.remnawave.url', 'https://stat01.test');
    config()->set('services.remnawave.token', 'fixture-only');
    Http::preventStrayRequests();
});

/** @return array{root:User,member:User} */
function stat01Users(): array
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 990101]);
    $member = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $member->id, 'telegram_user_id' => 990103]);

    DB::table('telegram_bot_contacts')->insert([
        'user_id' => $member->id, 'chat_id' => 990103, 'bot_key' => 'sales',
        'delivery_status' => 'reachable', 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return compact('root', 'member');
}

function stat01Bot(int $telegramId): Nutgram
{
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Admin'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/** @return array<string,mixed> */
function stat01Payload(NutgramRequest $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $data;
}

/** @return array<string,mixed> */
function stat01RemoteUser(int $id, string $status): array
{
    return [
        'id' => $id, 'username' => 'stat_'.$id, 'shortUuid' => 'short-'.$id,
        'status' => $status, 'trafficLimitBytes' => 1073741824,
        'trafficLimitStrategy' => 'NO_RESET',
        'expireAt' => now()->addDays(4)->toIso8601String(),
        'subscriptionUrl' => 'https://credentials.stat01.test/'.$id,
        'userTraffic' => ['usedTrafficBytes' => 0], 'description' => null,
        'activeInternalSquads' => [],
    ];
}

function stat01FakeProvider(int $total = 3): void
{
    Http::fake(static function (Request $r) use ($total) {
        $path = parse_url($r->url(), PHP_URL_PATH);
        $payload = match ($path) {
            '/api/system/health' => ['runtimeMetrics' => [['status' => 'HEALTHY']]],
            '/api/system/stats' => [
                'cpu' => ['cores' => 2],
                'memory' => ['total' => 2147483648, 'used' => 1073741824, 'free' => 1073741824],
                'users' => ['totalUsers' => $total],
                'onlineStats' => ['onlineNow' => 1],
                'uptime' => 500,
            ],
            '/api/nodes' => [
                ['uuid' => '123e4567-e89b-12d3-a456-426614174001', 'name' => 'Node A',
                    'isConnected' => true, 'isDisabled' => false],
                ['uuid' => '123e4567-e89b-12d3-a456-426614174002', 'name' => 'Node B',
                    'isConnected' => false, 'isDisabled' => false],
            ],
            '/api/hosts' => [['uuid' => '123e4567-e89b-12d3-a456-426614174003']],
            '/api/internal-squads' => ['internalSquads' => [], 'total' => 0],
            '/api/config-profiles' => ['configProfiles' => [], 'total' => 0],
            '/api/users' => [
                'users' => $total === 3
                    ? [stat01RemoteUser(1, 'ACTIVE'), stat01RemoteUser(2, 'EXPIRED'), stat01RemoteUser(3, 'LIMITED')]
                    : [],
                'total' => $total,
            ],
            default => null,
        };

        return $payload === null ? Http::response([], 404) : Http::response(['response' => $payload]);
    });
}

it('shows a private root-only read-only dashboard using complete live provider status and local identity counts', function (): void {
    $data = stat01Users();
    stat01FakeProvider();
    $report = app(ReadAdminDashboard::class)->handle($data['root']->id);

    expect($report['nodes'])->toBe(2)
        ->and($report['connected'])->toBe(1)
        ->and($report['hosts'])->toBe(1)
        ->and($report['panelUsers'])->toBe(3)
        ->and($report['online'])->toBe(1)
        ->and($report['subscriptionsActive'])->toBe(1)
        ->and($report['subscriptionsExpired'])->toBe(1)
        ->and($report['subscriptionsLimited'])->toBe(1)
        ->and($report['localUsers'])->toBe(2)
        ->and($report['localActive'])->toBe(2)
        ->and($report['telegramLinked'])->toBe(2)
        ->and($report['salesReachable'])->toBe(1)
        ->and($report['salesRecent'])->toBe(1)
        ->and($report['salesTodayRials'])->toBe(0);

    $bot = stat01Bot(990101);
    $bot->hearText('/stats')->reply()->assertRaw(static function (NutgramRequest $r): bool {
        $p = stat01Payload($r);

        return str_contains($p['text'], '📊 آمار DenaGate')
            && str_contains($p['text'], 'اشتراک‌های فعال پنل: 1')
            && str_contains($p['text'], '📈') === false
            && ! str_contains($p['text'], 'credentials.stat01.test')
            && str_contains(json_encode($p['reply_markup'], JSON_THROW_ON_ERROR), 'stats:refresh')
            && ($p['parse_mode'] ?? null) === 'HTML';
    });
    $bot->hearCallbackQueryData('stats:refresh')->reply()->assertCalled('editMessageText');
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'GET');
    Http::assertNotSent(static fn (Request $r): bool => $r->method() !== 'GET');
});

it('renders unavailable remote counts as unknown and does not treat local subscriptions as live', function (): void {
    $data = stat01Users();
    Http::fake(['https://stat01.test/*' => Http::response([], 503)]);
    $report = app(ReadAdminDashboard::class)->handle($data['root']->id);
    expect($report['nodes'])->toBeNull()
        ->and($report['subscriptionsActive'])->toBeNull()
        ->and($report['panelUsers'])->toBeNull()
        ->and($report['localUsers'])->toBe(2);

    $bot = stat01Bot(990101);
    $bot->hearText('/stats')->reply()->assertRaw(static function (NutgramRequest $r): bool {
        $p = stat01Payload($r);

        return str_contains($p['text'], 'نودها: نامشخص')
            && str_contains($p['text'], 'اشتراک‌های فعال پنل: نامشخص');
    });
});

it('does not fabricate subscription counts from a partial or oversized provider list', function (): void {
    $data = stat01Users();
    stat01FakeProvider(1001);
    $report = app(ReadAdminDashboard::class)->handle($data['root']->id);
    expect($report['panelUsers'])->toBe(1001)
        ->and($report['subscriptionsActive'])->toBeNull()
        ->and($report['subscriptionsExpired'])->toBeNull();
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'GET');
});

it('rejects ordinary and revoked admins and group callbacks before any provider requests', function (): void {
    $data = stat01Users();
    Http::fake();
    $bot = stat01Bot(990103);
    $bot->hearText('/stats')->reply()->assertReplyText('دسترسی مجاز نیست.');
    $bot->hearCallbackQueryData('stats:overview')->reply()->assertCalled('answerCallbackQuery');
    $bot->setCommonChat(Chat::make(-990103, ChatType::GROUP));
    $bot->hearText('/stats')->reply()->assertNoReply();

    expect(fn () => app(ReadAdminDashboard::class)->handle($data['member']->id))
        ->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

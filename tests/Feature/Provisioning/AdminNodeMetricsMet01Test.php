<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ReadAdminNodeMetrics;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

const MET01_NODE = '550e8400-e29b-41d4-a716-446655440010';

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['954001', '954002']);
    config()->set('services.remnawave.url', 'https://met01.test');
    config()->set('services.remnawave.token', 'offline-test-token');
    config()->set('services.remnawave.writes_enabled', false);
    Http::preventStrayRequests();
});

function met01Root(int $id = 954001, bool $super = true): User
{
    $user = User::factory()->create();
    if ($super) {
        $user->syncRoles(['super-admin']);
    }
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $id]);

    return $user;
}

function met01Bot(int $id = 954001, bool $private = true): Nutgram
{
    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($id, false, 'Root'));
    $bot->setCommonChat(Chat::make($private ? $id : -954001, $private ? ChatType::PRIVATE : ChatType::GROUP));

    return $bot;
}

function met01Fake(bool $metrics = true, bool $traffic = true, bool $nodes = true, ?string $bytes = null, bool $completeHistory = false): void
{
    Http::fake(static function (Request $request) use ($metrics, $traffic, $nodes, $bytes, $completeHistory) {
        $url = $request->url();
        $today = gmdate('Y-m-d');
        $yesterday = gmdate('Y-m-d', strtotime('-1 day'));

        if (str_starts_with($url, 'https://met01.test/api/bandwidth-stats/nodes?')) {
            $dates = [];
            for ($i = 29; $i >= 0; $i--) {
                $dates[] = gmdate('Y-m-d', strtotime("-{$i} days"));
            }

            return Http::response(['response' => [
                'categories' => $completeHistory ? $dates : [$today],
                'sparklineData' => $completeHistory ? array_fill(0, 30, 1073741824) : [1073741824],
                'topNodes' => [],
                'series' => [],
            ]]);
        }
        if ($url === 'https://met01.test/api/nodes') {
            return $nodes ? Http::response(['response' => [
                ['uuid' => MET01_NODE, 'name' => 'Germany', 'isConnected' => true, 'isDisabled' => false],
            ]]) : Http::response(['message' => 'down'], 503);
        }
        if ($url === 'https://met01.test/api/system/nodes/metrics') {
            return $metrics ? Http::response(['response' => ['nodes' => [
                ['nodeUuid' => MET01_NODE, 'nodeName' => 'Germany', 'usersOnline' => 3],
            ]]]) : Http::response(['message' => 'down'], 503);
        }
        if ($url === 'https://met01.test/api/system/stats') {
            return $metrics ? Http::response(['response' => [
                'cpu' => ['cores' => 2], 'memory' => ['total' => 4096, 'used' => 2048, 'free' => 2048],
                'users' => ['totalUsers' => 20], 'onlineStats' => ['onlineNow' => 2], 'uptime' => 600.0,
            ]]) : Http::response(['message' => 'down'], 503);
        }
        if (str_starts_with($url, 'https://met01.test/api/system/stats/nodes')) {
            return $traffic ? Http::response(['response' => ['lastSevenDays' => [
                ['nodeName' => 'Germany', 'date' => $today, 'totalBytes' => $bytes ?? '1073741824'],
                ['nodeName' => 'Germany', 'date' => $yesterday, 'totalBytes' => '1073741824'],
            ]]]) : Http::response(['message' => 'down'], 503);
        }

        return Http::response(['message' => 'unexpected'], 404);
    });
}

function met01AssertOnlyGets(): void
{
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'GET'
        && $r->hasHeader('Authorization', 'Bearer offline-test-token'));
    expect(collect(Http::recorded())->filter(static fn (array $pair): bool => $pair[0]->method() !== 'GET')->count())->toBe(0);
}

it('reads live node status, global online users and safe daily/weekly byte totals', function (): void {
    $root = met01Root();
    met01Fake();

    $result = app(ReadAdminNodeMetrics::class)->handle($root->id);

    expect($result['state'])->toBe('available')
        ->and($result['total'])->toBe(1)
        ->and($result['connected'])->toBe(1)
        ->and($result['enabled'])->toBe(1)
        ->and($result['nodes'][0]['name'])->toBe('Germany')
        ->and($result['nodes'][0]['online'])->toBe(3)
        ->and($result['onlineTotal'])->toBe(2)
        ->and($result['todayBytes'])->toBe(1073741824)
        ->and($result['weekBytes'])->toBe(2147483648)
        ->and($result['trafficDate'])->toBe(gmdate('Y-m-d'))
        ->and($result['receivedAt'])->toBeString();

    met01AssertOnlyGets();
    Http::assertSentCount(4);
});

it('keeps node status readable if optional online and traffic metrics are down', function (): void {
    $root = met01Root();
    met01Fake(metrics: false, traffic: false);

    $result = app(ReadAdminNodeMetrics::class)->handle($root->id);

    expect($result['state'])->toBe('available')
        ->and($result['total'])->toBe(1)
        ->and($result['onlineTotal'])->toBeNull()
        ->and($result['nodes'][0]['online'])->toBeNull()
        ->and($result['todayBytes'])->toBeNull()
        ->and($result['weekBytes'])->toBeNull();

    met01AssertOnlyGets();
});

it('does not fabricate traffic totals from malformed numbers or overflow', function (string $bytes): void {
    $root = met01Root();
    met01Fake(bytes: $bytes);

    $result = app(ReadAdminNodeMetrics::class)->handle($root->id);

    expect($result['state'])->toBe('available')
        ->and($result['todayBytes'])->toBeNull()
        ->and($result['weekBytes'])->toBeNull()
        ->and($result['onlineTotal'])->toBe(2);

    met01AssertOnlyGets();
})->with(['NaN', '-1', '1.5', '9223372036854775808', '9999999999999999999999999']);

it('fails closed on inaccessible inventory and missing Remnawave settings', function (): void {
    $root = met01Root();
    met01Fake(nodes: false);

    $result = app(ReadAdminNodeMetrics::class)->handle($root->id);
    expect($result['state'])->toBe('unavailable')->and($result['receivedAt'])->toBeNull();

    Http::assertSentCount(1);
    config()->set('services.remnawave.token', null);
    Http::fake();
    Http::preventStrayRequests();
    expect(app(ReadAdminNodeMetrics::class)->handle($root->id)['state'])->toBe('unavailable');
});

it('rejects non-root and revoked operators before provider access', function (): void {
    $user = met01Root(super: false);
    met01Fake();
    expect(fn () => app(ReadAdminNodeMetrics::class)->handle($user->id))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();

    $user->syncRoles(['super-admin']);
    $user->forceFill(['is_active' => false])->save();
    expect(fn () => app(ReadAdminNodeMetrics::class)->handle($user->id))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('exposes only a private read-only Admin command and callback, never a Sales action', function (): void {
    met01Root();
    met01Fake();
    $bot = met01Bot();

    $bot->hearText('/metrics')->reply()->assertCalled('sendRichMessage');
    $bot->hearCallbackQueryData('metrics:refresh')->reply()->assertCalled('editMessageText');
    met01AssertOnlyGets();
    Http::assertSentCount(12);
});

it('does not query the provider from a group or a non-root Telegram actor', function (): void {
    met01Root();
    met01Root(954002, false);
    met01Fake();

    met01Bot(954001, false)->hearText('/metrics')->reply()->assertNoReply();
    met01Bot(954002)->hearText('/metrics')->reply();
    met01Bot(954002)->hearCallbackQueryData('metrics:refresh')->reply();

    Http::assertNothingSent();
});

it('renders validated 7- and 30-day traffic totals in the Rich Admin dashboard', function (): void {
    met01Root();
    met01Fake(completeHistory: true);
    met01Bot()->hearText('/metrics')->reply()
        ->assertCalled('sendRichMessage', 1)
        ->assertRaw(static function (NutgramRequest $request): bool {
            $data = FakeNutgram::getActualData($request);
            $rich = is_string($data['rich_message'] ?? null)
                ? json_decode($data['rich_message'], true, flags: JSON_THROW_ON_ERROR)
                : ($data['rich_message'] ?? []);
            $html = (string) ($rich['html'] ?? '');

            return str_contains($html, '۷ روز اخیر')
                && str_contains($html, '۷ روز اخیر</td><td><b>7.00 GiB</b>')
                && str_contains($html, '۳۰ روز اخیر</td><td><b>30.00 GiB</b>')
                && str_contains($html, '<table bordered striped compact>')
                && str_contains($html, 'Germany')
                && str_contains($html, '<details>');
        }, index: 0);

    Http::assertSentCount(6);
    met01AssertOnlyGets();
});

it('does not invent monthly traffic from incomplete historical daily data', function (): void {
    met01Root();
    met01Fake(completeHistory: false);
    met01Bot()->hearText('/metrics')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $data = FakeNutgram::getActualData($request);
            $rich = is_string($data['rich_message'] ?? null)
                ? json_decode($data['rich_message'], true, flags: JSON_THROW_ON_ERROR)
                : ($data['rich_message'] ?? []);
            $html = (string) ($rich['html'] ?? '');

            return str_contains($html, '۷ روز اخیر</td><td><b>نامشخص</b>')
                && str_contains($html, '۳۰ روز اخیر</td><td><b>نامشخص</b>');
        }, index: 0);
    met01AssertOnlyGets();
});

it('keeps the full native monitoring fallback with previously registered routes', function (): void {
    met01Root();
    met01Fake();
    met01Bot()->hearCallbackQueryData('metrics:simple')->reply()
        ->assertCalled('editMessageText')
        ->assertCalled('sendRichMessage', 0);
    met01AssertOnlyGets();
});

it('shows the latest endpoint-bound TCP observation in both Rich and native monitoring without new probes', function (): void {
    $root = met01Root();
    met01Fake();
    config()->set('cache.default', 'array');
    config()->set('denagate_health.node_tcp_targets', MET01_NODE.'@1.1.1.1:443');

    Cache::put('denagate:node-tcp:v1:'.MET01_NODE, [
        'endpoint' => hash('sha256', '1.1.1.1:443'),
        'status' => 'ok', 'ms' => 27.3, 'checkedAt' => now()->timestamp,
    ], 180);

    $snapshot = app(ReadAdminNodeMetrics::class)->handle($root->id);
    expect($snapshot['nodes'][0]['tcp'])->toMatchArray(['status' => 'ok', 'ms' => 27.3]);

    met01Bot()->hearText('/metrics')->reply()->assertRaw(static function (NutgramRequest $request): bool {
        $payload = FakeNutgram::getActualData($request);
        $rich = is_string($payload['rich_message'] ?? null)
            ? json_decode($payload['rich_message'], true, flags: JSON_THROW_ON_ERROR)
            : ($payload['rich_message'] ?? []);

        return str_contains((string) ($rich['html'] ?? ''), '27.3 ms')
            && str_contains((string) ($rich['html'] ?? ''), 'آنلاین / TCP');
    }, index: 0);

    met01Bot()->hearCallbackQueryData('metrics:simple')->reply()
        ->assertCalled('editMessageText');
    met01AssertOnlyGets();
});

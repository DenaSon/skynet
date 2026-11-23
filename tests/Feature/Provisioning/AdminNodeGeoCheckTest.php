<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\NodeGeoCheck;
use GuzzleHttp\Psr7\Request as TelegramRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

const GEOC_NODE = '550e8400-e29b-41d4-a716-446655440888';

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['987001', '987099']);
    config()->set('services.remnawave.url', 'https://rw-geoc.test');
    config()->set('services.remnawave.token', 'private-test-key');
    config()->set('services.remnawave.node_geocheck_enabled', false);
    config()->set('cache.default', 'array');
    Cache::flush();
    Http::preventStrayRequests();
});

function geocRoot(): User
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create([
        'user_id' => $root->id, 'telegram_user_id' => 987001,
    ]);

    return $root;
}

/** @return array<string,mixed> */
function geocReport(): array
{
    return [
        'schema' => 1,
        'timestamp' => '2026-11-23T09:00:00Z',
        'duration_ms' => 24028,
        'identity' => ['ipv4' => '198.51.100.10', 'asn' => 14956,
            'as_name' => 'Example Hosting', 'as_country' => 'DE'],
        'reputation' => ['type' => 'hosting', 'country' => 'Germany',
            'risk' => 33, 'confidence' => 100, 'hosting' => true,
            'vpn' => false, 'proxy' => false, 'tor' => false],
        'transport' => ['resolver' => 'system'],
        'findings' => [],
        'consensus' => ['ipv4' => [['country' => 'Germany', 'count' => 27,
            'total' => 28, 'percent' => 96.43]]],
        'geo' => [
            'cdn' => [['name' => 'CDN-1', 'kind' => 'country', 'ipv4' => []]],
            'geoip' => [['name' => 'GeoIP-1', 'kind' => 'country', 'ipv4' => []]],
            'services' => [['name' => 'Service-1', 'kind' => 'availability', 'ipv4' => []]],
        ],
        'connectivity_checks' => [
            'ok' => 8, 'altered' => 1, 'unreachable' => 0,
            'endpoints' => [['name' => 'Captive portal', 'verdict' => 'ok',
                'status' => 204, 'rtt_ms' => 14.97]],
        ],
        'stash_checks' => [['name' => 'Netflix', 'state' => 'available', 'rtt_ms' => 90.2]],
        'ai_endpoints' => [['name' => 'OpenAI', 'state' => 'reachable',
            'http_status' => 401, 'rtt_ms' => 192.79]],
        'connectivity' => [
            'score' => 93, 'latency_floor_ms' => 0.82, 'icmp_available' => true,
            'breakdown' => ['direct' => 16, 'peered' => 1, 'transit' => 3,
                'detour' => 1, 'intercepted' => 0, 'failed' => 0],
            'targets' => [[
                'name' => 'Google', 'verdict' => 'direct', 'method' => 'icmp',
                'score' => 100, 'rtt_ms' => 0.84, 'jitter_ms' => 0.21,
                'loss' => 0,
                'hops' => [['ttl' => 1, 'addr' => '198.51.100.1',
                    'asn' => 15169, 'avg_ms' => 0.9, 'loss' => 0]],
            ]],
        ],
    ];
}

function geocFake(bool $invalid = false, bool $pending = false): void
{
    Http::fake(static function (Request $request) use ($invalid, $pending) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/api/nodes' && $request->method() === 'GET') {
            return Http::response(['response' => [[
                'uuid' => GEOC_NODE, 'name' => 'German2',
                'isConnected' => true, 'isDisabled' => false,
            ]]], 200);
        }
        if ($path === '/api/nodes/'.GEOC_NODE && $request->method() === 'GET') {
            return Http::response(['response' => [
                'uuid' => GEOC_NODE, 'name' => 'German2',
                'isConnected' => true, 'isDisabled' => false,
            ]], 200);
        }
        if ($path === '/api/connections/geocheck/'.GEOC_NODE && $request->method() === 'POST') {
            return Http::response(['response' => ['jobId' => 14]], 201);
        }
        if ($path === '/api/connections/geocheck/14' && $request->method() === 'GET') {
            if ($pending) {
                return Http::response(['response' => [
                    'isCompleted' => false, 'isFailed' => false, 'result' => null,
                ]]);
            }
            $report = geocReport();
            if ($invalid) {
                $report['schema'] = 999;
            }

            return Http::response(['response' => [
                'isCompleted' => true, 'isFailed' => false,
                'result' => [
                    'success' => true, 'nodeUuid' => GEOC_NODE,
                    'rawReport' => $report,
                    'image' => ['format' => 'svg', 'encoding' => 'base64',
                        'data' => 'SENSITIVE-SVG-BASE64'],
                ],
            ]]);
        }

        return Http::response(['message' => 'not found'], 404);
    });
}

function geocBot(int $telegramId = 987001, bool $private = true): Nutgram
{
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Operator'));
    $bot->setCommonChat(Chat::make($private ? $telegramId : -100111,
        $private ? ChatType::PRIVATE : ChatType::SUPERGROUP));

    return $bot;
}

/** @return array<string,mixed> */
function geocTelegramPayload(TelegramRequest $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $data;
}

function geocHasCallback(TelegramRequest $request, string $callback): bool
{
    return str_contains(json_encode(geocTelegramPayload($request)['reply_markup'] ?? [],
        JSON_THROW_ON_ERROR), '"callback_data":"'.$callback.'"');
}

it('keeps start disabled by default but lets trusted root browse the live Node inventory', function (): void {
    $root = geocRoot();
    geocFake();
    $api = app(NodeGeoCheck::class);
    expect($api->nodes($root->id))->toHaveCount(1)
        ->and($api->start($root->id, GEOC_NODE))->toBe('disabled');

    Http::assertNotSent(static fn (Request $request): bool => $request->method() === 'POST');
});

it('requires a trusted active root for every operation and private Telegram context for the UI', function (): void {
    $root = geocRoot();
    geocFake();
    config()->set('services.remnawave.node_geocheck_enabled', true);
    $buyer = User::factory()->create();
    expect(fn () => app(NodeGeoCheck::class)->nodes($buyer->id))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(NodeGeoCheck::class)->start($buyer->id, GEOC_NODE))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(NodeGeoCheck::class)->result($buyer->id, GEOC_NODE, true))
        ->toThrow(AuthorizationException::class);

    $privateBot = geocBot(987001, false);
    $privateBot->hearCallbackQueryData('geoc:list:1')->reply();

    DB::table('users')->where('id', $root->id)->update(['is_active' => false]);
    expect(fn () => app(NodeGeoCheck::class)->start($root->id, GEOC_NODE))
        ->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('creates exactly one JSON-object GeoCheck job and safely reuses pending/ready cache', function (): void {
    $root = geocRoot();
    config()->set('services.remnawave.node_geocheck_enabled', true);
    geocFake();
    $action = app(NodeGeoCheck::class);
    expect($action->start($root->id, GEOC_NODE))->toBe('pending')
        ->and($action->start($root->id, GEOC_NODE))->toBe('existing')
        ->and($action->result($root->id, GEOC_NODE)['state'])->toBe('pending');

    $ready = $action->result($root->id, GEOC_NODE, true);
    expect($ready['state'])->toBe('ready')
        ->and($ready['summary']['score'])->toBe(93.0)
        ->and($ready['summary']['risk'])->toBe(33.0)
        ->and($ready['routes'])->toHaveCount(1)
        ->and($ready['web'])->toHaveCount(1)
        ->and($ready['geo'])->toHaveCount(3)
        ->and(json_encode($ready))->not->toContain('SENSITIVE-SVG-BASE64', 'private-test-key');
    expect($action->result($root->id, GEOC_NODE)['state'])->toBe('ready');

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://rw-geoc.test/api/connections/geocheck/'.GEOC_NODE
        && trim($request->body()) === '{}'
        && $request->hasHeader('Authorization', 'Bearer private-test-key'));
    Http::assertSentCount(7); // Five authorized Node GETs, one POST and one result GET.
    $posts = collect(Http::recorded())->filter(
        static fn ($pair): bool => $pair[0]->method() === 'POST');
    expect($posts)->toHaveCount(1);
});

it('preserves a pending check without sending a second POST', function (): void {
    $root = geocRoot();
    config()->set('services.remnawave.node_geocheck_enabled', true);
    geocFake(pending: true);
    $action = app(NodeGeoCheck::class);
    expect($action->start($root->id, GEOC_NODE))->toBe('pending')
        ->and($action->result($root->id, GEOC_NODE, true)['state'])->toBe('pending')
        ->and($action->start($root->id, GEOC_NODE))->toBe('existing');
});

it('rejects a malformed completed report and does not replay POST', function (): void {
    $root = geocRoot();
    config()->set('services.remnawave.node_geocheck_enabled', true);
    geocFake(invalid: true);
    $action = app(NodeGeoCheck::class);
    expect($action->start($root->id, GEOC_NODE))->toBe('pending')
        ->and($action->result($root->id, GEOC_NODE, true)['state'])->toBe('unavailable')
        ->and($action->start($root->id, GEOC_NODE))->toBe('existing');
});

it('renders independent Rich-first lists, summary, technical pages and route details with return navigation', function (): void {
    geocRoot();
    config()->set('services.remnawave.node_geocheck_enabled', true);
    geocFake();
    $bot = geocBot();

    $bot->hearCallbackQueryData('menu:admin:control')->reply()
        ->assertRaw(static fn (TelegramRequest $r): bool => geocHasCallback($r, 'geoc:list:1') && geocHasCallback($r, 'flt:home'), index: 1);

    $bot->hearCallbackQueryData('geoc:list:1')->reply()
        ->assertRaw(static fn (TelegramRequest $r): bool => geocHasCallback($r, 'geoc:node:'.GEOC_NODE)
            && geocHasCallback($r, 'menu:admin:control')
            && isset(geocTelegramPayload($r)['rich_message']), index: 1);

    $bot->hearCallbackQueryData('geoc:node:'.GEOC_NODE)->reply()
        ->assertRaw(static fn (TelegramRequest $r): bool => geocHasCallback($r, 'geoc:start:'.GEOC_NODE), index: 1);
    $bot->hearCallbackQueryData('geoc:start:'.GEOC_NODE)->reply()
        ->assertRaw(static fn (TelegramRequest $r): bool => geocHasCallback($r, 'geoc:status:'.GEOC_NODE), index: 1);
    $bot->hearCallbackQueryData('geoc:status:'.GEOC_NODE)->reply()
        ->assertRaw(static fn (TelegramRequest $r): bool => geocHasCallback($r, 'geoc:section:'.GEOC_NODE.':routes:1')
            && geocHasCallback($r, 'geoc:section:'.GEOC_NODE.':geo:1')
            && geocHasCallback($r, 'geoc:list:1'), index: 1);
    $bot->hearCallbackQueryData('geoc:section:'.GEOC_NODE.':routes:1')->reply()
        ->assertRaw(static fn (TelegramRequest $r): bool => geocHasCallback($r, 'geoc:route:'.GEOC_NODE.':0')
            && geocHasCallback($r, 'geoc:node:'.GEOC_NODE), index: 1);
    $bot->hearCallbackQueryData('geoc:route:'.GEOC_NODE.':0')->reply()
        ->assertRaw(static fn (TelegramRequest $r): bool => geocHasCallback($r, 'geoc:section:'.GEOC_NODE.':routes:1'), index: 1);
    $bot->hearCallbackQueryData('geoc:section:'.GEOC_NODE.':tech:1')->reply()
        ->assertRaw(static fn (TelegramRequest $r): bool => geocHasCallback($r, 'geoc:node:'.GEOC_NODE), index: 1);

    $posts = collect(Http::recorded())->filter(
        static fn ($pair): bool => $pair[0]->method() === 'POST');
    expect($posts)->toHaveCount(1);
});

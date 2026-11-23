<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ManageAdminArianetCloudLinks;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

const ARI3_SERVER = 101305418924;
const ARI3_OTHER_SERVER = 101305418925;
const ARI3_NODE_MATCH = '550e8400-e29b-41d4-a716-446655440081';
const ARI3_NODE_DOMAIN = '550e8400-e29b-41d4-a716-446655440082';
const ARI3_NODE_WRONG = '550e8400-e29b-41d4-a716-446655440083';

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['991901', '991902']);
    config()->set('services.arianet_cloud.read_token', 'ari3-read-secret');
    config()->set('services.arianet_cloud.write_token', null);
    config()->set('services.arianet_cloud.writes_enabled', false);
    config()->set('services.remnawave.url', 'https://rw.ari3.test');
    config()->set('services.remnawave.token', 'ari3-rw-read-secret');
    config()->set('services.remnawave.writes_enabled', false);
    Http::preventStrayRequests();
    Cache::flush();
});

function ari3Root(bool $super = true, int $telegramId = 991901): User
{
    $root = User::factory()->create();
    if ($super) {
        $root->syncRoles(['super-admin']);
    }
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => $telegramId]);

    return $root;
}

function ari3Bot(int $telegramId = 991901, bool $private = true): Nutgram
{
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Admin'));
    $bot->setCommonChat(Chat::make($private ? $telegramId : -999901,
        $private ? ChatType::PRIVATE : ChatType::GROUP));

    return $bot;
}

/** @return array<string,mixed> */
function ari3Payload(NutgramRequest $request): array
{
    $payload = FakeNutgram::getActualData($request);
    if (is_string($payload['reply_markup'] ?? null)) {
        $payload['reply_markup'] = json_decode($payload['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $payload;
}

function ari3Rich(NutgramRequest $request): string
{
    $payload = ari3Payload($request);
    $rich = is_string($payload['rich_message'] ?? null)
        ? json_decode($payload['rich_message'], true, 512, JSON_THROW_ON_ERROR)
        : ($payload['rich_message'] ?? []);

    return ($rich['is_rtl'] ?? false) === true ? (string) ($rich['html'] ?? '') : '';
}

function ari3Callback(NutgramRequest $request, string $prefix): ?string
{
    foreach (ari3Payload($request)['reply_markup']['inline_keyboard'] ?? [] as $row) {
        foreach ($row as $button) {
            $callback = $button['callback_data'] ?? null;
            if (is_string($callback) && str_starts_with($callback, $prefix)) {
                return $callback;
            }
        }
    }

    return null;
}

/** @return array<string,mixed> */
function ari3State(): array
{
    return [
        'cloudIp' => '62.144.51.204',
        'nodeIp' => '62.144.51.204',
        'domain' => 'node.coreflare.ir',
        'otherIp' => '1.1.1.1',
    ];
}

/** @param array<string,mixed> $state */
function ari3Fake(array &$state): void
{
    Http::fake(static function (Request $request) use (&$state) {
        if ($request->method() !== 'GET') {
            return Http::response(['error' => 'write forbidden'], 405);
        }

        $url = $request->url();
        $cloud = 'https://api.ariaservice.net/cloud/v1';
        $rw = 'https://rw.ari3.test/api';
        if (in_array($url, [$cloud.'/servers/'.ARI3_SERVER, $cloud.'/servers/'.ARI3_OTHER_SERVER], true)) {
            $id = (int) substr($url, strrpos($url, '/') + 1);

            return Http::response(['data' => [
                'id' => $id,
                'name' => '<script>cloud-server</script>',
                'status' => 'running',
                'ipv4' => $state['cloudIp'],
                'datacenter' => 'ovh',
                'disk_gb' => 30,
                'plan_id' => 8,
            ]]);
        }
        if ($url === $cloud.'/servers/'.ARI3_SERVER.'/costs') {
            return Http::response(['data' => [
                'server_id' => ARI3_SERVER, 'hours_per_month' => 730,
                'totals' => [['currency' => 'IRR', 'hourly_price' => 20343, 'monthly_price' => 14850390]],
                'lines' => [], 'snapshots' => ['count' => 0], 'kept_backups' => ['count' => 0],
            ]]);
        }
        $nodes = [
            [
                'uuid' => ARI3_NODE_MATCH, 'name' => '<b>Match node</b>',
                'address' => $state['nodeIp'], 'port' => 2222, 'isConnected' => true, 'isDisabled' => false,
            ],
            [
                'uuid' => ARI3_NODE_DOMAIN, 'name' => 'Domain node',
                'address' => $state['domain'], 'port' => 2222, 'isConnected' => true, 'isDisabled' => false,
            ],
            [
                'uuid' => ARI3_NODE_WRONG, 'name' => 'Different node',
                'address' => $state['otherIp'], 'port' => 2222, 'isConnected' => false, 'isDisabled' => false,
            ],
        ];

        if ($url === $rw.'/nodes') {
            return Http::response(['response' => $nodes]);
        }
        foreach ($nodes as $node) {
            if ($url === $rw.'/nodes/'.$node['uuid']) {
                return Http::response(['response' => $node]);
            }
        }

        return Http::response(['error' => 'unexpected'], 404);
    });
}

function ari3OnlyGets(): void
{
    Http::assertNotSent(static fn (Request $request): bool => $request->method() !== 'GET');
    foreach (Http::recorded() as [$request]) {
        expect(
            $request->hasHeader('Authorization', 'Bearer ari3-read-secret')
            || $request->hasHeader('Authorization', 'Bearer ari3-rw-read-secret')
        )->toBeTrue();
    }
}

it('proposes exact IPv4 match first and flags domain and different IPv4 as unverified', function (): void {
    $root = ari3Root();
    $state = ari3State();
    ari3Fake($state);

    $result = app(ManageAdminArianetCloudLinks::class)->candidates($root->id, ARI3_SERVER, 1);

    expect(array_column($result['items'], 'uuid'))->toBe([
        ARI3_NODE_MATCH, ARI3_NODE_DOMAIN, ARI3_NODE_WRONG,
    ]);
    expect(array_column($result['items'], 'match'))->toBe(['match', 'domain', 'mismatch']);
    expect($result['cloudIpv4'])->toBe('62.144.51.204');
    ari3OnlyGets();
});

it('stores a one-to-one mapping with stable IDs and supports explicit reassignment and unlink', function (): void {
    $root = ari3Root();
    $state = ari3State();
    ari3Fake($state);
    $action = app(ManageAdminArianetCloudLinks::class);

    $first = $action->preview($root->id, ARI3_SERVER, ARI3_NODE_MATCH);
    expect($first['match'])->toBe('match');
    $action->apply($root->id, $first);

    expect($action->linked($root->id, ARI3_SERVER))->toBe(['nodeUuid' => ARI3_NODE_MATCH]);
    expect(DB::table('arianet_cloud_node_links')->count())->toBe(1);
    expect(DB::table('arianet_cloud_node_links')->value('created_by'))->toBe($root->id);

    $replacement = $action->preview($root->id, ARI3_SERVER, ARI3_NODE_DOMAIN);
    expect($replacement['match'])->toBe('domain');
    $action->apply($root->id, $replacement);
    expect($action->linked($root->id, ARI3_SERVER))->toBe(['nodeUuid' => ARI3_NODE_DOMAIN]);
    expect(DB::table('arianet_cloud_node_links')->count())->toBe(1);

    $unlink = $action->preview($root->id, ARI3_SERVER, null);
    $action->apply($root->id, $unlink);
    expect($action->linked($root->id, ARI3_SERVER))->toBeNull();
    expect(DB::table('arianet_cloud_node_links')->count())->toBe(0);
    ari3OnlyGets();
});

it('refuses linking a Node already reserved for a different Cloud server', function (): void {
    $root = ari3Root();
    $state = ari3State();
    ari3Fake($state);
    $action = app(ManageAdminArianetCloudLinks::class);
    $action->apply($root->id, $action->preview($root->id, ARI3_SERVER, ARI3_NODE_MATCH));

    expect(fn () => $action->preview($root->id, ARI3_OTHER_SERVER, ARI3_NODE_MATCH))
        ->toThrow(ValidationException::class);
    expect($action->candidates($root->id, ARI3_OTHER_SERVER, 1)['items'])
        ->toHaveCount(2);
    expect(DB::table('arianet_cloud_node_links')->count())->toBe(1);
    ari3OnlyGets();
});

it('refuses stale preview when Cloud IP or Node management address changes', function (): void {
    $root = ari3Root();
    $state = ari3State();
    ari3Fake($state);
    $action = app(ManageAdminArianetCloudLinks::class);

    $ipPreview = $action->preview($root->id, ARI3_SERVER, ARI3_NODE_MATCH);
    $state['cloudIp'] = '62.144.51.205';
    expect(fn () => $action->apply($root->id, $ipPreview))
        ->toThrow(ValidationException::class);
    $state['cloudIp'] = '62.144.51.204';

    $nodePreview = $action->preview($root->id, ARI3_SERVER, ARI3_NODE_MATCH);
    $state['nodeIp'] = '62.144.51.206';
    expect(fn () => $action->apply($root->id, $nodePreview))
        ->toThrow(ValidationException::class);
    expect(DB::table('arianet_cloud_node_links')->count())->toBe(0);
    ari3OnlyGets();
});

it('blocks a stale replacement and unlink without changing the newer saved mapping', function (): void {
    $root = ari3Root();
    $state = ari3State();
    ari3Fake($state);
    $action = app(ManageAdminArianetCloudLinks::class);

    $action->apply($root->id, $action->preview($root->id, ARI3_SERVER, ARI3_NODE_MATCH));
    $staleUnlink = $action->preview($root->id, ARI3_SERVER, null);
    $action->apply($root->id, $action->preview($root->id, ARI3_SERVER, ARI3_NODE_DOMAIN));
    expect(fn () => $action->apply($root->id, $staleUnlink))
        ->toThrow(ValidationException::class);
    expect($action->linked($root->id, ARI3_SERVER))->toBe(['nodeUuid' => ARI3_NODE_DOMAIN]);
    ari3OnlyGets();
});

it('denies all mapping access to an untrusted actor before calling providers', function (): void {
    $user = ari3Root(false);
    $action = app(ManageAdminArianetCloudLinks::class);
    Http::fake();

    expect(fn () => $action->linked($user->id, ARI3_SERVER))->toThrow(AuthorizationException::class);
    expect(fn () => $action->candidates($user->id, ARI3_SERVER, 1))->toThrow(AuthorizationException::class);
    expect(fn () => $action->preview($user->id, ARI3_SERVER, ARI3_NODE_MATCH))
        ->toThrow(AuthorizationException::class);
    expect(fn () => $action->apply($user->id, ['mode' => 'unlink', 'serverId' => ARI3_SERVER]))
        ->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('shows a safe Rich link candidate list and explicit one-use confirmation in Admin Bot', function (): void {
    ari3Root();
    $state = ari3State();
    ari3Fake($state);
    $bot = ari3Bot();
    $chosen = null;

    $bot->hearCallbackQueryData('ari3:nodes:'.ARI3_SERVER.':1:1')->reply()
        ->assertRaw(static function (NutgramRequest $request) use (&$chosen): bool {
            $chosen = ari3Callback($request, 'ari3:pick:'.ARI3_SERVER.':1:0:');

            return $chosen !== null
                && str_contains(ari3Rich($request), 'اتصال سرور به نود')
                && str_contains(ari3Rich($request), '&lt;b&gt;Match node&lt;/b&gt;')
                && ! str_contains(ari3Rich($request), '<script>')
                && ari3Callback($request, 'ari2:view:'.ARI3_SERVER.':1') !== null;
        }, index: 1);

    $confirm = null;
    $bot->hearCallbackQueryData($chosen)->reply()
        ->assertRaw(static function (NutgramRequest $request) use (&$confirm): bool {
            $confirm = ari3Callback($request, 'ari3:apply:');

            return $confirm !== null && str_contains(ari3Rich($request), 'IPv4 دو طرف یکسان است')
                && ari3Callback($request, 'ari3:cancel:') !== null;
        }, index: 1);

    $bot->hearCallbackQueryData($confirm)->reply()
        ->assertRaw(static fn (NutgramRequest $request): bool => str_contains((string) (ari3Payload($request)['text'] ?? ''), 'ارتباط سرور ابری و نود'),
            index: 1);

    expect(DB::table('arianet_cloud_node_links')->where('cloud_server_id', ARI3_SERVER)
        ->value('node_uuid'))->toBe(ARI3_NODE_MATCH);
    $bot->hearCallbackQueryData($confirm)->reply()
        ->assertRaw(static fn (NutgramRequest $request): bool => str_contains((string) (ari3Payload($request)['text'] ?? ''), 'قبلاً استفاده شده'),
            index: 1);
    expect(DB::table('arianet_cloud_node_links')->count())->toBe(1);
    ari3OnlyGets();
});

it('shows linked node details, allows confirmable unlink and does not mutate provider data', function (): void {
    $root = ari3Root();
    $state = ari3State();
    ari3Fake($state);
    $action = app(ManageAdminArianetCloudLinks::class);
    $action->apply($root->id, $action->preview($root->id, ARI3_SERVER, ARI3_NODE_DOMAIN));
    $bot = ari3Bot();
    $bot->hearCallbackQueryData('ari2:view:'.ARI3_SERVER.':1')->reply()
        ->assertRaw(static fn (NutgramRequest $request): bool => str_contains(ari3Rich($request), 'Domain node')
            && ari3Callback($request, 'ari3:nodes:'.ARI3_SERVER.':1:1') !== null
            && ari3Callback($request, 'ari3:unlink:'.ARI3_SERVER.':1') !== null,
            index: 1);

    $confirm = null;
    $bot->hearCallbackQueryData('ari3:unlink:'.ARI3_SERVER.':1')->reply()
        ->assertRaw(static function (NutgramRequest $request) use (&$confirm): bool {
            $confirm = ari3Callback($request, 'ari3:apply:');

            return $confirm !== null && str_contains(ari3Rich($request), 'قطع ارتباط');
        }, index: 1);
    $bot->hearCallbackQueryData($confirm)->reply();
    expect(DB::table('arianet_cloud_node_links')->count())->toBe(0);
    ari3OnlyGets();
});

it('invalid, expired and cancelled callbacks fail closed without local writes', function (): void {
    ari3Root();
    $state = ari3State();
    ari3Fake($state);
    $bot = ari3Bot();

    $bot->hearCallbackQueryData('ari3:pick:'.ARI3_SERVER.':1:0:deadbeefdeadbeef')->reply();
    $bot->hearCallbackQueryData('ari3:apply:'.str_repeat('a', 32))->reply();
    expect(DB::table('arianet_cloud_node_links')->count())->toBe(0);

    $chosen = null;
    $bot->hearCallbackQueryData('ari3:nodes:'.ARI3_SERVER.':1:1')->reply()
        ->assertRaw(static function (NutgramRequest $r) use (&$chosen): bool {
            $chosen = ari3Callback($r, 'ari3:pick:'.ARI3_SERVER.':1:0:');

            return $chosen !== null;
        }, index: 1);
    $cancel = null;
    $bot->hearCallbackQueryData($chosen)->reply()
        ->assertRaw(static function (NutgramRequest $r) use (&$cancel): bool {
            $cancel = ari3Callback($r, 'ari3:cancel:');

            return $cancel !== null;
        }, index: 1);
    $token = explode(':', $cancel)[2];
    $bot->hearCallbackQueryData($cancel)->reply();
    $bot->hearCallbackQueryData('ari3:apply:'.$token)->reply();
    expect(DB::table('arianet_cloud_node_links')->count())->toBe(0);
    ari3OnlyGets();
});

it('revocation, forged callbacks and group chats cannot authorize local linking', function (): void {
    $root = ari3Root();
    $state = ari3State();
    ari3Fake($state);
    $bot = ari3Bot();
    $chosen = null;
    $bot->hearCallbackQueryData('ari3:nodes:'.ARI3_SERVER.':1:1')->reply()
        ->assertRaw(static function (NutgramRequest $r) use (&$chosen): bool {
            $chosen = ari3Callback($r, 'ari3:pick:'.ARI3_SERVER.':1:0:');

            return $chosen !== null;
        }, index: 1);

    $confirmed = null;
    $bot->hearCallbackQueryData($chosen)->reply()
        ->assertRaw(static function (NutgramRequest $r) use (&$confirmed): bool {
            $confirmed = ari3Callback($r, 'ari3:apply:');

            return $confirmed !== null;
        }, index: 1);

    $root->syncRoles([]);
    $bot->hearCallbackQueryData($confirmed)->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
    ari3Bot(private: false)->hearCallbackQueryData('ari3:nodes:'.ARI3_SERVER.':1:1')->reply();

    expect(DB::table('arianet_cloud_node_links')->count())->toBe(0);
    ari3OnlyGets();
});

it('warns clearly before domain or mismatched-IP confirmation without auto-linking', function (): void {
    ari3Root();
    $state = ari3State();
    ari3Fake($state);
    $bot = ari3Bot();

    foreach ([
        1 => 'آدرس نود دامنه است',
        2 => 'IP نود با IP سرور متفاوت است',
    ] as $index => $warning) {
        $chosen = null;
        $bot->hearCallbackQueryData('ari3:nodes:'.ARI3_SERVER.':1:1')->reply()
            ->assertRaw(static function (NutgramRequest $r) use (&$chosen, $index): bool {
                $chosen = ari3Callback($r, 'ari3:pick:'.ARI3_SERVER.':1:'.$index.':');

                return $chosen !== null;
            }, index: 1);

        $bot->hearCallbackQueryData($chosen)->reply()
            ->assertRaw(static function (NutgramRequest $r) use ($warning): bool {
                $markup = json_encode(ari3Payload($r)['reply_markup'] ?? [], JSON_THROW_ON_ERROR);

                return str_contains(ari3Rich($r), $warning)
                    && str_contains($markup, '"style":"danger"');
            }, index: 1);
    }

    expect(DB::table('arianet_cloud_node_links')->count())->toBe(0);
    ari3OnlyGets();
});

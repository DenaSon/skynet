<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\InspectAdminArianetIpv4Dependencies;
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

const ARI6_SERVER = 101305418924;
const ARI6_NODE = '550e8400-e29b-41d4-a716-446655440091';
const ARI6_PEER = '550e8400-e29b-41d4-a716-446655440092';
const ARI6_PROFILE = '550e8400-e29b-41d4-a716-446655440093';
const ARI6_INBOUND = '550e8400-e29b-41d4-a716-446655440094';
const ARI6_HOST_DOMAIN = '550e8400-e29b-41d4-a716-446655440095';
const ARI6_HOST_IP = '550e8400-e29b-41d4-a716-446655440096';
const ARI6_ZONE_ID = 'abcdef0123456789abcdef0123456789';

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['991901', '991902']);
    config()->set('services.arianet_cloud.read_token', 'ari6-cloud-token');
    config()->set('services.arianet_cloud.writes_enabled', false);
    config()->set('services.remnawave.url', 'https://rw.ari6.test');
    config()->set('services.remnawave.token', 'ari6-rw-token');
    config()->set('services.remnawave.writes_enabled', false);
    config()->set('services.cloudflare.token', 'ari6-cf-token');
    config()->set('services.cloudflare.allowed_zones', 'coreflare.ir');
    config()->set('services.cloudflare.dns_writes_enabled', false);
    Http::preventStrayRequests();
});

function ari6Root(bool $root = true, int $telegram = 991901): User
{
    $user = User::factory()->create();
    if ($root) {
        $user->syncRoles(['super-admin']);
    }
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegram]);

    return $user;
}

function ari6Link(User $user): void
{
    DB::table('arianet_cloud_node_links')->insert([
        'cloud_server_id' => ARI6_SERVER, 'node_uuid' => ARI6_NODE,
        'created_by' => $user->id, 'updated_by' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function ari6Bot(bool $private = true): Nutgram
{
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make(991901, false, 'Root'));
    $bot->setCommonChat(Chat::make($private ? 991901 : -999006,
        $private ? ChatType::PRIVATE : ChatType::GROUP));

    return $bot;
}

/** @return array<string,mixed> */
function ari6Payload(NutgramRequest $req): array
{
    $payload = FakeNutgram::getActualData($req);
    if (is_string($payload['reply_markup'] ?? null)) {
        $payload['reply_markup'] = json_decode($payload['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $payload;
}

function ari6Rich(NutgramRequest $req): string
{
    $p = ari6Payload($req);
    $data = is_string($p['rich_message'] ?? null)
        ? json_decode($p['rich_message'], true, flags: JSON_THROW_ON_ERROR)
        : ($p['rich_message'] ?? []);

    return ($data['is_rtl'] ?? false) === true ? (string) ($data['html'] ?? '') : '';
}

/** @return array<string,mixed> */
function ari6State(): array
{
    return [
        'cloudIp' => '62.144.51.204',
        'recordIp' => '62.144.51.204',
        'nodeAddress' => 'fr.coreflare.ir',
        'shared' => false,
        'legacy' => false,
        'collision' => false,
        'brokenHosts' => false,
    ];
}

/** @param array<string,mixed> $state */
function ari6Fake(array &$state): void
{
    Http::fake(static function (Request $request) use (&$state) {
        if ($request->method() !== 'GET') {
            return Http::response(['error' => 'write prohibited'], 405);
        }
        $url = $request->url();
        $path = parse_url($url, PHP_URL_PATH);

        if ($url === 'https://api.ariaservice.net/cloud/v1/servers/'.ARI6_SERVER) {
            return Http::response(['data' => [
                'id' => ARI6_SERVER, 'name' => '<b>GermanyOVH</b>', 'status' => 'running',
                'datacenter' => 'ovh', 'ipv4' => $state['cloudIp'],
                'ip_change' => [
                    'allowed' => true, 'pending' => false, 'free_left' => 0,
                    'used' => 1, 'currency' => 'IRR', 'fee' => 2500000,
                ],
            ]]);
        }

        $node = [
            'uuid' => ARI6_NODE, 'name' => '<script>GermanyOVH</script>',
            'address' => $state['nodeAddress'], 'port' => 2222,
            'isConnected' => true, 'isDisabled' => false,
            'configProfile' => [
                'activeConfigProfileUuid' => ARI6_PROFILE,
                'activeInbounds' => [['uuid' => ARI6_INBOUND]],
            ],
        ];
        $peer = [
            'uuid' => ARI6_PEER, 'name' => 'Other',
            'address' => $state['collision'] ? 'fr.coreflare.ir' : 'other.coreflare.ir',
            'port' => 2222, 'isConnected' => true, 'isDisabled' => false,
        ];
        if ($url === 'https://rw.ari6.test/api/nodes/'.ARI6_NODE) {
            return Http::response(['response' => $node]);
        }
        if ($url === 'https://rw.ari6.test/api/nodes') {
            return Http::response(['response' => [$node, $peer]]);
        }

        if ($url === 'https://rw.ari6.test/api/hosts') {
            return $state['brokenHosts'] ? Http::response(['error' => 'secret'], 503)
                : Http::response(['response' => [
                    [
                        'uuid' => ARI6_HOST_DOMAIN, 'remark' => '<b>main host</b>',
                        'address' => 'sub.coreflare.ir', 'port' => 443, 'isDisabled' => false,
                        'nodes' => $state['shared'] ? [ARI6_NODE, ARI6_PEER] : [ARI6_NODE],
                        'inbound' => [
                            'configProfileUuid' => ARI6_PROFILE,
                            'configProfileInboundUuid' => ARI6_INBOUND,
                        ],
                    ],
                    [
                        'uuid' => ARI6_HOST_IP, 'remark' => 'direct host',
                        'address' => '62.144.51.204', 'port' => 8443, 'isDisabled' => false,
                        'nodes' => $state['legacy'] ? [] : [ARI6_NODE],
                        'inbound' => [
                            'configProfileUuid' => ARI6_PROFILE,
                            'configProfileInboundUuid' => ARI6_INBOUND,
                        ],
                    ],
                ]]);
        }

        if ($path === '/client/v4/zones') {
            return Http::response(['success' => true, 'result' => [[
                'id' => ARI6_ZONE_ID, 'name' => 'coreflare.ir',
            ]]]);
        }
        if ($path === '/client/v4/zones/'.ARI6_ZONE_ID.'/dns_records') {
            $name = $request->data()['name'] ?? null;
            $found = in_array($name, ['fr.coreflare.ir', 'sub.coreflare.ir'], true);
            $record = $found ? [[
                'id' => $name === 'fr.coreflare.ir'
                    ? 'ab010101010101010101010101010101'
                    : 'ab020202020202020202020202020202',
                'name' => $name, 'type' => 'A',
                'content' => $state['recordIp'],
                'proxied' => $name === 'sub.coreflare.ir',
                'proxiable' => true,
                'modified_on' => '2026-11-23T15:00:00Z',
            ]] : [];

            return Http::response(['success' => true, 'result' => $record]);
        }

        return Http::response(['error' => 'unknown'], 404);
    });
}

function ari6OnlyReads(): void
{
    Http::assertNotSent(static fn (Request $request): bool => $request->method() !== 'GET');
    foreach (Http::recorded() as [$request]) {
        expect(
            $request->hasHeader('Authorization', 'Bearer ari6-cloud-token')
            || $request->hasHeader('Authorization', 'Bearer ari6-rw-token')
            || $request->hasHeader('Authorization', 'Bearer ari6-cf-token')
        )->toBeTrue();
    }
}

it('audits a linked Node, one direct-IP Host and two unique DNS records without writing', function (): void {
    $user = ari6Root();
    ari6Link($user);
    $state = ari6State();
    ari6Fake($state);

    $result = app(InspectAdminArianetIpv4Dependencies::class)->handle($user->id, ARI6_SERVER);
    expect($result['ready'])->toBeTrue()
        ->and($result['oldIp'])->toBe('62.144.51.204')
        ->and($result['affectedHostCount'])->toBe(2)
        ->and($result['directHostCount'])->toBe(1)
        ->and($result['dns'])->toHaveCount(2)
        ->and($result['issues'])->toBe([]);
    ari6OnlyReads();
});

it('renders private Rich RTL audit with escaped Host labels and safe navigation', function (): void {
    $user = ari6Root();
    ari6Link($user);
    $state = ari6State();
    ari6Fake($state);

    ari6Bot()->hearCallbackQueryData('ari6:impact:'.ARI6_SERVER.':1')->reply()
        ->assertRaw(static function (NutgramRequest $req): bool {
            $rich = ari6Rich($req);
            $markup = json_encode(ari6Payload($req)['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains($rich, 'بررسی دامنه‌ها و اتصال‌ها')
                && str_contains($rich, '&lt;script&gt;GermanyOVH&lt;/script&gt;')
                && str_contains($rich, '&lt;b&gt;main host&lt;/b&gt;')
                && str_contains($rich, 'sub.coreflare.ir')
                && str_contains($rich, 'DNS Only')
                && ! str_contains($rich, '<script>')
                && str_contains($markup, 'ari6:impact:'.ARI6_SERVER.':1')
                && str_contains($markup, 'ari4:preview:'.ARI6_SERVER.':1')
                && ! str_contains($markup, 'ari6:apply:');
        }, index: 1);
    ari6OnlyReads();
});

it('flags shared Host membership and unmatched or unverifiable managed DNS', function (): void {
    $user = ari6Root();
    ari6Link($user);
    $state = ari6State();
    $state['shared'] = true;
    $state['recordIp'] = '62.144.51.205';
    ari6Fake($state);

    $result = app(InspectAdminArianetIpv4Dependencies::class)->handle($user->id, ARI6_SERVER);
    expect($result['ready'])->toBeFalse()
        ->and(implode(' ', $result['issues']))->toContain('مشترک', 'DNS');
    ari6OnlyReads();
});

it('flags ambiguous legacy Host membership and conflicting peer Node endpoints', function (): void {
    $user = ari6Root();
    ari6Link($user);
    $state = ari6State();
    $state['legacy'] = true;
    $state['collision'] = true;
    ari6Fake($state);

    $result = app(InspectAdminArianetIpv4Dependencies::class)->handle($user->id, ARI6_SERVER);
    expect($result['ready'])->toBeFalse()
        ->and(implode(' ', $result['issues']))->toContain('قطعی نیست', 'نود دیگر');
    ari6OnlyReads();
});

it('uses the immutable old IP and verified new IP when Cloud is awaiting coordination', function (): void {
    $user = ari6Root();
    ari6Link($user);
    $state = ari6State();
    $state['cloudIp'] = '62.144.51.206';
    ari6Fake($state);
    DB::table('arianet_cloud_ipv4_changes')->insert([
        'cloud_server_id' => ARI6_SERVER, 'active_server_id' => ARI6_SERVER,
        'actor_user_id' => $user->id, 'token_hash' => hash('sha256', 'fixture'),
        'old_ipv4' => '62.144.51.204', 'new_ipv4' => '62.144.51.206',
        'fee_rials' => 2500000, 'free_left' => 0,
        'snapshot_hash' => hash('sha256', 'snapshot'), 'status' => 'awaiting_coordination',
        'action_id' => 88, 'expires_at' => now()->addMinutes(10),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $result = app(InspectAdminArianetIpv4Dependencies::class)->handle($user->id, ARI6_SERVER);
    expect($result['oldIp'])->toBe('62.144.51.204')
        ->and($result['newIp'])->toBe('62.144.51.206')
        ->and($result['ready'])->toBeTrue();
    ari6OnlyReads();
});

it('keeps missing Node mapping visible as blocked instead of assuming no impact', function (): void {
    $user = ari6Root();
    $state = ari6State();
    ari6Fake($state);

    $report = app(InspectAdminArianetIpv4Dependencies::class)->handle($user->id, ARI6_SERVER);
    expect($report['ready'])->toBeFalse()
        ->and($report['nodeName'])->toBe('ثبت نشده')
        ->and($report['issues'])->not->toBeEmpty();
    ari6OnlyReads();
});

it('denies delegated actors and group callbacks without making provider requests', function (): void {
    $user = ari6Root(false);
    Http::fake();
    expect(fn () => app(InspectAdminArianetIpv4Dependencies::class)->handle($user->id, ARI6_SERVER))
        ->toThrow(AuthorizationException::class);

    ari6Bot()->hearCallbackQueryData('ari6:impact:'.ARI6_SERVER.':1')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
    ari6Bot(private: false)->hearCallbackQueryData('ari6:impact:'.ARI6_SERVER.':1')->reply();

    Http::assertNothingSent();
});

it('fails closed on unavailable Host inventory and returns with navigation', function (): void {
    $user = ari6Root();
    ari6Link($user);
    $state = ari6State();
    $state['brokenHosts'] = true;
    ari6Fake($state);

    ari6Bot()->hearCallbackQueryData('ari6:impact:'.ARI6_SERVER.':1')->reply()
        ->assertRaw(static fn (NutgramRequest $req): bool => str_contains(
            (string) (ari6Payload($req)['text'] ?? ''), 'دریافت وابستگی‌های اتصال ممکن نیست',
        ), index: 1);
    ari6OnlyReads();
});

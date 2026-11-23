<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ChangeAdminArianetIpv4;
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

const ARI5_SERVER = 101305418924;
const ARI5_NODE = '550e8400-e29b-41d4-a716-446655440087';
const ARI5_ZONE = 'abcdef0123456789abcdef0123456789';
const ARI5_RECORD = 'abcdefab010101010101010101010101';

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['991901', '991902']);
    config()->set('services.arianet_cloud.read_token', 'ari5-read-token');
    config()->set('services.arianet_cloud.write_token', 'ari5-write-token');
    config()->set('services.arianet_cloud.writes_enabled', true);
    config()->set('services.remnawave.url', 'https://rw.ari5.test');
    config()->set('services.remnawave.token', 'ari5-rw-token');
    config()->set('services.remnawave.writes_enabled', false);
    config()->set('services.cloudflare.token', 'ari5-dns-token');
    config()->set('services.cloudflare.allowed_zones', 'coreflare.ir');
    config()->set('services.cloudflare.dns_writes_enabled', false);
    Http::preventStrayRequests();
});

function ari5Root(bool $root = true, int $telegramId = 991901): User
{
    $user = User::factory()->create();
    if ($root) {
        $user->syncRoles(['super-admin']);
    }
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);

    return $user;
}

function ari5Link(User $user): void
{
    DB::table('arianet_cloud_node_links')->insert([
        'cloud_server_id' => ARI5_SERVER, 'node_uuid' => ARI5_NODE,
        'created_by' => $user->id, 'updated_by' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function ari5Bot(int $telegramId = 991901, bool $private = true): Nutgram
{
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Root'));
    $bot->setCommonChat(Chat::make($private ? $telegramId : -998877,
        $private ? ChatType::PRIVATE : ChatType::GROUP));

    return $bot;
}

/** @return array<string,mixed> */
function ari5Payload(NutgramRequest $request): array
{
    $payload = FakeNutgram::getActualData($request);
    if (is_string($payload['reply_markup'] ?? null)) {
        $payload['reply_markup'] = json_decode($payload['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $payload;
}

function ari5Rich(NutgramRequest $request): string
{
    $payload = ari5Payload($request);
    $rich = is_string($payload['rich_message'] ?? null)
        ? json_decode($payload['rich_message'], true, flags: JSON_THROW_ON_ERROR)
        : ($payload['rich_message'] ?? []);

    return ($rich['is_rtl'] ?? false) === true ? (string) ($rich['html'] ?? '') : '';
}

/** @return array<string,mixed> */
function ari5State(): array
{
    return [
        'ipv4' => '62.144.51.204',
        'fee' => 2500000,
        'freeLeft' => 0,
        'used' => 1,
        'allowed' => true,
        'pending' => false,
        'nodeAddress' => 'fr.coreflare.ir',
        'dnsIp' => '62.144.51.204',
        'dnsProxied' => false,
        'post' => 'accepted',
        'actionStatus' => 'running',
        'posts' => 0,
    ];
}

/** @param array<string,mixed> $state */
function ari5Fake(array &$state): void
{
    Http::fake(static function (Request $request) use (&$state) {
        $url = $request->url();
        $path = parse_url($url, PHP_URL_PATH);
        $cloud = 'https://api.ariaservice.net/cloud/v1';
        if ($request->method() === 'POST') {
            $state['posts']++;
            if ($url !== $cloud.'/servers/'.ARI5_SERVER.'/actions/change_ipv4') {
                return Http::response(['error' => 'unexpected write'], 400);
            }

            return $state['post'] === 'uncertain'
                ? Http::response(['error' => 'temporary'], 503)
                : Http::response(['action_id' => 87], 202);
        }

        if ($request->method() !== 'GET') {
            return Http::response(['error' => 'write forbidden'], 405);
        }
        if ($url === $cloud.'/servers/'.ARI5_SERVER) {
            return Http::response(['data' => [
                'id' => ARI5_SERVER, 'name' => 'test-cloud', 'status' => 'running',
                'datacenter' => 'ovh',
                'ipv4' => $state['ipv4'],
                'ip_change' => [
                    'allowed' => $state['allowed'], 'pending' => $state['pending'],
                    'free_left' => $state['freeLeft'], 'used' => $state['used'],
                    'fee' => $state['fee'], 'currency' => 'IRR',
                ],
            ]]);
        }
        if ($url === $cloud.'/actions/87') {
            return Http::response(['data' => [
                'id' => 87, 'command' => 'change_ipv4', 'status' => $state['actionStatus'],
                'resource' => ['type' => 'server', 'id' => ARI5_SERVER],
            ]]);
        }
        if ($url === 'https://rw.ari5.test/api/nodes/'.ARI5_NODE) {
            return Http::response(['response' => [
                'uuid' => ARI5_NODE, 'name' => '<b>linked-node</b>',
                'address' => $state['nodeAddress'], 'port' => 2222,
                'isConnected' => true, 'isDisabled' => false,
            ]]);
        }
        if ($path === '/client/v4/zones') {
            return Http::response(['success' => true, 'result' => [
                ['id' => ARI5_ZONE, 'name' => 'coreflare.ir'],
            ]]);
        }
        if ($path === '/client/v4/zones/'.ARI5_ZONE.'/dns_records') {
            return Http::response(['success' => true, 'result' => [[
                'id' => ARI5_RECORD, 'name' => 'fr.coreflare.ir',
                'type' => 'A', 'content' => $state['dnsIp'],
                'proxied' => $state['dnsProxied'], 'proxiable' => true,
            ]]]);
        }

        return Http::response(['error' => 'unmatched'], 404);
    });
}

function ari5OnlyCloudPost(int $expected): void
{
    $posts = collect(Http::recorded())->filter(static fn (array $entry): bool => $entry[0]->method() !== 'GET');
    expect($posts)->toHaveCount($expected);
    foreach ($posts as [$request]) {
        expect($request->method())->toBe('POST')
            ->and($request->url())->toBe('https://api.ariaservice.net/cloud/v1/servers/'.ARI5_SERVER.'/actions/change_ipv4')
            ->and($request->hasHeader('Authorization', 'Bearer ari5-write-token'))->toBeTrue();
    }
}

it('cannot execute on production even if all write credentials and transport flags are enabled', function (): void {
    $user = ari5Root();
    ari5Link($user);
    $state = ari5State();
    ari5Fake($state);

    app()->detectEnvironment(static fn (): string => 'production');
    try {
        $changes = app(ChangeAdminArianetIpv4::class);
        expect($changes->enabled())->toBeFalse();
        expect(fn () => $changes->prepare($user->id, ARI5_SERVER))->toThrow(RuntimeException::class, 'disabled');
    } finally {
        app()->detectEnvironment(static fn (): string => 'testing');
    }

    Http::assertNothingSent();
    expect(DB::table('arianet_cloud_ipv4_changes')->count())->toBe(0);
});

it('reserves exactly one actor-bound intent and can cancel only before dispatch', function (): void {
    $user = ari5Root();
    ari5Link($user);
    $state = ari5State();
    ari5Fake($state);
    $changes = app(ChangeAdminArianetIpv4::class);

    $prepared = $changes->prepare($user->id, ARI5_SERVER);
    expect($prepared['token'])->toMatch('/\A[a-f0-9]{24}\z/')
        ->and($prepared['preview']['ipv4'])->toBe($state['ipv4']);
    expect(DB::table('arianet_cloud_ipv4_changes')->value('status'))->toBe('prepared');

    expect(fn () => $changes->prepare($user->id, ARI5_SERVER))
        ->toThrow(RuntimeException::class, 'already exists');

    $changes->cancel($user->id, ARI5_SERVER, $prepared['token']);
    expect($changes->status($user->id, ARI5_SERVER)['status'])->toBe('cancelled');

    expect(fn () => $changes->apply($user->id, ARI5_SERVER, $prepared['token']))->toThrow(RuntimeException::class);
    $second = $changes->prepare($user->id, ARI5_SERVER);
    expect($second['token'])->not->toBe($prepared['token']);

    ari5OnlyCloudPost(0);
});

it('posts exactly once with explicit paid confirmation and never replays a consumed token', function (): void {
    $user = ari5Root();
    ari5Link($user);
    $state = ari5State();
    ari5Fake($state);
    $changes = app(ChangeAdminArianetIpv4::class);

    $prepared = $changes->prepare($user->id, ARI5_SERVER);
    $result = $changes->apply($user->id, ARI5_SERVER, $prepared['token']);

    expect($result)->toBe(['status' => 'accepted', 'actionId' => 87]);
    expect($changes->status($user->id, ARI5_SERVER))->toMatchArray([
        'status' => 'accepted', 'actionId' => 87,
        'oldIpv4' => '62.144.51.204', 'feeRials' => 2500000,
    ]);
    expect($state['posts'])->toBe(1);
    expect(fn () => $changes->apply($user->id, ARI5_SERVER, $prepared['token']))->toThrow(RuntimeException::class);
    expect(fn () => $changes->prepare($user->id, ARI5_SERVER))->toThrow(RuntimeException::class);
    expect(fn () => $changes->cancel($user->id, ARI5_SERVER, $prepared['token']))->toThrow(RuntimeException::class);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
        && $request->data()['confirm_paid'] === true);
    ari5OnlyCloudPost(1);
});

it('sends no paid confirmation for a fresh free entitlement', function (): void {
    $user = ari5Root();
    ari5Link($user);
    $state = ari5State();
    $state['freeLeft'] = 1;
    ari5Fake($state);
    $changes = app(ChangeAdminArianetIpv4::class);

    $prepared = $changes->prepare($user->id, ARI5_SERVER);
    $result = $changes->apply($user->id, ARI5_SERVER, $prepared['token']);
    expect($result['status'])->toBe('accepted');
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
        && ! array_key_exists('confirm_paid', $request->data()));
    ari5OnlyCloudPost(1);
});

it('rejects changed IP, fee, Node and DNS before any external write', function (): void {
    foreach (['ipv4', 'fee', 'nodeAddress', 'dnsIp'] as $changed) {
        $user = ari5Root(telegramId: 991901);
        ari5Link($user);
        $state = ari5State();
        ari5Fake($state);
        $changes = app(ChangeAdminArianetIpv4::class);

        $prepared = $changes->prepare($user->id, ARI5_SERVER);
        $state[$changed] = match ($changed) {
            'ipv4' => '62.144.51.205',
            'fee' => 2700000,
            'nodeAddress' => 'other.coreflare.ir',
            default => '62.144.51.205',
        };
        expect(fn () => $changes->apply($user->id, ARI5_SERVER, $prepared['token']))->toThrow(RuntimeException::class);
        expect($state['posts'])->toBe(0);

        DB::table('arianet_cloud_ipv4_changes')->delete();
        DB::table('arianet_cloud_node_links')->delete();
        TelegramAccount::query()->where('user_id', $user->id)->delete();
    }
});

it('preserves an ambiguous 503 outcome as permanently non-retryable', function (): void {
    $user = ari5Root();
    ari5Link($user);
    $state = ari5State();
    $state['post'] = 'uncertain';
    ari5Fake($state);
    $changes = app(ChangeAdminArianetIpv4::class);

    $prepared = $changes->prepare($user->id, ARI5_SERVER);
    expect($changes->apply($user->id, ARI5_SERVER, $prepared['token']))
        ->toBe(['status' => 'uncertain', 'actionId' => null]);
    expect(fn () => $changes->apply($user->id, ARI5_SERVER, $prepared['token']))->toThrow(RuntimeException::class);
    expect(fn () => $changes->reconcile($user->id, ARI5_SERVER))->toThrow(RuntimeException::class);
    expect($changes->status($user->id, ARI5_SERVER)['status'])->toBe('uncertain');
    expect($state['posts'])->toBe(1);
    ari5OnlyCloudPost(1);
});

it('requires exact action identity and Cloud GET before acknowledging new IP and retains coordination lock', function (): void {
    $user = ari5Root();
    ari5Link($user);
    $state = ari5State();
    ari5Fake($state);
    $changes = app(ChangeAdminArianetIpv4::class);

    $prepared = $changes->prepare($user->id, ARI5_SERVER);
    $changes->apply($user->id, ARI5_SERVER, $prepared['token']);
    expect($changes->reconcile($user->id, ARI5_SERVER)['status'])->toBe('accepted');

    $state['actionStatus'] = 'success';
    $state['ipv4'] = '62.144.51.206';
    $result = $changes->reconcile($user->id, ARI5_SERVER);
    expect($result)->toMatchArray([
        'status' => 'awaiting_coordination',
        'oldIpv4' => '62.144.51.204',
        'newIpv4' => '62.144.51.206',
    ]);
    expect(fn () => $changes->prepare($user->id, ARI5_SERVER))->toThrow(RuntimeException::class);
    expect($state['posts'])->toBe(1);
    ari5OnlyCloudPost(1);
});

it('rejects invalid readiness and never allows a paid change without a verified IRR fee', function (): void {
    $user = ari5Root();
    ari5Link($user);
    $state = ari5State();
    ari5Fake($state);
    $changes = app(ChangeAdminArianetIpv4::class);

    $state['fee'] = 0;
    expect(fn () => $changes->prepare($user->id, ARI5_SERVER))->toThrow(RuntimeException::class);
    $state['fee'] = 2500000;
    $state['pending'] = true;
    expect(fn () => $changes->prepare($user->id, ARI5_SERVER))->toThrow(RuntimeException::class);
    $state['pending'] = false;
    $state['dnsIp'] = '62.144.51.205';
    expect(fn () => $changes->prepare($user->id, ARI5_SERVER))->toThrow(RuntimeException::class);
    expect(DB::table('arianet_cloud_ipv4_changes')->count())->toBe(0);
    ari5OnlyCloudPost(0);
});

it('enforces independent trusted root access at UI and Application boundaries', function (): void {
    $user = ari5Root(false);
    $state = ari5State();
    ari5Fake($state);
    $changes = app(ChangeAdminArianetIpv4::class);

    expect(fn () => $changes->prepare($user->id, ARI5_SERVER))->toThrow(AuthorizationException::class);
    ari5Bot()->hearCallbackQueryData('ari5:begin:'.ARI5_SERVER.':1')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
    ari5Bot(private: false)->hearCallbackQueryData('ari5:begin:'.ARI5_SERVER.':1')->reply();

    Http::assertNothingSent();
    expect(DB::table('arianet_cloud_ipv4_changes')->count())->toBe(0);
});

it('shows a one-shot Rich confirmation and maintains preview/return routes', function (): void {
    $user = ari5Root();
    ari5Link($user);
    $state = ari5State();
    ari5Fake($state);

    ari5Bot()->hearCallbackQueryData('ari5:begin:'.ARI5_SERVER.':1')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $rich = ari5Rich($request);
            $buttons = json_encode(ari5Payload($request)['reply_markup'] ?? [], JSON_THROW_ON_ERROR);

            return str_contains($rich, 'تأیید تعویض IPv4')
                && str_contains($rich, '۲۵۰٬۰۰۰ تومان')
                && str_contains($rich, 'linked-node')
                && str_contains($buttons, 'ari5:apply:'.ARI5_SERVER.':1:')
                && str_contains($buttons, 'ari5:cancel:'.ARI5_SERVER.':1:')
                && str_contains($buttons, 'ari4:preview:'.ARI5_SERVER.':1');
        }, index: 1);

    expect(DB::table('arianet_cloud_ipv4_changes')->count())->toBe(1);
    ari5OnlyCloudPost(0);
});

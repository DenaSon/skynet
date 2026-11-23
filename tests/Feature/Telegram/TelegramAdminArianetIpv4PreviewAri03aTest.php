<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\PreviewAdminArianetIpv4Change;
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

const ARI4_SERVER_ID = 101305418924;
const ARI4_NODE_UUID = '550e8400-e29b-41d4-a716-446655440084';
const ARI4_ZONE_ID = 'abcdef0123456789abcdef0123456789';
const ARI4_RECORD_ID = 'cd010101010101010101010101010101';

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['991901', '991902']);
    config()->set('services.arianet_cloud.read_token', 'ari4-cloud-read-secret');
    config()->set('services.arianet_cloud.writes_enabled', false);
    config()->set('services.arianet_cloud.write_token', null);
    config()->set('services.remnawave.url', 'https://rw.ari4.test');
    config()->set('services.remnawave.token', 'ari4-rw-read-secret');
    config()->set('services.remnawave.writes_enabled', false);
    config()->set('services.cloudflare.token', 'ari4-dns-read-secret');
    config()->set('services.cloudflare.allowed_zones', 'coreflare.ir');
    config()->set('services.cloudflare.dns_writes_enabled', false);
    Http::preventStrayRequests();
});

function ari4Root(bool $super = true, int $telegramId = 991901): User
{
    $user = User::factory()->create();
    if ($super) {
        $user->syncRoles(['super-admin']);
    }
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);

    return $user;
}

function ari4Link(User $user): void
{
    DB::table('arianet_cloud_node_links')->insert([
        'cloud_server_id' => ARI4_SERVER_ID,
        'node_uuid' => ARI4_NODE_UUID,
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function ari4Bot(int $telegramId = 991901, bool $private = true): Nutgram
{
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Root'));
    $bot->setCommonChat(Chat::make($private ? $telegramId : -998899,
        $private ? ChatType::PRIVATE : ChatType::GROUP));

    return $bot;
}

/** @return array<string,mixed> */
function ari4Payload(NutgramRequest $request): array
{
    $payload = FakeNutgram::getActualData($request);
    if (is_string($payload['reply_markup'] ?? null)) {
        $payload['reply_markup'] = json_decode($payload['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $payload;
}

function ari4Rich(NutgramRequest $request): string
{
    $payload = ari4Payload($request);
    $rich = is_string($payload['rich_message'] ?? null)
        ? json_decode($payload['rich_message'], true, flags: JSON_THROW_ON_ERROR)
        : ($payload['rich_message'] ?? []);

    return ($rich['is_rtl'] ?? false) === true ? (string) ($rich['html'] ?? '') : '';
}

function ari4Has(NutgramRequest $request, string $callback): bool
{
    return str_contains(json_encode(ari4Payload($request)['reply_markup'] ?? [], JSON_THROW_ON_ERROR),
        '"callback_data":"'.$callback.'"');
}

/** @return array<string,mixed> */
function ari4State(): array
{
    return [
        'cloudIp' => '62.144.51.204',
        'nodeAddress' => 'fr.coreflare.ir',
        'recordIp' => '62.144.51.204',
        'recordProxied' => false,
        'allowed' => true,
        'freeLeft' => 0,
        'used' => 1,
        'pending' => false,
        'fee' => 2500000,
        'currency' => 'IRR',
        'cloudStatus' => 'running',
        'nodeFail' => false,
        'dnsFail' => false,
    ];
}

/** @param array<string,mixed> $state */
function ari4Fake(array &$state): void
{
    Http::fake(static function (Request $request) use (&$state) {
        if ($request->method() !== 'GET') {
            return Http::response(['error' => 'writes are forbidden'], 405);
        }
        $url = $request->url();
        $path = parse_url($url, PHP_URL_PATH);

        if ($url === 'https://api.ariaservice.net/cloud/v1/servers/'.ARI4_SERVER_ID) {
            return Http::response(['data' => [
                'id' => ARI4_SERVER_ID,
                'name' => '<script>OVH-Node</script>',
                'status' => $state['cloudStatus'],
                'datacenter' => 'ovh',
                'ipv4' => $state['cloudIp'],
                'ip_change' => [
                    'allowed' => $state['allowed'],
                    'free_left' => $state['freeLeft'],
                    'used' => $state['used'],
                    'pending' => $state['pending'],
                    'fee' => $state['fee'],
                    'currency' => $state['currency'],
                ],
            ]]);
        }
        if ($url === 'https://rw.ari4.test/api/nodes/'.ARI4_NODE_UUID) {
            return $state['nodeFail'] ? Http::response(['message' => 'not found'], 503)
                : Http::response(['response' => [
                    'uuid' => ARI4_NODE_UUID,
                    'name' => '<b>RW linked node</b>',
                    'address' => $state['nodeAddress'],
                    'port' => 2222,
                    'isConnected' => true,
                    'isDisabled' => false,
                ]]);
        }
        if ($path === '/client/v4/zones') {
            return $state['dnsFail'] ? Http::response(['success' => false], 503)
                : Http::response(['success' => true, 'result' => [
                    ['id' => ARI4_ZONE_ID, 'name' => 'coreflare.ir'],
                ]]);
        }
        if ($path === '/client/v4/zones/'.ARI4_ZONE_ID.'/dns_records') {
            return $state['dnsFail'] ? Http::response(['success' => false], 503)
                : Http::response(['success' => true, 'result' => [[
                    'id' => ARI4_RECORD_ID,
                    'name' => $state['nodeAddress'],
                    'type' => 'A',
                    'content' => $state['recordIp'],
                    'proxied' => $state['recordProxied'],
                    'proxiable' => true,
                    'modified_on' => '2026-11-23T10:30:00Z',
                ]]]);
        }

        return Http::response(['error' => 'unexpected request'], 404);
    });
}

function ari4OnlyGets(): void
{
    Http::assertNotSent(static fn (Request $request): bool => $request->method() !== 'GET');
    foreach (Http::recorded() as [$request]) {
        expect(
            $request->hasHeader('Authorization', 'Bearer ari4-cloud-read-secret')
            || $request->hasHeader('Authorization', 'Bearer ari4-rw-read-secret')
            || $request->hasHeader('Authorization', 'Bearer ari4-dns-read-secret')
        )->toBeTrue();
    }
}

it('shows safe Rich preview with live paid fee, linked Node, matched managed DNS and no write button', function (): void {
    $user = ari4Root();
    ari4Link($user);
    $state = ari4State();
    ari4Fake($state);

    ari4Bot()->hearCallbackQueryData('ari4:preview:'.ARI4_SERVER_ID.':1')->reply()
        ->assertRaw(static function (NutgramRequest $r): bool {
            $rich = ari4Rich($r);
            $payload = ari4Payload($r);
            $buttons = json_encode($payload['reply_markup'] ?? [], JSON_THROW_ON_ERROR);

            return str_contains($rich, 'پیش‌نمایش تعویض IPv4')
                && str_contains($rich, '&lt;script&gt;OVH-Node&lt;/script&gt;')
                && str_contains($rich, '&lt;b&gt;RW linked node&lt;/b&gt;')
                && ! str_contains($rich, '<script>')
                && str_contains($rich, '۲۵۰٬۰۰۰ تومان')
                && str_contains($rich, 'fr.coreflare.ir')
                && str_contains($rich, '✅ رکورد A مطابق IP فعلی')
                && str_contains($rich, 'DNS Only')
                && str_contains($buttons, '"copy_text":{"text":"62.144.51.204"}')
                && ari4Has($r, 'ari4:preview:'.ARI4_SERVER_ID.':1')
                && ari4Has($r, 'ari2:view:'.ARI4_SERVER_ID.':1')
                && ! str_contains($buttons, 'ari4:apply:')
                && ! str_contains($buttons, 'confirm_paid')
                && ! str_contains($buttons, 'change_ipv4');
        }, index: 1);

    ari4OnlyGets();
    Http::assertSentCount(4);
});

it('does not infer matching DNS when the record points elsewhere', function (): void {
    $user = ari4Root();
    ari4Link($user);
    $state = ari4State();
    $state['recordIp'] = '62.144.51.205';
    $state['recordProxied'] = true;
    ari4Fake($state);

    $result = app(PreviewAdminArianetIpv4Change::class)->handle($user->id, ARI4_SERVER_ID);

    expect($result['dns'])->toMatchArray([
        'state' => 'mismatch',
        'name' => 'fr.coreflare.ir',
        'ipv4' => '62.144.51.205',
        'proxied' => true,
    ]);
    ari4OnlyGets();
});

it('identifies direct-IP linked Nodes and never attempts DNS lookup for an IP address', function (): void {
    $user = ari4Root();
    ari4Link($user);
    $state = ari4State();
    $state['nodeAddress'] = '62.144.51.204';
    ari4Fake($state);

    $preview = app(PreviewAdminArianetIpv4Change::class)->handle($user->id, ARI4_SERVER_ID);
    expect($preview['nodeState'])->toBe('ip_match')
        ->and($preview['dns']['state'])->toBe('not_applicable');

    $state['nodeAddress'] = '62.144.51.205';
    $other = app(PreviewAdminArianetIpv4Change::class)->handle($user->id, ARI4_SERVER_ID);
    expect($other['nodeState'])->toBe('ip_mismatch');

    Http::assertNotSent(static fn (Request $req): bool => str_starts_with($req->url(), 'https://api.cloudflare.com'));
    ari4OnlyGets();
});

it('shows an unlinked state without querying Remnawave or Cloudflare', function (): void {
    $user = ari4Root();
    $state = ari4State();
    ari4Fake($state);
    $result = app(PreviewAdminArianetIpv4Change::class)->handle($user->id, ARI4_SERVER_ID);

    expect($result['nodeState'])->toBe('unlinked')
        ->and($result['nodeUuid'])->toBeNull()
        ->and($result['dns']['state'])->toBe('not_applicable');
    Http::assertSentCount(1);
    ari4OnlyGets();
});

it('fails safely when the linked Node or managed DNS is unavailable', function (): void {
    $user = ari4Root();
    ari4Link($user);
    $state = ari4State();
    ari4Fake($state);

    $state['dnsFail'] = true;
    $unverified = app(PreviewAdminArianetIpv4Change::class)->handle($user->id, ARI4_SERVER_ID);
    expect($unverified['nodeState'])->toBe('hostname')
        ->and($unverified['dns']['state'])->toBe('unverified')
        ->and($unverified['dns']['name'])->toBeNull();

    $state['nodeFail'] = true;
    $unavailable = app(PreviewAdminArianetIpv4Change::class)->handle($user->id, ARI4_SERVER_ID);
    expect($unavailable['nodeState'])->toBe('unavailable')
        ->and($unavailable['nodeUuid'])->toBe(ARI4_NODE_UUID)
        ->and($unavailable['node'])->toBeNull();
    ari4OnlyGets();
});

it('does not query arbitrary DNS zones outside the Cloudflare allowlist', function (): void {
    $user = ari4Root();
    ari4Link($user);
    $state = ari4State();
    $state['nodeAddress'] = 'unmanaged.example.net';
    ari4Fake($state);

    $result = app(PreviewAdminArianetIpv4Change::class)->handle($user->id, ARI4_SERVER_ID);
    expect($result['nodeState'])->toBe('hostname')
        ->and($result['dns']['state'])->toBe('unverified');
    Http::assertNotSent(static fn (Request $req): bool => str_starts_with($req->url(), 'https://api.cloudflare.com'));
    ari4OnlyGets();
});

it('never interprets forbidden, pending or non-IRR Cloud changes as approved paid operations', function (): void {
    $user = ari4Root();
    $state = ari4State();
    $state['allowed'] = false;
    $state['pending'] = true;
    $state['currency'] = 'USD';
    ari4Fake($state);

    ari4Bot()->hearCallbackQueryData('ari4:preview:'.ARI4_SERVER_ID.':1')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => str_contains(ari4Rich($r), 'امکان اجرای تعویض IP در این لحظه تأیید نشده است.')
            && str_contains(ari4Rich($r), 'سهمیه یا هزینه نیازمند بررسی مجدد است.')
            && str_contains(ari4Rich($r), 'هزینه تعویض بعدی')
            && str_contains(ari4Rich($r), 'نامشخص'), index: 1);
    ari4OnlyGets();
});

it('treats free change entitlement as informational without ever creating a confirmation action', function (): void {
    ari4Root();
    $state = ari4State();
    $state['freeLeft'] = 1;
    ari4Fake($state);

    ari4Bot()->hearCallbackQueryData('ari4:preview:'.ARI4_SERVER_ID.':1')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => str_contains(ari4Rich($r), 'هزینه تعویض بعدی')
            && str_contains(ari4Rich($r), 'رایگان')
            && ! str_contains(json_encode(ari4Payload($r)['reply_markup'], JSON_THROW_ON_ERROR),
                'ari4:apply:'), index: 1);
    ari4OnlyGets();
});

it('restricts IPv4 previews to private trusted Super Admin and checks the Application boundary', function (): void {
    $nonroot = ari4Root(false);
    $state = ari4State();
    ari4Fake($state);

    expect(fn () => app(PreviewAdminArianetIpv4Change::class)->handle($nonroot->id, ARI4_SERVER_ID))
        ->toThrow(AuthorizationException::class);
    ari4Bot()->hearCallbackQueryData('ari4:preview:'.ARI4_SERVER_ID.':1')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
    ari4Bot(private: false)->hearCallbackQueryData('ari4:preview:'.ARI4_SERVER_ID.':1')->reply();
    Http::assertNothingSent();
});

it('rejects forged invalid preview callback IDs and keeps a way back', function (): void {
    ari4Root();
    Http::fake();

    ari4Bot()->hearCallbackQueryData('ari4:preview:0:1')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => str_contains((string) (ari4Payload($r)['text'] ?? ''), 'انتخاب سرور معتبر نیست.')
            && ari4Has($r, 'ari2:list:1'), index: 1);
    Http::assertNothingSent();
});

it('adds a preview entry to Cloud detail without changing existing cost, history or Node controls', function (): void {
    $user = ari4Root();
    ari4Link($user);
    $state = ari4State();
    ari4Fake($state);

    ari4Bot()->hearCallbackQueryData('ari2:view:'.ARI4_SERVER_ID.':1')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => ari4Has($r, 'ari4:preview:'.ARI4_SERVER_ID.':1')
            && ari4Has($r, 'ari2:cost:'.ARI4_SERVER_ID.':1')
            && ari4Has($r, 'ari2:history:'.ARI4_SERVER_ID.':1:1')
            && ari4Has($r, 'ari3:unlink:'.ARI4_SERVER_ID.':1')
            && ari4Has($r, 'ari3:nodes:'.ARI4_SERVER_ID.':1:1'), index: 1);
    ari4OnlyGets();
});

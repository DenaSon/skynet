<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Interfaces\Telegram\Support\AdminNodesUi;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ManageAdminNodes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;

uses(RefreshDatabase::class);

const NODE01_UUID = '550e8400-e29b-41d4-a716-446655440000';
const NODE01_PROFILE = '550e8400-e29b-41d4-a716-446655440001';
const NODE01_INBOUND = '550e8400-e29b-41d4-a716-446655440002';

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['951111', '951113']);
    config()->set('services.remnawave.url', 'https://remna.node01.test');
    config()->set('services.remnawave.token', 'fake-token');
    config()->set('services.remnawave.writes_enabled', false);
    Http::preventStrayRequests();
});

/** @return array{root:User,buyer:User} */
function node01Actors(): array
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 951111]);

    $buyer = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $buyer->id, 'telegram_user_id' => 951112]);

    return compact('root', 'buyer');
}

function node01Bot(int $telegramId = 951111, bool $private = true): Nutgram
{
    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'User'));
    $bot->setCommonChat(Chat::make($private ? $telegramId : -1001234, $private ? ChatType::PRIVATE : ChatType::SUPERGROUP));

    return $bot;
}

function node01FakeProvider(bool $postFail = false): void
{
    Http::fake(static function (Request $r) use ($postFail) {
        if ($r->url() === 'https://remna.node01.test/api/nodes' && $r->method() === 'GET') {
            return Http::response(['response' => [[
                'uuid' => NODE01_UUID, 'name' => 'Germany-01',
                'isConnected' => true, 'isDisabled' => false,
            ]]], 200);
        }
        if ($r->url() === 'https://remna.node01.test/api/nodes/'.NODE01_UUID && $r->method() === 'GET') {
            return Http::response(['response' => [
                'uuid' => NODE01_UUID, 'name' => 'Germany-01',
                'isConnected' => true, 'isDisabled' => false,
            ]], 200);
        }
        if ($r->url() === 'https://remna.node01.test/api/nodes/tags') {
            return Http::response(['response' => ['tags' => ['PROD']]], 200);
        }
        if ($r->url() === 'https://remna.node01.test/api/config-profiles') {
            return Http::response(['response' => [
                'total' => 1,
                'configProfiles' => [['uuid' => NODE01_PROFILE, 'name' => 'Main']],
            ]], 200);
        }
        if ($r->url() === 'https://remna.node01.test/api/config-profiles/'.NODE01_PROFILE.'/inbounds') {
            return Http::response(['response' => [
                'inbounds' => [['uuid' => NODE01_INBOUND, 'tag' => 'VLESS']],
            ]], 200);
        }
        if ($r->url() === 'https://remna.node01.test/api/nodes' && $r->method() === 'POST') {
            return $postFail
                ? Http::response('', 504)
                : Http::response(['response' => ['uuid' => NODE01_UUID]], 201);
        }

        return Http::response(['message' => 'not found'], 404);
    });
}

it('shows a bounded, sanitized Node list/detail/tags with GET only for trusted root', function (): void {
    $actors = node01Actors();
    node01FakeProvider();
    $nodes = app(ManageAdminNodes::class);

    expect($nodes->all($actors['root']->id))->toHaveCount(1)
        ->and($nodes->one($actors['root']->id, NODE01_UUID)['connected'])->toBeTrue()
        ->and($nodes->tags($actors['root']->id))->toBe(['PROD']);
    Http::assertSentCount(3);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'GET'
        && $r->hasHeader('Authorization', 'Bearer fake-token'));

    $bot = node01Bot();
    $bot->hearCallbackQueryData('node1:list:1')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('node1:view:'.NODE01_UUID)->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('node1:tags')->reply()->assertCalled('editMessageText');
    Http::assertSentCount(6);
});

it('denies non-root and nonprivate Node actions without provider requests', function (): void {
    $actors = node01Actors();
    node01FakeProvider();
    $nodes = app(ManageAdminNodes::class);

    expect(fn () => $nodes->all($actors['buyer']->id))->toThrow(AuthorizationException::class)
        ->and(fn () => $nodes->create($actors['buyer']->id, 'X', 'host.example.com', NODE01_PROFILE, [NODE01_INBOUND]))
        ->toThrow(AuthorizationException::class);
    Http::assertNothingSent();

    app(AdminNodesUi::class)->begin(node01Bot(951111, false));
    app(AdminNodesUi::class)->browse(node01Bot(951112));
    Http::assertNothingSent();
});

it('keeps Node creation disabled by default and rejects invalid input before POST', function (): void {
    $actors = node01Actors();
    node01FakeProvider();
    $ui = app(AdminNodesUi::class);
    $ui->begin(node01Bot());

    expect(Cache::get(TelegramBotContext::current()->cacheKey('node01:draft:'.$actors['root']->id)))->toBeNull();
    $nodes = app(ManageAdminNodes::class);
    expect(fn () => $nodes->create($actors['root']->id, 'Germany-01', 'node.example.com', NODE01_PROFILE, [NODE01_INBOUND]))
        ->toThrow(ValidationException::class);
    expect(fn () => $nodes->validateIdentity($actors['root']->id, 'Unsafe', 'https://node.example.com'))
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('guides Admin name/address, profile, inbound, preview and a one-use provider POST', function (): void {
    $actors = node01Actors();
    config()->set('services.remnawave.writes_enabled', true);
    node01FakeProvider();
    $bot = node01Bot();
    $ui = app(AdminNodesUi::class);

    $bot->hearCallbackQueryData('node1:begin')->reply()->assertCalled('editMessageText');
    $bot->hearText('Germany-01 | node.example.com')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('node1:profile:0')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('node1:inbound:0')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('node1:preview')->reply()->assertCalled('editMessageText');

    $key = TelegramBotContext::current()->cacheKey('node01:draft:'.$actors['root']->id);
    $draft = Cache::get($key);
    expect($draft['stage'])->toBe('preview');
    $bot->hearCallbackQueryData('node1:apply:'.$draft['token'])->reply()->assertCalled('editMessageText');
    expect(Cache::get($key))->toBeNull();
    $bot->hearCallbackQueryData('node1:apply:'.$draft['token'])->reply()->assertCalled('editMessageText');

    Http::assertSentCount(5);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
        && $r->url() === 'https://remna.node01.test/api/nodes'
        && $r['name'] === 'Germany-01'
        && $r['address'] === 'node.example.com'
        && $r['configProfile'] === [
            'activeConfigProfileUuid' => NODE01_PROFILE,
            'activeInbounds' => [NODE01_INBOUND],
        ]);
    expect(collect(Http::recorded())->filter(static fn ($pair): bool => $pair[0]->method() === 'POST'))->toHaveCount(1);
});

it('consumes confirmation even if provider response is uncertain and never retries POST', function (): void {
    $actors = node01Actors();
    config()->set('services.remnawave.writes_enabled', true);
    node01FakeProvider(postFail: true);
    $ui = app(AdminNodesUi::class);
    $bot = node01Bot();

    $bot->hearCallbackQueryData('node1:begin')->reply();
    $bot->hearText('Germany-01 | node.example.com')->reply();
    $bot->hearCallbackQueryData('node1:profile:0')->reply();
    $bot->hearCallbackQueryData('node1:inbound:0')->reply();
    $bot->hearCallbackQueryData('node1:preview')->reply();

    $key = TelegramBotContext::current()->cacheKey('node01:draft:'.$actors['root']->id);
    $token = Cache::get($key)['token'];
    $bot->hearCallbackQueryData('node1:apply:'.$token)->reply();
    $bot->hearCallbackQueryData('node1:apply:'.$token)->reply();
    expect(Cache::get($key))->toBeNull();
    expect(collect(Http::recorded())->filter(static fn ($pair): bool => $pair[0]->method() === 'POST'))->toHaveCount(1);
});

it('revalidates chosen profile/inbounds and current root authority immediately before a write', function (): void {
    $actors = node01Actors();
    node01FakeProvider();
    config()->set('services.remnawave.writes_enabled', true);
    $nodes = app(ManageAdminNodes::class);

    expect(fn () => $nodes->create($actors['root']->id, 'Germany', 'node.example.com', NODE01_PROFILE, [NODE01_UUID]))
        ->toThrow(ValidationException::class);
    expect(collect(Http::recorded())->filter(static fn ($pair): bool => $pair[0]->method() === 'POST'))->toBeEmpty();

    $actors['root']->syncRoles(['member']);
    expect(fn () => $nodes->create($actors['root']->id, 'Germany', 'node.example.com', NODE01_PROFILE, [NODE01_INBOUND]))
        ->toThrow(AuthorizationException::class);
    expect(collect(Http::recorded())->filter(static fn ($pair): bool => $pair[0]->method() === 'POST'))->toBeEmpty();
});

it('renders a compact Node list with a single status summary and no duplicated names', function (): void {
    node01Actors();
    node01FakeProvider();

    node01Bot()->hearCallbackQueryData('node1:list:1')->reply()->assertCalled('editMessageText');
    Http::assertSentCount(1);
});

it('retires other pending Node forms when starting the create wizard', function (): void {
    $actors = node01Actors();
    config()->set('services.remnawave.writes_enabled', true);
    node01FakeProvider();
    $root = $actors['root']->id;

    Cache::put('node02:draft:'.$root, ['stage' => 'edit'], now()->addMinutes(10));
    Cache::put('node03:draft:'.$root, ['stage' => 'value'], now()->addMinutes(10));

    node01Bot()->hearCallbackQueryData('node1:begin')->reply()->assertCalled('editMessageText');

    expect(Cache::get('node02:draft:'.$root))->toBeNull()
        ->and(Cache::get('node03:draft:'.$root))->toBeNull()
        ->and(Cache::get('node01:draft:'.$root)['stage'])->toBe('info');
    Http::assertNothingSent();
});


it('NW-01 setup remains default-disabled and cannot cause a provider write', function (): void {
    node01Actors();
    node01FakeProvider();
    $bot = node01Bot();

    $bot->hearCallbackQueryData('nw1:begin')->reply()->assertCalled('editMessageText');

    expect(Cache::get(TelegramBotContext::current()->cacheKey('nw01:draft:1')))->toBeNull();
    Http::assertNothingSent();
});

it('NW-01 private root wizard validates a public VPS and uses existing GET-only profiles', function (): void {
    $actors = node01Actors();
    node01FakeProvider();
    config()->set('services.remnawave.node_ssh_setup_enabled', true);
    $root = $actors['root']->id;
    $key = TelegramBotContext::current()->cacheKey('nw01:draft:'.$root);
    $bot = node01Bot();

    $bot->hearCallbackQueryData('nw1:begin')->reply()->assertCalled('editMessageText');
    expect(Cache::get($key)['stage'])->toBe('identity');

    $bot->hearText('Test02 | 127.0.0.1')->reply();
    expect(Cache::get($key)['stage'])->toBe('identity');

    $bot->hearText('Test02 | 194.5.192.133')->reply();
    expect(Cache::get($key)['stage'])->toBe('port');

    $bot->hearText('22')->reply();
    expect(Cache::get($key)['stage'])->toBe('username');

    $bot->hearText('root')->reply();
    expect(Cache::get($key)['stage'])->toBe('password');

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET');
    expect(collect(Http::recorded())->filter(static fn (array $pair): bool => $pair[0]->method() !== 'GET'))->toBeEmpty();

    $bot->hearCallbackQueryData('nw1:cancel')->reply()->assertCalled('editMessageText');
    expect(Cache::get($key))->toBeNull();
});

it('NW-01 blocks untrusted and group chat from opening SSH readiness', function (): void {
    node01Actors();
    node01FakeProvider();
    config()->set('services.remnawave.node_ssh_setup_enabled', true);
    $ui = app(\App\Interfaces\Telegram\Support\AdminNodeSetupUi::class);

    $ui->begin(node01Bot(951112));
    $ui->begin(node01Bot(951111, false));
    Http::assertNothingSent();
});

it('NW-01 preflight rejects local SSH destinations and invalid ports without network', function (): void {
    $ssh = app(\App\Modules\Provisioning\Infrastructure\NodeSetup\NodeSshPreflight::class);
    expect(fn () => $ssh->validateTarget('127.0.0.1', 22))->toThrow(\RuntimeException::class)
        ->and(fn () => $ssh->validateTarget('192.168.1.1', 22))->toThrow(\RuntimeException::class)
        ->and(fn () => $ssh->validateTarget('194.5.192.133', 0))->toThrow(\RuntimeException::class);
    $ssh->validateTarget('194.5.192.133', 22);
});


it('NW-01 deletes the Telegram password message and stores only temporary ciphertext', function (): void {
    $actors = node01Actors();
    node01FakeProvider();
    config()->set('services.remnawave.node_ssh_setup_enabled', true);
    $bot = node01Bot();
    $key = TelegramBotContext::current()->cacheKey('nw01:draft:'.$actors['root']->id);

    $bot->hearCallbackQueryData('nw1:begin')->reply();
    $bot->hearText('Test02 | 194.5.192.133')->reply();
    $bot->hearText('22')->reply();
    $bot->hearText('root')->reply();
    expect(Cache::get($key)['stage'])->toBe('password');

    $bot->hearText('not-a-real-ssh-password')->reply()->assertCalled('deleteMessage');
    $state = Cache::get($key);
    expect($state['stage'])->toBe('profile')
        ->and($state['secret'])->not->toBe('not-a-real-ssh-password')
        ->and(\Illuminate\Support\Facades\Crypt::decryptString($state['secret']))->toBe('not-a-real-ssh-password');

    $bot->hearCallbackQueryData('nw1:profile:0')->reply();
    $bot->hearCallbackQueryData('nw1:inbound:0')->reply();
    expect(Cache::get($key)['stage'])->toBe('preview');
    $bot->hearCallbackQueryData('nw1:cancel')->reply();
    expect(Cache::get($key))->toBeNull();
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET');
});

it('NW-01 yields to a newer legacy Node wizard instead of intercepting its text', function (): void {
    $actors = node01Actors();
    node01FakeProvider();
    config()->set('services.remnawave.node_ssh_setup_enabled', true);
    config()->set('services.remnawave.writes_enabled', true);
    $key = TelegramBotContext::current()->cacheKey('nw01:draft:'.$actors['root']->id);
    $bot = node01Bot();

    $bot->hearCallbackQueryData('nw1:begin')->reply();
    expect(Cache::get($key)['stage'])->toBe('identity');
    $bot->hearCallbackQueryData('node1:begin')->reply();
    $bot->hearText('Legacy-01 | node.example.com')->reply();
    expect(Cache::get($key))->toBeNull();
    expect(Cache::get(TelegramBotContext::current()->cacheKey('node01:draft:'.$actors['root']->id))['stage'])->toBe('profile');
});

<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ManageAdminNodeOperations;
use App\Modules\Provisioning\Application\ManageAdminNodes;
use Illuminate\Contracts\Cache\LockTimeoutException;
use App\Modules\Provisioning\Application\ManageAdminNodeBatch;
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

const NODE03_A = '550e8400-e29b-41d4-a716-446655440030';
const NODE03_B = '550e8400-e29b-41d4-a716-446655440031';
const NODE03_PROFILE = '550e8400-e29b-41d4-a716-446655440032';
const NODE03_INBOUND = '550e8400-e29b-41d4-a716-446655440033';

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['953001', '953002']);
    config()->set('services.remnawave.url', 'https://node03.test');
    config()->set('services.remnawave.token', 'node03-fake-token');
    config()->set('services.remnawave.writes_enabled', false);
    Http::preventStrayRequests();
});

function node03Root(int $telegram = 953001): User
{
    $user = User::factory()->create();
    $user->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegram]);

    return $user;
}

function node03Bot(int $telegram = 953001, bool $private = true): Nutgram
{
    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($telegram, false, 'Root'));
    $bot->setCommonChat(Chat::make($private ? $telegram : -953001, $private ? ChatType::PRIVATE : ChatType::GROUP));

    return $bot;
}

/** @return list<array{uuid:string,name:string,connected:bool,disabled:bool}> */
function node03List(): array
{
    return [
        ['uuid' => NODE03_A, 'name' => 'Alpha', 'connected' => true, 'disabled' => false],
        ['uuid' => NODE03_B, 'name' => 'Beta', 'connected' => false, 'disabled' => true],
    ];
}

function node03Fake(bool $changed = false, bool $timeout = false): void
{
    Http::fake(static function (Request $r) use ($changed, $timeout) {
        if ($r->method() === 'GET' && $r->url() === 'https://node03.test/api/nodes') {
            return Http::response(['response' => array_map(static fn (array $node): array => [
                'uuid' => $node['uuid'], 'name' => $changed ? 'Changed' : $node['name'],
                'isConnected' => $node['connected'], 'isDisabled' => $node['disabled'],
            ], node03List())]);
        }
        if ($r->method() === 'GET' && $r->url() === 'https://node03.test/api/config-profiles') {
            return Http::response(['response' => [
                'configProfiles' => [['uuid' => NODE03_PROFILE, 'name' => 'Primary']],
            ]]);
        }
        if ($r->method() === 'GET' && $r->url() === 'https://node03.test/api/config-profiles/'.NODE03_PROFILE.'/inbounds') {
            return Http::response(['response' => [
                'inbounds' => [['uuid' => NODE03_INBOUND, 'tag' => 'VLESS']],
            ]]);
        }
        if ($timeout && $r->method() !== 'GET') {
            return Http::response('', 504);
        }
        if ($r->method() === 'POST' && str_ends_with($r->url(), '/api/nodes/actions/reorder')) {
            return Http::response(['response' => []]);
        }
        if ($r->method() === 'POST') {
            return Http::response('', 204);
        }

        return Http::response(['message' => 'unexpected'], 404);
    });
}

function node03Writes(): int
{
    return collect(Http::recorded())->filter(static fn (array $pair): bool => $pair[0]->method() !== 'GET')->count();
}

it('blocks all bulk actions when write switch is off or role is revoked', function (): void {
    $root = node03Root();
    node03Fake();
    $action = app(ManageAdminNodeBatch::class);
    expect(fn () => $action->perform($root->id, 'enable', node03List(), [NODE03_A]))
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
    config()->set('services.remnawave.writes_enabled', true);
    $root->syncRoles(['member']);
    expect(fn () => $action->perform($root->id, 'enable', node03List(), [NODE03_A]))
        ->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('rejects malformed selection, missing node and stale node status without writes', function (): void {
    $root = node03Root();
    config()->set('services.remnawave.writes_enabled', true);
    node03Fake(changed: true);
    $action = app(ManageAdminNodeBatch::class);
    expect(fn () => $action->perform($root->id, 'enable', node03List(), []))->toThrow(ValidationException::class)
        ->and(fn () => $action->perform($root->id, 'enable', node03List(), [NODE03_A, NODE03_A]))->toThrow(ValidationException::class)
        ->and(fn () => $action->perform($root->id, 'delete', node03List(), [NODE03_A]))->toThrow(ValidationException::class)
        ->and(fn () => $action->perform($root->id, 'enable', node03List(), [NODE03_A]))->toThrow(ValidationException::class);
    expect(node03Writes())->toBe(0);
});

it('maps each allowlisted bulk action to one Remnawave request', function (string $mode, string $remote): void {
    $root = node03Root();
    config()->set('services.remnawave.writes_enabled', true);
    node03Fake();
    app(ManageAdminNodeBatch::class)->perform($root->id, $mode, node03List(), [NODE03_A]);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
        && $r->url() === 'https://node03.test/api/nodes/bulk-actions'
        && $r['action'] === $remote && $r['uuids'] === [NODE03_A]);
    expect(node03Writes())->toBe(1);
})->with([
    ['enable', 'ENABLE'], ['disable', 'DISABLE'], ['restart', 'RESTART'], ['reset', 'RESET_TRAFFIC'],
]);

it('bulk updates one validated field, and sends its exact value once', function (): void {
    $root = node03Root();
    config()->set('services.remnawave.writes_enabled', true);
    node03Fake();
    app(ManageAdminNodeBatch::class)->perform($root->id, 'update', node03List(), [NODE03_A, NODE03_B], ['countryCode' => 'DE']);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
        && $r->url() === 'https://node03.test/api/nodes/bulk-actions/update'
        && $r['fields'] === ['countryCode' => 'DE'] && $r['uuids'] === [NODE03_A, NODE03_B]);
    expect(node03Writes())->toBe(1);
});

it('rejects bad batch update values without a remote POST', function (array $payload): void {
    $root = node03Root();
    config()->set('services.remnawave.writes_enabled', true);
    node03Fake();
    expect(fn () => app(ManageAdminNodeBatch::class)->perform($root->id, 'update', node03List(), [NODE03_A], $payload))
        ->toThrow(ValidationException::class);
    expect(node03Writes())->toBe(0);
})->with([
    [['countryCode' => 'XYZ']],
    [['note' => "bad\nline"]],
    [['tags' => ['valid', 'bad tag']]],
    [['providerUuid' => 'fake']],
    [['integrationUuids' => ['bad']]],
    [['consumptionMultiplier' => -1]],
    [['unknownField' => 'x']],
    [['countryCode' => 'DE', 'note' => 'x']],
]);

it('revalidates existing profile and inbounds for profile bulk modification', function (): void {
    $root = node03Root();
    config()->set('services.remnawave.writes_enabled', true);
    node03Fake();
    $profile = ['configProfile' => [
        'activeConfigProfileUuid' => NODE03_PROFILE,
        'activeInbounds' => [NODE03_INBOUND],
    ]];
    app(ManageAdminNodeBatch::class)->perform($root->id, 'profile', node03List(), [NODE03_A], $profile);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
        && $r->url() === 'https://node03.test/api/nodes/bulk-actions/profile-modification'
        && $r['configProfile']['activeInbounds'] === [NODE03_INBOUND]);
    expect(node03Writes())->toBe(1);
});

it('reorders the entire node set and issues exactly one request with positions', function (): void {
    $root = node03Root();
    config()->set('services.remnawave.writes_enabled', true);
    node03Fake();
    app(ManageAdminNodeBatch::class)->perform($root->id, 'reorder', node03List(), [NODE03_B, NODE03_A]);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
        && $r->url() === 'https://node03.test/api/nodes/actions/reorder'
        && $r['nodes'] === [['uuid' => NODE03_B, 'viewPosition' => 0], ['uuid' => NODE03_A, 'viewPosition' => 1]]);
    expect(node03Writes())->toBe(1);
});

it('restart-all requires complete unchanged inventory and one explicit request', function (): void {
    $root = node03Root();
    config()->set('services.remnawave.writes_enabled', true);
    node03Fake();
    $action = app(ManageAdminNodeBatch::class);
    expect(fn () => $action->perform($root->id, 'restart-all', node03List(), [NODE03_A]))
        ->toThrow(ValidationException::class);
    $action->perform($root->id, 'restart-all', node03List(), [NODE03_A, NODE03_B]);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
        && $r->url() === 'https://node03.test/api/nodes/actions/restart-all'
        && $r['forceRestart'] === false);
    expect(node03Writes())->toBe(1);
});

it('Telegram wizard consumes a signed one-shot confirmation and does not retry uncertain writes', function (): void {
    $root = node03Root();
    config()->set('services.remnawave.writes_enabled', true);
    node03Fake(timeout: true);
    $bot = node03Bot();
    $bot->hearCallbackQueryData('node3:list')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('node3:toggle:0')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('node3:choose:enable')->reply()->assertCalled('editMessageText');
    $state = Cache::get('node03:draft:'.$root->id);
    expect($state['stage'])->toBe('confirm');
    $bot->hearCallbackQueryData('node3:apply:'.$state['token'])->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('node3:apply:'.$state['token'])->reply()->assertCalled('editMessageText');
    expect(Cache::get('node03:draft:'.$root->id))->toBeNull()->and(node03Writes())->toBe(1);
});

it('Telegram profile, update and reorder flows are limited to root private chat', function (): void {
    $root = node03Root();
    node03Root(953002);
    config()->set('services.remnawave.writes_enabled', true);
    node03Fake();
    $bot = node03Bot();

    $bot->hearCallbackQueryData('node3:list')->reply();
    $bot->hearCallbackQueryData('node3:toggle:0')->reply();
    $bot->hearCallbackQueryData('node3:choose:profile')->reply();
    $bot->hearCallbackQueryData('node3:profile:0')->reply();
    $bot->hearCallbackQueryData('node3:inbound:0')->reply();
    $bot->hearCallbackQueryData('node3:preview')->reply();
    $state = Cache::get('node03:draft:'.$root->id);
    expect($state['stage'])->toBe('confirm')->and($state['mode'])->toBe('profile');

    node03Bot(953002)->hearCallbackQueryData('node3:apply:'.$state['token'])->reply();
    expect(node03Writes())->toBe(0);
    $bot = node03Bot(953001);
    $bot->hearCallbackQueryData('node3:cancel')->reply()->assertCalled('editMessageText');
    expect(Cache::get('node03:draft:'.$root->id))->toBeNull();

    $bot->hearCallbackQueryData('node3:list')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('node3:toggle:0')->reply();
    $bot->hearCallbackQueryData('node3:choose:update')->reply();
    $bot->hearCallbackQueryData('node3:field:0')->reply();
    $bot->hearText('de')->reply();
    $state = Cache::get('node03:draft:'.$root->id);
    expect($state['stage'])->toBe('confirm')->and($state['data'])->toBe(['countryCode' => 'DE']);
    $bot->hearCallbackQueryData('node3:cancel')->reply();

    $bot->hearCallbackQueryData('node3:list')->reply();
    $bot->hearCallbackQueryData('node3:choose:reorder')->reply();
    $bot->hearCallbackQueryData('node3:move:0:down')->reply();
    $bot->hearCallbackQueryData('node3:preview')->reply();
    $state = Cache::get('node03:draft:'.$root->id);
    expect($state['stage'])->toBe('confirm')->and($state['selected'])->toBe([NODE03_B, NODE03_A]);

    node03Bot(953001, false)->hearCallbackQueryData('node3:apply:'.$state['token'])->reply()->assertNoReply();
    expect(node03Writes())->toBe(0);
});

it('Telegram blocks disabled global write gate and non-root operator', function (): void {
    $root = node03Root();
    node03Fake();
    $bot = node03Bot();
    $bot->hearCallbackQueryData('node3:list')->reply();
    $bot->hearCallbackQueryData('node3:toggle:0')->reply();
    $bot->hearCallbackQueryData('node3:choose:reset')->reply()->assertCalled('editMessageText');
    expect(node03Writes())->toBe(0);
    $root->syncRoles(['member']);
    $bot->hearCallbackQueryData('node3:list')->reply();
    expect(node03Writes())->toBe(0);
});

it('serializes create, individual and group Node writes using the same short-lived lock', function (): void {
    $root = node03Root();
    config()->set('services.remnawave.writes_enabled', true);
    node03Fake();

    $lock = Cache::lock('nodes:mutation', 60);
    expect($lock->get())->toBeTrue();
    try {
        expect(fn () => app(ManageAdminNodes::class)->create(
            $root->id, 'NewNode', 'new.example.com', NODE03_PROFILE, [NODE03_INBOUND],
        ))->toThrow(LockTimeoutException::class);

        expect(fn () => app(ManageAdminNodeOperations::class)->perform(
            $root->id, 'disable', node03List()[0],
        ))->toThrow(LockTimeoutException::class);

        expect(fn () => app(ManageAdminNodeBatch::class)->perform(
            $root->id, 'restart', node03List(), [NODE03_A],
        ))->toThrow(LockTimeoutException::class);
    } finally {
        $lock->release();
    }

    Http::assertNothingSent();
});

it('previews exactly the selected Node before a confirmed traffic reset', function (): void {
    $root = node03Root();
    config()->set('services.remnawave.writes_enabled', true);
    node03Fake();
    $bot = node03Bot();

    $bot->hearCallbackQueryData('node3:list')->reply();
    $bot->hearCallbackQueryData('node3:toggle:0')->reply();
    $bot->hearCallbackQueryData('node3:choose:reset')->reply()->assertCalled('editMessageText');
    $state = Cache::get('node03:draft:'.$root->id);
    expect($state['mode'])->toBe('reset')
        ->and($state['selected'])->toBe([NODE03_A])
        ->and($state['stage'])->toBe('confirm');
    expect(node03Writes())->toBe(0);
});

it('displays the complete proposed note value before bulk approval', function (): void {
    node03Root();
    config()->set('services.remnawave.writes_enabled', true);
    node03Fake();
    $bot = node03Bot();
    $note = str_repeat('A', 140);

    $bot->hearCallbackQueryData('node3:list')->reply();
    $bot->hearCallbackQueryData('node3:toggle:0')->reply();
    $bot->hearCallbackQueryData('node3:choose:update')->reply();
    $bot->hearCallbackQueryData('node3:field:1')->reply();
    $bot->hearText($note)->reply()->assertReply('sendMessage', [
        'text' => "⚠️ تأیید عملیات گروهی\n\nویرایش گروهی\nتعداد سرورها: 1\n\n"
            ."سرورهای هدف:\n1. Alpha\nفیلد: یادداشت\nمقدار جدید: ".$note
            ."\n\nنتیجه تغییرات را پس از ثبت بررسی کنید.\nاعتبار تأیید: ۳ دقیقه.",
    ]);
    expect(node03Writes())->toBe(0);
});

it('replaces older single-node and create drafts when starting bulk management', function (): void {
    $root = node03Root();
    node03Fake();

    Cache::put('node01:draft:'.$root->id, ['stage' => 'info'], now()->addMinutes(10));
    Cache::put('node02:draft:'.$root->id, ['stage' => 'edit'], now()->addMinutes(10));

    node03Bot()->hearCallbackQueryData('node3:list')->reply()->assertCalled('editMessageText');

    expect(Cache::get('node01:draft:'.$root->id))->toBeNull()
        ->and(Cache::get('node02:draft:'.$root->id))->toBeNull()
        ->and(Cache::get('node03:draft:'.$root->id)['stage'])->toBe('select');
    expect(node03Writes())->toBe(0);
});

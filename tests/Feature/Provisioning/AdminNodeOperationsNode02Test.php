<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Support\AdminNodeOperationsUi;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ManageAdminNodeOperations;
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

const NODE02_UUID = '550e8400-e29b-41d4-a716-446655440020';

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['952001', '952002']);
    config()->set('services.remnawave.url', 'https://node02.example.test');
    config()->set('services.remnawave.token', 'fake-node02-token');
    config()->set('services.remnawave.writes_enabled', false);
    Http::preventStrayRequests();
});

function node02Root(int $id = 952001): User
{
    $user = User::factory()->create();
    $user->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $id]);

    return $user;
}

function node02Bot(int $id = 952001, bool $private = true): Nutgram
{
    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($id, false, 'Root'));
    $bot->setCommonChat(Chat::make($private ? $id : -952001, $private ? ChatType::PRIVATE : ChatType::GROUP));

    return $bot;
}

/** @return array{uuid:string,name:string,connected:bool,disabled:bool} */
function node02Snapshot(bool $disabled = false): array
{
    return ['uuid' => NODE02_UUID, 'name' => 'Germany', 'connected' => true, 'disabled' => $disabled];
}

function node02Fake(bool $disabled = false, bool $timeout = false, string $name = 'Germany'): void
{
    Http::fake(static function (Request $request) use ($disabled, $timeout, $name) {
        $url = $request->url();
        $base = 'https://node02.example.test/api/nodes';
        if ($request->method() === 'GET' && $url === $base.'/'.NODE02_UUID) {
            return Http::response(['response' => [
                'uuid' => NODE02_UUID, 'name' => $name, 'isConnected' => true, 'isDisabled' => $disabled,
            ]], 200);
        }
        if ($timeout && $request->method() !== 'GET') {
            return Http::response('', 504);
        }
        if ($request->method() === 'PATCH' && $url === $base) {
            return Http::response(['response' => ['uuid' => NODE02_UUID]], 200);
        }
        if ($request->method() === 'POST' && preg_match('~/actions/(enable|disable)$~', $url)) {
            return Http::response(['response' => ['uuid' => NODE02_UUID]], 200);
        }
        if ($request->method() === 'POST' && str_ends_with($url, '/actions/restart')) {
            return Http::response('', 202);
        }
        if ($request->method() === 'POST' && str_ends_with($url, '/actions/reset-traffic')) {
            return Http::response('', 204);
        }
        if ($request->method() === 'DELETE' && $url === $base.'/'.NODE02_UUID) {
            return Http::response('', 204);
        }

        return Http::response(['message' => 'unexpected'], 404);
    });
}

function node02Mutations(): int
{
    return collect(Http::recorded())
        ->filter(static fn (array $pair): bool => $pair[0]->method() !== 'GET')
        ->count();
}

it('rejects untrusted, revoked, invalid and default-OFF mutations before any write', function (): void {
    $root = node02Root();
    node02Fake();
    $operations = app(ManageAdminNodeOperations::class);

    expect(fn () => $operations->perform($root->id, 'disable', node02Snapshot()))->toThrow(ValidationException::class);
    Http::assertNothingSent();

    config()->set('services.remnawave.writes_enabled', true);
    expect(fn () => $operations->perform($root->id, 'delete-all', node02Snapshot()))->toThrow(ValidationException::class);
    expect(fn () => $operations->perform($root->id, 'disable', node02Snapshot(), ['unsafe' => 'value']))->toThrow(ValidationException::class);
    Http::assertNothingSent();

    $root->syncRoles(['member']);
    expect(fn () => $operations->perform($root->id, 'disable', node02Snapshot()))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('allows only a fresh Node status and refuses active deletion', function (): void {
    $root = node02Root();
    config()->set('services.remnawave.writes_enabled', true);
    node02Fake();
    $operations = app(ManageAdminNodeOperations::class);

    expect(fn () => $operations->perform($root->id, 'delete', node02Snapshot()))->toThrow(ValidationException::class);
    expect(fn () => $operations->perform($root->id, 'enable', node02Snapshot()))->toThrow(ValidationException::class);
    expect(node02Mutations())->toBe(0);

    expect(fn () => $operations->perform($root->id, 'disable', node02Snapshot(disabled: true)))->toThrow(ValidationException::class);
    expect(node02Mutations())->toBe(0);
});

it('requires the same remote Node name and status at apply time to reject stale confirmations', function (): void {
    $root = node02Root();
    config()->set('services.remnawave.writes_enabled', true);
    node02Fake(name: 'Renamed elsewhere');

    expect(fn () => app(ManageAdminNodeOperations::class)->perform($root->id, 'restart', node02Snapshot()))
        ->toThrow(ValidationException::class);
    expect(node02Mutations())->toBe(0);
});

it('sends a single documented PATCH for validated name/address edit', function (): void {
    $root = node02Root();
    config()->set('services.remnawave.writes_enabled', true);
    node02Fake();

    app(ManageAdminNodeOperations::class)->perform($root->id, 'edit', node02Snapshot(), [
        'name' => 'Germany-02', 'address' => 'node.example.com',
    ]);

    expect(node02Mutations())->toBe(1);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'https://node02.example.test/api/nodes'
        && $request['uuid'] === NODE02_UUID
        && $request['name'] === 'Germany-02'
        && $request['address'] === 'node.example.com');
});

it('guards enabling, disabling and restarting and uses only allowed Node endpoints', function (string $mode, bool $disabled, string $method, string $end): void {
    $root = node02Root();
    config()->set('services.remnawave.writes_enabled', true);
    node02Fake(disabled: $disabled);

    app(ManageAdminNodeOperations::class)->perform($root->id, $mode, node02Snapshot(disabled: $disabled));
    expect(node02Mutations())->toBe(1);
    Http::assertSent(static fn (Request $request): bool => $request->method() === $method
        && str_ends_with($request->url(), $end));
})->with([
    ['enable', true, 'POST', '/actions/enable'],
    ['disable', false, 'POST', '/actions/disable'],
    ['restart', false, 'POST', '/actions/restart'],
    ['reset', false, 'POST', '/actions/reset-traffic'],
    ['delete', true, 'DELETE', '/nodes/'.NODE02_UUID],
]);

it('uses actor-bound Telegram confirm once and never repeats a failed provider write', function (): void {
    $root = node02Root();
    config()->set('services.remnawave.writes_enabled', true);
    node02Fake(timeout: true);
    $bot = node02Bot();

    $bot->hearCallbackQueryData('node2:prepare:'.NODE02_UUID.':disable')->reply()->assertCalled('editMessageText');

    $key = 'node02:draft:'.$root->id;
    $state = Cache::get($key);
    expect($state)->toBeArray()->and($state['stage'])->toBe('confirm');

    $bot->hearCallbackQueryData('node2:apply:'.$state['token'])->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('node2:apply:'.$state['token'])->reply()->assertCalled('editMessageText');
    expect(Cache::get($key))->toBeNull()->and(node02Mutations())->toBe(1);
});

it('supports private one-message edit and denies cross-user or revoked one-time confirmation', function (): void {
    $root = node02Root();
    $other = node02Root(952002);
    config()->set('services.remnawave.writes_enabled', true);
    node02Fake();
    $bot = node02Bot();

    $bot->hearCallbackQueryData('node2:prepare:'.NODE02_UUID.':edit')->reply()->assertCalled('editMessageText');
    $bot->hearText('Germany-02 | 198.51.100.27')->reply()->assertCalled('sendMessage');

    $key = 'node02:draft:'.$root->id;
    $state = Cache::get($key);
    expect($state['stage'])->toBe('confirm')
        ->and($state['changes']['name'])->toBe('Germany-02');

    node02Bot(952002)->hearCallbackQueryData('node2:apply:'.$state['token'])->reply();
    expect(node02Mutations())->toBe(0);
    expect(Cache::get($key))->toBeArray();

    $root->syncRoles(['member']);
    $bot->hearCallbackQueryData('node2:apply:'.$state['token'])->reply();
    expect(node02Mutations())->toBe(0);
    expect(Cache::get($key))->toBeArray();
    expect($other->hasRole('super-admin'))->toBeTrue();
});

it('does not let group callbacks prepare changes or bypass the disabled write gate', function (): void {
    node02Root();
    node02Fake();

    node02Bot(952001, false)->hearCallbackQueryData('node2:prepare:'.NODE02_UUID.':delete')->reply()->assertNoReply();
    node02Bot()->hearCallbackQueryData('node2:prepare:'.NODE02_UUID.':disable')->reply()->assertCalled('editMessageText');
    Http::assertNothingSent();
});

it('cancels one pending edit without mutating remote state', function (): void {
    $root = node02Root();
    config()->set('services.remnawave.writes_enabled', true);
    node02Fake();
    $bot = node02Bot();

    $bot->hearCallbackQueryData('node2:prepare:'.NODE02_UUID.':edit')->reply();
    expect(Cache::get('node02:draft:'.$root->id))->toBeArray();
    $bot->hearCallbackQueryData('node2:cancel')->reply()->assertCalled('editMessageText');
    expect(Cache::get('node02:draft:'.$root->id))->toBeNull()->and(node02Mutations())->toBe(0);
});

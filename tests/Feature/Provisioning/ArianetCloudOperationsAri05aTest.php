<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ManageAdminArianetOperations;
use App\Modules\Provisioning\Infrastructure\Arianet\ArianetCloudWriteClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['993401', '993402']);
    config()->set('services.arianet_cloud.read_token', 'ari05a-fixture');
    config()->set('services.arianet_cloud.operations_enabled', true);
    config()->set('services.arianet_cloud.purchase_enabled', false);
    config()->set('services.arianet_cloud.writes_enabled', false);
    Http::preventStrayRequests();
});

function ari05aRoot(): User
{
    $actor = User::factory()->create();
    $actor->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $actor->id, 'telegram_user_id' => 993401]);

    return $actor;
}

function ari05aFake(): void
{
    Http::fake(static function (Request $request) {
        $base = 'https://api.ariaservice.net/cloud/v1';
        if ($request->method() === 'POST' && $request->url() === $base.'/servers/918/actions/reboot') {
            return match (true) {
                config('ari05a_fixture.uncertain') === true => Http::response([], 503),
                config('ari05a_fixture.rejected') === true => Http::response([], 422),
                default => Http::response(['action_id' => 702], 202),
            };
        }
        if ($request->method() === 'GET' && $request->url() === $base.'/servers/918') {
            return Http::response(['data' => ['id' => 918, 'name' => 'Fixture', 'status' => 'running', 'ipv4' => '194.5.192.133']]);
        }
        if ($request->method() === 'GET' && str_starts_with($request->url(), $base.'/servers/918/actions?')) {
            $history = [];
            for ($i = 1; $i <= (int) config('ari05a_fixture.history', 0); $i++) {
                $history[] = ['id' => $i, 'status' => 'success'];
            }
            if (config('ari05a_fixture.pending') === true) {
                $history[] = ['id' => 701, 'status' => 'running'];
            }

            return Http::response(['data' => $history]);
        }
        if ($request->method() === 'GET' && $request->url() === $base.'/actions/702') {
            return Http::response(['data' => ['id' => 702, 'status' => 'success', 'command' => 'reboot', 'resource' => ['type' => 'server', 'id' => 918]]]);
        }

        return Http::response([], 404);
    });
}

it('ARI-05A uses a one-time durable reboot confirmation and GET-only verification', function (): void {
    ari05aFake();
    $actor = ari05aRoot();
    $ops = app(ManageAdminArianetOperations::class);
    $prepared = $ops->prepare($actor->id, 918, 'reboot');
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    $ops->confirm($actor->id, 918, $prepared['token']);
    expect($ops->status($actor->id, 918)['status'])->toBe('accepted');
    expect(fn () => $ops->confirm($actor->id, 918, $prepared['token']))->toThrow(RuntimeException::class);
    config()->set('services.arianet_cloud.operations_enabled', false);
    $ops->reconcile($actor->id, 918);
    expect($ops->status($actor->id, 918)['status'])->toBe('completed')
        ->and(DB::table('arianet_cloud_operations')->value('active_server_id'))->toBeNull();
    expect(collect(Http::recorded())->filter(fn (array $entry): bool => $entry[0]->method() === 'POST'))->toHaveCount(1);
});

it('ARI-05A blocks replay of an uncertain operation and does not accept unsupported power actions', function (): void {
    ari05aFake();
    config()->set('ari05a_fixture.uncertain', true);
    $actor = ari05aRoot();
    $ops = app(ManageAdminArianetOperations::class);
    $prepared = $ops->prepare($actor->id, 918, 'reboot');
    $ops->confirm($actor->id, 918, $prepared['token']);
    $ops->reconcile($actor->id, 918);
    expect($ops->status($actor->id, 918)['status'])->toBe('uncertain');
    expect(fn () => $ops->prepare($actor->id, 918, 'reboot'))->toThrow(RuntimeException::class);
    expect(fn () => app(ArianetCloudWriteClient::class)->powerAction(918, 'reset'))->toThrow(InvalidArgumentException::class);
    expect(collect(Http::recorded())->filter(fn (array $entry): bool => $entry[0]->method() === 'POST'))->toHaveCount(1);
});

it('ARI-05A isolates the power switch from other Cloud write permissions', function (): void {
    ari05aFake();
    config()->set('services.arianet_cloud.operations_enabled', false);
    config()->set('services.arianet_cloud.writes_enabled', true);
    config()->set('services.arianet_cloud.purchase_enabled', true);
    expect(fn () => app(ArianetCloudWriteClient::class)->powerAction(918, 'reboot'))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('ARI-05A recognizes the official Cloud off status', function (): void {
    expect(ManageAdminArianetOperations::available('off'))->toBe(['poweron']);
});

it('ARI-06 accepts long flat action history but rejects unfinished actions', function (): void {
    ari05aFake();
    config()->set('ari05a_fixture.history', 45);
    $actor = ari05aRoot();
    $ops = app(ManageAdminArianetOperations::class);
    $draft = $ops->prepare($actor->id, 918, 'reboot');
    $ops->cancel($actor->id, 918, $draft['token']);
    config()->set('ari05a_fixture.pending', true);
    expect(fn () => $ops->prepare($actor->id, 918, 'reboot'))->toThrow(RuntimeException::class);
    Http::assertNotSent(fn (Request $r): bool => $r->method() !== 'GET');
});

it('ARI-06 releases a definitely rejected operation without replaying it', function (): void {
    ari05aFake();
    config()->set('ari05a_fixture.rejected', true);
    $actor = ari05aRoot();
    $ops = app(ManageAdminArianetOperations::class);
    $draft = $ops->prepare($actor->id, 918, 'reboot');
    $ops->confirm($actor->id, 918, $draft['token']);
    expect($ops->status($actor->id, 918)['status'])->toBe('failed')
        ->and(DB::table('arianet_cloud_operations')->value('active_server_id'))->toBeNull();
    expect(fn () => $ops->confirm($actor->id, 918, $draft['token']))->toThrow(RuntimeException::class);
    $ops->prepare($actor->id, 918, 'reboot');
    expect(collect(Http::recorded())->filter(fn (array $entry): bool => $entry[0]->method() === 'POST'))->toHaveCount(1);
});

it('ARI-06 permits explicitly reviewed unknown outcomes only after quiet provider evidence', function (): void {
    ari05aFake();
    config()->set('ari05a_fixture.uncertain', true);
    $actor = ari05aRoot();
    $ops = app(ManageAdminArianetOperations::class);
    $draft = $ops->prepare($actor->id, 918, 'reboot');
    $ops->confirm($actor->id, 918, $draft['token']);
    expect(fn () => $ops->reviewUnknown($actor->id, 918))->toThrow(RuntimeException::class);
    $this->travel(11)->minutes();
    config()->set('ari05a_fixture.pending', true);
    expect(fn () => $ops->reviewUnknown($actor->id, 918))->toThrow(RuntimeException::class);
    config()->set('ari05a_fixture.pending', false);
    $review = $ops->reviewUnknown($actor->id, 918);
    $ops->closeUnknown($actor->id, 918, $review['token']);
    expect($ops->status($actor->id, 918)['status'])->toBe('reviewed')
        ->and(DB::table('arianet_cloud_operations')->value('active_server_id'))->toBeNull();
    expect($ops->recent($actor->id))->toBe(918);
    expect(fn () => $ops->closeUnknown($actor->id, 918, $review['token']))->toThrow(RuntimeException::class);
    expect(collect(Http::recorded())->filter(fn (array $entry): bool => $entry[0]->method() === 'POST'))->toHaveCount(1);
});

it('ARI-06 isolates installation protection by IP and releases terminal checkpoints', function (): void {
    $ip = '194.5.192.133';
    $id = str_repeat('a', 32);
    Cache::put('nw02:host:'.$ip, $id, now()->addHours(3));
    Cache::put('nw02:status:'.$id, ['state' => 'installing'], now()->addHours(3));
    $this->travel(6)->minutes();
    expect(fn () => ManageAdminArianetOperations::assertHostAvailable($ip))->toThrow(RuntimeException::class);
    ManageAdminArianetOperations::assertHostAvailable('194.5.192.134');
    Cache::put('nw02:status:'.$id, ['state' => 'running'], now()->addHours(3));
    ManageAdminArianetOperations::assertHostAvailable($ip);
});

it('ARI-06 cancels only an owned undispatched preview and never a reserved operation', function (): void {
    ari05aFake();
    $actor = ari05aRoot();
    $other = User::factory()->create();
    $other->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $other->id, 'telegram_user_id' => 993402]);
    $ops = app(ManageAdminArianetOperations::class);
    $draft = $ops->prepare($actor->id, 918, 'reboot');
    expect(fn () => $ops->discardPrepared($other->id, 918))->toThrow(RuntimeException::class);
    $ops->discardPrepared($actor->id, 918);
    expect(fn () => $ops->confirm($actor->id, 918, $draft['token']))->toThrow(RuntimeException::class);
    Http::assertNotSent(fn (Request $r): bool => $r->method() !== 'GET');
    $draft = $ops->prepare($actor->id, 918, 'reboot');
    $ops->confirm($actor->id, 918, $draft['token']);
    expect(fn () => $ops->discardPrepared($actor->id, 918))->toThrow(RuntimeException::class);
});

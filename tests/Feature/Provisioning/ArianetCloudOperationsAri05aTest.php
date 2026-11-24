<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ManageAdminArianetOperations;
use App\Modules\Provisioning\Infrastructure\Arianet\ArianetCloudWriteClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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
            return config('ari05a_fixture.uncertain')
                ? Http::response([], 503)
                : Http::response(['action_id' => 702], 202);
        }
        if ($request->method() === 'GET' && $request->url() === $base.'/servers/918') {
            return Http::response(['data' => ['id' => 918, 'name' => 'Fixture', 'status' => 'running', 'ipv4' => '194.5.192.133']]);
        }
        if ($request->method() === 'GET' && str_starts_with($request->url(), $base.'/servers/918/actions?')) {
            return Http::response(['data' => []]);
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

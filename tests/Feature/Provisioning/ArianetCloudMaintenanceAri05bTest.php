<?php

declare(strict_types=1);

use App\Modules\Provisioning\Infrastructure\Arianet\ArianetCloudReadClient;
use App\Modules\Provisioning\Infrastructure\Arianet\ArianetCloudWriteClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.arianet_cloud.read_token', 'ari05b-fixture');
    config()->set('services.arianet_cloud.operations_enabled', true);
    config()->set('services.arianet_cloud.maintenance_enabled', true);
    config()->set('services.arianet_cloud.purchase_enabled', false);
    config()->set('services.arianet_cloud.writes_enabled', false);
    Http::preventStrayRequests();
});

it('ARI-05B uses only the exact maintenance paths and safe delete defaults', function (): void {
    Http::fake(['https://api.ariaservice.net/cloud/v1/servers/*' => Http::response(['action_id' => 702], 202)]);
    $write = app(ArianetCloudWriteClient::class);
    expect($write->maintenanceAction(918, 'rebuild', 'ubuntu-24.04'))->toBe(['action_id' => 702])
        ->and($write->maintenanceAction(918, 'change_plan', '7'))->toBe(['action_id' => 702])
        ->and($write->maintenanceAction(918, 'delete'))->toBe(['action_id' => 702]);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
        && str_ends_with($r->url(), '/918/actions/rebuild') && $r->data() === ['image' => 'ubuntu-24.04']);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
        && str_ends_with($r->url(), '/918/actions/change_plan') && $r->data() === ['plan_id' => 7]);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE'
        && str_ends_with($r->url(), '/918?keep_backups=false&delete_snapshots=false'));
    expect(fn () => $write->maintenanceAction(918, 'reset'))->toThrow(RuntimeException::class);
    expect(fn () => $write->maintenanceAction(918, 'rebuild', 'snapshot:7'))->toThrow(InvalidArgumentException::class);
    Http::assertSentCount(3);
});

it('ARI-05B power permission never enables maintenance by itself', function (): void {
    Http::fake();
    config()->set('services.arianet_cloud.maintenance_enabled', false);
    expect(fn () => app(ArianetCloudWriteClient::class)->maintenanceAction(918, 'delete'))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('ARI-05C treats only exact not-found as delete absence', function (): void {
    Http::fake(['https://api.ariaservice.net/cloud/v1/servers/918' => Http::response(['code' => 'not_found'], 404)]);
    expect(app(ArianetCloudReadClient::class)->serverMissing(918))->toBeTrue();
    Http::assertSent(fn (Request $r): bool => $r->method() === 'GET');
});

<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ManageAdminArianetOperations;
use App\Modules\Provisioning\Infrastructure\Arianet\ArianetCloudReadClient;
use App\Modules\Provisioning\Infrastructure\Arianet\ArianetCloudWriteClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

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

function ari05bRoot(): User
{
    config()->set('super_admin.telegram_ids', ['993501', '993502']);
    config()->set('services.remnawave.url', 'https://rw.ari05b.test');
    config()->set('services.remnawave.token', 'fixture-token');
    config()->set('services.cloudflare.allowed_zones', 'coreflare.ir');
    config()->set('services.cloudflare.token', 'fixture-cf-token');
    $actor = User::factory()->create();
    $actor->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $actor->id, 'telegram_user_id' => 993501]);

    return $actor;
}

function ari05bInventory(): void
{
    Http::fake(static function (Request $r) {
        $base = 'https://api.ariaservice.net/cloud/v1';
        if ($r->method() === 'DELETE' && str_starts_with($r->url(), $base.'/servers/918?')) {
            config()->set('ari05b.deleted', true);

            return Http::response(['action_id' => 702], 202);
        }
        if ($r->method() === 'POST' && str_starts_with($r->url(), $base.'/servers/918/actions/')) {
            $command = basename($r->url());
            if ($command === 'rebuild') {
                config()->set('ari05b.image', $r['image']);
            } elseif ($command === 'change_plan') {
                config()->set('ari05b.plan', $r['plan_id']);
            } else {
                return Http::response([], 405);
            }
            config()->set('ari05b.command', $command);

            return Http::response(['action_id' => 702], 202);
        }
        if ($r->method() !== 'GET') {
            return Http::response([], 405);
        }
        if ($r->url() === $base.'/servers/918') {
            if (config('ari05b.deleted')) {
                return Http::response(['code' => 'not_found'], 404);
            }

            return Http::response(['data' => [
                'id' => 918, 'name' => 'Unused fixture', 'status' => 'off',
                'ipv4' => '194.5.192.133', 'ipv6' => '',
                'locked' => false, 'rescue_enabled' => false,
                'protection_delete' => config('ari05b.protected', false),
                'protection_rebuild' => config('ari05b.protected', false),
                'datacenter' => 'ovh', 'plan_id' => config('ari05b.plan', 7), 'disk_gb' => 15,
                'image' => config('ari05b.image', 'debian-12'),
            ]]);
        }
        if (str_starts_with($r->url(), $base.'/servers/918/actions?')) {
            return Http::response(['data' => []]);
        }
        if ($r->url() === $base.'/datacenters') {
            return Http::response(['data' => [['code' => 'ovh', 'name' => 'OVH', 'orderable' => true]]]);
        }
        if (str_starts_with($r->url(), $base.'/plans?')) {
            $plan = [
                'datacenter' => 'ovh', 'is_active' => true, 'currency' => 'IRR',
                'availability' => ['available' => true], 'minimum_usage_hours' => 24,
                'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 15,
                'hourly_price' => 1000, 'monthly_price' => 730000,
            ];

            return Http::response(['data' => [
                array_replace($plan, ['id' => 7, 'name' => 'Old', 'is_active' => false, 'availability' => ['available' => false]]),
                array_replace($plan, ['id' => 8, 'name' => 'New', 'vcpu' => 2, 'ram_mb' => 2048, 'disk_gb' => 30,
                    'hourly_price' => config('ari05b.price', 2000), 'monthly_price' => 1460000]),
            ]]);
        }
        if (str_contains($r->url(), '/images?')) {
            return Http::response(['data' => [[
                'image' => 'ubuntu-24.04', 'name' => 'Ubuntu', 'type' => 'system', 'status' => 'available',
                'deprecated' => false, 'datacenters' => ['ovh'], 'min_disk_gb' => 10,
            ]]]);
        }
        if ($r->url() === $base.'/servers/918/costs') {
            return Http::response(['data' => [
                'server_id' => 918, 'totals' => [['currency' => 'IRR', 'hourly_price' => 18770, 'monthly_price' => 13702100]],
                'lines' => [],
            ]]);
        }
        if ($r->url() === $base.'/actions/702') {
            return Http::response(['data' => [
                'id' => 702, 'command' => config('ari05b.command', 'delete'), 'status' => 'success',
                'resource' => ['type' => 'server', 'id' => 918],
            ]]);
        }
        if ($r->url() === 'https://rw.ari05b.test/api/nodes' || $r->url() === 'https://rw.ari05b.test/api/hosts') {
            return Http::response(['response' => []]);
        }
        if (str_starts_with($r->url(), 'https://api.cloudflare.com/client/v4/zones?')) {
            return Http::response(['success' => true, 'result' => [
                ['id' => str_repeat('a', 32), 'name' => 'coreflare.ir'],
            ], 'result_info' => ['total_count' => 1]]);
        }
        if (str_contains($r->url(), '/dns_records?')) {
            $items = config('ari05b.dns_reference') ? [['id' => str_repeat('b', 32)]] : [];

            return Http::response(['success' => true, 'result' => $items, 'result_info' => ['total_count' => count($items)]]);
        }

        return Http::response([], 404);
    });
}

it('ARI-05C does not delete protected or managed-DNS-referenced servers', function (): void {
    $actor = ari05bRoot();
    ari05bInventory();
    $ops = app(ManageAdminArianetOperations::class);
    config()->set('ari05b.protected', true);
    expect(fn () => $ops->prepare($actor->id, 918, 'delete'))->toThrow(RuntimeException::class);
    config()->set('ari05b.protected', false);
    config()->set('ari05b.dns_reference', true);
    expect(fn () => $ops->prepare($actor->id, 918, 'delete'))->toThrow(RuntimeException::class);
    Http::assertNotSent(fn (Request $r): bool => $r->method() !== 'GET');
});

it('ARI-05C rechecks dependencies immediately before the one-shot delete', function (): void {
    $actor = ari05bRoot();
    ari05bInventory();
    $ops = app(ManageAdminArianetOperations::class);
    $draft = $ops->prepare($actor->id, 918, 'delete');
    config()->set('ari05b.dns_reference', true);
    expect(fn () => $ops->confirm($actor->id, 918, $draft['token']))->toThrow(RuntimeException::class);
    Http::assertNotSent(fn (Request $r): bool => $r->method() !== 'GET');
    expect(DB::table('arianet_cloud_operations')->value('status'))->toBe('prepared');
});

it('ARI-05C verifies a confirmed delete with the exact action and server absence', function (): void {
    $actor = ari05bRoot();
    ari05bInventory();
    $ops = app(ManageAdminArianetOperations::class);
    $draft = $ops->prepare($actor->id, 918, 'delete');
    $ops->confirm($actor->id, 918, $draft['token']);
    expect($ops->status($actor->id, 918)['status'])->toBe('accepted');
    $ops->reconcile($actor->id, 918);
    expect($ops->status($actor->id, 918)['status'])->toBe('completed');
    expect(collect(Http::recorded())->filter(fn (array $entry): bool => $entry[0]->method() !== 'GET'))->toHaveCount(1);
});

it('ARI-06 rebuilds against actual disk size without requiring the old plan to be orderable', function (): void {
    $actor = ari05bRoot();
    ari05bInventory();
    $ops = app(ManageAdminArianetOperations::class);
    $draft = $ops->prepare($actor->id, 918, 'rebuild', 'ubuntu-24.04');
    $ops->confirm($actor->id, 918, $draft['token']);
    $ops->reconcile($actor->id, 918);
    expect($ops->status($actor->id, 918)['status'])->toBe('completed');
    expect(collect(Http::recorded())->filter(fn (array $entry): bool => $entry[0]->method() !== 'GET'))->toHaveCount(1);
});

it('ARI-06 verifies plan price again and accepts only the later freshly confirmed upgrade', function (): void {
    $actor = ari05bRoot();
    ari05bInventory();
    $ops = app(ManageAdminArianetOperations::class);
    $draft = $ops->prepare($actor->id, 918, 'change_plan', '8');
    config()->set('ari05b.price', 2500);
    expect(fn () => $ops->confirm($actor->id, 918, $draft['token']))->toThrow(RuntimeException::class);
    Http::assertNotSent(fn (Request $r): bool => $r->method() !== 'GET');
    $draft = $ops->prepare($actor->id, 918, 'change_plan', '8');
    $ops->confirm($actor->id, 918, $draft['token']);
    $ops->reconcile($actor->id, 918);
    expect($ops->status($actor->id, 918)['status'])->toBe('completed')
        ->and(ManageAdminArianetOperations::verifiedPlan(918, 7))->toBe(8);
    expect(collect(Http::recorded())->filter(fn (array $entry): bool => $entry[0]->method() !== 'GET'))->toHaveCount(1);
});

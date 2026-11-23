<?php

use App\Modules\Provisioning\Infrastructure\Arianet\ArianetCloudReadClient;
use App\Modules\Provisioning\Infrastructure\Arianet\ArianetCloudWriteClient;
use App\Modules\Provisioning\Infrastructure\Arianet\ArianetCloudWriteUncertain;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.arianet_cloud.writes_enabled', false);
    config()->set('services.arianet_cloud.read_token', 'read-only-secret');
    config()->set('services.arianet_cloud.timeout', 10);
    Http::preventStrayRequests();
});

it('never calls the provider while the independent Cloud write switch is off', function (): void {
    Http::fake();
    $cloud = app(ArianetCloudWriteClient::class);

    expect(fn () => $cloud->createServer('denagate-test', 'ovh', 8, 'ubuntu-24.04', 7))
        ->toThrow(RuntimeException::class, 'disabled');
    expect(fn () => $cloud->changeIpv4(42))
        ->toThrow(RuntimeException::class, 'disabled');
    Http::assertNothingSent();
});

it('refuses absent or invalid one-token write configuration before any request', function (): void {
    Http::fake();
    config()->set('services.arianet_cloud.writes_enabled', true);
    $cloud = app(ArianetCloudWriteClient::class);

    config()->set('services.arianet_cloud.read_token', null);
    expect(fn () => $cloud->changeIpv4(42))->toThrow(RuntimeException::class, 'not configured');

    config()->set('services.arianet_cloud.read_token', 'read-only-secret');
    config()->set('services.arianet_cloud.timeout', 0);
    expect(fn () => $cloud->changeIpv4(42))->toThrow(RuntimeException::class, 'timeout');

    Http::assertNothingSent();
});

it('validates all Cloud create inputs and forbids generic or bulk requests', function (): void {
    Http::fake();
    config()->set('services.arianet_cloud.writes_enabled', true);
    $cloud = app(ArianetCloudWriteClient::class);

    foreach ([
        ['bad/name', 'ovh', 8, 'ubuntu-24.04', 7],
        ['node', 'OVH', 8, 'ubuntu-24.04', 7],
        ['node', 'ovh/../x', 8, 'ubuntu-24.04', 7],
        ['node', 'ovh', 0, 'ubuntu-24.04', 7],
        ['node', 'ovh', 8, 'snapshot:22', 7],
        ['node', 'ovh', 8, 'ubuntu-24.04', 0],
        [str_repeat('x', 64), 'ovh', 8, 'ubuntu-24.04', 7],
    ] as $args) {
        expect(fn () => $cloud->createServer(...$args))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => $cloud->changeIpv4(0))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('posts exactly one single-server create and never exposes root password', function (): void {
    config()->set('services.arianet_cloud.writes_enabled', true);
    Http::fake(['https://api.ariaservice.net/cloud/v1/servers' => Http::response([
        'data' => ['id' => 123, 'status' => 'building'],
        'servers' => [['data' => ['id' => 123], 'action_id' => 77]],
        'action_id' => 77,
        'root_password' => 'one-time-private-root-password',
    ], 201)]);

    $result = app(ArianetCloudWriteClient::class)->createServer(
        'denagate-01', 'ovh', 8, 'ubuntu-24.04', 9,
    );

    expect($result)->toBe(['server_id' => 123, 'action_id' => 77])
        ->not->toHaveKey('root_password');

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.ariaservice.net/cloud/v1/servers'
        && $request->hasHeader('Authorization', 'Bearer read-only-secret')
        && $request['count'] === 1
        && $request['name'] === 'denagate-01'
        && $request['datacenter'] === 'ovh'
        && $request['plan_id'] === 8
        && $request['image'] === 'ubuntu-24.04'
        && $request['ssh_key_ids'] === [9]
        && array_keys($request->data()) === ['name', 'datacenter', 'plan_id', 'image', 'ssh_key_ids', 'count']);
    Http::assertSentCount(1);
});

it('sends the exact IPv4-change endpoint with no paid acceptance unless explicitly passed', function (): void {
    config()->set('services.arianet_cloud.writes_enabled', true);
    Http::fake(['https://api.ariaservice.net/cloud/v1/*' => Http::response(['action_id' => 88], 202)]);

    $cloud = app(ArianetCloudWriteClient::class);
    expect($cloud->changeIpv4(42))->toBe(['action_id' => 88]);
    expect($cloud->changeIpv4(42, true))->toBe(['action_id' => 88]);

    $sent = Http::recorded()->map(
        static fn (array $pair): array => [
            'method' => $pair[0]->method(),
            'url' => $pair[0]->url(),
            'paid' => $pair[0]->data()['confirm_paid'] ?? null,
        ],
    )->all();

    expect($sent)->toBe([
        ['method' => 'POST', 'url' => 'https://api.ariaservice.net/cloud/v1/servers/42/actions/change_ipv4', 'paid' => null],
        ['method' => 'POST', 'url' => 'https://api.ariaservice.net/cloud/v1/servers/42/actions/change_ipv4', 'paid' => true],
    ]);
    Http::assertSentCount(2);
});

it('rejects confirmed status with invalid or mismatched action receipts as uncertain', function (): void {
    config()->set('services.arianet_cloud.writes_enabled', true);
    Http::fakeSequence()
        ->push(['action_id' => 0], 202)
        ->push(['action_id' => '88'], 202)
        ->push(['data' => ['id' => 10], 'servers' => [], 'action_id' => 5, 'root_password' => 'secret'], 201)
        ->push(['data' => ['id' => 10], 'servers' => [['data' => ['id' => 11], 'action_id' => 5]], 'action_id' => 5, 'root_password' => 'secret'], 201);

    $cloud = app(ArianetCloudWriteClient::class);
    expect(fn () => $cloud->changeIpv4(42))->toThrow(ArianetCloudWriteUncertain::class);
    expect(fn () => $cloud->changeIpv4(42))->toThrow(ArianetCloudWriteUncertain::class);
    expect(fn () => $cloud->createServer('node', 'ovh', 8, 'ubuntu-24.04', 9))
        ->toThrow(ArianetCloudWriteUncertain::class);
    expect(fn () => $cloud->createServer('node', 'ovh', 8, 'ubuntu-24.04', 9))
        ->toThrow(ArianetCloudWriteUncertain::class);
    Http::assertSentCount(4);
});

it('distinguishes definite upstream rejections from ambiguous outcomes without replay', function (): void {
    config()->set('services.arianet_cloud.writes_enabled', true);
    $secret = 'one-time-private-root-password';
    Http::fakeSequence()
        ->push(['error' => $secret], 402)
        ->push(['error' => $secret], 409)
        ->push(['error' => $secret], 422)
        ->push(['error' => $secret], 429, ['Retry-After' => '10'])
        ->push(['error' => $secret], 503, ['Retry-After' => '15'])
        ->push('', 302, ['Location' => 'https://evil.invalid/collect'])
        ->push('not-json', 202);

    $cloud = app(ArianetCloudWriteClient::class);
    foreach ([402, 409, 422] as $status) {
        try {
            $cloud->changeIpv4(42);
            test()->fail('Expected HTTP '.$status.' rejection.');
        } catch (RuntimeException $e) {
            expect($e)->not->toBeInstanceOf(ArianetCloudWriteUncertain::class);
            expect($e->getMessage())->not->toContain($secret);
        }
    }

    foreach (range(1, 4) as $attempt) {
        expect(fn () => $cloud->changeIpv4(42))->toThrow(ArianetCloudWriteUncertain::class);
    }

    Http::assertSentCount(7);
});

it('treats connection failures as ambiguous and never automatically retries', function (): void {
    config()->set('services.arianet_cloud.writes_enabled', true);
    $attempts = 0;
    Http::fake(static function () use (&$attempts): never {
        $attempts++;
        throw new ConnectionException('Authorization: Bearer read-only-secret');
    });

    expect(fn () => app(ArianetCloudWriteClient::class)->createServer('node', 'ovh', 8, 'ubuntu-24.04', 9))
        ->toThrow(ArianetCloudWriteUncertain::class, 'outcome is unknown');

    expect($attempts)->toBe(1);
    Http::assertNothingSent();
});

it('keeps GET reads available while the Cloud write switch is disabled', function (): void {
    Http::fake(['https://api.ariaservice.net/cloud/v1/*' => Http::response(['data' => []])]);
    config()->set('services.arianet_cloud.writes_enabled', false);

    expect(app(ArianetCloudReadClient::class)->servers()['data'])->toBe([]);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer read-only-secret'));
    Http::assertSentCount(1);
});

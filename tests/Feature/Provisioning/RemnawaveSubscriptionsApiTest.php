<?php

use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClient;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveSubscriptionsApi;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function subscriptionTestApi(): RemnawaveSubscriptionsApi
{
    return new RemnawaveSubscriptionsApi(new RemnawaveApiClient('https://panel.example.test', 'subscription-test-token'));
}

it('retrieves protected subscription details by numeric ID, username and short UUID', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://panel.example.test/*' => Http::response([
        'response' => [
            'isFound' => true,
            'user' => ['id' => 42],
            'links' => ['vless://sensitive-config'],
            'ssConfLinks' => [],
            'subscriptionUrl' => 'https://subscription.example.test/secret',
        ],
    ])]);

    $api = subscriptionTestApi();

    expect($api->byUserId(42)['isFound'])->toBeTrue()
        ->and($api->byUsername('sky_42')['isFound'])->toBeTrue()
        ->and($api->byShortUuid('abcdefgh')['isFound'])->toBeTrue();

    Http::assertSentCount(3);

    foreach ([
        '/api/subscriptions/by-id/42',
        '/api/subscriptions/by-username/sky_42',
        '/api/subscriptions/by-short-uuid/abcdefgh',
    ] as $path) {
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://panel.example.test'.$path
            && $request->hasHeader('Authorization', 'Bearer subscription-test-token'));
    }
});

it('reads connection keys via protected numeric user ID endpoint', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://panel.example.test/*' => Http::response([
        'response' => ['enabledKeys' => [], 'hiddenKeys' => [], 'disabledKeys' => []],
    ])]);

    expect(subscriptionTestApi()->connectionKeys(42)['enabledKeys'])->toBe([]);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://panel.example.test/api/subscriptions/connection-keys/42');
});

it('rejects bad subscription identifiers and provider envelopes without disclosing secrets', function (): void {
    Http::preventStrayRequests();
    Http::fake();

    $api = subscriptionTestApi();
    expect(fn () => $api->byUserId(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $api->byShortUuid('../../unsafe'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $api->byUsername('invalid username'))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();

    Http::fake(['https://panel.example.test/*' => Http::response(['response' => ['isFound' => true]])]);
    expect(fn () => $api->byUserId(42))->toThrow(UnexpectedValueException::class);
    Http::assertSentCount(1);
});

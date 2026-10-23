<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ManageCloudflareDns;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const CF01_ZONE_ID = 'abcdef0123456789abcdef0123456789';

function cf01Operator(): array
{
    config()->set('super_admin.telegram_ids', ['970001', '970002']);
    config()->set('services.cloudflare.token', 'cf01-test-token');
    config()->set('services.cloudflare.allowed_zones', 'denagate.ir,atel.ir');
    config()->set('services.cloudflare.dns_writes_enabled', true);
    Http::preventStrayRequests();

    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 970001]);
    $member = User::factory()->create();
    $member->syncRoles(['member']);

    return compact('root', 'member');
}

/** @param array<string, mixed>|null $existing */
function cf01FakeDns(?array $existing = null, bool $failPost = false): object
{
    $state = (object) ['records' => $existing === null ? [] : [$existing], 'posts' => 0];
    Http::fake(static function (Request $r) use ($state, $failPost) {
        $url = $r->url();
        $base = 'https://api.cloudflare.com/client/v4/zones';
        if ($r->method() === 'GET' && str_starts_with($url, $base.'?')) {
            $name = $r->data()['name'] ?? '';
            return Http::response(['success' => true, 'result' => $name === 'denagate.ir'
                ? [['id' => CF01_ZONE_ID, 'name' => 'denagate.ir', 'status' => 'active']]
                : []]);
        }
        if (str_starts_with($url, $base.'/'.CF01_ZONE_ID.'/dns_records')) {
            if ($r->method() === 'GET') {
                return Http::response(['success' => true, 'result' => $state->records]);
            }
            if ($r->method() === 'POST') {
                $state->posts++;
                if ($failPost) {
                    return Http::response(['success' => false, 'errors' => [['message' => 'gateway timeout']]], 504);
                }
                $data = $r->data();
                $state->records = [[
                    'id' => CF01_ZONE_ID,
                    'name' => $data['name'], 'type' => $data['type'],
                    'content' => $data['content'], 'proxied' => $data['proxied'],
                ]];
                return Http::response(['success' => true, 'result' => $state->records[0]], 201);
            }
        }

        return Http::response(['success' => false], 404);
    });

    return $state;
}

it('CF-01 lists only allowlisted Cloudflare zones and validates exact subdomains', function (): void {
    $actors = cf01Operator();
    cf01FakeDns();
    $dns = app(ManageCloudflareDns::class);

    expect($dns->wizardEnabled())->toBeTrue()
        ->and($dns->zones($actors['root']->id))->toBe([['id' => CF01_ZONE_ID, 'name' => 'denagate.ir']])
        ->and($dns->hostname($actors['root']->id, 'denagate.ir', 'USA'))->toBe('usa.denagate.ir')
        ->and($dns->hostname($actors['root']->id, 'denagate.ir', 'eu.usa.denagate.ir'))->toBe('eu.usa.denagate.ir');

    foreach (['denagate.ir', 'bad.evil.ir', '*.denagate.ir', 'https://usa.denagate.ir'] as $name) {
        expect(fn () => $dns->hostname($actors['root']->id, 'denagate.ir', $name))->toThrow(RuntimeException::class);
    }
    expect(fn () => $dns->hostname($actors['root']->id, 'evil.ir', 'usa'))->toThrow(RuntimeException::class);
    expect(fn () => $dns->zones($actors['member']->id))->toThrow(AuthorizationException::class);
});

it('CF-01 creates one DNS-only A record and a matching repeat is a GET-only no-op', function (): void {
    $actors = cf01Operator();
    $state = cf01FakeDns();
    $dns = app(ManageCloudflareDns::class);
    $before = 0;
    $callback = static function () use (&$before): void {
        $before++;
    };

    expect($dns->ensureA($actors['root']->id, CF01_ZONE_ID, 'denagate.ir',
        'usa.denagate.ir', '194.5.192.133', $callback))->toBe('created');
    expect($dns->ensureA($actors['root']->id, CF01_ZONE_ID, 'denagate.ir',
        'usa.denagate.ir', '194.5.192.133', $callback))->toBe('existing');
    expect($state->posts)->toBe(1)->and($before)->toBe(1);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
        && ($r->data()['type'] ?? null) === 'A'
        && ($r->data()['ttl'] ?? null) === 1
        && ($r->data()['proxied'] ?? null) === false);
});

it('CF-01 rejects mismatched or proxied records without changing DNS', function (): void {
    $actors = cf01Operator();
    $record = ['id' => CF01_ZONE_ID, 'type' => 'A', 'name' => 'usa.denagate.ir',
        'content' => '194.5.192.134', 'proxied' => false];
    $state = cf01FakeDns($record);
    $dns = app(ManageCloudflareDns::class);

    expect(fn () => $dns->ensureA($actors['root']->id, CF01_ZONE_ID,
        'denagate.ir', 'usa.denagate.ir', '194.5.192.133', static fn () => null))
        ->toThrow(RuntimeException::class);
    expect($state->posts)->toBe(0);

    $state->records[0]['content'] = '194.5.192.133';
    $state->records[0]['proxied'] = true;
    expect(fn () => $dns->ensureA($actors['root']->id, CF01_ZONE_ID,
        'denagate.ir', 'usa.denagate.ir', '194.5.192.133', static fn () => null))
        ->toThrow(RuntimeException::class);
    expect($state->posts)->toBe(0);
});

it('CF-01 keeps DNS writes independently disabled and never leaks the token into outputs', function (): void {
    $actors = cf01Operator();
    $state = cf01FakeDns();
    $dns = app(ManageCloudflareDns::class);
    config()->set('services.cloudflare.dns_writes_enabled', false);

    expect($dns->wizardEnabled())->toBeFalse();
    expect(fn () => $dns->ensureA($actors['root']->id, CF01_ZONE_ID, 'denagate.ir',
        'usa.denagate.ir', '194.5.192.133', static fn () => null))->toThrow(RuntimeException::class);
    expect($state->posts)->toBe(0);
});

it('CF-01 surfaces ambiguous external failures without a write retry', function (): void {
    $actors = cf01Operator();
    $state = cf01FakeDns(null, failPost: true);
    $dns = app(ManageCloudflareDns::class);
    $marked = 0;

    expect(fn () => $dns->ensureA($actors['root']->id, CF01_ZONE_ID,
        'denagate.ir', 'usa.denagate.ir', '194.5.192.133',
        static function () use (&$marked): void {
            $marked++;
        }))->toThrow(RuntimeException::class);
    expect($state->posts)->toBe(1)->and($marked)->toBe(1);
});

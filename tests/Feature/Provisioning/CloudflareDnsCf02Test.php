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

const CF02_ZONE = 'abcdef0123456789abcdef0123456789';
const CF02_RECORD = 'ab010101010101010101010101010101';
const CF02_DOMAIN = 'denagate.ir';

/** @return array{root:User,member:User} */
function cf02Actors(): array
{
    config()->set('super_admin.telegram_ids', ['970001', '970002']);
    config()->set('services.cloudflare.token', 'secret-only-in-config');
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

/** @param list<array<string,mixed>> $records */
function cf02Api(array $records): object
{
    $state = (object) ['rows' => $records, 'writes' => 0, 'post' => 0, 'failPatch' => false];
    Http::fake(static function (Request $r) use ($state) {
        $uri = $r->url();
        $base = 'https://api.cloudflare.com/client/v4/zones';
        $method = $r->method();
        if ($method === 'GET' && str_starts_with($uri, $base.'?')) {
            $name = $r->data()['name'] ?? '';

            return Http::response(['success' => true, 'result' => $name === CF02_DOMAIN
                ? [['id' => CF02_ZONE, 'name' => CF02_DOMAIN]] : []]);
        }
        $prefix = $base.'/'.CF02_ZONE.'/dns_records';
        if ($method === 'GET' && str_starts_with($uri, $prefix.'?')) {
            $query = $r->data();
            if (isset($query['page'])) {
                $page = (int) $query['page'];

                return Http::response(['success' => true,
                    'result' => array_slice($state->rows, ($page - 1) * 8, 8),
                    'result_info' => ['page' => $page, 'total_pages' => max(1, (int) ceil(count($state->rows) / 8))],
                ]);
            }
            $filtered = array_values(array_filter($state->rows,
                static fn (array $row): bool => $row['name'] === ($query['name'] ?? null)));

            return Http::response(['success' => true, 'result' => $filtered]);
        }
        if ($method === 'GET' && str_starts_with($uri, $prefix.'/')) {
            $id = substr($uri, strlen($prefix) + 1);
            foreach ($state->rows as $row) {
                if ($row['id'] === $id) {
                    return Http::response(['success' => true, 'result' => $row]);
                }
            }

            return Http::response(['success' => false], 404);
        }
        if ($method === 'PATCH' && str_starts_with($uri, $prefix.'/')) {
            $state->writes++;
            if ($state->failPatch) {
                return Http::response(['success' => false], 504);
            }
            $id = substr($uri, strlen($prefix) + 1);
            foreach ($state->rows as &$row) {
                if ($row['id'] === $id) {
                    $row['content'] = $r->data()['content'];
                    $row['proxied'] = $r->data()['proxied'];

                    return Http::response(['success' => true, 'result' => $row]);
                }
            }
            unset($row);

            return Http::response(['success' => false], 404);
        }
        if ($method === 'POST' && $uri === $prefix) {
            $state->post++;
            $row = [
                'id' => 'ab020202020202020202020202020202', 'type' => 'A',
                'name' => $r->data()['name'], 'content' => $r->data()['content'],
                'proxied' => $r->data()['proxied'], 'proxiable' => true,
            ];
            $state->rows[] = $row;

            return Http::response(['success' => true, 'result' => $row], 201);
        }

        return Http::response(['success' => false], 404);
    });

    return $state;
}

function cf02A(): array
{
    return [
        'id' => CF02_RECORD, 'type' => 'A', 'name' => 'usa.denagate.ir',
        'content' => '194.5.192.133', 'proxied' => false, 'proxiable' => true,
        'modified_on' => '2026-10-23T12:00:00Z',
    ];
}

it('CF-02 paginates mixed records, hides TXT content and permits only eligible subdomain A edits', function (): void {
    $actors = cf02Actors();
    $records = [cf02A(), [
        'id' => 'ab030303030303030303030303030303', 'type' => 'TXT',
        'name' => '_acme-challenge.denagate.ir', 'content' => 'do-not-expose-secret',
    ], [
        'id' => 'ab040404040404040404040404040404', 'type' => 'A',
        'name' => 'denagate.ir', 'content' => '194.5.192.133', 'proxied' => false,
    ]];
    $state = cf02Api($records);
    $action = app(ManageCloudflareDns::class);
    $page = $action->page($actors['root']->id, CF02_ZONE, CF02_DOMAIN, 1);
    expect($page['pages'])->toBe(1)
        ->and(count($page['items']))->toBe(3)
        ->and($page['items'][0]['editable'])->toBeTrue()
        ->and($page['items'][1]['content'])->toBeNull()
        ->and($page['items'][1]['editable'])->toBeFalse()
        ->and($page['items'][2]['editable'])->toBeFalse();
    expect(json_encode($page))->not->toContain('do-not-expose-secret');
    expect(fn () => $action->changeA($actors['root']->id, CF02_ZONE, CF02_DOMAIN,
        $page['items'][2], '194.5.192.134', true))->toThrow(RuntimeException::class);
    expect($state->writes)->toBe(0);
    expect(fn () => $action->page($actors['member']->id, CF02_ZONE, CF02_DOMAIN, 1))
        ->toThrow(AuthorizationException::class);
});

it('CF-02 edits A IP and proxy with a fresh snapshot and rejects a stale or unchanged request', function (): void {
    $actors = cf02Actors();
    $state = cf02Api([cf02A()]);
    $dns = app(ManageCloudflareDns::class);
    $expected = $dns->one($actors['root']->id, CF02_ZONE, CF02_DOMAIN, CF02_RECORD);
    $dns->changeA($actors['root']->id, CF02_ZONE, CF02_DOMAIN, $expected, '194.5.192.134', true);
    expect($state->writes)->toBe(1)
        ->and($state->rows[0]['content'])->toBe('194.5.192.134')
        ->and($state->rows[0]['proxied'])->toBeTrue();

    expect(fn () => $dns->changeA($actors['root']->id, CF02_ZONE, CF02_DOMAIN,
        $expected, '194.5.192.135', false))->toThrow(RuntimeException::class);
    expect($state->writes)->toBe(1);
    $fresh = $dns->one($actors['root']->id, CF02_ZONE, CF02_DOMAIN, CF02_RECORD);
    expect(fn () => $dns->changeA($actors['root']->id, CF02_ZONE, CF02_DOMAIN,
        $fresh, '194.5.192.134', true))->toThrow(RuntimeException::class);
    expect($state->writes)->toBe(1);
});

it('CF-02 reuses the CF-01 create Action and blocks existing conflicts', function (): void {
    $actors = cf02Actors();
    $state = cf02Api([cf02A()]);
    $dns = app(ManageCloudflareDns::class);
    expect($dns->createManagedA($actors['root']->id, CF02_ZONE, CF02_DOMAIN,
        'new.denagate.ir', '194.5.192.133'))->toBe('created')
        ->and($state->post)->toBe(1)
        ->and($state->rows[1]['proxied'])->toBeFalse();

    expect($dns->createManagedA($actors['root']->id, CF02_ZONE, CF02_DOMAIN,
        'new.denagate.ir', '194.5.192.133'))->toBe('existing');
    expect($state->post)->toBe(1);
    expect(fn () => $dns->createManagedA($actors['root']->id, CF02_ZONE, CF02_DOMAIN,
        'usa.denagate.ir', '194.5.192.135'))->toThrow(RuntimeException::class);
    expect($state->post)->toBe(1);
});

it('CF-02 reads while writes are off, refuses unauthorized zones and fails safely on a lost PATCH', function (): void {
    $actors = cf02Actors();
    $state = cf02Api([cf02A()]);
    $dns = app(ManageCloudflareDns::class);
    $expected = $dns->one($actors['root']->id, CF02_ZONE, CF02_DOMAIN, CF02_RECORD);

    config()->set('services.cloudflare.dns_writes_enabled', false);
    expect($dns->page($actors['root']->id, CF02_ZONE, CF02_DOMAIN, 1)['pages'])->toBe(1);
    expect(fn () => $dns->changeA($actors['root']->id, CF02_ZONE, CF02_DOMAIN,
        $expected, '194.5.192.134', true))->toThrow(RuntimeException::class);
    expect($state->writes)->toBe(0);
    config()->set('services.cloudflare.dns_writes_enabled', true);

    expect(fn () => $dns->page($actors['root']->id, CF02_ZONE, 'evil.ir', 1))
        ->toThrow(RuntimeException::class);
    $state->failPatch = true;
    expect(fn () => $dns->changeA($actors['root']->id, CF02_ZONE, CF02_DOMAIN,
        $expected, '194.5.192.134', true))->toThrow(RuntimeException::class);
    expect($state->writes)->toBe(1);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'PATCH'
        && $r->url() === 'https://api.cloudflare.com/client/v4/zones/'.CF02_ZONE.'/dns_records/'.CF02_RECORD);
});

it('CF-02 uses genuine API pages without loading the entire DNS zone', function (): void {
    $actors = cf02Actors();
    $rows = [];
    for ($i = 0; $i < 10; $i++) {
        $rows[] = [
            'id' => sprintf('%032x', $i + 1), 'type' => 'A',
            'name' => 'n'.$i.'.denagate.ir',
            'content' => '194.5.192.133', 'proxied' => false,
        ];
    }
    cf02Api($rows);
    $dns = app(ManageCloudflareDns::class);
    expect(count($dns->page($actors['root']->id, CF02_ZONE, CF02_DOMAIN, 1)['items']))->toBe(8)
        ->and(count($dns->page($actors['root']->id, CF02_ZONE, CF02_DOMAIN, 2)['items']))->toBe(2)
        ->and($dns->page($actors['root']->id, CF02_ZONE, CF02_DOMAIN, 2)['pages'])->toBe(2);
});

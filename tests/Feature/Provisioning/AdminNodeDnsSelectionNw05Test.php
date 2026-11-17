<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;

uses(RefreshDatabase::class);

const CF03_UI_ZONE_ID = 'abcdef0123456789abcdef0123456789';

function cf03UiActors(): User
{
    config()->set('super_admin.telegram_ids', ['980401', '980402']);
    config()->set('services.cloudflare.token', 'cf-ui-test-token');
    config()->set('services.cloudflare.allowed_zones', 'denagate.ir');
    config()->set('services.cloudflare.dns_writes_enabled', true);
    config()->set('services.remnawave.node_ssh_setup_enabled', true);
    Http::preventStrayRequests();

    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 980401]);

    return $root;
}

function cf03UiBot(): Nutgram
{
    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make(980401, false, 'Root'));
    $bot->setCommonChat(Chat::make(980401, ChatType::PRIVATE));

    return $bot;
}

/** @param list<array<string,mixed>> $records */
function cf03UiFakeCloudflare(array $records): void
{
    Http::fake(static function (Request $request) use ($records) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $method = $request->method();
        $basePath = '/client/v4';
        if ($method === 'GET' && $path === $basePath.'/zones') {
            return Http::response(['success' => true, 'result' => [[
                'id' => CF03_UI_ZONE_ID,
                'name' => 'denagate.ir',
            ]]]);
        }

        $recordsPath = $basePath.'/zones/'.CF03_UI_ZONE_ID.'/dns_records';
        if ($method === 'GET' && $path === $recordsPath) {
            $query = $request->data();
            if (isset($query['page'])) {
                $page = (int) $query['page'];

                return Http::response([
                    'success' => true,
                    'result' => array_slice($records, ($page - 1) * 8, 8),
                    'result_info' => [
                        'page' => $page,
                        'total_pages' => max(1, (int) ceil(count($records) / 8)),
                    ],
                ]);
            }
            $name = $query['name'] ?? null;
            $filtered = array_values(array_filter(
                $records,
                static fn (array $record): bool => $record['name'] === $name,
            ));

            return Http::response(['success' => true, 'result' => $filtered]);
        }
        if ($method === 'GET' && is_string($path) && str_starts_with($path, $recordsPath.'/')) {
            $id = substr($path, strlen($recordsPath) + 1);
            foreach ($records as $record) {
                if ($record['id'] === $id) {
                    return Http::response(['success' => true, 'result' => $record]);
                }
            }

            return Http::response(['success' => false], 404);
        }

        return Http::response(['error' => 'unexpected request'], 404);
    });
}

/** @return list<array<string,mixed>> */
function cf03UiRecords(): array
{
    $records = [];
    foreach (['uae', 'usa', 'nl', 'de', 'fr', 'se', 'sg'] as $index => $label) {
        $records[] = [
            'id' => sprintf('%032x', $index + 1),
            'type' => 'A',
            'name' => $label.'.denagate.ir',
            'content' => '185.194.188.'.(100 + $index),
            'proxied' => $index === 0,
            'proxiable' => true,
            'modified_on' => '2026-11-17T08:00:00Z',
        ];
    }
    array_splice($records, 2, 0, [[
        'id' => str_repeat('a', 32),
        'type' => 'TXT',
        'name' => '_acme-challenge.denagate.ir',
        'content' => 'must-not-be-displayed',
    ], [
        'id' => str_repeat('b', 32),
        'type' => 'CNAME',
        'name' => 'www.denagate.ir',
        'content' => 'target.example.net',
    ]]);

    return $records;
}

/** @return array{key:string} */
function cf03UiDraft(User $root, string $stage = 'dns_choice'): array
{
    $key = TelegramBotContext::current()->cacheKey('nw01:draft:'.$root->id);
    Cache::put($key, [
        'stage' => $stage,
        'name' => 'UAE-02',
        'ip' => '194.5.192.133',
    ], now()->addMinutes(10));

    return compact('key');
}

it('CF-03 lists paginated editable A records, hides other record data and rejects stale selection tokens', function (): void {
    $root = cf03UiActors();
    cf03UiFakeCloudflare(cf03UiRecords());
    $draft = cf03UiDraft($root);
    $bot = cf03UiBot();

    $bot->hearCallbackQueryData('nw1:dns:zones')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('nw1:dns:zone:0')->reply()->assertCalled('editMessageText');

    $state = Cache::get($draft['key']);
    expect($state['stage'])->toBe('dns_records')
        ->and($state['dnsRecordPage'])->toBe(1)
        ->and(count($state['dnsRecords']))->toBe(6);

    $firstToken = $state['dnsRecordToken'];
    $bot->hearCallbackQueryData('nw1:dns:records:2')->reply();
    $state = Cache::get($draft['key']);
    expect($state['dnsRecordPage'])->toBe(2)
        ->and(count($state['dnsRecords']))->toBe(1);

    $secondToken = $state['dnsRecordToken'];
    expect($secondToken)->not->toBe($firstToken);
    $bot->hearCallbackQueryData('nw1:dns:record:0:'.$firstToken)->reply();
    expect(Cache::get($draft['key'])['stage'])->toBe('dns_records')
        ->and(Cache::get($draft['key'])['dnsRecordToken'])->toBe($secondToken);

    $bot->hearCallbackQueryData('nw1:dns:record:0:'.$secondToken)->reply();
    $selected = Cache::get($draft['key']);
    expect($selected['stage'])->toBe('port')
        ->and($selected['dnsName'])->toBe('sg.denagate.ir')
        ->and($selected['dnsRecordExpected']['content'])->toBe('185.194.188.106')
        ->and($selected['dnsRecordExpected']['proxied'])->toBeFalse();

    expect(collect(Http::recorded())->filter(
        static fn (array $pair): bool => $pair[0]->method() !== 'GET',
    ))->toBeEmpty();
});

it('CF-03 rejects a selected hostname with multiple records before it enters the SSH flow', function (): void {
    $root = cf03UiActors();
    $records = [[
        'id' => str_repeat('1', 32),
        'type' => 'A',
        'name' => 'uae.denagate.ir',
        'content' => '185.194.188.191',
        'proxied' => true,
        'proxiable' => true,
        'modified_on' => '2026-11-17T08:00:00Z',
    ], [
        'id' => str_repeat('2', 32),
        'type' => 'A',
        'name' => 'uae.denagate.ir',
        'content' => '185.194.188.192',
        'proxied' => true,
        'proxiable' => true,
        'modified_on' => '2026-11-17T08:00:00Z',
    ]];
    cf03UiFakeCloudflare($records);
    $draft = cf03UiDraft($root);
    $bot = cf03UiBot();

    $bot->hearCallbackQueryData('nw1:dns:zones')->reply();
    $bot->hearCallbackQueryData('nw1:dns:zone:0')->reply();
    $state = Cache::get($draft['key']);
    $bot->hearCallbackQueryData('nw1:dns:record:0:'.$state['dnsRecordToken'])->reply();

    $after = Cache::get($draft['key']);
    expect($after['stage'])->toBe('dns_records')
        ->and($after['dnsRecordExpected'] ?? null)->toBeNull()
        ->and($after['dnsName'] ?? null)->toBeNull();
    expect(collect(Http::recorded())->filter(
        static fn (array $pair): bool => $pair[0]->method() !== 'GET',
    ))->toBeEmpty();
});

it('CF-03 supports creating a new subdomain and continuing without DNS with real back navigation', function (): void {
    $root = cf03UiActors();
    cf03UiFakeCloudflare([]);
    $draft = cf03UiDraft($root, 'dns_records');
    $state = Cache::get($draft['key']);
    $state['dnsZone'] = 'denagate.ir';
    $state['dnsZoneId'] = CF03_UI_ZONE_ID;
    Cache::put($draft['key'], $state, now()->addMinutes(10));
    $bot = cf03UiBot();

    $bot->hearCallbackQueryData('nw1:dns:new')->reply();
    expect(Cache::get($draft['key'])['stage'])->toBe('dns_name');
    $bot->hearText('newnode')->reply();
    expect(Cache::get($draft['key'])['stage'])->toBe('port')
        ->and(Cache::get($draft['key'])['dnsName'])->toBe('newnode.denagate.ir')
        ->and(Cache::get($draft['key'])['dnsRecordExpected'] ?? null)->toBeNull();

    $bot->hearCallbackQueryData('nw1:step:back')->reply();
    expect(Cache::get($draft['key'])['stage'])->toBe('dns_name');
    $bot->hearCallbackQueryData('nw1:step:back')->reply();
    expect(Cache::get($draft['key'])['stage'])->toBe('dns_records');

    $bot->hearCallbackQueryData('nw1:dns:skip')->reply();
    $after = Cache::get($draft['key']);
    expect($after['stage'])->toBe('port')
        ->and($after['dnsName'] ?? null)->toBeNull()
        ->and($after['dnsZone'] ?? null)->toBeNull()
        ->and($after['dnsRecordExpected'] ?? null)->toBeNull();
    expect(collect(Http::recorded())->filter(
        static fn (array $pair): bool => $pair[0]->method() !== 'GET',
    ))->toBeEmpty();
});

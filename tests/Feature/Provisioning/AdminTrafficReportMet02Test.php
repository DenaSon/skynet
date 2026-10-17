<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ReadAdminTrafficReport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;

uses(RefreshDatabase::class);

const MET02_NODE = '550e8400-e29b-41d4-a716-446655440020';
const MET02_SECOND = '550e8400-e29b-41d4-a716-446655440021';

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['955001', '955002']);
    config()->set('services.remnawave.url', 'https://met02.test');
    config()->set('services.remnawave.token', 'offline-test-token');
    config()->set('services.remnawave.writes_enabled', false);
    Http::preventStrayRequests();
});

function met02Root(int $telegramId = 955001, bool $trusted = true): User
{
    $root = User::factory()->create();
    if ($trusted) {
        $root->syncRoles(['super-admin']);
    }
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => $telegramId]);

    return $root;
}

function met02Bot(int $telegramId = 955001, bool $private = true): Nutgram
{
    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'User'));
    $bot->setCommonChat(Chat::make($private ? $telegramId : -955001, $private ? ChatType::PRIVATE : ChatType::GROUP));

    return $bot;
}

/** @param array<string,mixed>|null $history */
function met02Fake(?array $history = null, bool $topUsersOk = true): void
{
    Http::fake(static function (Request $r) use ($history, $topUsersOk) {
        $path = parse_url($r->url(), PHP_URL_PATH);
        $today = gmdate('Y-m-d');
        $yesterday = gmdate('Y-m-d', strtotime('-1 day'));

        return match ($path) {
            '/api/nodes' => Http::response(['response' => [
                ['uuid' => MET02_NODE, 'name' => 'Alpha', 'isConnected' => true, 'isDisabled' => false],
                ['uuid' => MET02_SECOND, 'name' => 'Beta', 'isConnected' => false, 'isDisabled' => false],
            ]]),
            '/api/bandwidth-stats/nodes' => Http::response(['response' => $history ?? [
                'categories' => [$yesterday, $today],
                'sparklineData' => [1073741824, 2147483648],
                'topNodes' => [],
                'series' => [
                    ['name' => 'Alpha', 'data' => [536870912, 1073741824]],
                    ['name' => 'Beta', 'data' => [536870912, 1073741824]],
                ],
            ]]),
            '/api/bandwidth-stats/nodes/'.MET02_NODE.'/users' => $topUsersOk
                ? Http::response(['response' => [
                    'categories' => [], 'sparklineData' => [],
                    'topUsers' => [
                        ['username' => 'buyer1', 'total' => 2097152, 'color' => '#fff'],
                        ['username' => 'buyer2', 'total' => 1048576, 'color' => '#fff'],
                    ],
                ]])
                : Http::response(['message' => 'down'], 503),
            default => Http::response(['message' => 'not found'], 404),
        };
    });
}

it('shows 7/30 day daily trend and exact per-node totals only from validated reports', function (): void {
    $root = met02Root();
    met02Fake();
    $report = app(ReadAdminTrafficReport::class)->summary($root->id, '7');

    expect($report['state'])->toBe('available')
        ->and($report['days'])->toBe(7)
        ->and($report['trend'])->toHaveCount(2)
        ->and($report['trend'][0]['total'])->toBe(1073741824)
        ->and($report['nodes'])->toHaveCount(2)
        ->and($report['nodes'][0]['total'])->toBe(1610612736)
        ->and($report['nodes'][1]['total'])->toBe(1610612736);

    $report30 = app(ReadAdminTrafficReport::class)->summary($root->id, '30');
    expect($report30['days'])->toBe(30)
        ->and($report30['start'])->toBe(gmdate('Y-m-d', strtotime('-29 days')))
        ->and($report30['end'])->toBe(gmdate('Y-m-d'));

    Http::assertSentCount(4);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'GET'
        && str_contains($r->url(), 'start=') && str_contains($r->url(), 'end='));
    expect(collect(Http::recorded())->every(static fn (array $pair): bool => $pair[0]->method() === 'GET'))->toBeTrue();
});

it('reads top users for one existing node without exposing full user payload', function (): void {
    $root = met02Root();
    met02Fake();
    $result = app(ReadAdminTrafficReport::class)->nodeUsers($root->id, '7', MET02_NODE);
    expect($result['state'])->toBe('available')
        ->and($result['name'])->toBe('Alpha')
        ->and($result['users'])->toBe([
            ['username' => 'buyer1', 'total' => 2097152],
            ['username' => 'buyer2', 'total' => 1048576],
        ]);
    Http::assertSentCount(2);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'GET'
        && str_contains($r->url(), MET02_NODE.'/users?')
        && str_contains($r->url(), 'topUsersLimit=10'));
});

it('does not use arbitrary UUIDs or invalid date ranges for provider requests', function (): void {
    $root = met02Root();
    met02Fake();
    expect(fn () => app(ReadAdminTrafficReport::class)->summary($root->id, '180'))
        ->toThrow(ValidationException::class);
    expect(fn () => app(ReadAdminTrafficReport::class)->nodeUsers($root->id, '7', 'not-a-uuid'))
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();

    expect(app(ReadAdminTrafficReport::class)->nodeUsers(
        $root->id, '7', '550e8400-e29b-41d4-a716-446655449999',
    )['state'])->toBe('unavailable');
    Http::assertSentCount(1);
});

it('retains the daily trend when the provider series changes format instead of making up totals', function (): void {
    $root = met02Root();
    met02Fake([
        'categories' => [gmdate('Y-m-d')], 'sparklineData' => [1024],
        'topNodes' => [], 'series' => [['name' => 'Alpha', 'data' => [['unknown' => 7]]]],
    ]);
    $report = app(ReadAdminTrafficReport::class)->summary($root->id, '7');
    expect($report['state'])->toBe('available')
        ->and($report['trend'])->toHaveCount(1)
        ->and($report['nodes'][0]['total'])->toBeNull();
});

it('does not interpret malformed or overflowing daily usage as valid report data', function (mixed $value): void {
    $root = met02Root();
    met02Fake([
        'categories' => [gmdate('Y-m-d')], 'sparklineData' => [$value], 'topNodes' => [], 'series' => [],
    ]);
    expect(app(ReadAdminTrafficReport::class)->summary($root->id, '7')['state'])->toBe('unavailable');
})->with([-3, 'bad', '9223372036854775808', 1.5]);

it('fails safely on incomplete top-user data or a remote service error', function (): void {
    $root = met02Root();
    met02Fake(topUsersOk: false);
    expect(app(ReadAdminTrafficReport::class)->nodeUsers($root->id, '7', MET02_NODE)['state'])->toBe('unavailable');
});

it('isolates the new Admin commands and enforces trust/private chat on every callback', function (): void {
    met02Root();
    met02Root(955002, false);
    met02Fake();
    $bot = met02Bot();

    $bot->hearText('/traffic')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('metrics:range:30')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('metrics:node:7:'.MET02_NODE)->reply()->assertCalled('editMessageText');

    Http::assertSentCount(6);
    expect(collect(Http::recorded())->every(static fn (array $pair): bool => $pair[0]->method() === 'GET'))->toBeTrue();

    Http::fake();
    met02Bot(955001, false)->hearText('/traffic')->reply()->assertNoReply();
    met02Bot(955002)->hearCallbackQueryData('metrics:range:7')->reply();
    Http::assertNothingSent();
});

it('denies revoked Super Admin roles before any provider read', function (): void {
    $root = met02Root();
    met02Fake();
    $root->syncRoles(['member']);
    expect(fn () => app(ReadAdminTrafficReport::class)->summary($root->id, '7'))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ReadAdminTrafficReport::class)->nodeUsers($root->id, '7', MET02_NODE))
        ->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

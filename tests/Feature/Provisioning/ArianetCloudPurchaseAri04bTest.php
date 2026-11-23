<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Support\AdminArianetPurchaseUi;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\PurchaseAdminArianetCloud;
use App\Modules\Provisioning\Infrastructure\Arianet\ArianetCloudWriteClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['993301', '993302']);
    config()->set('services.arianet_cloud.read_token', 'ari04b-fixture-token');
    config()->set('services.arianet_cloud.writes_enabled', false);
    config()->set('services.arianet_cloud.purchase_enabled', true);
    config()->set('services.arianet_cloud.timeout', 10);
    Http::preventStrayRequests();
});

function ari04bUser(bool $root = true): User
{
    $user = User::factory()->create();
    $user->syncRoles([$root ? 'super-admin' : 'member']);
    TelegramAccount::query()->create([
        'user_id' => $user->id,
        'telegram_user_id' => $root ? 993301 : 993302,
    ]);

    return $user;
}

/** @return array<string,mixed> */
function ari04bSelection(): array
{
    return [
        'name' => 'DenaServer', 'datacenter' => 'ovh', 'planId' => 7,
        'image' => 'ubuntu-24.04', 'keyId' => 132,
    ];
}

function ari04bFake(bool $newPrice = false, string $post = 'success'): void
{
    // Laravel keeps the first registered fake callback. Let later GETs see
    // updated fixture state without stacking responders or clearing requests.
    config()->set('ari04b_fixture.price_changed', $newPrice);
    config()->set('ari04b_fixture.post_result', $post);
    Http::fake(static function (Request $request) {
        $url = $request->url();
        $base = 'https://api.ariaservice.net/cloud/v1';
        if ($request->method() === 'POST' && $url === $base.'/servers') {
            return match (config('ari04b_fixture.post_result')) {
                'success' => Http::response([
                    'data' => ['id' => 918, 'name' => 'DenaServer'],
                    'servers' => [['data' => ['id' => 918], 'action_id' => 702]],
                    'action_id' => 702, 'root_password' => 'never-log-or-persist',
                ], 201),
                'bad_receipt' => Http::response([
                    'data' => ['id' => 918], 'servers' => [['data' => ['id' => 919], 'action_id' => 702]],
                    'action_id' => 702, 'root_password' => 'never-log-or-persist',
                ], 201),
                default => Http::response(['error' => 'rate-limited'], 429),
            };
        }
        if ($request->method() !== 'GET') {
            return Http::response(['error' => 'Unknown write'], 405);
        }
        if ($url === $base.'/datacenters') {
            return Http::response(['data' => [['code' => 'ovh', 'name' => 'OVH', 'orderable' => true]]]);
        }
        if ($url === $base.'/plans?datacenter=ovh') {
            return Http::response(['data' => [[
                'id' => 7, 'datacenter' => 'ovh', 'name' => 'S1',
                'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 15,
                'traffic_gb' => 50000, 'minimum_usage_hours' => 1,
                'is_active' => true, 'availability' => ['available' => true],
                'currency' => 'IRR',
                'hourly_price' => config('ari04b_fixture.price_changed') ? 19400 : 18770,
                'monthly_price' => config('ari04b_fixture.price_changed') ? 14162000 : 13702100,
            ]]]);
        }
        if (str_starts_with($url, $base.'/images?')) {
            return Http::response(['data' => [[
                'id' => 'ubuntu-24.04', 'image' => 'ubuntu-24.04',
                'type' => 'system', 'name' => 'Ubuntu 24.04',
                'datacenters' => ['ovh'], 'min_disk_gb' => 15,
                'deprecated' => false, 'status' => 'available',
            ]], 'meta' => ['pagination' => ['last_page' => 1]]]);
        }
        if ($url === 'https://api.ariaservice.net/api/v1/ssh-keys/132') {
            return Http::response(['success' => true, 'data' => [
                'id' => 132, 'name' => 'denagate-node-setup',
                'key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFixturedPublicKey== denagate-node-setup',
            ]]);
        }
        if ($url === $base.'/actions/702') {
            return Http::response(['data' => [
                'id' => 702, 'command' => 'create', 'status' => 'success',
                'resource' => ['id' => 918, 'type' => 'server'],
            ]]);
        }
        if ($url === $base.'/servers/918') {
            return Http::response(['data' => ['id' => 918, 'name' => 'DenaServer', 'status' => 'running']]);
        }
        if (str_starts_with($url, $base.'/actions?') || str_starts_with($url, $base.'/servers?')) {
            return Http::response(['data' => []]);
        }

        return Http::response(['error' => 'Unexpected request'], 404);
    });
}

function ari04bOnlyCreatePost(): void
{
    $posts = collect(Http::recorded())->filter(static fn (array $r): bool => $r[0]->method() === 'POST');
    expect($posts)->toHaveCount(1);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
        && $r->url() === 'https://api.ariaservice.net/cloud/v1/servers'
        && $r['count'] === 1
        && $r['name'] === 'DenaServer'
        && $r['datacenter'] === 'ovh'
        && $r['plan_id'] === 7
        && $r['image'] === 'ubuntu-24.04'
        && $r['ssh_key_ids'] === [132]
        && array_keys($r->data()) === ['name', 'datacenter', 'plan_id', 'image', 'ssh_key_ids', 'count']);
}

it('ARI-04B reserves one immutable paid intent before exactly one confirmed POST and GET reconciliation', function (): void {
    ari04bFake();
    $actor = ari04bUser();
    $purchase = app(PurchaseAdminArianetCloud::class);
    $intent = $purchase->prepare($actor->id, ari04bSelection());
    expect($intent['quote']['plan']['hourlyRials'])->toBe(18770)
        ->and(DB::table('arianet_cloud_purchases')->where('id', $intent['id'])->value('status'))->toBe('prepared');
    Http::assertNotSent(static fn (Request $r): bool => $r->method() !== 'GET');

    $accepted = $purchase->confirm($actor->id, $intent['id'], $intent['token']);
    expect($accepted['status'])->toBe('accepted')
        ->and($accepted['serverId'])->toBe(918)
        ->and($accepted['actionId'])->toBe(702)
        ->and($purchase->recent($actor->id))->toBe($intent['id']);
    ari04bOnlyCreatePost();
    expect(json_encode(DB::table('arianet_cloud_purchases')->first(), JSON_THROW_ON_ERROR))
        ->not->toContain('never-log-or-persist', 'BEGIN OPENSSH PRIVATE KEY');

    expect(fn () => $purchase->confirm($actor->id, $intent['id'], $intent['token']))
        ->toThrow(RuntimeException::class);
    expect(fn () => $purchase->prepare($actor->id, ari04bSelection()))
        ->toThrow(RuntimeException::class);
    ari04bOnlyCreatePost();

    $completed = $purchase->reconcile($actor->id, $intent['id']);
    expect($completed['status'])->toBe('completed')
        ->and($completed['serverId'])->toBe(918)
        ->and(DB::table('arianet_cloud_purchases')->where('id', $intent['id'])->value('active_slot'))->toBeNull();
    ari04bOnlyCreatePost();
});

it('ARI-04B rejects stale price, consumes no token, and permits a new fresh quote', function (): void {
    $actor = ari04bUser();
    ari04bFake();
    $purchase = app(PurchaseAdminArianetCloud::class);
    $intent = $purchase->prepare($actor->id, ari04bSelection());
    ari04bFake(newPrice: true);
    expect(fn () => $purchase->confirm($actor->id, $intent['id'], $intent['token']))
        ->toThrow(RuntimeException::class, 'changed');
    expect($purchase->status($actor->id, $intent['id'])['status'])->toBe('expired');
    Http::assertNotSent(static fn (Request $r): bool => $r->method() === 'POST');

    $fresh = $purchase->prepare($actor->id, ari04bSelection());
    expect($fresh['quote']['plan']['hourlyRials'])->toBe(19400)
        ->and($fresh['id'])->not->toBe($intent['id']);
    $purchase->cancel($actor->id, $fresh['id'], $fresh['token']);
    expect($purchase->status($actor->id, $fresh['id'])['status'])->toBe('cancelled');
    expect(fn () => $purchase->cancel($actor->id, $fresh['id'], $fresh['token']))
        ->toThrow(RuntimeException::class);
});

it('ARI-04B never retries an ambiguous provider create', function (string $scenario): void {
    $actor = ari04bUser();
    ari04bFake(post: $scenario);
    $purchase = app(PurchaseAdminArianetCloud::class);
    $intent = $purchase->prepare($actor->id, ari04bSelection());
    $result = $purchase->confirm($actor->id, $intent['id'], $intent['token']);
    expect($result['status'])->toBe('uncertain')
        ->and($result['serverId'])->toBeNull()
        ->and($result['actionId'])->toBeNull();
    ari04bOnlyCreatePost();
    $recheck = $purchase->reconcile($actor->id, $intent['id']);
    expect($recheck['status'])->toBe('uncertain');
    ari04bOnlyCreatePost();
    expect(fn () => $purchase->prepare($actor->id, ari04bSelection()))
        ->toThrow(RuntimeException::class);
})->with([
    'HTTP 429' => ['rate_limit'],
    'mismatched success receipt' => ['bad_receipt'],
]);

it('ARI-04B denies other actors, revocation and the default-off purchase gate without POST', function (): void {
    ari04bFake();
    $actor = ari04bUser();
    $purchase = app(PurchaseAdminArianetCloud::class);
    $intent = $purchase->prepare($actor->id, ari04bSelection());
    $other = User::factory()->create();
    $other->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $other->id, 'telegram_user_id' => 993302]);
    expect(fn () => $purchase->confirm($other->id, $intent['id'], $intent['token']))
        ->toThrow(RuntimeException::class);
    $actor->syncRoles(['member']);
    expect(fn () => $purchase->confirm($actor->id, $intent['id'], $intent['token']))
        ->toThrow(AuthorizationException::class);
    $actor->syncRoles(['super-admin']);
    config()->set('services.arianet_cloud.purchase_enabled', false);
    expect(fn () => $purchase->confirm($actor->id, $intent['id'], $intent['token']))
        ->toThrow(RuntimeException::class, 'disabled');
    Http::assertNotSent(static fn (Request $r): bool => $r->method() === 'POST');
});

it('ARI-04B Production purchase gate is independent of the old IPv4 write permission', function (): void {
    Http::fake(['https://api.ariaservice.net/cloud/v1/servers' => Http::response([
        'data' => ['id' => 918],
        'servers' => [['data' => ['id' => 918], 'action_id' => 702]],
        'action_id' => 702,
    ], 201)]);
    app()->detectEnvironment(static fn (): string => 'production');
    try {
        config()->set('services.arianet_cloud.purchase_enabled', false);
        config()->set('services.arianet_cloud.writes_enabled', true);
        expect(fn () => app(ArianetCloudWriteClient::class)->createServer('DenaServer', 'ovh', 7, 'ubuntu-24.04', 132))
            ->toThrow(RuntimeException::class, 'disabled in production');

        config()->set('services.arianet_cloud.writes_enabled', false);
        config()->set('services.arianet_cloud.purchase_enabled', true);
        expect(app(ArianetCloudWriteClient::class)->createServer('DenaServer', 'ovh', 7, 'ubuntu-24.04', 132))
            ->toBe(['server_id' => 918, 'action_id' => 702]);
        expect(fn () => app(ArianetCloudWriteClient::class)->changeIpv4(42))
            ->toThrow(RuntimeException::class, 'disabled');
        ari04bOnlyCreatePost();
    } finally {
        app()->detectEnvironment(static fn (): string => 'testing');
    }
});

it('ARI-04B exposes the paid action only behind the independent off-by-default switch', function (): void {
    $actor = ari04bUser();
    ari04bFake();
    $draft = ['stage' => 'quote', ...ari04bSelection()];
    Cache::put('ari8:quote:'.$actor->id, $draft, now()->addMinutes(15));

    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('chat')->andReturn(Chat::make(993301, ChatType::PRIVATE));
    $bot->shouldReceive('user')->andReturn(TelegramUser::make(993301, false, 'Operator'));
    $bot->shouldReceive('isCallbackQuery')->andReturn(false);
    $buttons = null;
    $bot->shouldReceive('sendRichMessage')->andReturnUsing(function (...$args) use (&$buttons): null {
        foreach ($args as $argument) {
            if ($argument instanceof InlineKeyboardMarkup) {
                $buttons = json_encode($argument, JSON_THROW_ON_ERROR);
            }
        }

        return null;
    });

    config()->set('services.arianet_cloud.purchase_enabled', false);
    app(AdminArianetPurchaseUi::class)->review($bot);
    expect($buttons)->not->toContain('ari8:purchase:prepare');

    config()->set('services.arianet_cloud.purchase_enabled', true);
    app(AdminArianetPurchaseUi::class)->review($bot);
    expect($buttons)->toContain('ari8:purchase:prepare');
    Http::assertNotSent(static fn (Request $r): bool => $r->method() === 'POST');
});

it('ARI-04B denies a group or delegated Admin UI paid callback without any provider POST', function (): void {
    $member = ari04bUser(false);
    ari04bFake();
    Cache::put('ari8:quote:'.$member->id, ['stage' => 'quote', ...ari04bSelection()], now()->addMinutes(15));
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('chat')->andReturn(Chat::make(993302, ChatType::PRIVATE));
    $bot->shouldReceive('user')->andReturn(TelegramUser::make(993302, false, 'Member'));
    $bot->shouldReceive('isCallbackQuery')->andReturn(false);
    $bot->shouldReceive('sendMessage')->andReturnNull();
    app(AdminArianetPurchaseUi::class)->preparePurchase($bot);
    expect(DB::table('arianet_cloud_purchases')->count())->toBe(0);
    Http::assertNothingSent();
});

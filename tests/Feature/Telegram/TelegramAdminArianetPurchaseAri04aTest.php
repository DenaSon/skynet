<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\PreviewAdminArianetPurchase;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['992501']);
    config()->set('services.arianet_cloud.read_token', 'ari04a-readonly');
    config()->set('services.arianet_cloud.writes_enabled', false);
    Http::preventStrayRequests();
});

function ari04aRoot(bool $isRoot = true): User
{
    $user = User::factory()->create();
    if ($isRoot) {
        $user->syncRoles(['super-admin']);
    }
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 992501]);

    return $user;
}

function ari04aBot(bool $private = true): Nutgram
{
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    require base_path('app/Interfaces/Telegram/routes/legacy_text.php');
    $bot->setCommonUser(TelegramUser::make(992501, false, 'Admin'));
    $bot->setCommonChat(Chat::make($private ? 992501 : -992501,
        $private ? ChatType::PRIVATE : ChatType::GROUP));

    return $bot;
}

/** @return array<string,mixed> */
function ari04aPayload(NutgramRequest $request): array
{
    $payload = FakeNutgram::getActualData($request);
    if (is_string($payload['reply_markup'] ?? null)) {
        $payload['reply_markup'] = json_decode($payload['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $payload;
}

function ari04aHas(NutgramRequest $req, string $callback): bool
{
    return str_contains(json_encode(ari04aPayload($req)['reply_markup'] ?? [], JSON_THROW_ON_ERROR),
        '"callback_data":"'.$callback.'"');
}

function ari04aRich(NutgramRequest $req): string
{
    $payload = ari04aPayload($req);
    $rich = is_string($payload['rich_message'] ?? null)
        ? json_decode($payload['rich_message'], true, flags: JSON_THROW_ON_ERROR)
        : ($payload['rich_message'] ?? []);

    return ($rich['is_rtl'] ?? null) === true ? (string) ($rich['html'] ?? '') : '';
}

function ari04aFake(
    bool $soldOut = false,
    bool $wrongCurrency = false,
    bool $closed = false,
    bool $invalidKey = false,
): void {
    Http::fake(static function (Request $r) use ($soldOut, $wrongCurrency, $closed, $invalidKey) {
        if ($r->method() !== 'GET') {
            return Http::response(['error' => 'No write allowed'], 405);
        }
        $url = $r->url();
        $cloud = 'https://api.ariaservice.net/cloud/v1';
        if ($url === $cloud.'/datacenters') {
            return Http::response(['data' => [
                ['code' => 'ovh', 'name' => 'Frankfurt', 'orderable' => ! $closed],
                ['code' => 'closed', 'name' => 'Closed', 'orderable' => false],
            ]]);
        }
        if ($url === $cloud.'/plans?datacenter=ovh') {
            return Http::response(['data' => [[
                'id' => 8, 'datacenter' => 'ovh',
                'name' => 'S2', 'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40,
                'traffic_gb' => 1000, 'minimum_usage_hours' => 1,
                'is_active' => true, 'availability' => ['available' => ! $soldOut],
                'currency' => $wrongCurrency ? 'USD' : 'IRR',
                'hourly_price' => 20343, 'monthly_price' => 14850390,
            ]]]);
        }
        if (str_starts_with($url, $cloud.'/images?')) {
            return Http::response(['data' => [
                [
                    'id' => 'ubuntu-24.04', 'image' => 'ubuntu-24.04',
                    'type' => 'system', 'name' => 'Ubuntu 24.04',
                    'datacenters' => ['ovh'], 'min_disk_gb' => 20,
                    'deprecated' => false, 'status' => 'available',
                ],
                [
                    'id' => 'centos-9', 'image' => 'centos-9',
                    'type' => 'system', 'name' => 'CentOS',
                    'datacenters' => ['ovh'], 'min_disk_gb' => 20,
                    'deprecated' => false, 'status' => 'available',
                ],
            ], 'meta' => ['pagination' => ['last_page' => 1]]]);
        }
        if ($url === 'https://api.ariaservice.net/api/v1/ssh-keys/7') {
            return $invalidKey
                ? Http::response(['success' => false, 'error' => ['code' => 'NOT_FOUND']], 404)
                : Http::response(['success' => true, 'data' => [
                    'id' => 7, 'name' => 'deployment',
                    'key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFixturedPublicKey== ops@example.com',
                ]]);
        }

        return Http::response(['error' => 'Unexpected URL'], 404);
    });
}

function ari04aOnlyReads(): void
{
    expect(collect(Http::recorded())->every(static fn (array $entry): bool =>
        $entry[0]->method() === 'GET'
            && $entry[0]->hasHeader('Authorization', 'Bearer ari04a-readonly')
            && (str_starts_with($entry[0]->url(), 'https://api.ariaservice.net/cloud/v1/')
                || str_starts_with($entry[0]->url(), 'https://api.ariaservice.net/api/v1/'))))->toBeTrue();
}

it('quotes one existing system image, verified public SSH key and fresh IRR amounts without a write', function (): void {
    $root = ari04aRoot();
    ari04aFake();
    $q = app(PreviewAdminArianetPurchase::class)->quote(
        $root->id, 'ovh', 8, 'ubuntu-24.04', 'denagate-test-01', 7);
    expect($q['name'])->toBe('denagate-test-01')
        ->and($q['plan']['cores'])->toBe(2)
        ->and($q['plan']['hourlyRials'])->toBe(20343)
        ->and($q['plan']['monthlyRials'])->toBe(14850390)
        ->and($q['plan']['trafficGb'])->toBe(1000)
        ->and($q['minimumRials'])->toBe(20343)
        ->and($q['sshKeyName'])->toBe('deployment');
    ari04aOnlyReads();
});

it('blocks sold-out, non-IRR, closed and invalid-image or key quotes without any write', function (): void {
    $root = ari04aRoot();
    foreach (['sold', 'currency', 'closed', 'key', 'image'] as $issue) {
        Http::fake();
        ari04aFake(
            soldOut: $issue === 'sold',
            wrongCurrency: $issue === 'currency',
            closed: $issue === 'closed',
            invalidKey: $issue === 'key',
        );
        expect(fn () => app(PreviewAdminArianetPurchase::class)->quote(
            $root->id, 'ovh', 8, $issue === 'image' ? 'centos-9' : 'ubuntu-24.04',
            'denagate-test', 7))->toThrow(Throwable::class);
    }
    ari04aOnlyReads();
});

it('shows the private RTL quote path and never offers a create or purchase callback', function (): void {
    ari04aRoot();
    ari04aFake();
    $bot = ari04aBot();

    $bot->hearCallbackQueryData('ari2:list:1')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => ari04aHas($r, 'ari8:begin'), index: 1);

    $bot->hearCallbackQueryData('ari8:begin')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => ari04aHas($r, 'ari8:plans:ovh:1')
            && ! ari04aHas($r, 'ari8:plans:closed:1'), index: 1);

    $bot->hearCallbackQueryData('ari8:plans:ovh:1')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => ari04aHas($r, 'ari8:images:ovh:8'), index: 1);

    $bot->hearCallbackQueryData('ari8:images:ovh:8')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool =>
            ari04aHas($r, 'ari8:image:ovh:8:ubuntu-24.04')
            && ! ari04aHas($r, 'ari8:image:ovh:8:centos-9'), index: 1);

    $bot->hearCallbackQueryData('ari8:image:ovh:8:ubuntu-24.04')->reply();
    $bot->hearText('denagate-test-01')->reply();
    $bot->hearText('7')->reply();

    $bot->hearCallbackQueryData('ari8:review')->reply()
        ->assertRaw(static function (NutgramRequest $r): bool {
            $rich = ari04aRich($r);
            $buttons = json_encode(ari04aPayload($r)['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains($rich, 'پیش‌نمایش خرید')
                && str_contains($rich, 'denagate-test-01')
                && str_contains($rich, 'Ubuntu')
                    === false // The selected verified image slug appears instead of an invented label.
                && str_contains($rich, 'ubuntu-24.04')
                && str_contains($rich, 'نرخ ساعتی پلن')
                && str_contains($rich, 'کلید عمومی SSH')
                && str_contains($rich, 'هیچ سفارش یا هزینه‌ای')
                && ! str_contains($buttons, 'ari8:apply')
                && ! str_contains($buttons, 'createServer');
        }, index: 1);
    ari04aOnlyReads();
});

it('denies delegated or group access before any provider GET', function (): void {
    $delegated = ari04aRoot(false);
    Http::fake();
    ari04aBot()->hearCallbackQueryData('ari8:begin')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
    ari04aBot(private: false)->hearCallbackQueryData('ari8:begin')->reply();
    expect(fn () => app(PreviewAdminArianetPurchase::class)->datacenters($delegated->id))
        ->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

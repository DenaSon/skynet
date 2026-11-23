<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ReadAdminArianetCloud;
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

const ARI2_SERVER_ID = 101305418924;

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['991901', '991902']);
    config()->set('services.arianet_cloud.read_token', 'readonly-fixture-key');
    config()->set('services.arianet_cloud.writes_enabled', false);
    config()->set('services.arianet_cloud.write_token', null);
    Http::preventStrayRequests();
});

function ari2Root(bool $root = true, int $telegramId = 991901): User
{
    $user = User::factory()->create();
    if ($root) {
        $user->syncRoles(['super-admin']);
    }
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);

    return $user;
}

function ari2Bot(int $telegramId = 991901, bool $private = true): Nutgram
{
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Root'));
    $bot->setCommonChat(Chat::make($private ? $telegramId : -998999,
        $private ? ChatType::PRIVATE : ChatType::GROUP));

    return $bot;
}

/** @return array<string,mixed> */
function ari2Payload(NutgramRequest $request): array
{
    $payload = FakeNutgram::getActualData($request);
    if (is_string($payload['reply_markup'] ?? null)) {
        $payload['reply_markup'] = json_decode($payload['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $payload;
}

function ari2Html(NutgramRequest $request): string
{
    $payload = ari2Payload($request);
    $rich = is_string($payload['rich_message'] ?? null)
        ? json_decode($payload['rich_message'], true, flags: JSON_THROW_ON_ERROR)
        : ($payload['rich_message'] ?? []);

    return ($rich['is_rtl'] ?? null) === true ? (string) ($rich['html'] ?? '') : '';
}

function ari2Has(NutgramRequest $request, string $callback): bool
{
    return str_contains(
        json_encode(ari2Payload($request)['reply_markup'] ?? [], JSON_THROW_ON_ERROR),
        '"callback_data":"'.$callback.'"',
    );
}

function ari2Fake(bool $brokenCost = false, bool $brokenList = false): void
{
    Http::fake(static function (Request $request) use ($brokenCost, $brokenList) {
        $url = $request->url();
        $base = 'https://api.ariaservice.net/cloud/v1';
        $server = [
            'id' => ARI2_SERVER_ID,
            'name' => '<script>test-node</script>',
            'status' => 'running',
            'datacenter' => 'ovh',
            'plan_id' => 8,
            'disk_gb' => 30,
            'ipv4' => '62.144.51.204',
            'ipv6' => '2001:41d0:f00:da01::349',
            'ip_change' => [
                'allowed' => true, 'free_left' => 0, 'used' => 1,
                'pending' => false, 'currency' => 'IRR', 'fee' => 2500000,
            ],
        ];

        if (str_starts_with($url, $base.'/servers?')) {
            return $brokenList
                ? Http::response(['data' => ['not' => 'a list']], 200)
                : Http::response(['data' => [$server], 'meta' => ['pagination' => [
                    'page' => 1, 'per_page' => 8, 'last_page' => 1, 'total' => 1,
                ]]]);
        }
        if ($url === $base.'/servers/'.ARI2_SERVER_ID) {
            return Http::response(['data' => $server]);
        }
        if ($url === $base.'/servers/'.ARI2_SERVER_ID.'/costs') {
            return $brokenCost ? Http::response(['error' => 'secret-internal'], 503) : Http::response(['data' => [
                'server_id' => ARI2_SERVER_ID, 'hours_per_month' => 730,
                'totals' => [['currency' => 'IRR', 'hourly_price' => 20343, 'monthly_price' => 14850390]],
                'lines' => [['type' => 'server', 'name' => 'S2',
                    'currency' => 'IRR', 'hourly_price' => 20343, 'monthly_price' => 14850390]],
                'snapshots' => ['count' => 0], 'kept_backups' => ['count' => 0],
            ]]);
        }
        if (str_starts_with($url, $base.'/servers/'.ARI2_SERVER_ID.'/actions?')) {
            return Http::response(['data' => [['id' => 55, 'command' => 'change_ipv4',
                'status' => 'success', 'started_at' => '2026-11-21T12:00:00Z']],
                'meta' => ['pagination' => ['page' => 1, 'per_page' => 5, 'last_page' => 1]]]);
        }
        if ($url === $base.'/datacenters') {
            return Http::response(['data' => [['code' => 'ovh', 'name' => 'OVH']]]);
        }
        if ($url === $base.'/plans?datacenter=ovh') {
            return Http::response(['data' => [
                ['id' => 8, 'name' => 'S2', 'cores' => 1, 'memory_mb' => 2048, 'disk_gb' => 30,
                    'prices' => [['location' => 'ovh',
                        'price_hourly' => ['net' => 20343, 'currency' => 'IRR'],
                        'price_monthly' => ['net' => 14850390, 'currency' => 'IRR']]]],
            ]]);
        }

        return Http::response(['error' => 'unexpected API path'], 404);
    });
}

function ari2OnlyReads(): void
{
    expect(collect(Http::recorded())
        ->filter(static fn (array $entry): bool => $entry[0]->method() !== 'GET'
            || ! str_starts_with($entry[0]->url(), 'https://api.ariaservice.net/cloud/v1/')
            || ! $entry[0]->hasHeader('Authorization', 'Bearer readonly-fixture-key'))
        ->count())->toBe(0);
}

it('shows the Cloud entry within existing root server overview without changing existing buttons', function (): void {
    ari2Root();
    config()->set('services.remnawave.url', null);
    Http::fake();
    ari2Bot()->hearCallbackQueryData('menu:admin:servers')->reply()
        ->assertRaw(static fn (NutgramRequest $request): bool => ari2Has($request, 'ari2:list:1')
            && ari2Has($request, 'menu:admin:control')
            && ari2Has($request, 'servers:refresh')
            && ari2Has($request, 'cf2:zones'), index: 1);
    Http::assertNothingSent();
});

it('renders the bounded Cloud inventory as native RTL Rich and preserves paging/back controls', function (): void {
    ari2Root();
    ari2Fake();
    ari2Bot()->hearCallbackQueryData('ari2:list:1')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $html = ari2Html($request);

            return str_contains($html, 'سرورهای ابری')
                && str_contains($html, '&lt;script&gt;test-node&lt;/script&gt;')
                && ! str_contains($html, '<script>')
                && str_contains($html, '۱٬۴۸۵٬۰۳۹')
                && ari2Has($request, 'ari2:view:'.ARI2_SERVER_ID.':1')
                && ari2Has($request, 'ari2:dc')
                && ari2Has($request, 'menu:admin:servers');
        }, index: 1);
    ari2OnlyReads();
    Http::assertSentCount(2);
});

it('renders server details with safe copy button, real IP fee and explicit read-only navigation', function (): void {
    ari2Root();
    ari2Fake();

    ari2Bot()->hearCallbackQueryData('ari2:view:'.ARI2_SERVER_ID.':1')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $html = ari2Html($request);
            $payload = ari2Payload($request);
            $keyboard = json_encode($payload['reply_markup'] ?? [], JSON_THROW_ON_ERROR);

            return str_contains($html, '۶۲') === false
                && str_contains($html, '62.144.51.204')
                && str_contains($html, '۲۵۰٬۰۰۰ تومان')
                && str_contains($keyboard, '"copy_text":{"text":"62.144.51.204"}')
                && ari2Has($request, 'ari2:cost:'.ARI2_SERVER_ID.':1')
                && ari2Has($request, 'ari2:history:'.ARI2_SERVER_ID.':1:1')
                && ! str_contains($keyboard, 'change_ipv4')
                && ! str_contains($keyboard, 'confirm_paid');
        }, index: 1);
    ari2OnlyReads();
});

it('offers costs, action history and datacenter plans as GET-only Rich views', function (): void {
    ari2Root();
    ari2Fake();
    $bot = ari2Bot();

    $bot->hearCallbackQueryData('ari2:cost:'.ARI2_SERVER_ID.':1')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => str_contains(ari2Html($r), '۱٬۴۸۵٬۰۳۹')
            && str_contains(ari2Html($r), 'نرخ ساعتی')
            && ari2Has($r, 'ari2:view:'.ARI2_SERVER_ID.':1'), index: 1);

    $bot->hearCallbackQueryData('ari2:history:'.ARI2_SERVER_ID.':1:1')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => str_contains(ari2Html($r), 'تعویض IPv4')
            && ari2Has($r, 'ari2:view:'.ARI2_SERVER_ID.':1'), index: 1);

    $bot->hearCallbackQueryData('ari2:dc')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => ari2Has($r, 'ari2:plans:ovh:1')
            && ari2Has($r, 'ari2:list:1'), index: 1);

    $bot->hearCallbackQueryData('ari2:plans:ovh:1')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => str_contains(ari2Html($r), '۱٬۴۸۵٬۰۳۹')
            && str_contains(ari2Html($r), 'S2')
            && ari2Has($r, 'ari2:dc'), index: 1);

    ari2OnlyReads();
    Http::assertSentCount(4);
});

it('fails closed for invalid callbacks before provider HTTP requests', function (): void {
    ari2Root();
    Http::fake();
    $bot = ari2Bot();
    foreach (['ari2:list:0', 'ari2:list:101', 'ari2:view:-1:1',
        'ari2:cost:0:1', 'ari2:history:1:1:0',
        'ari2:plans:bad-DC:1', 'ari2:plans:ovh:1000'] as $callback) {
        $bot->hearCallbackQueryData($callback)->reply()
            ->assertRaw(static fn (NutgramRequest $r): bool => (
                ari2Has($r, 'ari2:list:1') || ari2Has($r, 'ari2:dc')
            ), index: 1);
    }
    Http::assertNothingSent();
});

it('denies delegated operators, revoked root and nonprivate access without provider reads', function (): void {
    $delegated = ari2Root(false);
    Http::fake();
    $bot = ari2Bot();
    $bot->hearCallbackQueryData('ari2:list:1')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
    Http::assertNothingSent();

    $delegated->syncRoles(['super-admin']);
    $delegated->syncRoles([]);
    $bot->hearCallbackQueryData('ari2:view:'.ARI2_SERVER_ID.':1')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
    Http::assertNothingSent();

    ari2Bot(private: false)->hearCallbackQueryData('ari2:list:1')->reply();
    Http::assertNothingSent();
});

it('also enforces trusted Super Admin at the application boundary', function (): void {
    $user = ari2Root(false);
    Http::fake();
    expect(fn () => app(ReadAdminArianetCloud::class)->browse($user->id, 1))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ReadAdminArianetCloud::class)->detail($user->id, ARI2_SERVER_ID))
        ->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('does not guess prices or show secret provider errors when reads fail', function (): void {
    ari2Root();
    ari2Fake(brokenCost: true);
    ari2Bot()->hearCallbackQueryData('ari2:view:'.ARI2_SERVER_ID.':1')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => str_contains(ari2Html($r), 'برآورد ماهانه')
            && str_contains(ari2Html($r), 'نامشخص')
            && ! str_contains(ari2Html($r), 'secret-internal'), index: 1);

    ari2Bot()->hearCallbackQueryData('ari2:cost:'.ARI2_SERVER_ID.':1')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => str_contains(
            (string) (ari2Payload($r)['text'] ?? ''), 'دریافت هزینه‌ها ممکن نیست',
        ) && ! str_contains(
            (string) (ari2Payload($r)['text'] ?? ''), 'secret-internal',
        ), index: 1);
    ari2OnlyReads();
});

it('handles malformed provider inventory without claiming server health or leaking secrets', function (): void {
    ari2Root();
    ari2Fake(brokenList: true);
    ari2Bot()->hearCallbackQueryData('ari2:list:1')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => str_contains(
            (string) (ari2Payload($r)['text'] ?? ''), 'دریافت فهرست ممکن نیست',
        ) && ari2Has($r, 'menu:admin:servers'), index: 1);
    ari2OnlyReads();
});

it('rejects mismatched server identity and never invents monetary totals', function (): void {
    $root = ari2Root();
    Http::fake(['https://api.ariaservice.net/cloud/v1/*' => Http::response(['data' => [
        'id' => ARI2_SERVER_ID + 1, 'name' => 'wrong-server',
    ]])]);

    expect(fn () => app(ReadAdminArianetCloud::class)->detail($root->id, ARI2_SERVER_ID))
        ->toThrow(RuntimeException::class);
    ari2OnlyReads();
});

it('renders real Arianet Cloud /plans fields instead of unknown CPU, RAM and prices', function (): void {
    ari2Root();
    // Captured field names and amounts from a real Runflare GET /plans?datacenter=ovh.
    Http::fake(['https://api.ariaservice.net/cloud/v1/plans?datacenter=ovh' => Http::response([
        'data' => [[
            'id' => 18,
            'datacenter' => 'ovh',
            'name' => 'S0',
            'flavor' => 'arianet-s0',
            'vcpu' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 5,
            'traffic_gb' => 50000,
            'hourly_price' => 13973,
            'monthly_price' => 10200290,
            'currency' => 'IRR',
            'is_active' => true,
            'availability' => ['available' => true, 'known' => true],
        ]],
    ])]);

    ari2Bot()->hearCallbackQueryData('ari2:plans:ovh:1')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $html = ari2Html($request);

            return str_contains($html, 'S0 #18')
                && str_contains($html, '۱ CPU')
                && str_contains($html, '۱٬۰۲۴ MB RAM')
                && str_contains($html, '۵ GB')
                && str_contains($html, '۱٬۳۹۸ تومان')
                && str_contains($html, '۱٬۰۲۰٬۰۲۹ تومان')
                && ! str_contains($html, 'نامشخص')
                && ari2Has($request, 'ari2:dc');
        }, index: 1);

    ari2OnlyReads();
    Http::assertSentCount(1);
});

it('keeps unverified or non-IRR Cloud plan prices hidden while showing safe hardware specs', function (): void {
    ari2Root();
    Http::fake(['https://api.ariaservice.net/cloud/v1/plans?datacenter=ovh' => Http::response([
        'data' => [[
            'id' => 18,
            'name' => 'S0',
            'vcpu' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 5,
            'hourly_price' => 13973,
            'monthly_price' => 10200290,
            'currency' => 'USD',
        ]],
    ])]);

    ari2Bot()->hearCallbackQueryData('ari2:plans:ovh:1')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $html = ari2Html($request);

            return str_contains($html, '۱ CPU')
                && str_contains($html, '۱٬۰۲۴ MB RAM')
                && str_contains($html, 'ساعتی: نامشخص')
                && str_contains($html, 'ماهانه: نامشخص')
                && ! str_contains($html, '۱٬۰۲۰٬۰۲۹ تومان');
        }, index: 1);

    ari2OnlyReads();
    Http::assertSentCount(1);
});
it('renders live data-only action history and paginates returned records locally', function (): void {
    ari2Root();
    $actions = [];
    foreach (range(1, 7) as $id) {
        $actions[] = [
            'id' => $id,
            'command' => $id === 7 ? 'reboot' : 'change_ipv4',
            'status' => 'success',
            'progress' => 100,
            'resource' => ['type' => 'server', 'id' => ARI2_SERVER_ID],
            'error' => null,
            'started_at' => '2026-11-21T12:00:00Z',
            'finished_at' => '2026-11-21T12:01:00Z',
        ];
    }

    Http::fake([
        'https://api.ariaservice.net/cloud/v1/servers/'.ARI2_SERVER_ID.'/actions*' =>
            Http::response(['data' => $actions]),
    ]);

    $bot = ari2Bot();
    $bot->hearCallbackQueryData('ari2:history:'.ARI2_SERVER_ID.':1:1')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $html = ari2Html($request);

            return str_contains($html, 'سوابق عملیات سرور')
                && str_contains($html, 'تعویض IPv4 #1')
                && str_contains($html, 'تعویض IPv4 #5')
                && ! str_contains($html, 'راه‌اندازی مجدد #7')
                && ari2Has($request, 'ari2:history:'.ARI2_SERVER_ID.':1:2')
                && ari2Has($request, 'ari2:view:'.ARI2_SERVER_ID.':1');
        }, index: 1);

    $bot->hearCallbackQueryData('ari2:history:'.ARI2_SERVER_ID.':1:2')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $html = ari2Html($request);

            return str_contains($html, 'تعویض IPv4 #6')
                && str_contains($html, 'راه‌اندازی مجدد #7')
                && ! str_contains($html, 'تعویض IPv4 #1')
                && ari2Has($request, 'ari2:history:'.ARI2_SERVER_ID.':1:1')
                && ! ari2Has($request, 'ari2:history:'.ARI2_SERVER_ID.':1:3');
        }, index: 1);

    ari2OnlyReads();
    Http::assertSentCount(2);
});

it('fails closed on malformed Cloud history pagination instead of inventing pages', function (): void {
    ari2Root();
    Http::fake([
        'https://api.ariaservice.net/cloud/v1/servers/'.ARI2_SERVER_ID.'/actions*' =>
            Http::response(['data' => [['id' => 42]], 'meta' => ['unexpected' => 1]]),
    ]);

    ari2Bot()->hearCallbackQueryData('ari2:history:'.ARI2_SERVER_ID.':1:1')->reply()
        ->assertRaw(static fn (NutgramRequest $request): bool => str_contains(
            (string) (ari2Payload($request)['text'] ?? ''), 'دریافت سوابق عملیات ممکن نیست',
        ) && ari2Has($request, 'ari2:view:'.ARI2_SERVER_ID.':1'), index: 1);

    ari2OnlyReads();
    Http::assertSentCount(1);
});

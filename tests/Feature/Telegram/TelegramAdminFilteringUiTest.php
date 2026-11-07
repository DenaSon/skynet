<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Support\AdminFilteringUi;
use App\Interfaces\Telegram\Support\ReplyMenuLabels;
use App\Interfaces\Telegram\Support\ReplyMenuRenderer;
use App\Modules\Filtering\Application\FilteringFoundation;
use App\Modules\Filtering\Application\ReadFilteringCenter;
use App\Modules\Filtering\Jobs\ScanRegionalNodeJob;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-07 18:00:00 UTC'));
    config()->set('super_admin.telegram_ids', ['995001', '995099']);
    config()->set('telegram.hybrid_menu_enabled', true);
    config()->set('filtering.node_monitor_enabled', false);
    config()->set('filtering.regional_monitor_enabled', false);
    config()->set('cache.default', 'array');
    Cache::flush();
    Queue::fake();
    Http::preventStrayRequests();
});

/** @return array<string,mixed> */
function flt04Payload(NutgramRequest $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, 512, JSON_THROW_ON_ERROR);
    }

    return $data;
}

function flt04Has(NutgramRequest $request, string $callback): bool
{
    return str_contains(
        json_encode(flt04Payload($request)['reply_markup'] ?? [], JSON_THROW_ON_ERROR),
        '"callback_data":"'.$callback.'"',
    );
}

function flt04Root(): User
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 995001]);

    return $root;
}

function flt04Bot(int $telegramId = 995001): Nutgram
{
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Operator'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/** The real Admin registry includes the Reply-menu text handler. */
function flt04ReplyBot(int $telegramId = 995001): Nutgram
{
    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Operator'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

function flt04Uuid(int $number): string
{
    return sprintf('550e8400-e29b-41d4-a716-%012d', $number);
}

function flt04Healthy(string $uuid, int $online = 4): void
{
    app(FilteringFoundation::class)->record(
        $uuid, 'node', 'healthy', now()->subMinute()->startOfMinute(),
        ['online_users' => $online, 'rx_bytes_per_sec' => 40, 'tx_bytes_per_sec' => 20],
    );
}

it('appends a root-only danger Reply button after all six existing items while preserving Sales and delegate menus', function (): void {
    flt04Root();
    $actor = app(ResolveTelegramActor::class)->handle(995001);
    expect($actor)->not->toBeNull();
    $keyboard = app(ReplyMenuRenderer::class)->adminKeyboard($actor);
    $labels = array_map(static fn ($button): string => $button->text, array_merge(...$keyboard->keyboard));
    expect($labels)->toBe([
        ReplyMenuLabels::DASHBOARD, ReplyMenuLabels::REPORTS, ReplyMenuLabels::USERS,
        ReplyMenuLabels::FINANCE_SECTION, ReplyMenuLabels::DENABAN_RADAR, ReplyMenuLabels::CONTROL_PANEL,
        ReplyMenuLabels::FILTERING,
    ]);
    expect($keyboard->keyboard[3][0]->style)->toBe(ButtonStyle::DANGER);

    $delegate = User::factory()->admin()->create();
    TelegramAccount::query()->create(['user_id' => $delegate->id, 'telegram_user_id' => 995002]);
    $otherActor = app(ResolveTelegramActor::class)->handle(995002);
    $delegateMarkup = app(ReplyMenuRenderer::class)->adminKeyboard($otherActor);
    $delegateLabels = array_map(static fn ($button): string => $button->text, array_merge(...$delegateMarkup->keyboard));
    expect($delegateLabels)->not->toContain(ReplyMenuLabels::FILTERING);
    expect(ReplyMenuLabels::all())->toContain(ReplyMenuLabels::FILTERING);

    $sales = app(ReplyMenuRenderer::class)->salesKeyboard();
    $salesLabels = array_map(static fn ($button): string => $button->text, array_merge(...$sales->keyboard));
    expect($salesLabels)->not->toContain(ReplyMenuLabels::FILTERING);
});

it('opens trusted private Rich screens and refreshes from stored evidence without network or queue side effects', function (): void {
    flt04Root();
    $uuid = flt04Uuid(401);
    flt04Healthy($uuid);
    config()->set('filtering.node_monitor_enabled', true);
    $bot = flt04Bot();

    flt04ReplyBot()->hearText(ReplyMenuLabels::FILTERING)->reply()->assertCalled('sendRichMessage');
    $bot->hearCallbackQueryData('flt:home')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $p = flt04Payload($request);
            $rich = $p['rich_message'] ?? [];
            $html = is_string($rich) ? (json_decode($rich, true)['html'] ?? '') : ($rich['html'] ?? '');

            return str_contains($html, 'فیلترینگ')
                && str_contains($html, 'نمای کلی')
                && flt04Has($request, 'flt:nodes:1')
                && flt04Has($request, 'flt:history:1')
                && flt04Has($request, 'flt:access:1')
                && flt04Has($request, 'flt:home');
        }, index: 1);

    $bot->hearCallbackQueryData('flt:nodes:1')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => flt04Has($r, 'flt:node:'.flt04Uuid(401)), index: 1);
    $bot->hearCallbackQueryData('flt:access:1')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => flt04Has($r, 'flt:node:'.flt04Uuid(401)), index: 1);
    $bot->hearCallbackQueryData('flt:node:'.$uuid)->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => flt04Has($r, 'flt:recheck:'.flt04Uuid(401)), index: 1);
    $bot->hearCallbackQueryData('flt:home:simple')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => str_contains((string) (flt04Payload($r)['text'] ?? ''), 'فیلترینگ')
            && flt04Has($r, 'flt:nodes:1')
            && flt04Has($r, 'flt:history:1')
            && ! isset(flt04Payload($r)['rich_message']), index: 1);

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('shows validated cached Remnawave names in node, access and incident views without provider reads', function (): void {
    $root = flt04Root();
    $uuid = flt04Uuid(450);
    flt04Healthy($uuid);
    Cache::put(FilteringFoundation::NODE_NAME_CACHE_KEY, [$uuid => 'Amsterdam <NL>'], now()->addDay());

    $reader = app(ReadFilteringCenter::class);
    expect($reader->node($root->id, $uuid)['label'])->toBe('Amsterdam <NL>')
        ->and($reader->nodes($root->id)['rows'][0]['label'])->toBe('Amsterdam <NL>');

    $bot = flt04Bot();
    $bot->hearCallbackQueryData('flt:nodes:1')->reply()->assertRaw(
        static function (NutgramRequest $request): bool {
            $raw = flt04Payload($request)['rich_message'] ?? [];
            $html = is_string($raw) ? (json_decode($raw, true)['html'] ?? '') : ($raw['html'] ?? '');

            return str_contains($html, 'Amsterdam &lt;NL&gt;')
                && flt04Has($request, 'flt:node:'.flt04Uuid(450));
        },
        index: 1,
    );
    $bot->hearCallbackQueryData('flt:access:1')->reply()->assertRaw(
        static fn (NutgramRequest $request): bool => str_contains(
            json_encode(flt04Payload($request), JSON_THROW_ON_ERROR), 'Amsterdam',
        ),
        index: 1,
    );
    $bot->hearCallbackQueryData('flt:node:'.$uuid)->reply()->assertRaw(
        static fn (NutgramRequest $request): bool => str_contains(
            json_encode(flt04Payload($request), JSON_THROW_ON_ERROR), 'Amsterdam',
        ),
        index: 1,
    );

    app(FilteringFoundation::class)->record(
        $uuid, 'node', 'disrupted', now()->startOfMinute(),
        ['online_users' => 0, 'causes' => ['online_zero']],
    );
    $incident = $reader->history($root->id)['rows'][0];
    expect($incident['label'])->toBe('Amsterdam <NL>')
        ->and($reader->incident($root->id, $incident['id'])['label'])->toBe('Amsterdam <NL>');

    Cache::put(FilteringFoundation::NODE_NAME_CACHE_KEY, [$uuid => "Invalid\nName"], now()->addDay());
    expect($reader->node($root->id, $uuid)['label'])->toBe(strtoupper(substr($uuid, 0, 8)));
    Http::assertNothingSent();
});

it('projects fresh, unknown, stale and active composite states without inventing healthy or leaking metadata', function (): void {
    $root = flt04Root();
    $healthy = flt04Uuid(501);
    $disrupted = flt04Uuid(502);
    $stale = flt04Uuid(503);
    config()->set('filtering.node_monitor_enabled', true);
    flt04Healthy($healthy, 8);
    app(FilteringFoundation::class)->record($disrupted, 'node', 'disrupted', now()->subMinute(), [
        'online_users' => 0, 'rx_bytes_per_sec' => 0, 'tx_bytes_per_sec' => 0,
        'causes' => ['online_zero', 'traffic_zero'],
    ]);
    app(FilteringFoundation::class)->record($disrupted, 'regional', 'suspicious', now()->subMinute(), [
        'completed_probes' => 8, 'failed_probes' => 2, 'pending_probes' => 0,
        'asn_count' => 8, 'city_count' => 2, 'failed_asn_count' => 2, 'failed_city_count' => 1,
        'causes' => ['regional_possible'],
    ]);
    app(FilteringFoundation::class)->record($stale, 'node', 'healthy', now()->subMinutes(10), [
        'online_users' => 3,
    ]);
    $data = app(ReadFilteringCenter::class)->overview($root->id);
    expect($data['total'])->toBe(3)
        ->and($data['counts']['healthy'])->toBe(1)
        ->and($data['counts']['disrupted'])->toBe(1)
        ->and($data['counts']['unknown'])->toBe(1);
    $node = app(ReadFilteringCenter::class)->node($root->id, $disrupted);
    expect($node['state'])->toBe('disrupted')
        ->and($node['causes'])->toContain('online_zero', 'traffic_zero', 'regional_possible')
        ->and($node['regional']['failed_probes'])->toBe(2)
        ->and($node['online'])->toBe(0);

    $raw = json_encode($node, JSON_THROW_ON_ERROR);
    expect($raw)->not->toContain('password', 'token', 'address', '8.8.8.8');
    Http::assertNothingSent();
});

it('keeps fresh healthy online activity visible while regional reachability stays unknown', function (): void {
    $root = flt04Root();
    $uuid = flt04Uuid(504);
    config()->set('filtering.node_monitor_enabled', true);
    config()->set('filtering.regional_monitor_enabled', true);

    app(FilteringFoundation::class)->record($uuid, 'node', 'healthy', now()->subMinutes(3), [
        'online_users' => 5,
    ]);
    app(FilteringFoundation::class)->record($uuid, 'regional', 'unknown', now()->subMinutes(9), [
        'completed_probes' => 0, 'pending_probes' => 8,
    ]);

    $data = app(ReadFilteringCenter::class)->node($root->id, $uuid);
    expect($data['state'])->toBe('healthy')
        ->and($data['regional_outcome'])->toBe('unknown')
        ->and($data['node_fresh'])->toBeTrue()
        ->and($data['regional_fresh'])->toBeTrue();

    $this->travel(3)->minutes();
    $stale = app(ReadFilteringCenter::class)->node($root->id, $uuid);
    expect($stale['state'])->toBe('unknown')
        ->and($stale['node_fresh'])->toBeFalse();
});

it('bounds list pagination and preserves open incidents beyond the 24-hour resolved-history window', function (): void {
    $root = flt04Root();
    for ($i = 1; $i <= 13; $i++) {
        flt04Healthy(flt04Uuid(600 + $i));
    }

    $reader = app(ReadFilteringCenter::class);
    $second = $reader->nodes($root->id, 2);
    expect($second['total'])->toBe(13)
        ->and($second['pages'])->toBe(3)
        ->and($second['page'])->toBe(2)
        ->and($second['rows'])->toHaveCount(6)
        ->and($reader->nodes($root->id, 100)['page'])->toBe(3);

    $row = [
        'node_uuid' => flt04Uuid(701), 'active_node_uuid' => null,
        'state' => 'resolved',
        'affected_modules' => json_encode([], JSON_THROW_ON_ERROR),
        'opened_at' => now()->subHours(3), 'last_observed_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
        'resolved_at' => now()->subHours(2),
    ];
    DB::table('filtering_incidents')->insert($row);
    DB::table('filtering_incidents')->insert([
        ...$row, 'node_uuid' => flt04Uuid(702),
        'opened_at' => now()->subDays(3), 'last_observed_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2), 'resolved_at' => now()->subDays(2),
    ]);
    DB::table('filtering_incidents')->insert([
        ...$row, 'node_uuid' => flt04Uuid(703), 'active_node_uuid' => flt04Uuid(703),
        'state' => 'suspicious', 'affected_modules' => json_encode([
            'node' => ['state' => 'suspicious', 'causes' => ['online_zero'], 'observed_at' => now()->subDays(3)->toDateTimeString()],
        ], JSON_THROW_ON_ERROR),
        'opened_at' => now()->subDays(3), 'last_observed_at' => now()->subDays(3),
        'updated_at' => now()->subDays(3), 'resolved_at' => null,
    ]);
    $history = $reader->history($root->id);
    expect($history['total'])->toBe(2);
    $uuids = array_column($history['rows'], 'uuid');
    expect($uuids)->toContain(flt04Uuid(701), flt04Uuid(703))
        ->not->toContain(flt04Uuid(702));
    expect($reader->overview($root->id)['counts']['suspicious'])->toBe(1);
    Http::assertNothingSent();
});

it('guards all read actions, stale keyboards, forged pages and revoked roots', function (): void {
    $root = flt04Root();
    $member = User::factory()->admin()->create();
    TelegramAccount::query()->create(['user_id' => $member->id, 'telegram_user_id' => 995003]);
    $reader = app(ReadFilteringCenter::class);
    expect(fn () => $reader->overview($member->id))->toThrow(AuthorizationException::class);
    $memberReplyBot = flt04ReplyBot(995003);
    $memberReplyBot->hearText(ReplyMenuLabels::FILTERING)->reply()
        ->assertReplyText('دسترسی مجاز نیست. منوی شما به‌روز شد.');
    $memberBot = flt04Bot(995003);
    $memberBot->hearCallbackQueryData('flt:home')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
    $memberBot->hearCallbackQueryData('flt:recheck:'.flt04Uuid(801))->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);

    $rootBot = flt04Bot();
    $rootBot->hearCallbackQueryData('flt:nodes:999')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => str_contains((string) (flt04Payload($r)['text'] ?? ''), 'معتبر نیست'), index: 1);
    $rootBot->hearCallbackQueryData('flt:node:bad-id')->reply()->assertCalled('editMessageText');

    $root->syncRoles(['member']);
    expect(fn () => $reader->overview($root->id))->toThrow(AuthorizationException::class);
    flt04ReplyBot()->hearText(ReplyMenuLabels::FILTERING)->reply()
        ->assertReplyText('دسترسی مجاز نیست. منوی شما به‌روز شد.');
    $rootBot->hearCallbackQueryData('flt:home')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);

    $memberReplyBot->setCommonChat(Chat::make(-995003, ChatType::GROUP));
    $memberReplyBot->hearText(ReplyMenuLabels::FILTERING)->reply()->assertNoReply();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('separates passive refresh from cooldown-limited queued regional rechecks', function (): void {
    $root = flt04Root();
    $node = flt04Uuid(901);
    flt04Healthy($node);
    $reader = app(ReadFilteringCenter::class);
    expect($reader->recheck($root->id, $node))->toBe('disabled');

    config()->set('filtering.regional_monitor_enabled', true);
    expect($reader->recheck($root->id, $node))->toBe('queued')
        ->and($reader->recheck($root->id, $node))->toBe('cooldown')
        ->and($reader->recheck($root->id, flt04Uuid(902)))->toBe('missing');

    Queue::assertPushed(ScanRegionalNodeJob::class, 1);
    $bot = flt04Bot();
    $bot->hearCallbackQueryData('flt:home')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('flt:node:'.$node)->reply()->assertCalled('editMessageText');
    Queue::assertPushed(ScanRegionalNodeJob::class, 1);
    Http::assertNothingSent();
});

it('falls back to native HTML with identical data and buttons after a Rich transport error', function (): void {
    flt04Root();
    flt04Healthy(flt04Uuid(999));
    config()->set('filtering.node_monitor_enabled', true);
    $markup = null;
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('chat')->andReturn(Chat::make(995001, ChatType::PRIVATE));
    $bot->shouldReceive('user')->andReturn(TelegramUser::make(995001, false, 'Admin'));
    $bot->shouldReceive('isCallbackQuery')->andReturn(false);
    $bot->shouldReceive('sendRichMessage')->once()->andReturnUsing(function (...$args) use (&$markup): void {
        foreach ($args as $value) {
            if ($value instanceof InputRichMessage) {
                expect($value->html)->toContain('فیلترینگ', 'نمای کلی', 'نود');
            }
            if ($value instanceof InlineKeyboardMarkup) {
                $markup = $value;
            }
        }
        throw new TelegramException('Bad Request: unsupported RichMessage', 400);
    });
    $bot->shouldReceive('sendMessage')->once()->andReturnUsing(function (...$args) use (&$markup): null {
        expect($args[0])->toContain('فیلترینگ', 'نمای کلی', 'سالم')
            ->not->toContain('<table', '<h2>');
        expect(in_array($markup, $args, true))->toBeTrue()
            ->and(json_encode($markup, JSON_THROW_ON_ERROR))->toContain(
                'flt:nodes:1', 'flt:history:1', 'flt:access:1', 'flt:home',
            );

        return null;
    });

    app(AdminFilteringUi::class)->show($bot);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use App\Interfaces\Telegram\Notifications\TelegramNotificationSender;
use App\Modules\Filtering\Application\FilteringFoundation;
use App\Modules\Filtering\Application\FilteringIncidentAlerts;
use App\Modules\Filtering\Application\FilteringIncidentNotice;
use App\Modules\Filtering\Application\MonitorNodeFiltering;
use App\Modules\Filtering\Application\ScanRegionalFiltering;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

const FLT02_NODE = '550e8400-e29b-41d4-a716-446655440020';
const FLT02_OTHER = '550e8400-e29b-41d4-a716-446655440021';

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-07 12:00:00 UTC'));
    config()->set('filtering.node_monitor_enabled', true);
    config()->set('filtering.node_alerts_enabled', false);
    config()->set('telegram_notifications.enabled', false);
    config()->set('services.remnawave.url', 'https://flt02.test');
    config()->set('services.remnawave.token', 'flt02-test-token');
    config()->set('nutgram.token', '12345:admin_test');
    Http::preventStrayRequests();
    $GLOBALS['flt02_nodes'] = [];
    $GLOBALS['flt02_provider_error'] = false;
    Http::fake(static function (Request $request) {
        if ($request->url() === 'https://flt02.test/api/nodes') {
            return $GLOBALS['flt02_provider_error']
                ? Http::response(['error' => true], 503)
                : Http::response(['response' => $GLOBALS['flt02_nodes']]);
        }
        if (str_starts_with($request->url(), 'https://api.telegram.org/')) {
            return Http::response(['ok' => true, 'result' => ['message_id' => 42]]);
        }

        return Http::response([], 503);
    });
    Queue::fake();
});

/** @return array<string, mixed> */
function flt02Node(
    ?int $online, ?int $rx, ?int $tx,
    string $uuid = FLT02_NODE, bool $disabled = false, bool $connected = true,
): array {
    $interface = [];
    if ($rx !== null) {
        $interface['rxBytesPerSec'] = $rx;
    }
    if ($tx !== null) {
        $interface['txBytesPerSec'] = $tx;
    }

    return [
        'uuid' => $uuid, 'name' => 'Node 02', 'isConnected' => $connected, 'isDisabled' => $disabled,
        'usersOnline' => $online, 'system' => ['stats' => ['interface' => $interface]],
    ];
}

/** @param list<array<string,mixed>> $nodes */
function flt02Feed(array $nodes): void
{
    $GLOBALS['flt02_nodes'] = $nodes;
    $GLOBALS['flt02_provider_error'] = false;
}

function flt02Step(?int $online, ?int $rx, ?int $tx, int $minutes = 2): void
{
    if ($minutes > 0) {
        Carbon::setTestNow(now()->addMinutes($minutes));
    }
    flt02Feed([flt02Node($online, $rx, $tx)]);
    app(MonitorNodeFiltering::class)->scan();
}

it('is OFF by default in deployment settings and registers two-minute scheduler with overlap lock', function (): void {
    $defaults = require base_path('config/filtering.php');
    expect($defaults['node_monitor_enabled'])->toBeFalse()
        ->and($defaults['node_alerts_enabled'])->toBeFalse();
    config()->set('filtering.node_monitor_enabled', false);
    flt02Feed([flt02Node(0, 0, 0)]);
    expect(app(MonitorNodeFiltering::class)->scan())->toBe(0);
    Http::assertNothingSent();

    Artisan::call('list', ['--raw' => true]);
    expect(Artisan::output())->toContain('filtering:nodes:scan');
    $event = collect(Schedule::events())->first(
        static fn ($event): bool => str_contains((string) $event->command, 'filtering:nodes:scan'),
    );
    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/2 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('caches sanitized Remnawave names from the existing node GET without a second provider request', function (): void {
    config()->set('cache.default', 'array');
    \Illuminate\Support\Facades\Cache::flush();

    $named = flt02Node(3, 400, 200);
    $named['name'] = 'Amsterdam <NL>';
    $untrusted = flt02Node(2, 100, 50, uuid: FLT02_OTHER);
    $untrusted['name'] = "Invalid\\nName";
    flt02Feed([$named, $untrusted]);

    expect(app(MonitorNodeFiltering::class)->scan())->toBe(2);
    $names = \Illuminate\Support\Facades\Cache::get(FilteringFoundation::NODE_NAME_CACHE_KEY);
    expect($names)->toBe([FLT02_NODE => 'Amsterdam <NL>']);
    Http::assertSentCount(1);
});

it('opens on two consecutive online zero samples with positive baseline even when traffic stays positive', function (): void {
    flt02Step(2, 100, 200, 0);
    flt02Step(0, 130, 140);
    expect(DB::table('filtering_incidents')->count())->toBe(0);

    flt02Step(0, 120, 110);
    $state = app(FilteringFoundation::class)->read(FLT02_NODE);
    expect($state['state'])->toBe('disrupted')
        ->and($state['affected_modules']['node']['causes'])->toBe(['online_zero'])
        ->and(DB::table('filtering_incidents')->count())->toBe(1);

    // A repeat call in the same minute cannot count as another valid sample.
    app(MonitorNodeFiltering::class)->scan();
    expect(DB::table('filtering_checks')->count())->toBe(3)
        ->and(DB::table('filtering_incidents')->count())->toBe(1);

    flt02Step(2, 100, 300);
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['state'])->toBe('disrupted');
    flt02Step(1, 110, 100);
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['incident_id'])->toBeNull()
        ->and(DB::table('filtering_incidents')->where('state', 'resolved')->count())->toBe(1);
});

it('opens on two consecutive zero traffic snapshots even while online is positive', function (): void {
    flt02Step(5, 100, 50, 0);
    flt02Step(4, 0, 0);
    flt02Step(3, 0, 0);
    $state = app(FilteringFoundation::class)->read(FLT02_NODE);
    expect($state['state'])->toBe('disrupted')
        ->and($state['affected_modules']['node']['causes'])->toBe(['traffic_zero']);

    flt02Step(2, 5, 0);
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['state'])->toBe('disrupted');
    flt02Step(2, 0, 3);
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['incident_id'])->toBeNull();
});

it('requires both affected signals to recover and never uses unknown as recovery', function (): void {
    flt02Step(4, 90, 30, 0);
    flt02Step(0, 0, 0);
    flt02Step(0, 0, 0);
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['affected_modules']['node']['causes'])
        ->toBe(['online_zero', 'traffic_zero']);
    flt02Step(null, null, null);
    flt02Step(2, 0, 0);
    flt02Step(1, 0, 0);
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['affected_modules']['node']['causes'])
        ->toBe(['traffic_zero']);
    flt02Step(3, 50, 0);
    flt02Step(3, 50, 1);
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['incident_id'])->toBeNull();
});

it('recovers a confirmed online signal even when unrelated traffic counters remain unknown', function (): void {
    flt02Step(2, null, null, 0);
    flt02Step(0, null, null);
    flt02Step(0, null, null);
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['affected_modules']['node']['causes'])
        ->toBe(['online_zero']);

    flt02Step(3, null, null);
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['state'])->toBe('disrupted');
    flt02Step(2, null, null);
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['incident_id'])->toBeNull();
});

it('recovers a confirmed traffic signal even when the unrelated online count is unknown', function (): void {
    flt02Step(null, 20, 10, 0);
    flt02Step(null, 0, 0);
    flt02Step(null, 0, 0);
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['affected_modules']['node']['causes'])
        ->toBe(['traffic_zero']);

    flt02Step(null, 30, 0);
    flt02Step(null, 30, 1);
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['incident_id'])->toBeNull();
});

it('breaks zero streak on failed provider read, stale interval and disabled node', function (): void {
    flt02Step(1, 100, 30, 0);
    flt02Step(0, 100, 30);
    $this->travel(1)->minutes();
    $GLOBALS['flt02_provider_error'] = true;
    expect(app(MonitorNodeFiltering::class)->scan())->toBe(0);
    expect(DB::table('filtering_incidents')->count())->toBe(0);

    flt02Step(0, 100, 30);
    expect(DB::table('filtering_incidents')->count())->toBe(0);
    flt02Step(0, 100, 30);
    expect(DB::table('filtering_incidents')->count())->toBe(1);

    flt02Feed([flt02Node(0, 0, 0, disabled: true)]);
    $this->travel(1)->minutes();
    expect(app(MonitorNodeFiltering::class)->scan())->toBe(0);
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['state'])->toBe('disrupted');
});

it('keeps never-active nodes and unknown rates from raising traffic-zero alerts', function (): void {
    flt02Step(0, 0, 0, 0);
    flt02Step(0, 0, 0);
    flt02Step(0, 0, 0);
    expect(DB::table('filtering_incidents')->count())->toBe(0);

    flt02Step(3, null, null);
    flt02Step(3, null, null);
    flt02Step(3, null, null);
    expect(DB::table('filtering_incidents')->count())->toBe(0);
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['latest']['node']['outcome'])->toBe('healthy');

    // No baseline inside the 30 minute window, even after consecutive zeros.
    $this->travel(31)->minutes();
    flt02Step(0, 0, 0, 0);
    flt02Step(0, 0, 0);
    expect(DB::table('filtering_incidents')->count())->toBe(0);
});

it('shows positive online activity as healthy with missing rates but retains independent zero-traffic alert', function (): void {
    flt02Step(5, null, null, 0);
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['latest']['node']['outcome'])->toBe('healthy');
    flt02Step(4, null, 0);
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['latest']['node']['outcome'])->toBe('healthy');
    flt02Step(3, 0, 0);
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['incident_id'])->toBeNull();
    flt02Step(2, 0, 0);
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['affected_modules']['node']['causes'])
        ->toBe(['traffic_zero']);
});

it('requires valid consecutive two-minute observations and resets after a four-minute gap', function (): void {
    flt02Step(5, 50, 50, 0);
    flt02Step(0, 50, 50);
    flt02Step(0, 50, 50, 4);
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['incident_id'])->toBeNull();
    flt02Step(0, 50, 50);
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['affected_modules']['node']['causes'])
        ->toBe(['online_zero']);
});

it('does not turn provider failures or disappeared Nodes into false recovery', function (): void {
    flt02Step(2, 10, 10, 0);
    flt02Step(0, 10, 10);
    flt02Step(0, 10, 10);
    $old = app(FilteringFoundation::class)->read(FLT02_NODE)['incident_id'];

    $this->travel(1)->minutes();
    flt02Feed([flt02Node(4, 40, 40, uuid: FLT02_OTHER)]);
    app(MonitorNodeFiltering::class)->scan();
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['incident_id'])->toBe($old);

    // Restarting the monitor instance cannot erase DB-owned active state.
    $fresh = new MonitorNodeFiltering(
        app(RemnawaveApiClientFactory::class),
        app(FilteringFoundation::class),
        app(FilteringIncidentAlerts::class),
        app(ScanRegionalFiltering::class),
    );
    flt02Feed([flt02Node(2, 10, 10)]);
    $this->travel(1)->minutes();
    $fresh->scan();
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['incident_id'])->toBe($old);
});

it('records a single durable root notice and one recovery without leaking to sales or revoked roles', function (): void {
    config()->set('filtering.node_alerts_enabled', true);
    config()->set('telegram_notifications.enabled', true);
    config()->set('super_admin.telegram_ids', ['9980021', '9980022']);
    Role::findOrCreate('super-admin', 'web');
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 9980021]);

    flt02Step(1, 10, 10, 0);
    flt02Step(0, 100, 100);
    flt02Step(0, 100, 100);

    $opened = DB::table('telegram_notification_deliveries')->where('event_type', 'FilteringOpened')->first();
    expect($opened)->not->toBeNull()
        ->and($opened->bot_key)->toBe('admin')
        ->and((int) $opened->recipient_user_id)->toBe($root->id)
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(1);

    // The new root filtering center is discoverable from the existing alert.
    $notice = app(FilteringIncidentNotice::class)->messageFor($opened);
    expect($notice)->toContain('/filtering')
        ->not->toContain('flt:home', 'token', 'password');

    app(DispatchTelegramNotification::class, ['deliveryId' => (int) $opened->id])
        ->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->where('id', $opened->id)->value('state'))->toBe('sent');
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/bot12345:admin_test/sendMessage'));

    flt02Step(0, 100, 100);
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'FilteringOpened')->count())->toBe(1);

    flt02Step(3, 100, 100);
    flt02Step(4, 100, 100);
    $recovered = DB::table('telegram_notification_deliveries')->where('event_type', 'FilteringRecovered')->first();
    expect($recovered)->not->toBeNull()
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(2);

    $root->forceFill(['is_active' => false])->save();
    app(DispatchTelegramNotification::class, ['deliveryId' => (int) $recovered->id])
        ->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->where('id', $recovered->id)->value('state'))
        ->toBe('unavailable');
});

it('uses only one provider GET for Node snapshots and rejects malformed identities', function (): void {
    flt02Feed([flt02Node(1, 100, 10)]);
    expect(app(MonitorNodeFiltering::class)->scan())->toBe(1);
    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://flt02.test/api/nodes');

    $this->travel(1)->minutes();
    flt02Feed([flt02Node(0, 0, 0, uuid: 'unexpected')]);
    expect(app(MonitorNodeFiltering::class)->scan())->toBe(0);
    expect(DB::table('filtering_incidents')->count())->toBe(0);
});

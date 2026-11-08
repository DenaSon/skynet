<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use App\Interfaces\Telegram\Notifications\TelegramNotificationSender;
use App\Modules\Filtering\Application\FilteringFoundation;
use App\Modules\Filtering\Application\FilteringGroupDestination;
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
use Illuminate\Support\Facades\Cache;
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
    $GLOBALS['flt02_telegram_forbidden'] = false;
    Http::fake(static function (Request $request) {
        if ($request->url() === 'https://flt02.test/api/nodes') {
            return $GLOBALS['flt02_provider_error']
                ? Http::response(['error' => true], 503)
                : Http::response(['response' => $GLOBALS['flt02_nodes']]);
        }
        if (str_starts_with($request->url(), 'https://api.telegram.org/')) {
            return $GLOBALS['flt02_telegram_forbidden']
                ? Http::response(['ok' => false], 403)
                : Http::response(['ok' => true, 'result' => ['message_id' => 42]]);
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

function flt02EnableRootNotifications(): void
{
    config()->set('filtering.notification_group_chat_id', '-1001234567890');
    config()->set('filtering.node_alerts_enabled', true);
    config()->set('telegram_notifications.enabled', true);
    config()->set('super_admin.telegram_ids', ['9980021', '9980022']);
    Role::findOrCreate('super-admin', 'web');

    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 9980021]);
}

it('preserves all AMS-02-like low-activity incidents and measurements without sending online-only notices', function (): void {
    flt02EnableRootNotifications();

    for ($cycle = 0; $cycle < 9; $cycle++) {
        flt02Step(1, null, null, $cycle === 0 ? 0 : 2);
        flt02Step(0, null, null);
        flt02Step(0, null, null);
        expect(app(FilteringFoundation::class)->read(FLT02_NODE)['state'])->toBe('disrupted');
        for ($i = 0; $i < 6; $i++) {
            flt02Step(1, null, null);
        }
        expect(app(FilteringFoundation::class)->read(FLT02_NODE)['incident_id'])->toBeNull();
    }

    expect(DB::table('filtering_incidents')->count())->toBe(9)
        ->and(DB::table('filtering_checks')->where('module', 'node')->count())->toBe(81)
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(0);
});

it('does not notify on a solitary three-user spike in a low-activity window', function (): void {
    flt02EnableRootNotifications();
    flt02Step(1, null, null, 0);
    flt02Step(3, null, null);
    flt02Step(1, null, null);
    flt02Step(0, null, null);
    flt02Step(0, null, null);

    expect(DB::table('filtering_incidents')->count())->toBe(1)
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(0);
});

it('retains independent traffic-zero alerts on low-user nodes', function (): void {
    flt02EnableRootNotifications();
    flt02Step(1, 100, 100, 0);
    flt02Step(1, 0, 0);
    flt02Step(1, 0, 0);

    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['affected_modules']['node']['causes'])
        ->toBe(['traffic_zero'])
        ->and(DB::table('telegram_notification_deliveries')->where('event_type', 'FilteringOpened')->count())
        ->toBe(1);
});

it('notifies when confirmed traffic-zero joins an unnotified online-only incident', function (): void {
    flt02EnableRootNotifications();
    flt02Step(1, 100, 100, 0);
    flt02Step(0, 100, 100);
    flt02Step(0, 100, 100);

    expect(DB::table('filtering_incidents')->count())->toBe(1)
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(0);

    flt02Step(0, 0, 0);
    flt02Step(0, 0, 0);

    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['affected_modules']['node']['causes'])
        ->toBe(['online_zero', 'traffic_zero'])
        ->and(DB::table('filtering_incidents')->count())->toBe(1)
        ->and(DB::table('telegram_notification_deliveries')->where('event_type', 'FilteringOpened')->count())
        ->toBe(1);
});

it('throttles repeated online-only openings for thirty minutes without suppressing new incidents', function (): void {
    flt02EnableRootNotifications();
    flt02Step(3, 10, 10, 0);
    flt02Step(4, 10, 10);
    flt02Step(0, 10, 10);
    flt02Step(0, 10, 10);
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'FilteringOpened')->count())
        ->toBe(1);

    $opening = DB::table('telegram_notification_deliveries')->where('event_type', 'FilteringOpened')->first();
    app(DispatchTelegramNotification::class, ['deliveryId' => (int) $opening->id])
        ->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->where('id', $opening->id)->value('state'))
        ->toBe('sent');

    for ($i = 0; $i < 6; $i++) {
        flt02Step(4, 10, 10);
    }
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['incident_id'])->toBeNull();

    flt02Step(0, 10, 10);
    flt02Step(0, 10, 10);
    expect(DB::table('filtering_incidents')->count())->toBe(2)
        ->and(DB::table('telegram_notification_deliveries')->where('event_type', 'FilteringOpened')->count())
        ->toBe(1);

    for ($i = 0; $i < 6; $i++) {
        flt02Step(4, 10, 10);
    }
    $this->travel(20)->minutes();
    flt02Step(4, 10, 10, 0);
    flt02Step(4, 10, 10);
    flt02Step(0, 10, 10);
    flt02Step(0, 10, 10);
    expect(DB::table('filtering_incidents')->count())->toBe(3)
        ->and(DB::table('telegram_notification_deliveries')->where('event_type', 'FilteringOpened')->count())
        ->toBe(2);
});

it('does not hide a new opening after an older notification was only queued', function (): void {
    flt02EnableRootNotifications();
    flt02Step(3, 10, 10, 0);
    flt02Step(4, 10, 10);
    flt02Step(0, 10, 10);
    flt02Step(0, 10, 10);
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'FilteringOpened')
        ->where('state', 'pending')->count())->toBe(1);

    for ($i = 0; $i < 6; $i++) {
        flt02Step(4, 10, 10);
    }
    flt02Step(0, 10, 10);
    flt02Step(0, 10, 10);

    expect(DB::table('filtering_incidents')->count())->toBe(2)
        ->and(DB::table('telegram_notification_deliveries')->where('event_type', 'FilteringOpened')->count())
        ->toBe(2);
});

it('resets the ten-minute recovery window after an unavailable observation', function (): void {
    flt02Step(3, 10, 10, 0);
    flt02Step(0, 10, 10);
    flt02Step(0, 10, 10);
    for ($i = 0; $i < 4; $i++) {
        flt02Step(3, 10, 10);
    }
    flt02Step(null, null, null);
    flt02Step(3, 10, 10);
    flt02Step(3, 10, 10);
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['state'])->toBe('disrupted');
    for ($i = 0; $i < 4; $i++) {
        flt02Step(3, 10, 10);
    }
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['incident_id'])->toBeNull();
});

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
    Cache::flush();

    $named = flt02Node(3, 400, 200);
    $named['name'] = 'Amsterdam <NL>';
    $untrusted = flt02Node(2, 100, 50, uuid: FLT02_OTHER);
    $untrusted['name'] = "Invalid\nName";
    flt02Feed([$named, $untrusted]);

    expect(app(MonitorNodeFiltering::class)->scan())->toBe(2);
    $names = Cache::get(FilteringFoundation::NODE_NAME_CACHE_KEY);
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
    for ($i = 0; $i < 4; $i++) {
        flt02Step(1, 110, 100);
    }
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
    for ($i = 0; $i < 4; $i++) {
        flt02Step(2, 0, 3);
    }
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
    for ($i = 0; $i < 4; $i++) {
        flt02Step(1, 0, 0);
    }
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['affected_modules']['node']['causes'])
        ->toBe(['traffic_zero']);
    for ($i = 0; $i < 6; $i++) {
        flt02Step(3, 50, 1);
    }
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
    for ($i = 0; $i < 4; $i++) {
        flt02Step(2, null, null);
    }
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
    for ($i = 0; $i < 4; $i++) {
        flt02Step(null, 30, 1);
    }
    expect(app(FilteringFoundation::class)->read(FLT02_NODE)['state'])->toBe('disrupted');
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

it('sends exactly one group notice per incident and protects recovery after root revocation', function (): void {
    config()->set('filtering.notification_group_chat_id', '-1001234567890');
    config()->set('filtering.node_alerts_enabled', true);
    config()->set('telegram_notifications.enabled', true);
    config()->set('super_admin.telegram_ids', ['9980021', '9980022']);
    Role::findOrCreate('super-admin', 'web');

    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 9980021]);
    $otherRoot = User::factory()->create();
    $otherRoot->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $otherRoot->id, 'telegram_user_id' => 9980022]);

    flt02Step(3, 10, 10, 0);
    flt02Step(4, 10, 10);
    flt02Step(0, 100, 100);
    flt02Step(0, 100, 100);

    $opened = DB::table('telegram_notification_deliveries')->where('event_type', 'FilteringOpened')->first();
    expect($opened)->not->toBeNull()
        ->and($opened->bot_key)->toBe('admin')
        ->and($opened->event_key)->toBe('flt:1:open:group:-1001234567890')
        ->and((int) $opened->recipient_user_id)->toBe($root->id)
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(1);

    $notice = app(FilteringIncidentNotice::class)->messageFor($opened);
    expect($notice)->toContain('⚠️ نیازمند بررسی —', 'نشانه: کاربران آنلاین صفر', '🕒', '(تهران)')
        ->not->toContain('شناسه:', 'UTC', 'گفتگوی خصوصی', 'اثبات', 'token', 'password');
    expect(substr_count($notice, "\n"))->toBe(2);

    app(DispatchTelegramNotification::class, ['deliveryId' => (int) $opened->id])
        ->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->where('id', $opened->id)->value('state'))->toBe('sent');
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/bot12345:admin_test/sendMessage')
        && (string) $request->data()['chat_id'] === '-1001234567890'
        && ! array_key_exists('message_thread_id', $request->data()));
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/sendMessage')
        && (string) $request->data()['chat_id'] === '9980021');

    $escalation = (object) [
        'event_type' => 'FilteringEscalated',
        'event_key' => 'flt:1:escalate:group:-1001234567890',
        'bot_key' => 'admin',
    ];
    $escalationNotice = app(FilteringIncidentNotice::class)->messageFor($escalation);
    expect($escalationNotice)->toContain('🚨 اختلال ثبت‌شده —', 'نشانه: کاربران آنلاین صفر', '🕒')
        ->not->toContain('شناسه:', 'UTC', 'اثبات');
    expect(substr_count($escalationNotice, "\n"))->toBe(2);

    flt02Step(0, 100, 100);
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'FilteringOpened')->count())->toBe(1);

    flt02Step(3, 100, 100);
    for ($i = 0; $i < 5; $i++) {
        flt02Step(4, 100, 100);
    }
    $recovered = DB::table('telegram_notification_deliveries')->where('event_type', 'FilteringRecovered')->first();
    expect($recovered)->not->toBeNull()
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(2);

    $recoveryNotice = app(FilteringIncidentNotice::class)->messageFor($recovered);
    expect($recoveryNotice)->toContain('✅ وضعیت عادی شد —', '🕒', '(تهران)')
        ->not->toContain('نشانه:', 'شناسه:', 'UTC', 'اثبات', 'گفتگوی خصوصی');
    expect(substr_count($recoveryNotice, "\n"))->toBe(1);

    $root->forceFill(['is_active' => false])->save();
    app(DispatchTelegramNotification::class, ['deliveryId' => (int) $recovered->id])
        ->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->where('id', $recovered->id)->value('state'))
        ->toBe('unavailable');
});

it('fails closed with missing or malformed group ID and never falls back to private chats', function (): void {
    config()->set('filtering.node_alerts_enabled', true);
    config()->set('telegram_notifications.enabled', true);
    config()->set('super_admin.telegram_ids', ['9980021', '9980022']);
    Role::findOrCreate('super-admin', 'web');
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 9980021]);

    foreach ([null, '', '9980021', '100', '-1abc', '-999999999999999999999999'] as $invalid) {
        config()->set('filtering.notification_group_chat_id', $invalid);
        expect(FilteringGroupDestination::chatId())->toBeNull();
    }
    flt02Step(3, 10, 10, 0);
    flt02Step(4, 10, 10);
    flt02Step(0, 100, 100);
    flt02Step(0, 100, 100);

    expect(DB::table('filtering_incidents')->count())->toBe(1)
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(0);
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/sendMessage'));
});

it('rejects old private filtering outbox intents and group destination changes before transport', function (): void {
    config()->set('filtering.notification_group_chat_id', '-1001234567890');
    config()->set('filtering.node_alerts_enabled', true);
    config()->set('telegram_notifications.enabled', true);
    config()->set('super_admin.telegram_ids', ['9980021', '9980022']);
    Role::findOrCreate('super-admin', 'web');
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 9980021]);

    flt02Step(3, 10, 10, 0);
    flt02Step(4, 10, 10);
    flt02Step(0, 100, 100);
    flt02Step(0, 100, 100);

    $group = DB::table('telegram_notification_deliveries')->where('event_type', 'FilteringOpened')->first();
    $legacyId = DB::table('telegram_notification_deliveries')->insertGetId([
        'event_key' => 'flt:1:open:root:'.$root->id,
        'event_type' => 'FilteringOpened',
        'recipient_user_id' => $root->id,
        'bot_key' => 'admin',
        'state' => 'pending',
        'available_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    app(DispatchTelegramNotification::class, ['deliveryId' => (int) $legacyId])
        ->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->where('id', $legacyId)->value('state'))
        ->toBe('unavailable');

    config()->set('filtering.notification_group_chat_id', '-1009988776655');
    app(DispatchTelegramNotification::class, ['deliveryId' => (int) $group->id])
        ->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->where('id', $group->id)->value('state'))
        ->toBe('unavailable');
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/sendMessage'));
});

it('delivers group recovery only after a successfully sent group opening', function (): void {
    config()->set('filtering.notification_group_chat_id', '-1001234567890');
    config()->set('filtering.node_alerts_enabled', true);
    config()->set('telegram_notifications.enabled', true);
    config()->set('super_admin.telegram_ids', ['9980021', '9980022']);
    Role::findOrCreate('super-admin', 'web');
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 9980021]);

    flt02Step(3, 10, 10, 0);
    flt02Step(4, 10, 10);
    flt02Step(0, 100, 100);
    flt02Step(0, 100, 100);
    $opening = DB::table('telegram_notification_deliveries')->where('event_type', 'FilteringOpened')->first();
    app(DispatchTelegramNotification::class, ['deliveryId' => (int) $opening->id])
        ->handle(app(TelegramNotificationSender::class));

    for ($i = 0; $i < 6; $i++) {
        flt02Step(2, 100, 100);
    }
    $recovery = DB::table('telegram_notification_deliveries')->where('event_type', 'FilteringRecovered')->first();
    expect($recovery)->not->toBeNull()
        ->and($recovery->event_key)->toBe('flt:1:recover:group:-1001234567890');
    app(DispatchTelegramNotification::class, ['deliveryId' => (int) $recovery->id])
        ->handle(app(TelegramNotificationSender::class));

    expect(DB::table('telegram_notification_deliveries')->where('state', 'sent')->count())->toBe(2);
    $posts = Http::recorded(static fn (Request $r): bool => str_contains($r->url(), '/sendMessage'));
    expect($posts)->toHaveCount(2);
    foreach ($posts as [$request]) {
        expect((string) $request->data()['chat_id'])->toBe('-1001234567890');
    }
});

it('never sends an already queued group notice if the ENV destination is removed', function (): void {
    config()->set('filtering.notification_group_chat_id', '-1001234567890');
    config()->set('filtering.node_alerts_enabled', true);
    config()->set('telegram_notifications.enabled', true);
    config()->set('super_admin.telegram_ids', ['9980021', '9980022']);
    Role::findOrCreate('super-admin', 'web');
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 9980021]);

    flt02Step(3, 10, 10, 0);
    flt02Step(4, 10, 10);
    flt02Step(0, 100, 100);
    flt02Step(0, 100, 100);
    $opening = DB::table('telegram_notification_deliveries')->where('event_type', 'FilteringOpened')->first();

    config()->set('filtering.notification_group_chat_id', null);
    app(DispatchTelegramNotification::class, ['deliveryId' => (int) $opening->id])
        ->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->where('id', $opening->id)->value('state'))
        ->toBe('unavailable');
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/sendMessage'));
});

it('does not mark a private Admin contact blocked when the group rejects a message', function (): void {
    config()->set('filtering.notification_group_chat_id', '-1001234567890');
    config()->set('filtering.node_alerts_enabled', true);
    config()->set('telegram_notifications.enabled', true);
    config()->set('super_admin.telegram_ids', ['9980021', '9980022']);
    Role::findOrCreate('super-admin', 'web');
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 9980021]);
    DB::table('telegram_bot_contacts')->insert([
        'user_id' => $root->id,
        'bot_key' => 'admin',
        'chat_id' => 9980021,
        'delivery_status' => 'reachable',
        'last_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    flt02Step(3, 10, 10, 0);
    flt02Step(4, 10, 10);
    flt02Step(0, 100, 100);
    flt02Step(0, 100, 100);
    $opening = DB::table('telegram_notification_deliveries')->where('event_type', 'FilteringOpened')->first();

    $GLOBALS['flt02_telegram_forbidden'] = true;
    app(DispatchTelegramNotification::class, ['deliveryId' => (int) $opening->id])
        ->handle(app(TelegramNotificationSender::class));

    expect(DB::table('telegram_notification_deliveries')->where('id', $opening->id)->value('state'))
        ->toBe('unavailable')
        ->and(DB::table('telegram_bot_contacts')->where('user_id', $root->id)->value('delivery_status'))
        ->toBe('reachable');
});

it('opens in the group for an existing incident previously notified only in private', function (): void {
    flt02EnableRootNotifications();
    $rootId = (int) DB::table('users')->value('id');

    config()->set('filtering.notification_group_chat_id', null);
    flt02Step(3, 100, 100, 0);
    flt02Step(4, 100, 100);
    flt02Step(0, 100, 100);
    flt02Step(0, 100, 100);
    expect(DB::table('filtering_incidents')->count())->toBe(1)
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(0);

    DB::table('filtering_incidents')->where('id', 1)->update([
        'last_notified_state' => 'disrupted', 'last_notified_at' => now(),
    ]);
    DB::table('telegram_notification_deliveries')->insert([
        'event_key' => 'flt:1:open:root:'.$rootId,
        'event_type' => 'FilteringOpened',
        'recipient_user_id' => $rootId,
        'bot_key' => 'admin',
        'state' => 'sent',
        'available_at' => now(),
        'sent_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    config()->set('filtering.notification_group_chat_id', '-1001234567890');
    flt02Step(0, 100, 100);
    $group = DB::table('telegram_notification_deliveries')
        ->where('event_key', 'flt:1:open:group:-1001234567890')->first();
    expect($group)->not->toBeNull()
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(2);
    app(DispatchTelegramNotification::class, ['deliveryId' => (int) $group->id])
        ->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->where('id', $group->id)->value('state'))
        ->toBe('sent');
    Http::assertNotSent(static fn (Request $r): bool => str_contains($r->url(), '/sendMessage')
        && (string) $r->data()['chat_id'] === '9980021');
});

it('initializes a new group opening when group ID rotates during an active incident', function (): void {
    flt02EnableRootNotifications();
    flt02Step(3, 100, 100, 0);
    flt02Step(4, 100, 100);
    flt02Step(0, 100, 100);
    flt02Step(0, 100, 100);
    $old = DB::table('telegram_notification_deliveries')->where('event_type', 'FilteringOpened')->first();
    app(DispatchTelegramNotification::class, ['deliveryId' => (int) $old->id])
        ->handle(app(TelegramNotificationSender::class));

    config()->set('filtering.notification_group_chat_id', '-1009988776655');
    flt02Step(0, 100, 100);
    $new = DB::table('telegram_notification_deliveries')
        ->where('event_key', 'flt:1:open:group:-1009988776655')->first();
    expect($new)->not->toBeNull()
        ->and(DB::table('telegram_notification_deliveries')->where('event_type', 'FilteringOpened')->count())
        ->toBe(2);
    app(DispatchTelegramNotification::class, ['deliveryId' => (int) $new->id])
        ->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->where('id', $new->id)->value('state'))
        ->toBe('sent');
    Http::assertSent(static fn (Request $r): bool => str_contains($r->url(), '/sendMessage')
        && (string) $r->data()['chat_id'] === '-1009988776655');
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

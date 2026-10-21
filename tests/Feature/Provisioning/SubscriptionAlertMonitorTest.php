<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use App\Interfaces\Telegram\Notifications\TelegramNotificationSender;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\SubscriptionAlertMonitor;
use App\Modules\Sales\Application\CalculateQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Sales\Application\CreatePendingOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-21T12:00:00Z'));
    config()->set('telegram_notifications.enabled', true);
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram.sales.token', '54321:sales_test');
    config()->set('nutgram.token', '12345:admin_test');
    config()->set('services.remnawave.url', 'https://remna.test');
    config()->set('services.remnawave.token', 'test-token');
    config()->set('services.remnawave.writes_enabled', false);
    Queue::fake();
    Http::preventStrayRequests();

    app(ConfigureProduct::class)->handle('sub-alert', 'پلن آزمایشی');
    app(ConfigureRate::class)->handle('sub-alert', 30, 100_000, 20_000);
});

/** @return array{order:int,buyer:User,reference:string} */
function subAlertFixture(int $telegramId = 998301): array
{
    $buyer = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $buyer->id, 'telegram_user_id' => $telegramId]);
    DB::table('telegram_bot_contacts')->insert([
        'user_id' => $buyer->id, 'bot_key' => 'sales',
        'chat_id' => $telegramId, 'delivery_status' => 'reachable',
        'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $quote = app(CalculateQuote::class)->handle($buyer->id, 'sub-alert', 30, 10);
    $order = app(CreatePendingOrder::class)->handle($buyer->id, $quote, bin2hex(random_bytes(16)))->id;
    DB::table('sales_orders')->where('id', $order)->update(['status' => 'fulfilled']);

    $reference = 'sub-alert:'.$order;
    $subscription = DB::table('provisioning_subscriptions')->insertGetId([
        'reference' => $reference,
        'remote_username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
        'remote_user_id' => 42, 'state' => 'active',
        'last_operation_key' => 'sub-alert-create:'.$order,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('provisioning_order_links')->insert([
        'sales_order_id' => $order, 'provisioning_subscription_id' => $subscription,
        'created_at' => now(),
    ]);

    return ['buyer' => $buyer, 'order' => $order, 'reference' => $reference];
}

/** @return array<string, mixed> */
function subAlertRemote(string $reference, string $expire, int $used = 0, int $limit = 1073741824): array
{
    return [
        'id' => 42,
        'username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
        'description' => 'SkyNetwork ref:'.hash('sha256', $reference),
        'shortUuid' => 'sub-alert-test',
        'status' => 'ACTIVE',
        'trafficLimitBytes' => $limit,
        'trafficLimitStrategy' => 'NO_RESET',
        'expireAt' => $expire,
        'subscriptionUrl' => 'https://example.test/credential-secret',
        'userTraffic' => ['usedTrafficBytes' => $used],
        'activeInternalSquads' => [],
    ];
}

/** @param array<string, mixed> $remote */
function subAlertHttp(array &$remote): void
{
    Http::fake(static function (Request $request) use (&$remote) {
        if ($request->method() === 'GET' && $request->url() === 'https://remna.test/api/users/42') {
            return Http::response(['response' => $remote]);
        }
        if (str_contains($request->url(), 'api.telegram.org/bot54321:sales_test/sendMessage')) {
            return Http::response(['ok' => true, 'result' => ['message_id' => 99]]);
        }

        return Http::response(['ok' => false], 503);
    });
}

it('issues one 24-hour and one 1-hour alert for an unchanged expiration', function (): void {
    $data = subAlertFixture();
    $remote = subAlertRemote($data['reference'], now()->addHours(23)->toIso8601String());
    subAlertHttp($remote);
    $monitor = app(SubscriptionAlertMonitor::class);

    expect($monitor->scan())->toBe(1);
    $monitor->scan();
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'SubscriptionExpiry24h')->count())->toBe(1);

    $this->travel(22)->hours();
    $this->travel(30)->minutes();
    $monitor->scan();
    $monitor->scan();
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'SubscriptionExpiry1h')->count())->toBe(1)
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(2);

    $this->travel(60)->minutes();
    $monitor->scan();
    expect(DB::table('telegram_notification_deliveries')->count())->toBe(2);
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'GET');
    Http::assertNotSent(static fn (Request $r): bool => $r->method() !== 'GET');
});

it('rearms both expiration alerts after a verified new expiry, never on unchanged scans', function (): void {
    $data = subAlertFixture();
    $remote = subAlertRemote($data['reference'], now()->addMinutes(40)->toIso8601String());
    subAlertHttp($remote);
    $monitor = app(SubscriptionAlertMonitor::class);
    $monitor->scan();
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'SubscriptionExpiry1h')->count())->toBe(1);

    $remote['expireAt'] = now()->addHours(12)->toIso8601String();
    $monitor->scan();
    $monitor->scan();
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'SubscriptionExpiry24h')->count())->toBe(1);

    $remote['expireAt'] = now()->addMinutes(45)->toIso8601String();
    $monitor->scan();
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'SubscriptionExpiry1h')->count())->toBe(2);
});

it('warns below 350 MiB and rearms only after verified quota replenishment', function (): void {
    $data = subAlertFixture();
    $gb = 1024 * 1024 * 1024;
    $remote = subAlertRemote($data['reference'], now()->addDays(10)->toIso8601String(), $gb - 350 * 1024 * 1024);
    subAlertHttp($remote);
    $monitor = app(SubscriptionAlertMonitor::class);

    $monitor->scan();
    expect(DB::table('telegram_notification_deliveries')->count())->toBe(0);

    $remote['userTraffic']['usedTrafficBytes'] += 1024 * 1024;
    $monitor->scan();
    $monitor->scan();
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'SubscriptionLowTraffic')->count())->toBe(1);

    // More consumption cannot cause repeated warnings for the same quota.
    $remote['userTraffic']['usedTrafficBytes'] += 100 * 1024 * 1024;
    $monitor->scan();
    expect(DB::table('telegram_notification_deliveries')->count())->toBe(1);

    // Remote reset restores remaining traffic above the threshold.
    $remote['userTraffic']['usedTrafficBytes'] = 0;
    $monitor->scan();
    $remote['userTraffic']['usedTrafficBytes'] = $gb - 100 * 1024 * 1024;
    $monitor->scan();
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'SubscriptionLowTraffic')->count())->toBe(2)
        ->and(DB::table('subscription_alert_states')->value('quota_generation'))->toBe(2);
});

it('does not send queued warnings after renewal, refund or loss of Sales contact', function (): void {
    $data = subAlertFixture();
    $remote = subAlertRemote($data['reference'], now()->addHours(23)->toIso8601String());
    subAlertHttp($remote);
    app(SubscriptionAlertMonitor::class)->scan();

    $id = (int) DB::table('telegram_notification_deliveries')->value('id');
    $remote['expireAt'] = now()->addDays(20)->toIso8601String();
    app(DispatchTelegramNotification::class, ['deliveryId' => $id])->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->value('state'))->toBe('unavailable');

    // A refund invalidates ownership of the active service, even for stale queued jobs.
    DB::table('sales_orders')->where('id', $data['order'])->update(['status' => 'refunded']);
    $remote['expireAt'] = now()->addHours(10)->toIso8601String();
    expect(app(SubscriptionAlertMonitor::class)->scan())->toBe(0);
    Http::assertNotSent(static fn (Request $r): bool => $r->method() === 'POST');
});

it('delivers a private alert through Sales and never sends a credential', function (): void {
    $data = subAlertFixture();
    $remote = subAlertRemote($data['reference'], now()->addHours(12)->toIso8601String());
    subAlertHttp($remote);
    app(SubscriptionAlertMonitor::class)->scan();
    $id = (int) DB::table('telegram_notification_deliveries')->value('id');
    app(DispatchTelegramNotification::class, ['deliveryId' => $id])->handle(app(TelegramNotificationSender::class));

    expect(DB::table('telegram_notification_deliveries')->value('state'))->toBe('sent');
    Http::assertSent(static fn (Request $r): bool => $r->method() === 'POST'
        && str_contains($r->url(), '54321:sales_test/sendMessage')
        && str_contains((string) $r->body(), 'sales%3Asubs%3Aview%3A'.$data['order'])
        && ! str_contains((string) $r->body(), 'credential-secret'));

    app(DispatchTelegramNotification::class, ['deliveryId' => $id])->handle(app(TelegramNotificationSender::class));
    Http::assertSentCount(3); // scan GET, delivery GET, one Telegram POST.
});

it('fails closed for unreachable buyers, provider errors and ownership mismatches', function (): void {
    $data = subAlertFixture();
    $remote = subAlertRemote($data['reference'], now()->addMinutes(35)->toIso8601String());
    $remote['description'] = 'wrong-owner';
    subAlertHttp($remote);
    expect(app(SubscriptionAlertMonitor::class)->scan())->toBe(0);
    expect(DB::table('telegram_notification_deliveries')->count())->toBe(0);

    $remote['description'] = 'SkyNetwork ref:'.hash('sha256', $data['reference']);
    DB::table('telegram_bot_contacts')->where('user_id', $data['buyer']->id)
        ->update(['delivery_status' => 'blocked']);
    expect(app(SubscriptionAlertMonitor::class)->scan())->toBe(0);
    DB::table('telegram_bot_contacts')->where('user_id', $data['buyer']->id)
        ->update(['delivery_status' => 'reachable']);
    $data['buyer']->forceFill(['is_active' => false])->save();
    expect(app(SubscriptionAlertMonitor::class)->scan())->toBe(0);
    expect(DB::table('telegram_notification_deliveries')->count())->toBe(0);
});

it('keeps scanning gated and the command registered without touching Remnawave when disabled', function (): void {
    $data = subAlertFixture();
    $remote = subAlertRemote($data['reference'], now()->addHours(3)->toIso8601String());
    subAlertHttp($remote);
    config()->set('telegram_notifications.enabled', false);

    $this->artisan('sales:subscriptions:alerts')->assertSuccessful();
    expect(DB::table('subscription_alert_states')->count())->toBe(0)
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(0);
    Http::assertNothingSent();
});

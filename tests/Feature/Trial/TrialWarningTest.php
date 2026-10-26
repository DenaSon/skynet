<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use App\Interfaces\Telegram\Notifications\TelegramNotificationSender;
use App\Interfaces\Telegram\Notifications\TrialEndMonitor;
use App\Interfaces\Telegram\Notifications\TrialWarningNotifications;
use App\Modules\Trial\Application\ProvisionFreeTrial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\FreeTrialFixtures;

uses(RefreshDatabase::class, FreeTrialFixtures::class);

beforeEach(function (): void {
    $this->configureFreeTrial();
    $this->freezeTime();
    $member = $this->trialMember();
    $this->claimId = $this->activateFreeTrial($member);
    $this->recordTrialSalesContact($member);
});

it('warns strictly below 10 MiB once while the trial still has traffic', function (): void {
    $this->trialRemote['userTraffic']['usedTrafficBytes'] = ProvisionFreeTrial::BYTES - 10 * 1048576;
    app(TrialEndMonitor::class)->scan();
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'TrialLowTraffic')->count())->toBe(0);
    $this->trialRemote['userTraffic']['usedTrafficBytes']++;
    app(TrialEndMonitor::class)->scan();
    app(TrialEndMonitor::class)->scan();
    $delivery = DB::table('telegram_notification_deliveries')->where('event_type', 'TrialLowTraffic')->first();
    expect($delivery)->not->toBeNull()
        ->and(DB::table('telegram_notification_deliveries')->where('event_type', 'TrialLowTraffic')->count())->toBe(1);
    $worker = new DispatchTelegramNotification((int) $delivery->id);
    $worker->handle(app(TelegramNotificationSender::class));
    $worker->handle(app(TelegramNotificationSender::class));
    expect($this->trialTelegramAttempts)->toBe(1)
        ->and(DB::table('telegram_notification_deliveries')->find($delivery->id)->state)->toBe('sent');
    Http::assertSent(fn ($r): bool => str_contains($r['text'] ?? '', 'کمتر از ۱۰ مگابایت')
        && str_contains($r['reply_markup'] ?? '', 'sales:buy'));
});

it('warns at the five minute boundary once and ends later with a separate final notice', function (): void {
    $this->travel(6899)->seconds();
    app(TrialEndMonitor::class)->scan();
    $scheduled = DB::table('telegram_notification_deliveries')->where('event_type', 'TrialExpiring5m')->first();
    expect(Carbon::parse($scheduled->available_at)->timestamp)->toBe(now()->timestamp + 1);
    (new DispatchTelegramNotification((int) $scheduled->id))->handle(app(TelegramNotificationSender::class));
    expect($this->trialTelegramAttempts)->toBe(0);
    $this->travel(1)->seconds();
    app(TrialEndMonitor::class)->scan();
    app(TrialEndMonitor::class)->scan();
    $delivery = DB::table('telegram_notification_deliveries')->where('event_type', 'TrialExpiring5m')->first();
    expect($delivery)->not->toBeNull();
    (new DispatchTelegramNotification((int) $delivery->id))->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->find($delivery->id)->state)->toBe('sent');
    $this->travel(300)->seconds();
    app(TrialEndMonitor::class)->scan();
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'TrialExpiring5m')->count())->toBe(1)
        ->and(DB::table('telegram_notification_deliveries')->where('event_type', 'TrialEnded')->count())->toBe(1);
});

it('drops delayed warnings after either type of completion and still sends the final notice', function (bool $traffic): void {
    $this->travel(115)->minutes();
    $this->trialRemote['userTraffic']['usedTrafficBytes'] = ProvisionFreeTrial::BYTES - 1;
    app(TrialEndMonitor::class)->scan();
    expect(DB::table('telegram_notification_deliveries')->whereIn('event_type', ['TrialLowTraffic', 'TrialExpiring5m'])->count())->toBe(2);
    if ($traffic) {
        $this->trialRemote['userTraffic']['usedTrafficBytes'] = ProvisionFreeTrial::BYTES;
    } else {
        $this->travel(5)->minutes();
    }
    foreach (DB::table('telegram_notification_deliveries')->whereIn('event_type', ['TrialLowTraffic', 'TrialExpiring5m'])->get() as $row) {
        (new DispatchTelegramNotification((int) $row->id))->handle(app(TelegramNotificationSender::class));
        expect(DB::table('telegram_notification_deliveries')->find($row->id)->last_failure)->toBe('trial_warning_obsolete');
    }
    app(TrialEndMonitor::class)->scan();
    $end = DB::table('telegram_notification_deliveries')->where('event_type', 'TrialEnded')->first();
    (new DispatchTelegramNotification((int) $end->id))->handle(app(TelegramNotificationSender::class));
    expect($this->trialTelegramAttempts)->toBe(1)
        ->and(DB::table('trial_claims')->find($this->claimId)->end_reason)->toBe($traffic ? 'traffic' : 'time');
})->with([true, false]);

it('does not warn on disabled or unknown provider data and logs safe evidence for recovery', function (): void {
    $this->travel(115)->minutes();
    Log::spy();
    $this->trialProviderStatus = 503;
    app(TrialEndMonitor::class)->scan();
    Log::shouldHaveReceived('warning')->with('Trial monitoring unavailable.', ['trial_id' => $this->claimId])->once();
    $this->trialProviderStatus = 200;
    $this->trialRemote['status'] = 'DISABLED';
    app(TrialEndMonitor::class)->scan();
    expect($this->trialTelegramAttempts)->toBe(0);
    $this->trialRemote['status'] = 'ACTIVE';
    app(TrialEndMonitor::class)->scan();
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'TrialExpiring5m')->count())->toBe(1);
});

it('retries a warning provider outage before sending and rejects forged warning event keys', function (): void {
    $this->travel(115)->minutes();
    app(TrialEndMonitor::class)->scan();
    $row = DB::table('telegram_notification_deliveries')->where('event_type', 'TrialExpiring5m')->first();
    $worker = new DispatchTelegramNotification((int) $row->id);
    $this->trialProviderStatus = 503;
    $worker->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->find($row->id)->state)->toBe('pending');
    $this->trialProviderStatus = 200;
    $this->travel(10)->seconds();
    $worker->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->find($row->id)->state)->toBe('sent');
    $row->event_key = 'free-trial:999:TrialExpiring5m';
    expect(app(TrialWarningNotifications::class)->messageFor($row, 9210001))->toBeNull();
});

it('provides read-only safe diagnosis including failed outbox deliveries', function (): void {
    $this->artisan('sales:trials:inspect')->expectsOutputToContain('"provider_read":"ok"')
        ->expectsOutputToContain('"sales_contact_reachable":true')->assertSuccessful();
    $this->trialProviderStatus = 503;
    $this->artisan('sales:trials:inspect', ['id' => $this->claimId])
        ->expectsOutputToContain('Provider/owner verification unavailable')->assertSuccessful();
    expect(DB::table('trial_claims')->find($this->claimId)->state)->toBe('active')
        ->and(DB::table('telegram_notification_deliveries')->where('event_type', 'TrialEnded')->count())->toBe(0);
    $this->artisan('sales:trials:inspect', ['id' => 'invalid'])->assertFailed();
});

<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use App\Interfaces\Telegram\Notifications\SubscriptionAlertMonitor;
use App\Interfaces\Telegram\Notifications\TelegramNotificationSender;
use App\Interfaces\Telegram\Notifications\TrialEndMonitor;
use App\Modules\Trial\Application\EndFreeTrial;
use App\Modules\Trial\Application\ProvisionFreeTrial;
use App\Modules\Trial\Application\ReadFreeTrial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FreeTrialFixtures;

uses(RefreshDatabase::class, FreeTrialFixtures::class);

beforeEach(function (): void {
    $this->configureFreeTrial();
    $this->freezeTime();
});

it('ends on exactly two hours only after a real-source GET and never emits the paid warnings', function (): void {
    $member = $this->trialMember();
    $id = $this->activateFreeTrial($member);
    $this->recordTrialSalesContact($member);
    $monitor = app(TrialEndMonitor::class);
    $this->travel(7199)->seconds();
    expect($monitor->scan())->toBe(0);
    expect(app(SubscriptionAlertMonitor::class)->scan())->toBe(0);
    $this->travel(1)->seconds();
    expect($monitor->scan())->toBe(1)
        ->and(DB::table('trial_claims')->find($id)->end_reason)->toBe('time');
    expect($monitor->scan())->toBe(0);
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'TrialEnded')->count())->toBe(1)
        ->and(DB::table('telegram_notification_deliveries')->whereIn('event_type', [
            'SubscriptionExpiry24h', 'SubscriptionExpiry1h', 'SubscriptionLowTraffic',
        ])->count())->toBe(0)
        ->and(DB::table('subscription_alert_states')->count())->toBe(0);
});

it('ends early at exactly 250 MiB even before the provider status label catches up', function (string $status): void {
    $member = $this->trialMember();
    $id = $this->activateFreeTrial($member);
    $this->recordTrialSalesContact($member);
    $this->travel(25)->minutes();
    $this->trialRemote['status'] = $status;
    $this->trialRemote['userTraffic']['usedTrafficBytes'] = ProvisionFreeTrial::BYTES;
    expect(app(TrialEndMonitor::class)->scan())->toBe(1)
        ->and(DB::table('trial_claims')->find($id)->end_reason)->toBe('traffic');
    expect(app(EndFreeTrial::class)->handle($member->id, 9210001))->toBeFalse()
        ->and(DB::table('telegram_notification_deliveries')->where('event_type', 'TrialEnded')->count())->toBe(1);
    expect(fn () => app(ReadFreeTrial::class)->connection($member->id, 9210001))->toThrow(RuntimeException::class);
})->with(['ACTIVE', 'LIMITED']);

it('creates only one terminal intent when both time and traffic finish together', function (): void {
    $member = $this->trialMember();
    $this->activateFreeTrial($member);
    $this->recordTrialSalesContact($member);
    $this->travel(2)->hours();
    $this->trialRemote['userTraffic']['usedTrafficBytes'] = ProvisionFreeTrial::BYTES;
    app(TrialEndMonitor::class)->scan();
    app(TrialEndMonitor::class)->scan();
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'TrialEnded')->count())->toBe(1);
});

it('keeps an unavailable or identity-mismatched provider unknown and later recovers', function (): void {
    $member = $this->trialMember();
    $id = $this->activateFreeTrial($member);
    $this->recordTrialSalesContact($member);
    $this->travel(3)->hours();
    $this->trialProviderStatus = 503;
    expect(app(TrialEndMonitor::class)->scan())->toBe(0)
        ->and(DB::table('trial_claims')->find($id)->state)->toBe('active');
    $this->trialProviderStatus = 200;
    $oldMarker = $this->trialRemote['description'];
    $this->trialRemote['description'] = 'foreign';
    expect(app(TrialEndMonitor::class)->scan())->toBe(0);
    $this->trialRemote['description'] = $oldMarker;
    expect(app(TrialEndMonitor::class)->scan())->toBe(1);
});

it('does not mistake disabled status for early expiration or read when the trial switch is off', function (): void {
    $member = $this->trialMember();
    $id = $this->activateFreeTrial($member);
    $this->recordTrialSalesContact($member);
    $this->trialRemote['status'] = 'DISABLED';
    expect(app(TrialEndMonitor::class)->scan())->toBe(0)
        ->and(DB::table('trial_claims')->find($id)->state)->toBe('active');
    config()->set('trial.enabled', false);
    $this->travel(3)->hours();
    expect(app(TrialEndMonitor::class)->scan())->toBe(0);
    Http::assertSentCount(2); // CREATE plus the first GET only.
});

it('sends one final notice with existing buy and plan callbacks across repeated jobs', function (): void {
    $member = $this->trialMember();
    $id = $this->activateFreeTrial($member);
    $this->recordTrialSalesContact($member);
    $this->travel(2)->hours();
    app(TrialEndMonitor::class)->scan();
    $delivery = DB::table('telegram_notification_deliveries')->where('event_type', 'TrialEnded')->first();
    $worker = new DispatchTelegramNotification((int) $delivery->id);
    $worker->handle(app(TelegramNotificationSender::class));
    $worker->handle(app(TelegramNotificationSender::class));
    expect($this->trialTelegramAttempts)->toBe(1)
        ->and(DB::table('telegram_notification_deliveries')->find($delivery->id)->state)->toBe('sent')
        ->and(DB::table('trial_claims')->find($id)->end_notice_sent_at)->not->toBeNull();
    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/sendMessage')) {
            return false;
        }
        $buttons = json_decode($request['reply_markup'], true);

        return str_contains($request['text'], 'تست رایگان شما به پایان رسید')
            && isset($buttons['keyboard'])
            && str_contains(json_encode($buttons, JSON_UNESCAPED_UNICODE), 'خرید سرویس')
            && str_contains(json_encode($buttons, JSON_UNESCAPED_UNICODE), 'پلن‌ها و قیمت‌ها')
            && ! str_contains(json_encode($buttons, JSON_UNESCAPED_UNICODE), 'تست رایگان');
    });
    expect(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('referrals')->count())->toBe(0);
});

it('retries a delivery-time provider outage before claiming the terminal send fence', function (): void {
    $member = $this->trialMember();
    $id = $this->activateFreeTrial($member);
    $this->recordTrialSalesContact($member);
    $this->travel(2)->hours();
    app(TrialEndMonitor::class)->scan();
    $delivery = DB::table('telegram_notification_deliveries')->where('event_type', 'TrialEnded')->first();
    $worker = new DispatchTelegramNotification((int) $delivery->id);
    $this->trialProviderStatus = 503;
    $worker->handle(app(TelegramNotificationSender::class));
    expect($this->trialTelegramAttempts)->toBe(0)
        ->and(DB::table('trial_claims')->find($id)->end_notice_started_at)->toBeNull()
        ->and(DB::table('telegram_notification_deliveries')->find($delivery->id)->state)->toBe('pending');
    $this->trialProviderStatus = 200;
    $this->travel(10)->seconds();
    $worker->handle(app(TelegramNotificationSender::class));
    expect($this->trialTelegramAttempts)->toBe(1)
        ->and(DB::table('telegram_notification_deliveries')->find($delivery->id)->state)->toBe('sent');
});

it('blocks blind resend after an ambiguous Telegram send even after manual outbox retry', function (): void {
    $member = $this->trialMember();
    $id = $this->activateFreeTrial($member);
    $this->recordTrialSalesContact($member);
    $this->travel(2)->hours();
    app(TrialEndMonitor::class)->scan();
    $delivery = DB::table('telegram_notification_deliveries')->where('event_type', 'TrialEnded')->first();
    $worker = new DispatchTelegramNotification((int) $delivery->id);
    $this->trialTelegramStatus = 500;
    $worker->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->find($delivery->id)->state)->toBe('unavailable')
        ->and(DB::table('trial_claims')->find($id)->end_notice_started_at)->not->toBeNull();
    $this->trialTelegramStatus = 200;
    $this->artisan('telegram:notifications:dispatch', ['--retry-id' => (string) $delivery->id])->assertSuccessful();
    $worker->handle(app(TelegramNotificationSender::class));
    expect($this->trialTelegramAttempts)->toBe(1)
        ->and(DB::table('trial_claims')->find($id)->end_notice_sent_at)->toBeNull()
        ->and(DB::table('telegram_notification_deliveries')->find($delivery->id)->last_failure)->toBe('trial_already_attempted');
});

it('retries a known Telegram 429 rejection without duplicating an accepted notice', function (): void {
    $member = $this->trialMember();
    $id = $this->activateFreeTrial($member);
    $this->recordTrialSalesContact($member);
    $this->travel(2)->hours();
    app(TrialEndMonitor::class)->scan();
    $delivery = DB::table('telegram_notification_deliveries')->where('event_type', 'TrialEnded')->first();
    $worker = new DispatchTelegramNotification((int) $delivery->id);
    $this->trialTelegramStatus = 429;
    $worker->handle(app(TelegramNotificationSender::class));
    expect(DB::table('trial_claims')->find($id)->end_notice_started_at)->toBeNull()
        ->and(DB::table('telegram_notification_deliveries')->find($delivery->id)->state)->toBe('pending');
    $this->trialTelegramStatus = 200;
    $this->travel(10)->seconds();
    $worker->handle(app(TelegramNotificationSender::class));
    $worker->handle(app(TelegramNotificationSender::class));
    expect($this->trialTelegramAttempts)->toBe(2)
        ->and(DB::table('telegram_notification_deliveries')->find($delivery->id)->state)->toBe('sent');
});

<?php

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use App\Interfaces\Telegram\Notifications\TelegramNotificationSender;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\CalculateQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Sales\Application\CreatePendingOrder;
use App\Modules\Sales\Application\ManualBankPayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function mb05aUser(int $tg, bool $root = false): User
{
    $user = User::factory()->create();

    if ($root) {
        $user->syncRoles(['super-admin']);
    }

    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $tg]);

    return $user;
}

/** @return array{buyer: User, root: User, order: int, claim: int} */
function mb05aClaim(string $source = 'admin', bool $notifications = true): array
{
    config()->set('telegram_notifications.enabled', $notifications);

    if ($notifications) {
        Queue::fake();
    }

    config()->set('super_admin.telegram_ids', ['996001', '996002']);
    config()->set('sales.manual_bank_review_enabled', true);
    config()->set('nutgram.token', '12345:admin_test');

    app(ConfigureProduct::class)->handle('notify-test', 'Plan');
    app(ConfigureRate::class)->handle('notify-test', 30, 100_000, 20_000);
    $root = mb05aUser(996001, true);
    $buyer = mb05aUser(996099);
    $quote = app(CalculateQuote::class)->handle($buyer->id, 'notify-test', 30, 10);
    $order = app(CreatePendingOrder::class)->handle($buyer->id, $quote, bin2hex(random_bytes(16)))->id;

    $claim = app(ManualBankPayments::class)->submit(
        SubscriptionViewer::telegram(996099), $order,
        'image-token-test', 'image-unique-test', 8001, $source,
    );

    return compact('buyer', 'root', 'order', 'claim');
}

it('records per-root notification intent atomically with the submitted claim and dedupes duplicate photos', function (): void {
    Http::preventStrayRequests();
    Http::fake();
    $data = mb05aClaim();

    $row = DB::table('telegram_notification_deliveries')->first();

    expect($row)->not->toBeNull()
        ->and($row->event_type)->toBe('ReceiptSubmitted')
        ->and($row->bot_key)->toBe('admin')
        ->and((int) $row->recipient_user_id)->toBe($data['root']->id)
        ->and($row->state)->toBe('pending')
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(1);

    $again = app(ManualBankPayments::class)->submit(
        SubscriptionViewer::telegram(996099), $data['order'],
        'image-token-test', 'image-unique-test', 8001,
    );

    expect($again)->toBe($data['claim'])
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(1);
    Http::assertNothingSent();
});

it('records review notifications only on committed decisions and keeps settlement idempotent', function (): void {
    $data = mb05aClaim();
    $payment = app(ManualBankPayments::class);
    $payment->review($data['root']->id, $data['claim'], true, 'MB05ABANK123');

    $notice = DB::table('telegram_notification_deliveries')
        ->where('event_type', 'PaymentReviewed')->first();

    expect($notice)->not->toBeNull()
        ->and($notice->bot_key)->toBe('admin')
        ->and((int) $notice->recipient_user_id)->toBe($data['buyer']->id)
        ->and(DB::table('sales_bank_settlements')->count())->toBe(1)
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(1);

    expect(fn () => $payment->review($data['root']->id, $data['claim'], true, 'MB05ABANK123'))
        ->toThrow(ValidationException::class);
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'PaymentReviewed')->count())->toBe(1)
        ->and(DB::table('sales_bank_settlements')->count())->toBe(1);
});

it('sends an Admin photo through the matching Admin token and never repeats a completed notification', function (): void {
    $data = mb05aClaim();
    config()->set('telegram_notifications.enabled', true);

    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

    $id = (int) DB::table('telegram_notification_deliveries')->value('id');
    app(DispatchTelegramNotification::class, ['deliveryId' => $id])->handle(app(TelegramNotificationSender::class));
    app(DispatchTelegramNotification::class, ['deliveryId' => $id])->handle(app(TelegramNotificationSender::class));

    expect(DB::table('telegram_notification_deliveries')->where('id', $id)->value('state'))->toBe('sent');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '12345:admin_test/sendPhoto'));
});

it('backs off transient errors, then retries and marks an unreachable destination unavailable', function (): void {
    $data = mb05aClaim();
    config()->set('telegram_notifications.enabled', true);

    $id = (int) DB::table('telegram_notification_deliveries')->value('id');
    Http::preventStrayRequests();
    $networkFails = true;
    Http::fake(static function (Request $request) use (&$networkFails) {
        return $networkFails
            ? Http::response(['ok' => false], 500)
            : Http::response(['ok' => true], 200);
    });

    app(DispatchTelegramNotification::class, ['deliveryId' => $id])->handle(app(TelegramNotificationSender::class));

    expect(DB::table('telegram_notification_deliveries')->where('id', $id)->value('state'))->toBe('pending')
        ->and(DB::table('telegram_notification_deliveries')->where('id', $id)->value('attempts'))->toBe(1);

    $this->travel(10)->seconds();
    $networkFails = false;
    app(DispatchTelegramNotification::class, ['deliveryId' => $id])->handle(app(TelegramNotificationSender::class));

    expect(DB::table('telegram_notification_deliveries')->where('id', $id)->value('state'))->toBe('sent');

    // A revoked root cannot get a notification even with a stale queued intent.
    $data2 = mb05aAdditionalClaim($data['buyer']);
    $data['root']->forceFill(['is_active' => false])->save();
    app(DispatchTelegramNotification::class, ['deliveryId' => $data2])->handle(app(TelegramNotificationSender::class));

    expect(DB::table('telegram_notification_deliveries')->where('id', $data2)->value('state'))->toBe('unavailable');
});

function mb05aAdditionalClaim(User $buyer): int
{
    $quote = app(CalculateQuote::class)->handle($buyer->id, 'notify-test', 30, 10);
    $order = app(CreatePendingOrder::class)->handle($buyer->id, $quote, bin2hex(random_bytes(16)))->id;
    app(ManualBankPayments::class)->submit(
        SubscriptionViewer::telegram(996099), $order, 'image-token-again', 'image-unique-again', 8002,
    );

    return (int) DB::table('telegram_notification_deliveries')->where('event_type', 'ReceiptSubmitted')->orderByDesc('id')->value('id');
}

it('reuploads a bounded Sales-origin photo under the Admin token without forwarding a Sales file ID', function (): void {
    $data = mb05aClaim('sales');
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram.sales.token', '54321:sales_test');
    config()->set('telegram_notifications.enabled', true);

    $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP8z8DAwMDAxMDAwMDAAAANHQEDasKb6QAAAABJRU5ErkJggg==', true);
    expect($image)->toBeString();

    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($image) {
        $url = $request->url();

        if (str_contains($url, '54321:sales_test/getFile')) {
            return Http::response(['ok' => true, 'result' => ['file_path' => 'photos/test.png', 'file_size' => strlen($image)]]);
        }

        if (str_contains($url, '/file/bot54321:sales_test/photos/test.png')) {
            return Http::response($image, 200, ['Content-Type' => 'image/png']);
        }

        if (str_contains($url, '12345:admin_test/sendPhoto')) {
            return Http::response(['ok' => true, 'result' => ['message_id' => 2]]);
        }

        return Http::response(['ok' => false], 503);
    });

    $id = (int) DB::table('telegram_notification_deliveries')->value('id');
    app(DispatchTelegramNotification::class, ['deliveryId' => $id])->handle(app(TelegramNotificationSender::class));

    $urls = Http::recorded()->map(static fn (array $record): string => $record[0]->url())->all();
    expect($urls)->toBe([
        'https://api.telegram.org/bot54321:sales_test/getFile',
        'https://api.telegram.org/file/bot54321:sales_test/photos/test.png',
        'https://api.telegram.org/bot12345:admin_test/sendPhoto',
    ]);

    expect(DB::table('telegram_notification_deliveries')->where('id', $id)->value('state'))->toBe('sent');
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '12345:admin_test/sendPhoto'));
});

it('falls back to identifiers on invalid cross-bot photo and never declares photo delivery', function (): void {
    mb05aClaim('sales');
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram.sales.token', '54321:sales_test');
    config()->set('telegram_notifications.enabled', true);

    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'getFile')) {
            return Http::response(['ok' => true, 'result' => ['file_path' => '../secrets', 'file_size' => 20]]);
        }

        return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
    });

    $id = (int) DB::table('telegram_notification_deliveries')->value('id');
    app(DispatchTelegramNotification::class, ['deliveryId' => $id])->handle(app(TelegramNotificationSender::class));

    expect(DB::table('telegram_notification_deliveries')->where('id', $id)->value('state'))->toBe('sent');
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '12345:admin_test/sendMessage'));
});

it('scopes receipt message identity to the source bot without changing historical Admin keys', function (): void {
    $data = mb05aClaim();
    $quote = app(CalculateQuote::class)->handle($data['buyer']->id, 'notify-test', 30, 10);
    $newOrder = app(CreatePendingOrder::class)->handle($data['buyer']->id, $quote, bin2hex(random_bytes(16)))->id;
    $salesClaim = app(ManualBankPayments::class)->submit(
        SubscriptionViewer::telegram(996099), $newOrder,
        'different-photo-id', 'different-unique-id', 8001, 'sales',
    );

    expect(DB::table('sales_bank_payment_claims')->where('id', $data['claim'])->value('telegram_message_key'))
        ->toBe('tg:996099:8001')
        ->and(DB::table('sales_bank_payment_claims')->where('id', $salesClaim)->value('telegram_message_key'))
        ->toBe('tg:sales:996099:8001')
        ->and(DB::table('sales_bank_payment_claims')->count())->toBe(2);
});

it('refuses destructive notification down migration and supports guarded console recovery', function (): void {
    mb05aClaim();
    $migration = require database_path('migrations/2026_10_15_000003_create_telegram_notification_deliveries.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);

    $id = (int) DB::table('telegram_notification_deliveries')->value('id');
    DB::table('telegram_notification_deliveries')->where('id', $id)->update([
        'state' => 'dead', 'attempts' => 5,
    ]);

    config()->set('telegram_notifications.enabled', true);
    Queue::fake();
    $this->artisan('telegram:notifications:dispatch', ['--retry-id' => (string) $id])->assertSuccessful();

    expect(DB::table('telegram_notification_deliveries')->where('id', $id)->value('state'))->toBe('pending')
        ->and(DB::table('telegram_notification_deliveries')->where('id', $id)->value('attempts'))->toBe(0);

    DB::table('telegram_notification_deliveries')->where('id', $id)->update(['state' => 'sent']);
    $this->artisan('telegram:notifications:dispatch', ['--retry-id' => (string) $id])->assertFailed();
});

it('preserves a suppressed durable record without replaying legacy notifications on rollout', function (): void {
    mb05aClaim('admin', false);
    $row = DB::table('telegram_notification_deliveries')->first();

    expect($row->state)->toBe('suppressed');

    config()->set('telegram_notifications.enabled', true);
    Http::preventStrayRequests();
    Http::fake();

    app(DispatchTelegramNotification::class, ['deliveryId' => (int) $row->id])
        ->handle(app(TelegramNotificationSender::class));

    expect(DB::table('telegram_notification_deliveries')->where('id', $row->id)->value('state'))
        ->toBe('suppressed');
    Http::assertNothingSent();
});

it('bounds rate-limit retries, marks dead after five attempts and only recovers by an explicit operator action', function (): void {
    $data = mb05aClaim();
    config()->set('telegram_notifications.enabled', true);
    $id = (int) DB::table('telegram_notification_deliveries')->value('id');

    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Too Many Requests'], 429)]);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        app(DispatchTelegramNotification::class, ['deliveryId' => $id])->handle(app(TelegramNotificationSender::class));

        $entry = DB::table('telegram_notification_deliveries')->where('id', $id)->first();
        expect((int) $entry->attempts)->toBe($attempt)
            ->and($entry->state)->toBe($attempt === 5 ? 'dead' : 'pending');

        $this->travel(301)->seconds();
    }

    app(DispatchTelegramNotification::class, ['deliveryId' => $id])->handle(app(TelegramNotificationSender::class));

    expect(DB::table('telegram_notification_deliveries')->where('id', $id)->value('state'))->toBe('dead');
    Http::assertSentCount(5);

    Queue::fake();
    $this->artisan('telegram:notifications:dispatch', ['--retry-id' => (string) $id])->assertSuccessful();
    expect(DB::table('telegram_notification_deliveries')->where('id', $id)->value('state'))->toBe('pending')
        ->and(DB::table('telegram_notification_deliveries')->where('id', $id)->value('attempts'))->toBe(0)
        ->and(DB::table('sales_bank_settlements')->count())->toBe(0)
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(0);
});

it('recovers expired delivery leases once while ignoring live leases and suppressed legacy notices', function (): void {
    mb05aClaim();
    config()->set('telegram_notifications.enabled', true);
    $id = (int) DB::table('telegram_notification_deliveries')->value('id');

    DB::table('telegram_notification_deliveries')->where('id', $id)->update([
        'state' => 'processing', 'leased_until' => now()->addMinute(),
    ]);

    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

    app(DispatchTelegramNotification::class, ['deliveryId' => $id])->handle(app(TelegramNotificationSender::class));
    Http::assertNothingSent();

    $this->travel(61)->seconds();
    app(DispatchTelegramNotification::class, ['deliveryId' => $id])->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->where('id', $id)->value('state'))->toBe('sent')
        ->and(DB::table('telegram_notification_deliveries')->where('id', $id)->value('attempts'))->toBe(1);

    app(DispatchTelegramNotification::class, ['deliveryId' => $id])->handle(app(TelegramNotificationSender::class));
    Http::assertSentCount(1);

    DB::table('telegram_notification_deliveries')->where('id', $id)->update(['state' => 'suppressed']);
    $this->artisan('telegram:notifications:dispatch', ['--retry-id' => (string) $id])->assertFailed();
    expect(DB::table('telegram_notification_deliveries')->where('id', $id)->value('state'))->toBe('suppressed');
});

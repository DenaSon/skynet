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
function mb05aClaim(string $source = 'admin'): array
{
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
        ->toThrow(\Illuminate\Validation\ValidationException::class);
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
    config()->set('telegram_notifications.enabled', false);
    $data2 = mb05aAdditionalClaim($data['buyer']);
    config()->set('telegram_notifications.enabled', true);
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

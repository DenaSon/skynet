<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use App\Interfaces\Telegram\Notifications\TelegramNotificationSender;
use App\Interfaces\Telegram\Notifications\WalletTopupNotificationOutbox;
use App\Modules\Billing\Application\RequestTopup;
use App\Modules\Billing\Application\SubmitTopupPhoto;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\CalculateQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Sales\Application\CreatePendingOrder;
use App\Modules\Sales\Application\ManualBankPayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['987001', '987002', '987003']);
    config()->set('telegram_notifications.enabled', true);
    config()->set('billing.topups_enabled', true);
    config()->set('sales.manual_bank_review_enabled', true);
    config()->set('nutgram.token', '12345:admin_test');
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake();
});

function reminderRoot(int $telegramId, bool $root = true): User
{
    $user = User::factory()->create();
    if ($root) {
        $user->syncRoles(['super-admin']);
    }
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);

    return $user;
}

/** @return array{buyer:User,claim:int,topup:int} */
function reminderReceipts(): array
{
    app(ConfigureProduct::class)->handle('reminder-test', 'Reminder Plan');
    app(ConfigureRate::class)->handle('reminder-test', 30, 100_000, 20_000);

    $buyer = reminderRoot(987099, false);
    $quote = app(CalculateQuote::class)->handle($buyer->id, 'reminder-test', 30, 10);
    $order = app(CreatePendingOrder::class)->handle($buyer->id, $quote, bin2hex(random_bytes(16)));
    $claim = app(ManualBankPayments::class)->submit(
        SubscriptionViewer::telegram(987099),
        $order->id, 'purchase-file-987099', 'purchase-unique-987099', 77,
    );

    $topup = app(RequestTopup::class)->handle($buyer->id, 500_000, 'reminder:topup:one');
    DB::transaction(static function () use ($buyer, $topup): void {
        app(SubmitTopupPhoto::class)->handle(
            $buyer->id, $topup, 987099, 'topup-file-987099', 'topup-unique-987099', 78,
        );
        app(WalletTopupNotificationOutbox::class)->submitted($topup);
    });

    return ['buyer' => $buyer, 'claim' => $claim, 'topup' => $topup];
}

it('writes no reminder or report and dispatches nothing when no unreviewed receipts exist', function (): void {
    reminderRoot(987001);
    reminderRoot(987002);
    Artisan::call('telegram:receipts:remind');
    Artisan::call('telegram:receipts:remind');

    expect(DB::table('telegram_notification_deliveries')->count())->toBe(0)
        ->and(Artisan::output())->toBe('');
    Queue::assertNotPushed(DispatchTelegramNotification::class);
    Http::assertNothingSent();
});

it('does not remind about fresh receipts or when notifications are disabled', function (): void {
    reminderRoot(987001);
    reminderRoot(987002);
    reminderReceipts();
    $before = DB::table('telegram_notification_deliveries')->count();
    Artisan::call('telegram:receipts:remind');
    expect(DB::table('telegram_notification_deliveries')->count())->toBe($before)
        ->and(DB::table('telegram_notification_deliveries')->where('event_type', 'ReceiptReviewReminder')->count())->toBe(0);

    $this->travel(16)->minutes();
    config()->set('telegram_notifications.enabled', false);
    Artisan::call('telegram:receipts:remind');
    expect(DB::table('telegram_notification_deliveries')->count())->toBe($before);
    Http::assertNothingSent();
});

it('aggregates aged purchase and wallet receipts per authorized root, once per hour', function (): void {
    $root1 = reminderRoot(987001);
    $root2 = reminderRoot(987002);
    $member = reminderRoot(987003, false);
    $data = reminderReceipts();

    $this->travel(16)->minutes();
    Artisan::call('telegram:receipts:remind');

    $rows = DB::table('telegram_notification_deliveries')
        ->where('event_type', 'ReceiptReviewReminder')->orderBy('recipient_user_id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('recipient_user_id')->all())->toBe([$root1->id, $root2->id])
        ->and($rows->pluck('bot_key')->unique()->all())->toBe(['admin'])
        ->and($rows->pluck('state')->unique()->all())->toBe(['pending'])
        ->and($rows->pluck('attempts')->unique()->all())->toBe([0])
        ->and($rows->pluck('event_key')->unique()->count())->toBe(2)
        ->and($member->hasRole('super-admin'))->toBeFalse();
    Queue::assertPushed(DispatchTelegramNotification::class, 6); // two originals per root + two reminders

    Artisan::call('telegram:receipts:remind');
    $this->travel(44)->minutes();
    Artisan::call('telegram:receipts:remind');
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'ReceiptReviewReminder')->count())->toBe(2);

    $this->travel(16)->minutes();
    Artisan::call('telegram:receipts:remind');
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'ReceiptReviewReminder')->count())->toBe(4)
        ->and(DB::table('sales_bank_payment_claims')->where('id', $data['claim'])->value('status'))->toBe('submitted')
        ->and(DB::table('billing_topups')->where('id', $data['topup'])->value('status'))->toBe('submitted')
        ->and(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});

it('sends one private Admin text with both counters and existing protected review routes', function (): void {
    $root = reminderRoot(987001);
    reminderRoot(987002);
    reminderReceipts();
    $this->travel(16)->minutes();
    Artisan::call('telegram:receipts:remind');

    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
    $id = (int) DB::table('telegram_notification_deliveries')
        ->where('event_type', 'ReceiptReviewReminder')->where('recipient_user_id', $root->id)->value('id');
    app(DispatchTelegramNotification::class, ['deliveryId' => $id])
        ->handle(app(TelegramNotificationSender::class));

    expect(DB::table('telegram_notification_deliveries')->where('id', $id)->value('state'))->toBe('sent');
    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '12345:admin_test/sendMessage')
        && (string) $request['chat_id'] === '987001'
        && str_contains((string) $request['text'], 'رسید خرید: <b>1</b>')
        && str_contains((string) $request['text'], 'رسید شارژ کیف پول: <b>1</b>')
        && str_contains((string) $request['reply_markup'], 'bank:list')
        && str_contains((string) $request['reply_markup'], 'finance:topups:1'));

    app(DispatchTelegramNotification::class, ['deliveryId' => $id])
        ->handle(app(TelegramNotificationSender::class));
    Http::assertSentCount(1);
});

it('sends no stale reminder after both reviews and stops scheduling future reminders', function (): void {
    $root = reminderRoot(987001);
    reminderRoot(987002);
    $data = reminderReceipts();
    $this->travel(16)->minutes();
    Artisan::call('telegram:receipts:remind');
    $id = (int) DB::table('telegram_notification_deliveries')
        ->where('event_type', 'ReceiptReviewReminder')->where('recipient_user_id', $root->id)->value('id');

    DB::table('sales_bank_payment_claims')->where('id', $data['claim'])->update(['status' => 'rejected']);
    DB::table('billing_topups')->where('id', $data['topup'])->update(['status' => 'rejected']);
    app(DispatchTelegramNotification::class, ['deliveryId' => $id])
        ->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->where('id', $id)->value('state'))->toBe('unavailable')
        ->and(DB::table('telegram_notification_deliveries')->where('id', $id)->value('last_failure'))->toBe('already_reviewed');
    Http::assertNothingSent();

    $this->travel(61)->minutes();
    Artisan::call('telegram:receipts:remind');
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'ReceiptReviewReminder')->count())->toBe(2);
});

it('refuses a reminder for a root whose permissions were revoked after enqueue', function (): void {
    $root = reminderRoot(987001);
    reminderRoot(987002);
    reminderReceipts();
    $this->travel(16)->minutes();
    Artisan::call('telegram:receipts:remind');

    $id = (int) DB::table('telegram_notification_deliveries')
        ->where('event_type', 'ReceiptReviewReminder')->where('recipient_user_id', $root->id)->value('id');
    $root->syncRoles(['member']);
    app(DispatchTelegramNotification::class, ['deliveryId' => $id])
        ->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->where('id', $id)->value('state'))->toBe('unavailable')
        ->and(DB::table('telegram_notification_deliveries')->where('id', $id)->value('last_failure'))->toBe('not_authorized');
    Http::assertNothingSent();
});

<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use App\Modules\Provisioning\Jobs\FulfillPaidPlanOrderJob;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Billing\Application\BrowsePhotoTopups;
use App\Modules\Billing\Application\ReviewTopup;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Role;
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

/** @param list<string> $permissions */
function reminderDelegated(int $telegramId, string $name, array $permissions): User
{
    $user = reminderRoot($telegramId, false);
    $role = Role::findOrCreate($name, 'web');
    $role->syncPermissions($permissions);
    $user->assignRole($role);
    DB::table('telegram_bot_contacts')->insert([
        'user_id' => $user->id,
        'bot_key' => 'admin',
        'chat_id' => $telegramId,
        'delivery_status' => 'reachable',
        'last_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

it('splits admin entry, purchase view, purchase review and wallet permissions without implicit users.manage', function (): void {
    reminderRoot(987001);
    reminderRoot(987002);
    $viewer = reminderDelegated(987010, 'bank-viewer', ['purchases.view']);
    $reviewer = reminderDelegated(987011, 'bank-reviewer', ['purchases.view', 'purchases.review']);
    $topup = reminderDelegated(987012, 'wallet-reviewer', ['topups.view', 'topups.review']);
    $unpaired = reminderDelegated(987013, 'unpaired-reviewer', ['purchases.review']);
    $data = reminderReceipts();

    $actor = app(ResolveTelegramActor::class)->handle(987010);
    expect($actor?->canEnterAdmin())->toBeTrue()
        ->and($actor?->isAdmin())->toBeFalse()
        ->and($actor?->allows('users.view'))->toBeFalse()
        ->and($actor?->allows('purchases.view'))->toBeTrue()
        ->and($actor?->allows('purchases.review'))->toBeFalse()
        ->and(app(ResolveTelegramActor::class)->handle(987013)?->canEnterAdmin())->toBeFalse()
        ->and(app(ManualBankPayments::class)->pending($viewer->id))->toHaveCount(1)
        ->and(app(ManualBankPayments::class)->pending($reviewer->id))->toHaveCount(1)
        ->and(app(BrowsePhotoTopups::class)->pending($topup->id))->toHaveCount(1);
    expect(fn () => app(ManualBankPayments::class)->review($viewer->id, $data['claim'], false))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ManualBankPayments::class)->detail($topup->id, $data['claim']))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(BrowsePhotoTopups::class)->pending($reviewer->id))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ReviewTopup::class)->handle($viewer->id, $data['topup'], false, 'PHOTO-REJECT'))
        ->toThrow(AuthorizationException::class);
    expect(DB::table('sales_bank_payment_claims')->where('id', $data['claim'])->value('status'))->toBe('submitted');
});

it('fans out both immediate receipt types to matching delegated reviewers and keeps roots', function (): void {
    $root1 = reminderRoot(987001);
    $root2 = reminderRoot(987002);
    $bank = reminderDelegated(987010, 'bank-review', ['purchases.view', 'purchases.review']);
    $wallet = reminderDelegated(987011, 'wallet-review', ['topups.view', 'topups.review']);
    $viewOnly = reminderDelegated(987012, 'wallet-only-view', ['topups.view']);
    reminderReceipts();

    $purchaseIds = DB::table('telegram_notification_deliveries')
        ->where('event_type', 'ReceiptSubmitted')->pluck('recipient_user_id')->all();
    $topupIds = DB::table('telegram_notification_deliveries')
        ->where('event_type', 'WalletTopupSubmitted')->pluck('recipient_user_id')->all();
    expect($purchaseIds)->toHaveCount(3)
        ->and($topupIds)->toHaveCount(3)
        ->and($purchaseIds)->toContain($root1->id, $root2->id, $bank->id)
        ->and($topupIds)->toContain($root1->id, $root2->id, $wallet->id)
        ->and($purchaseIds)->not->toContain($wallet->id, $viewOnly->id)
        ->and($topupIds)->not->toContain($bank->id, $viewOnly->id);
});

it('never leaks cross-category counts in the delegated 15-minute reminders', function (): void {
    reminderRoot(987001);
    reminderRoot(987002);
    $bank = reminderDelegated(987010, 'bank-review', ['purchases.view', 'purchases.review']);
    $wallet = reminderDelegated(987011, 'wallet-review', ['topups.view', 'topups.review']);
    reminderReceipts();

    $this->travel(16)->minutes();
    Artisan::call('telegram:receipts:remind');
    $bankDelivery = (int) DB::table('telegram_notification_deliveries')
        ->where('recipient_user_id', $bank->id)->where('event_type', 'ReceiptReviewReminder')->value('id');
    $walletDelivery = (int) DB::table('telegram_notification_deliveries')
        ->where('recipient_user_id', $wallet->id)->where('event_type', 'ReceiptReviewReminder')->value('id');
    expect($bankDelivery)->toBeGreaterThan(0)
        ->and($walletDelivery)->toBeGreaterThan(0);

    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    app(DispatchTelegramNotification::class, ['deliveryId' => $bankDelivery])
        ->handle(app(TelegramNotificationSender::class));
    app(DispatchTelegramNotification::class, ['deliveryId' => $walletDelivery])
        ->handle(app(TelegramNotificationSender::class));

    Http::assertSentCount(2);
    Http::assertSent(static fn (Request $r): bool => (string) $r['chat_id'] === '987010'
        && str_contains((string) $r['text'], 'رسید خرید')
        && ! str_contains((string) $r['text'], 'رسید شارژ')
        && str_contains((string) $r['reply_markup'], 'bank:list')
        && ! str_contains((string) $r['reply_markup'], 'finance:topups'));
    Http::assertSent(static fn (Request $r): bool => (string) $r['chat_id'] === '987011'
        && ! str_contains((string) $r['text'], 'رسید خرید')
        && str_contains((string) $r['text'], 'رسید شارژ')
        && ! str_contains((string) $r['reply_markup'], 'bank:list')
        && str_contains((string) $r['reply_markup'], 'finance:topups:1'));
});

it('does not create a reminder row when a delegated reviewer has no matching aged work', function (): void {
    reminderRoot(987001);
    reminderRoot(987002);
    $bank = reminderDelegated(987010, 'bank-review', ['purchases.view', 'purchases.review']);
    $data = reminderReceipts();
    DB::table('sales_bank_payment_claims')->where('id', $data['claim'])->update(['status' => 'rejected']);
    $this->travel(16)->minutes();
    Artisan::call('telegram:receipts:remind');

    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'ReceiptReviewReminder')
        ->where('recipient_user_id', $bank->id)->count())->toBe(0)
        ->and(DB::table('telegram_notification_deliveries')->where('event_type', 'ReceiptReviewReminder')->count())->toBe(2);
});

it('revoking a delegated reviewer blocks queued immediate and reminder notifications', function (): void {
    reminderRoot(987001);
    reminderRoot(987002);
    $bank = reminderDelegated(987010, 'bank-review', ['purchases.view', 'purchases.review']);
    reminderReceipts();
    $id = (int) DB::table('telegram_notification_deliveries')
        ->where('event_type', 'ReceiptSubmitted')->where('recipient_user_id', $bank->id)->value('id');

    $bank->syncRoles(['member']);
    app(DispatchTelegramNotification::class, ['deliveryId' => $id])
        ->handle(app(TelegramNotificationSender::class));

    expect(DB::table('telegram_notification_deliveries')->where('id', $id)->value('state'))->toBe('unavailable')
        ->and(DB::table('telegram_notification_deliveries')->where('id', $id)->value('last_failure'))->toBe('not_authorized');
    Http::assertNothingSent();
});

it('lets a delegated reviewer reject an actual purchase and a wallet photo without posting money', function (): void {
    reminderRoot(987001);
    reminderRoot(987002);
    $bank = reminderDelegated(987010, 'bank-review', ['purchases.view', 'purchases.review']);
    $wallet = reminderDelegated(987011, 'wallet-review', ['topups.view', 'topups.review']);
    $data = reminderReceipts();

    app(ManualBankPayments::class)->review($bank->id, $data['claim'], false);
    app(ReviewTopup::class)->handle($wallet->id, $data['topup'], false, 'PHOTO-REJECT');

    expect(DB::table('sales_bank_payment_claims')->where('id', $data['claim'])->value('status'))->toBe('rejected')
        ->and(DB::table('billing_topups')->where('id', $data['topup'])->value('status'))->toBe('rejected')
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('billing_postings')->count())->toBe(0);
});

it('approves a verified purchase once as a delegated bank reviewer without impersonating root in provider job', function (): void {
    reminderRoot(987001);
    reminderRoot(987002);
    $bank = reminderDelegated(987010, 'bank-review', ['purchases.view', 'purchases.review']);
    $data = reminderReceipts();
    config()->set('sales.auto_fulfillment_enabled', true);
    config()->set('services.remnawave.writes_enabled', true);
    config()->set('services.remnawave.squad_bindings_live_verified', true);

    app(ManualBankPayments::class)->review($bank->id, $data['claim'], true, 'DELEGATEDBANK123');

    expect(DB::table('sales_bank_payment_claims')->where('id', $data['claim'])->value('status'))->toBe('approved')
        ->and(DB::table('sales_bank_settlements')->where('verified_by', $bank->id)->count())->toBe(1)
        ->and(DB::table('billing_journals')->where('kind', 'direct_bank_sale')->count())->toBe(1)
        ->and(DB::table('billing_postings')->count())->toBe(2)
        ->and(DB::table('sales_fulfillment_outbox')->where('state', 'pending')->count())->toBe(1);
    Queue::assertPushed(FulfillPaidPlanOrderJob::class,
        static fn (FulfillPaidPlanOrderJob $job): bool => $job->actorId === FulfillPaidPlanOrderJob::VERIFIED_BANK_REVIEW);
    expect(fn () => app(ManualBankPayments::class)->review($bank->id, $data['claim'], true, 'DELEGATEDBANK123'))
        ->toThrow(\\Illuminate\\Validation\\ValidationException::class);
    expect(DB::table('billing_journals')->where('kind', 'direct_bank_sale')->count())->toBe(1);
    Http::assertNothingSent();
});

it('allows users.view to read basic directory details but protects customer 360 and live service read Actions', function (): void {
    $viewer = reminderDelegated(987010, 'read-users', ['users.view']);
    $target = reminderRoot(987099, false);

    expect(app(\\App\\Modules\\Identity\\Application\\BrowseUsers::class)->find($viewer->id, $target->id))
        ->not->toBeNull();
    expect(fn () => app(\\App\\Modules\\Identity\\Application\\ReadCustomerSupportProfile::class)
        ->handle($viewer->id, $target->id))->toThrow(AuthorizationException::class);
    expect(fn () => app(\\App\\Modules\\Provisioning\\Application\\ReadCustomerLiveServices::class)
        ->page($viewer->id, $target->id, 1))->toThrow(AuthorizationException::class);

    $legacyAdmin = User::factory()->admin()->create();
    expect(app(\\App\\Modules\\Identity\\Application\\ReadCustomerSupportProfile::class)
        ->handle($legacyAdmin->id, $target->id)['user']->id)->toBe($target->id);
});

it('requires matching view permission when managing review roles through existing audited actions', function (): void {
    $root = reminderRoot(987001);
    reminderRoot(987002);

    expect(fn () => app(\\App\\Modules\\Identity\\Application\\CreateRole::class)
        ->handle($root, 'invalid-bank', ['purchases.review']))
        ->toThrow(\\Illuminate\\Validation\\ValidationException::class);

    $role = app(\\App\\Modules\\Identity\\Application\\CreateRole::class)
        ->handle($root, 'valid-bank', ['purchases.view']);
    $review = \\Spatie\\Permission\\Models\\Permission::findByName('purchases.review', 'web');
    $view = \\Spatie\\Permission\\Models\\Permission::findByName('purchases.view', 'web');
    $toggle = app(\\App\\Modules\\Identity\\Application\\ToggleRolePermission::class);
    $toggle->handle($root->id, $role->id, $review->id, false);

    expect(fn () => $toggle->handle($root->id, $role->id, $view->id, true))
        ->toThrow(\\Illuminate\\Validation\\ValidationException::class);
    expect($role->fresh()->hasPermissionTo('purchases.review'))->toBeTrue()
        ->and($role->fresh()->hasPermissionTo('purchases.view'))->toBeTrue();
});

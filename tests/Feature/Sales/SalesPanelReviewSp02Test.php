<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use App\Interfaces\Telegram\Notifications\SalesPanelNotificationOutbox;
use App\Interfaces\Telegram\Notifications\TelegramNotificationSender;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ReviewSalesPanelRequest;
use App\Modules\Sales\Application\SalesPanelRequests;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['972001', '972002']);
    config()->set('telegram_notifications.enabled', true);
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram.sales.token', '2345:sp_sales_test');
    config()->set('nutgram.token', '1234:sp_admin_test');
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    Role::firstOrCreate(['name' => 'reseller', 'guard_name' => 'web']);
});

/** @return array{root:User,buyer:User,id:int} */
function sp02Case(): array
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 972001]);
    DB::table('telegram_bot_contacts')->insert([
        'user_id' => $root->id, 'bot_key' => 'admin', 'chat_id' => 972001,
        'delivery_status' => 'reachable', 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $buyer = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $buyer->id, 'telegram_user_id' => 972099]);
    DB::table('telegram_bot_contacts')->insert([
        'user_id' => $buyer->id, 'bot_key' => 'sales', 'chat_id' => 972099,
        'delivery_status' => 'reachable', 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $row = DB::transaction(static function () use ($buyer): array {
        $row = app(SalesPanelRequests::class)->submit($buyer->id, 'علی', 'محمدی', '09123456789');
        app(SalesPanelNotificationOutbox::class)->submitted($row['id']);

        return $row;
    });

    return ['root' => $root, 'buyer' => $buyer, 'id' => $row['id']];
}

it('creates one protected root alert and sends it through the existing notification worker', function (): void {
    $case = sp02Case();
    $notice = DB::table('telegram_notification_deliveries')->first();
    expect($notice->event_type)->toBe('SalesPanelSubmitted')
        ->and((int) $notice->panel_request_id)->toBe($case['id'])
        ->and((int) $notice->recipient_user_id)->toBe($case['root']->id)
        ->and($notice->state)->toBe('pending');

    app(SalesPanelNotificationOutbox::class)->submitted($case['id']);
    expect(DB::table('telegram_notification_deliveries')->count())->toBe(1);

    app(DispatchTelegramNotification::class, ['deliveryId' => (int) $notice->id])
        ->handle(app(TelegramNotificationSender::class));
    Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '1234:sp_admin_test/sendMessage')
        && str_contains((string) $request['text'], 'درخواست جدید پنل فروش')
        && str_contains((string) $request['reply_markup'], 'sp:detail:'.$case['id']));
    expect(DB::table('telegram_notification_deliveries')->value('state'))->toBe('sent');
    app(DispatchTelegramNotification::class, ['deliveryId' => (int) $notice->id])
        ->handle(app(TelegramNotificationSender::class));
    Http::assertSentCount(1);
});

it('atomically approves once, assigns only reseller through Identity and notifies customer', function (): void {
    $case = sp02Case();
    $review = app(ReviewSalesPanelRequest::class);
    $rows = $review->page($case['root']->id, 1);
    expect($rows)->toHaveCount(1)->and($rows[0]['mobile'])->toBe('09123456789');
    DB::transaction(static function () use ($case, $review): void {
        $result = $review->decide($case['root']->id, $case['id'], true);
        expect($result['status'])->toBe('approved');
        app(SalesPanelNotificationOutbox::class)->reviewed($case['id']);
    });

    expect($case['buyer']->fresh()->getRoleNames()->all())->toBe(['reseller'])
        ->and(DB::table('sales_panel_requests')->where('id', $case['id'])->value('reviewed_by_user_id'))->toBe($case['root']->id)
        ->and(DB::table('sales_panel_requests')->where('status', 'pending')->count())->toBe(0)
        ->and(DB::table('telegram_notification_deliveries')->where('event_type', 'SalesPanelApproved')->count())->toBe(1)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('sales_orders')->count())->toBe(0);
    expect(fn () => $review->decide($case['root']->id, $case['id'], true))->toThrow(ValidationException::class);
    expect(DB::table('telegram_notification_deliveries')->count())->toBe(2);
    $id = (int) DB::table('telegram_notification_deliveries')->where('event_type', 'SalesPanelApproved')->value('id');
    app(DispatchTelegramNotification::class, ['deliveryId' => $id])->handle(app(TelegramNotificationSender::class));
    Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '2345:sp_sales_test/sendMessage')
        && str_contains((string) $request['text'], 'پنل فروش شما فعال شد')
        && str_contains((string) $request['reply_markup'], 'sales:account'));
});

it('rejects a request without assigning any privileged role, sends buyer notice once', function (): void {
    $case = sp02Case();
    DB::transaction(static function () use ($case): void {
        app(ReviewSalesPanelRequest::class)->decide($case['root']->id, $case['id'], false);
        app(SalesPanelNotificationOutbox::class)->reviewed($case['id']);
    });
    expect($case['buyer']->fresh()->hasRole('member'))->toBeTrue()
        ->and($case['buyer']->fresh()->hasRole('reseller'))->toBeFalse()
        ->and(DB::table('sales_panel_requests')->value('status'))->toBe('rejected')
        ->and(DB::table('telegram_notification_deliveries')->where('event_type', 'SalesPanelRejected')->count())->toBe(1);
    $id = (int) DB::table('telegram_notification_deliveries')->where('event_type', 'SalesPanelRejected')->value('id');
    app(DispatchTelegramNotification::class, ['deliveryId' => $id])->handle(app(TelegramNotificationSender::class));
    Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '2345:sp_sales_test/sendMessage')
        && str_contains((string) $request['text'], 'تأیید نشد'));
});

it('denies ordinary admin, revoked or disabled root, and a promoted or disabled applicant', function (): void {
    $case = sp02Case();
    $ordinary = User::factory()->admin()->create();
    $review = app(ReviewSalesPanelRequest::class);
    expect(fn () => $review->page($ordinary->id, 1))->toThrow(AuthorizationException::class);
    expect(fn () => $review->find($ordinary->id, $case['id']))->toThrow(AuthorizationException::class);
    expect(fn () => $review->decide($ordinary->id, $case['id'], true))->toThrow(AuthorizationException::class);
    $case['buyer']->syncRoles(['reseller']);
    expect(fn () => $review->decide($case['root']->id, $case['id'], true))->toThrow(ValidationException::class);
    expect(DB::table('sales_panel_requests')->value('status'))->toBe('pending');
    $case['buyer']->syncRoles(['member']);
    $case['buyer']->forceFill(['is_active' => false])->save();
    expect(fn () => $review->decide($case['root']->id, $case['id'], true))->toThrow(ValidationException::class);
    expect(DB::table('sales_panel_requests')->value('status'))->toBe('pending');
    $case['root']->forceFill(['is_active' => false])->save();
    expect(fn () => $review->decide($case['root']->id, $case['id'], false))->toThrow(AuthorizationException::class);
});

it('suppresses alerts without the notification flag and preserves notification audit on rollback', function (): void {
    config()->set('telegram_notifications.enabled', false);
    $case = sp02Case();
    $notice = DB::table('telegram_notification_deliveries')->first();
    expect($notice->state)->toBe('suppressed');
    config()->set('telegram_notifications.enabled', true);
    app(DispatchTelegramNotification::class, ['deliveryId' => (int) $notice->id])
        ->handle(app(TelegramNotificationSender::class));
    Http::assertNothingSent();
    $migration = require database_path('migrations/2026_10_21_000004_add_panel_request_id_to_telegram_notification_deliveries.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
});

it('does not deliver a root alert after the application was already reviewed', function (): void {
    $case = sp02Case();
    app(ReviewSalesPanelRequest::class)->decide($case['root']->id, $case['id'], false);
    $id = (int) DB::table('telegram_notification_deliveries')->value('id');
    app(DispatchTelegramNotification::class, ['deliveryId' => $id])->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->value('state'))->toBe('unavailable');
    Http::assertNothingSent();
});

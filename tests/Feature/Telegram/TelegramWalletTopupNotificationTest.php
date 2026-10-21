<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use App\Interfaces\Telegram\Notifications\TelegramNotificationSender;
use App\Interfaces\Telegram\Notifications\WalletTopupNotificationOutbox;
use App\Interfaces\Telegram\Support\BillingReviewTokens;
use App\Modules\Billing\Application\RequestTopup;
use App\Modules\Billing\Application\ReviewTopup;
use App\Modules\Billing\Application\SubmitTopupPhoto;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('billing.topups_enabled', true);
    config()->set('billing.payment_card_number', '6037991234567890');
    config()->set('super_admin.telegram_ids', ['916001', '916002']);
    config()->set('telegram_notifications.enabled', true);
    config()->set('nutgram.token', '12345:admin_test');
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram.sales.token', '54321:sales_test');
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake();
});

function finNotRoute(string $bot): void
{
    $route = new Route('POST', 'api/telegram/'.$bot.'/webhook', static fn (): null => null);
    $route->defaults('bot_key', $bot);
    $route->bind(request());
    request()->setRouteResolver(static fn (): Route => $route);
}

function finNotUser(int $telegramId, bool $root = false): User
{
    $user = User::factory()->create();
    if ($root) {
        $user->syncRoles(['super-admin']);
    }
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);
    DB::table('telegram_bot_contacts')->insert([
        'user_id' => $user->id,
        'bot_key' => $root ? 'admin' : 'sales',
        'chat_id' => $telegramId,
        'delivery_status' => 'reachable',
        'last_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

/** @return array{root:User,buyer:User,topup:int} */
function finNotSubmitted(): array
{
    $root = finNotUser(916001, true);
    $buyer = finNotUser(916099);
    $id = app(RequestTopup::class)->handle($buyer->id, 500000, 'fin:not:topup:1');
    DB::transaction(static function () use ($buyer, $id): void {
        app(SubmitTopupPhoto::class)->handle($buyer->id, $id, 916099, 'topup-photo-file-101', 'unique-photo-101', 501);
        app(WalletTopupNotificationOutbox::class)->submitted($id);
    });

    return ['root' => $root, 'buyer' => $buyer, 'topup' => $id];
}

it('records one root notice on Sales photo submission and never credits or duplicates it', function (): void {
    $root = finNotUser(916001, true);
    $buyer = finNotUser(916099);
    finNotRoute('sales');
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/sales.php');
    $bot->setCommonUser(TelegramUser::make(916099, false, 'Buyer'));
    $bot->setCommonChat(Chat::make(916099, ChatType::PRIVATE));

    $bot->hearCallbackQueryData('sales:wallet:topup')->reply()->assertCalled('editMessageText');
    $bot->hearText('۵۰۰۰۰')->reply()->assertCalled('sendMessage');
    $bot->hearMessage(['message_id' => 501, 'photo' => [
        ['file_id' => 'topup-photo-file-101', 'file_unique_id' => 'unique-photo-101', 'width' => 300, 'height' => 300],
    ]])->reply()->assertCalled('sendMessage');

    $topup = DB::table('billing_topups')->first();
    $notice = DB::table('telegram_notification_deliveries')->first();
    expect($topup->status)->toBe('submitted')
        ->and($notice->event_type)->toBe('WalletTopupSubmitted')
        ->and((int) $notice->topup_id)->toBe((int) $topup->id)
        ->and((int) $notice->recipient_user_id)->toBe($root->id)
        ->and($notice->bot_key)->toBe('admin')
        ->and($notice->state)->toBe('pending')
        ->and(app(ViewWallet::class)->handle($buyer->id)->balanceRials)->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
    app(WalletTopupNotificationOutbox::class)->submitted((int) $topup->id);
    expect(DB::table('telegram_notification_deliveries')->count())->toBe(1);
});

it('forwards a protected photo to an eligible root, then suppresses stale or revoked delivery', function (): void {
    $data = finNotSubmitted();
    $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP8z8DAwMDAxMDAwMDAAAANHQEDasKb6QAAAABJRU5ErkJggg==', true);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(static function (Request $request) use ($image) {
        $url = $request->url();
        if (str_contains($url, '54321:sales_test/getFile')) {
            return Http::response(['ok' => true, 'result' => ['file_path' => 'photos/test.png', 'file_size' => strlen($image)]]);
        }
        if (str_contains($url, '/file/bot54321:sales_test/photos/test.png')) {
            return Http::response($image, 200, ['Content-Type' => 'image/png']);
        }
        if (str_contains($url, '12345:admin_test/sendPhoto')) {
            return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
        }

        return Http::response(['ok' => false], 503);
    });

    $notice = DB::table('telegram_notification_deliveries')->first();
    app(DispatchTelegramNotification::class, ['deliveryId' => (int) $notice->id])
        ->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->value('state'))->toBe('sent');
    Http::assertSentCount(3);
    Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/sendPhoto')
        && str_contains((string) $request->body(), 'finance:topup:decision:'.$data['topup'].':a')
    );
    app(DispatchTelegramNotification::class, ['deliveryId' => (int) $notice->id])
        ->handle(app(TelegramNotificationSender::class));
    Http::assertSentCount(3);

    $next = app(RequestTopup::class)->handle($data['buyer']->id, 600000, 'fin:not:topup:2');
    DB::transaction(static function () use ($data, $next): void {
        app(SubmitTopupPhoto::class)->handle($data['buyer']->id, $next, 916099, 'topup-photo-file-102', 'unique-photo-102', 502);
        app(WalletTopupNotificationOutbox::class)->submitted($next);
    });
    $data['root']->syncRoles(['admin']);
    $latest = (int) DB::table('telegram_notification_deliveries')->orderByDesc('id')->value('id');
    app(DispatchTelegramNotification::class, ['deliveryId' => $latest])
        ->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->where('id', $latest)->value('state'))->toBe('unavailable');
    Http::assertSentCount(3);
});

it('records one Sales review result after Admin confirms; never before or on a repeated decision', function (): void {
    $data = finNotSubmitted();
    finNotRoute('admin');
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    require base_path('app/Interfaces/Telegram/routes/legacy_text.php');
    $bot->setCommonUser(TelegramUser::make(916001, false, 'Root'));
    $bot->setCommonChat(Chat::make(916001, ChatType::PRIVATE));

    $token = app(BillingReviewTokens::class)->issue($data['root']->id, $data['topup'], true, 'BANKVERIFIED7001');
    $bot->hearCallbackQueryData('finance:topup:apply:'.$token)->reply()->assertCalled('editMessageText');

    $review = DB::table('telegram_notification_deliveries')->where('event_type', 'WalletTopupApproved')->first();
    expect($review)->not->toBeNull()
        ->and($review->bot_key)->toBe('sales')
        ->and((int) $review->recipient_user_id)->toBe($data['buyer']->id)
        ->and(app(ViewWallet::class)->handle($data['buyer']->id)->balanceRials)->toBe(500000)
        ->and(DB::table('billing_journals')->count())->toBe(1);
    $bot->hearCallbackQueryData('finance:topup:apply:'.$token)->reply()->assertCalled('editMessageText');
    expect(DB::table('telegram_notification_deliveries')->where('event_type', 'WalletTopupApproved')->count())->toBe(1)
        ->and(DB::table('billing_postings')->count())->toBe(2);
    Http::assertNothingSent();

    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['https://api.telegram.org/bot54321:sales_test/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    app(DispatchTelegramNotification::class, ['deliveryId' => (int) $review->id])
        ->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->where('id', $review->id)->value('state'))->toBe('sent');
    Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '54321:sales_test/sendMessage')
        && str_contains((string) $request['text'], '50,000 تومان')
        && str_contains((string) $request['reply_markup'], 'sales:wallet')
    );
});

it('notifies the buyer of a rejection without credit and never replays suppressed intents', function (): void {
    $data = finNotSubmitted();
    DB::transaction(static function () use ($data): void {
        app(ReviewTopup::class)->handle($data['root']->id, $data['topup'], false, 'PHOTO-REJECT');
        app(WalletTopupNotificationOutbox::class)->reviewed($data['topup']);
    });
    $row = DB::table('telegram_notification_deliveries')->where('event_type', 'WalletTopupRejected')->first();
    expect($row)->not->toBeNull()
        ->and(app(ViewWallet::class)->handle($data['buyer']->id)->balanceRials)->toBe(0)
        ->and(DB::table('billing_postings')->count())->toBe(0);

    config()->set('telegram_notifications.enabled', false);
    $next = app(RequestTopup::class)->handle($data['buyer']->id, 600000, 'fin:not:topup:3');
    DB::transaction(static function () use ($data, $next): void {
        app(SubmitTopupPhoto::class)->handle($data['buyer']->id, $next, 916099, 'topup-photo-file-103', 'unique-photo-103', 503);
        app(WalletTopupNotificationOutbox::class)->submitted($next);
    });
    $suppressed = DB::table('telegram_notification_deliveries')->where('topup_id', $next)->first();
    expect($suppressed->state)->toBe('suppressed');

    config()->set('telegram_notifications.enabled', true);
    app(DispatchTelegramNotification::class, ['deliveryId' => (int) $suppressed->id])
        ->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->where('id', $suppressed->id)->value('state'))->toBe('suppressed');
    Http::assertNothingSent();
});

it('falls back to a finance-queue message if the protected root photo is unavailable', function (): void {
    finNotSubmitted();
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(static function (Request $request) {
        if (str_contains($request->url(), '/getFile')) {
            return Http::response(['ok' => true, 'result' => ['file_path' => '../invalid', 'file_size' => 100]]);
        }

        return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
    });

    $id = (int) DB::table('telegram_notification_deliveries')->value('id');
    app(DispatchTelegramNotification::class, ['deliveryId' => $id])->handle(app(TelegramNotificationSender::class));

    expect(DB::table('telegram_notification_deliveries')->where('id', $id)->value('state'))->toBe('sent');
    Http::assertSentCount(2);
    Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '12345:admin_test/sendMessage')
        && str_contains((string) $request['text'], 'تصویر فعلاً قابل ارسال نیست')
        && str_contains((string) $request['reply_markup'], 'finance:topups:1'));
});

it('sends a rejection result to the buyer Sales Bot without a financial posting', function (): void {
    $data = finNotSubmitted();
    DB::transaction(static function () use ($data): void {
        app(ReviewTopup::class)->handle($data['root']->id, $data['topup'], false, 'PHOTO-REJECT');
        app(WalletTopupNotificationOutbox::class)->reviewed($data['topup']);
    });

    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['https://api.telegram.org/bot54321:sales_test/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    $id = (int) DB::table('telegram_notification_deliveries')->where('event_type', 'WalletTopupRejected')->value('id');
    app(DispatchTelegramNotification::class, ['deliveryId' => $id])->handle(app(TelegramNotificationSender::class));

    expect(DB::table('telegram_notification_deliveries')->where('id', $id)->value('state'))->toBe('sent')
        ->and(DB::table('billing_journals')->count())->toBe(0);
    Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '54321:sales_test/sendMessage')
        && str_contains((string) $request['text'], 'تأیید نشد')
        && str_contains((string) $request['reply_markup'], 'sales:wallet'));
});

it('rejects a destructive rollback once wallet notification evidence exists', function (): void {
    finNotSubmitted();
    $migration = require database_path('migrations/2026_10_21_000002_add_topup_id_to_telegram_notification_deliveries.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
});

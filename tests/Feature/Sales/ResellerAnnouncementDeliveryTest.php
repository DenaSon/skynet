<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use App\Interfaces\Telegram\Notifications\TelegramNotificationSender;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ManageAnnouncements;
use App\Modules\Sales\Application\ReadPublishedAnnouncements;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['995001']);
    config()->set('telegram_notifications.enabled', true);
    config()->set('telegram.sales.enabled', true);
    config()->set('nutgram.token', '123:admin_unit_test');
    config()->set('telegram.sales.token', '456:sales_unit_test');
    Role::findOrCreate('super-admin', 'web');
    Role::findOrCreate('reseller', 'web');
    Role::findOrCreate('member', 'web');
    Http::preventStrayRequests();
    Http::fake(['https://api.telegram.org/bot456:sales_unit_test/sendMessage' => Http::response(['ok' => true], 200)]);
});

function raRoot(): User
{
    $user = User::factory()->create();
    $user->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 995001]);

    return $user;
}

function raRecipient(int $chat, string $role = 'reseller', bool $reachable = true, bool $active = true): User
{
    $user = User::factory()->create(['is_active' => $active]);
    $user->syncRoles([$role]);
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $chat]);
    DB::table('telegram_bot_contacts')->insert([
        'user_id' => $user->id,
        'bot_key' => 'sales',
        'chat_id' => $chat,
        'delivery_status' => $reachable ? 'reachable' : 'blocked',
        'last_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

it('targets only reachable active Sales resellers, freezes content and keeps public announcements unchanged', function (): void {
    $root = raRoot();
    $eligible = raRecipient(995101);
    raRecipient(995102, 'member');
    raRecipient(995103, 'reseller', false);
    raRecipient(995104, 'reseller', true, false);
    $other = raRecipient(995105);
    DB::table('telegram_bot_contacts')->where('user_id', $other->id)->update(['chat_id' => 995999]);

    $app = app(ManageAnnouncements::class);
    expect($app->resellerRecipientCount($root->id))->toBe(1);
    $notice = $app->sendToResellers($root->id, 'عنوان <VIP>', "متن & پیام\nخط دوم");

    expect($notice['recipients'])->toBe(1)
        ->and(DB::table('announcements')->where('id', $notice['id'])->value('status'))->toBe('reseller_sent')
        ->and(app(ReadPublishedAnnouncements::class)->latest())->toBe([])
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(1)
        ->and(DB::table('telegram_notification_deliveries')->value('event_key'))
        ->toBe("reseller:ann:{$notice['id']}:user:{$eligible->id}")
        ->and(DB::table('telegram_notification_deliveries')->value('bot_key'))->toBe('sales');

    expect(fn () => $app->update($root->id, $notice['id'], 1, 'ویرایش', 'جدید'))
        ->toThrow(ValidationException::class);
    expect(fn () => $app->changeStatus($root->id, $notice['id'], 1, 'publish'))
        ->toThrow(ValidationException::class);

    $delivery = (int) DB::table('telegram_notification_deliveries')->value('id');
    (new DispatchTelegramNotification($delivery))->handle(app(TelegramNotificationSender::class));

    expect(DB::table('telegram_notification_deliveries')->value('state'))->toBe('sent')
        ->and($app->resellerDeliveryStats($root->id, $notice['id'])['sent'])->toBe(1);
    Http::assertSent(static function (Request $request): bool {
        return str_contains($request->url(), 'bot456:sales_unit_test/sendMessage')
            && (int) $request['chat_id'] === 995101
            && $request['parse_mode'] === 'HTML'
            && str_contains((string) $request['text'], 'عنوان &lt;VIP&gt;')
            && str_contains((string) $request['text'], 'متن &amp; پیام')
            && ! str_contains((string) $request['text'], '<VIP>');
    });

    (new DispatchTelegramNotification($delivery))->handle(app(TelegramNotificationSender::class));
    Http::assertSentCount(1);
});

it('does not broadcast on disabled gates, zero reachability, or revoked root authority', function (): void {
    $root = raRoot();
    $app = app(ManageAnnouncements::class);

    expect(fn () => $app->sendToResellers($root->id, 'عنوان', 'متن'))
        ->toThrow(ValidationException::class);
    raRecipient(995101);
    config()->set('telegram_notifications.enabled', false);
    expect(fn () => $app->sendToResellers($root->id, 'عنوان', 'متن'))
        ->toThrow(ValidationException::class);
    config()->set('telegram_notifications.enabled', true);
    config()->set('telegram.sales.enabled', false);
    expect(fn () => $app->sendToResellers($root->id, 'عنوان', 'متن'))
        ->toThrow(ValidationException::class);
    config()->set('telegram.sales.enabled', true);
    $root->syncRoles(['member']);
    expect(fn () => $app->sendToResellers($root->id, 'عنوان', 'متن'))
        ->toThrow(AuthorizationException::class);

    expect(DB::table('announcements')->count())->toBe(0)
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(0);
    Http::assertNothingSent();
});

it('rechecks reseller role and reachability at delivery rather than trusting old audience snapshots', function (): void {
    $root = raRoot();
    $reseller = raRecipient(995101);
    $app = app(ManageAnnouncements::class);
    $notice = $app->sendToResellers($root->id, 'خبر', 'متن خبر');
    $id = (int) DB::table('telegram_notification_deliveries')->value('id');

    $reseller->syncRoles(['member']);
    (new DispatchTelegramNotification($id))->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->value('state'))->toBe('unavailable')
        ->and(DB::table('telegram_notification_deliveries')->value('last_failure'))->toBe('not_reseller')
        ->and($app->resellerDeliveryStats($root->id, $notice['id'])['failed'])->toBe(1);
    Http::assertNothingSent();
});

it('fences ambiguous Sales transport failures without retrying or sending duplicate broadcast messages', function (): void {
    $root = raRoot();
    raRecipient(995101);
    $app = app(ManageAnnouncements::class);
    $app->sendToResellers($root->id, 'خبر', 'متن خبر');
    $id = (int) DB::table('telegram_notification_deliveries')->value('id');

    Http::fake(['https://api.telegram.org/bot456:sales_unit_test/sendMessage' => Http::response('unavailable', 503)]);
    (new DispatchTelegramNotification($id))->handle(app(TelegramNotificationSender::class));

    expect(DB::table('telegram_notification_deliveries')->value('state'))->toBe('dead')
        ->and(DB::table('telegram_notification_deliveries')->value('last_failure'))->toBe('delivery_uncertain');
    (new DispatchTelegramNotification($id))->handle(app(TelegramNotificationSender::class));
    Http::assertSentCount(1);

    $this->artisan('telegram:notifications:dispatch', ['--retry-id' => (string) $id])
        ->assertFailed();
    expect(DB::table('telegram_notification_deliveries')->value('state'))->toBe('dead');
});

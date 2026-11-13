<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ManageAnnouncements;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['994901', '994902']);
    config()->set('telegram_notifications.enabled', true);
    config()->set('telegram.sales.enabled', true);
    config()->set('nutgram.token', '123:admin_test');
    config()->set('telegram.sales.token', '456:sales_test');
    Role::findOrCreate('super-admin', 'web');
    Role::findOrCreate('reseller', 'web');
    Queue::fake();
});

function raqRoot(): User
{
    $user = User::factory()->create();
    $user->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 994901]);

    return $user;
}

function raqResellers(int $count): void
{
    foreach (range(1, $count) as $i) {
        $user = User::factory()->create();
        $user->syncRoles(['reseller']);
        $chat = 994910 + $i;
        TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $chat]);
        DB::table('telegram_bot_contacts')->insert([
            'user_id' => $user->id,
            'bot_key' => 'sales',
            'chat_id' => $chat,
            'delivery_status' => 'reachable',
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

it('enqueues one delayed job per recipient only after the enclosing database transaction commits', function (): void {
    $now = Carbon::parse('2026-11-13 06:00:00');
    $this->travelTo($now);
    $root = raqRoot();
    raqResellers(3);

    DB::beginTransaction();
    $notice = app(ManageAnnouncements::class)->sendToResellers($root->id, 'خبر', 'متن نمایندگان');
    Queue::assertNotPushed(DispatchTelegramNotification::class);
    expect(DB::table('telegram_notification_deliveries')->count())->toBe(3);

    DB::commit();

    Queue::assertPushed(DispatchTelegramNotification::class, 3);
    $jobs = Queue::pushed(DispatchTelegramNotification::class);
    $delays = $jobs->map(static fn (array $item): int => $item['job']->delay->getTimestamp())
        ->sort()->values()->all();
    expect($delays)->toBe([$now->timestamp, $now->timestamp + 3, $now->timestamp + 6])
        ->and($jobs->pluck('job.deliveryId')->unique()->count())->toBe(3)
        ->and(DB::table('telegram_notification_deliveries')->where('state', 'pending')->count())->toBe(3)
        ->and(DB::table('announcements')->where('id', $notice['id'])->value('status'))->toBe('reseller_sent');
});

it('never enqueues or commits reseller messages when an outer transaction rolls back', function (): void {
    $root = raqRoot();
    raqResellers(2);

    DB::beginTransaction();
    app(ManageAnnouncements::class)->sendToResellers($root->id, 'خبر آزمایشی', 'متن');
    Queue::assertNotPushed(DispatchTelegramNotification::class);

    DB::rollBack();

    Queue::assertNotPushed(DispatchTelegramNotification::class);
    expect(DB::table('announcements')->count())->toBe(0)
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(0);
});

it('keeps the existing minute dispatcher as recovery when an initial Redis enqueue is lost', function (): void {
    $this->travelTo(Carbon::parse('2026-11-13 06:00:00'));
    $root = raqRoot();
    raqResellers(2);
    app(ManageAnnouncements::class)->sendToResellers($root->id, 'خبر', 'متن');

    Queue::assertPushed(DispatchTelegramNotification::class, 2);
    // Simulate a missing transient Redis queue while committed outbox stays intact.
    Queue::fake();
    $this->travel(10)->seconds();
    $this->artisan('telegram:notifications:dispatch')->assertSuccessful();
    Queue::assertPushed(DispatchTelegramNotification::class, 2);

    $events = app(\Illuminate\Console\Scheduling\Schedule::class)->events();
    $event = collect($events)->first(static fn ($item): bool => str_contains(
        (string) ($item->command ?? ''), 'telegram:notifications:dispatch',
    ));
    expect($event)->not->toBeNull()
        ->and($event->expiresAt)->toBe(5);
});
